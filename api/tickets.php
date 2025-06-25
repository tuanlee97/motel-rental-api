<?php
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/utils/common.php';

// Customer: Create Ticket
function createTicket() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    // Ensure the user is a customer
    if ($role !== 'customer') {
        responseJson(['status' => 'error', 'message' => 'Chỉ khách hàng mới có thể tạo ticket'], 403);
        return;
    }

    // Parse request body
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        responseJson(['status' => 'error', 'message' => 'Dữ liệu yêu cầu không hợp lệ'], 400);
        return;
    }

    // Extract and validate required fields
    $subject = isset($data['subject']) ? trim(sanitizeInput($data['subject'])) : null;
    $description = isset($data['description']) ? trim(sanitizeInput($data['description'])) : null;
    $room_id = isset($data['room_id']) ? (int)$data['room_id'] : null;
    $priority = isset($data['priority']) ? trim(sanitizeInput($data['priority'])) : 'medium';
    $status = 'open'; // Default status for new tickets

    if (!$subject || !$description) {
        responseJson(['status' => 'error', 'message' => 'Thiếu tiêu đề hoặc mô tả'], 400);
        return;
    }

    // Validate priority
    $valid_priorities = ['low', 'medium', 'high'];
    if (!in_array($priority, $valid_priorities)) {
        responseJson(['status' => 'error', 'message' => 'Mức độ ưu tiên không hợp lệ'], 400);
        return;
    }

    // Validate room_id if provided
    $contract_id = null;
    if ($room_id) {
        $stmt = $pdo->prepare("
            SELECT id FROM contracts 
            WHERE user_id = ? AND room_id = ? AND status = 'active' AND deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([$user_id, $room_id]);
        $contract_id = $stmt->fetchColumn();

        if (!$contract_id) {
            responseJson(['status' => 'error', 'message' => 'Bạn không có quyền tạo ticket cho phòng này'], 403);
            return;
        }
    }

    // Insert ticket into the database
    try {
        $stmt = $pdo->prepare("
            INSERT INTO tickets (user_id, room_id, contract_id, subject, description, priority, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$user_id, $room_id, $contract_id, $subject, $description, $priority, $status]);

        $ticket_id = $pdo->lastInsertId();

        // Fetch the created ticket to return
        $stmt = $pdo->prepare("
            SELECT t.id, t.user_id, t.room_id, t.contract_id, t.subject, t.description, t.priority, t.status, t.created_at, 
                   r.name AS room_name, u.name AS user_name, b.name AS branch_name, b.id AS branch_id
            FROM tickets t
            LEFT JOIN rooms r ON t.room_id = r.id
            LEFT JOIN branches b ON r.branch_id = b.id
            JOIN users u ON t.user_id = u.id
            WHERE t.id = ?
        ");
        $stmt->execute([$ticket_id]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ticket) {
            responseJson(['status' => 'error', 'message' => 'Không thể tìm thấy ticket vừa tạo'], 500);
            return;
        }

        responseJson([
            'status' => 'success',
            'data' => $ticket
        ], 201);
    } catch (PDOException $e) {
        logError("Lỗi tạo ticket: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Get Tickets for a Customer
function getCustomerTickets($userId) {
    $pdo = getDB();
    $user = verifyJWT();
    $current_user_id = $user['user_id'];
    $role = $user['role'];

    // Ensure the user is a customer and accessing their own tickets
    if ($role !== 'customer' || $current_user_id != $userId) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền truy cập'], 403);
        return;
    }

    // Pagination
    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $conditions = ["t.user_id = ?", "t.deleted_at IS NULL"];
    $params = [$current_user_id];

    // Add search condition
    if (!empty($_GET['search'])) {
        $search = '%' . sanitizeInput($_GET['search']) . '%';
        $conditions[] = "(t.subject LIKE ? OR t.description LIKE ? OR r.name LIKE ?)";
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
    }

    // Add status filter
    if (!empty($_GET['status'])) {
        $conditions[] = "t.status = ?";
        $params[] = sanitizeInput($_GET['status']);
    }

    // Add priority filter
    if (!empty($_GET['priority'])) {
        $conditions[] = "t.priority = ?";
        $params[] = sanitizeInput($_GET['priority']);
    }

    $whereClause = "WHERE " . implode(" AND ", $conditions);

    $query = "
        SELECT 
            t.id, t.user_id, t.room_id, t.contract_id, t.subject, t.description, t.priority, t.status, t.created_at,
            r.name AS room_name, u.name AS user_name, b.name AS branch_name, b.id AS branch_id
        FROM tickets t
        LEFT JOIN rooms r ON t.room_id = r.id
        LEFT JOIN branches b ON r.branch_id = b.id
        JOIN users u ON t.user_id = u.id
        $whereClause
        ORDER BY t.created_at DESC
        LIMIT $limit OFFSET $offset
    ";

    try {
        // Count total records
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM tickets t LEFT JOIN rooms r ON t.room_id = r.id JOIN users u ON t.user_id = u.id $whereClause");
        $countStmt->execute($params);
        $totalRecords = $countStmt->fetchColumn();
        $totalPages = ceil($totalRecords / $limit);

        // Fetch tickets
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch statistics
        $statsQuery = "
            SELECT 
                COUNT(*) AS total,
                SUM(CASE WHEN t.status = 'open' THEN 1 ELSE 0 END) AS open,
                SUM(CASE WHEN t.status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress,
                SUM(CASE WHEN t.status = 'resolved' THEN 1 ELSE 0 END) AS resolved,
                SUM(CASE WHEN t.status = 'closed' THEN 1 ELSE 0 END) AS closed
            FROM tickets t
            LEFT JOIN rooms r ON t.room_id = r.id
            JOIN users u ON t.user_id = u.id
            $whereClause
        ";
        $statsStmt = $pdo->prepare($statsQuery);
        $statsStmt->execute($params);
        $statistics = $statsStmt->fetch(PDO::FETCH_ASSOC);

        responseJson([
            'status' => 'success',
            'data' => [
                'tickets' => $tickets,
                'statistics' => $statistics
            ],
            'pagination' => [
                'current_page' => $page,
                'limit' => $limit,
                'total_records' => $totalRecords,
                'total_pages' => $totalPages
            ]
        ]);
    } catch (PDOException $e) {
        logError("Lỗi lấy danh sách ticket: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Get All Tickets (Admin/Owner/Employee)
function getAllTickets() {
    $pdo = getDB();
    $user = verifyJWT();
    if (!$user) {
        responseJson(['status' => 'error', 'message' => 'Không xác thực được người dùng'], 401);
        return;
    }
    $user_id = $user['user_id'];
    $role = $user['role'];

    // Pagination
    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    // Conditions and parameters
    $conditions = ['t.deleted_at IS NULL', 'u.deleted_at IS NULL'];
    $params = [];

    // Role-based access control
    if ($role === 'admin') {
        // Admin can filter by owner_id (userId) or branch_id
        if (!empty($_GET['userId'])) {
            $owner_id = (int)sanitizeInput($_GET['userId']);
            $stmt = $pdo->prepare("
                SELECT 1 
                FROM users u
                JOIN branches b ON b.owner_id = u.id
                WHERE u.id = ? AND u.deleted_at IS NULL AND b.deleted_at IS NULL
                LIMIT 1
            ");
            $stmt->execute([$owner_id]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Owner không tồn tại hoặc không sở hữu chi nhánh'], 404);
                return;
            }
            $conditions[] = "b.owner_id = ?";
            $params[] = $owner_id;
        }
        if (!empty($_GET['branch_id'])) {
            $branch_id = (int)$_GET['branch_id'];
            $conditions[] = "b.id = ?";
            $params[] = $branch_id;
        }
    } elseif ($role === 'owner') {
        $conditions[] = "b.owner_id = ?";
        $params[] = $user_id;
    } elseif ($role === 'employee') {
        $conditions[] = "b.id IN (SELECT branch_id FROM employee_assignments WHERE employee_id = ? AND deleted_at IS NULL)";
        $params[] = $user_id;
    } else {
        responseJson(['status' => 'error', 'message' => 'Không có quyền truy cập danh sách ticket'], 403);
        return;
    }

    // Add search condition
    if (!empty($_GET['search'])) {
        $search = '%' . sanitizeInput($_GET['search']) . '%';
        $conditions[] = "(t.subject LIKE ? OR t.description LIKE ? OR r.name LIKE ? OR u.name LIKE ?)";
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
    }

    // Add status filter
    if (!empty($_GET['status'])) {
        $conditions[] = "t.status = ?";
        $params[] = sanitizeInput($_GET['status']);
    }

    // Add priority filter
    if (!empty($_GET['priority'])) {
        $conditions[] = "t.priority = ?";
        $params[] = sanitizeInput($_GET['priority']);
    }

    $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

    $baseJoin = "
        FROM tickets t
        LEFT JOIN rooms r ON t.room_id = r.id
        LEFT JOIN branches b ON r.branch_id = b.id
        JOIN users u ON t.user_id = u.id
        LEFT JOIN contracts c ON t.contract_id = c.id
    ";

    $query = "
        SELECT 
            t.id, t.user_id, t.room_id, t.contract_id, t.subject, t.description, t.priority, t.status, t.created_at,
            r.name AS room_name, u.name AS user_name, b.name AS branch_name, b.id AS branch_id
        $baseJoin
        $whereClause
        ORDER BY t.created_at DESC
        LIMIT $limit OFFSET $offset
    ";

    try {
        // Count total records
        $countStmt = $pdo->prepare("SELECT COUNT(*) $baseJoin $whereClause");
        $countStmt->execute($params);
        $totalRecords = $countStmt->fetchColumn();
        $totalPages = ceil($totalRecords / $limit);

        // Fetch tickets
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch statistics
        $statsQuery = "
            SELECT 
                COUNT(*) AS total,
                SUM(CASE WHEN t.status = 'open' THEN 1 ELSE 0 END) AS open,
                SUM(CASE WHEN t.status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress,
                SUM(CASE WHEN t.status = 'resolved' THEN 1 ELSE 0 END) AS resolved,
                SUM(CASE WHEN t.status = 'closed' THEN 1 ELSE 0 END) AS closed
            $baseJoin
            $whereClause
        ";
        $statsStmt = $pdo->prepare($statsQuery);
        $statsStmt->execute($params);
        $statistics = $statsStmt->fetch(PDO::FETCH_ASSOC);

        responseJson([
            'status' => 'success',
            'data' => [
                'tickets' => $tickets,
                'statistics' => $statistics
            ],
            'pagination' => [
                'current_page' => $page,
                'limit' => $limit,
                'total_records' => $totalRecords,
                'total_pages' => $totalPages
            ]
        ]);
    } catch (PDOException $e) {
        logError("Lỗi lấy danh sách ticket: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Update Ticket (Admin/Owner/Employee/Customer)
function updateTicket($id) {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    // Parse request body
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data || (!isset($data['status']) && !isset($data['priority']) && !isset($data['room_id']))) {
        responseJson(['status' => 'error', 'message' => 'Thiếu trạng thái, mức độ ưu tiên hoặc phòng để cập nhật'], 400);
        return;
    }

    $status = isset($data['status']) ? trim(sanitizeInput($data['status'])) : null;
    $priority = isset($data['priority']) ? trim(sanitizeInput($data['priority'])) : null;
    $room_id = isset($data['room_id']) ? (int)$data['room_id'] : null;

    // Validate status and priority
    $valid_statuses = ['open', 'in_progress', 'resolved', 'closed'];
    $valid_priorities = ['low', 'medium', 'high'];

    if ($status && !in_array($status, $valid_statuses)) {
        responseJson(['status' => 'error', 'message' => 'Trạng thái không hợp lệ'], 400);
        return;
    }

    if ($priority && !in_array($priority, $valid_priorities)) {
        responseJson(['status' => 'error', 'message' => 'Mức độ ưu tiên không hợp lệ'], 400);
        return;
    }

    // Fetch ticket details
    $stmt = $pdo->prepare("
        SELECT t.room_id, t.user_id, t.status, r.branch_id
        FROM tickets t
        LEFT JOIN rooms r ON t.room_id = r.id
        WHERE t.id = ? AND t.deleted_at IS NULL
    ");
    $stmt->execute([$id]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ticket) {
        responseJson(['status' => 'error', 'message' => 'Ticket không tồn tại'], 404);
        return;
    }

    // Customer-specific checks
    if ($role === 'customer') {
        if ($ticket['user_id'] != $user_id) {
            responseJson(['status' => 'error', 'message' => 'Không có quyền cập nhật ticket này'], 403);
            return;
        }
        if ($ticket['status'] !== 'open') {
            responseJson(['status' => 'error', 'message' => 'Không thể chuyển trạng thái'], 403);
            return;
        }
        if ($status && !($status === 'closed' || $status === 'open')) {
            responseJson(['status' => 'error', 'message' => 'Không thể chuyển trạng thái'], 403);
            return;
        }
    } else {
        // Validate branch permission for admin/owner/employee
        $branch_id = null;
        if ($room_id) {
            $stmt = $pdo->prepare("SELECT branch_id FROM rooms WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$room_id]);
            $branch_id = $stmt->fetchColumn();
            if (!$branch_id) {
                responseJson(['status' => 'error', 'message' => 'Phòng không tồn tại'], 404);
                return;
            }
        } elseif ($ticket['branch_id']) {
            $branch_id = $ticket['branch_id'];
        }

        if ($branch_id) {
            if ($role === 'owner') {
                $stmt = $pdo->prepare("SELECT id FROM branches WHERE id = ? AND owner_id = ? AND deleted_at IS NULL");
                $stmt->execute([$branch_id, $user_id]);
                if (!$stmt->fetchColumn()) {
                    responseJson(['status' => 'error', 'message' => 'Không có quyền cập nhật ticket này'], 403);
                    return;
                }
            } elseif ($role === 'employee') {
                $stmt = $pdo->prepare("SELECT branch_id FROM employee_assignments WHERE employee_id = ? AND branch_id = ? AND deleted_at IS NULL");
                $stmt->execute([$user_id, $branch_id]);
                if (!$stmt->fetchColumn()) {
                    responseJson(['status' => 'error', 'message' => 'Không có quyền cập nhật ticket này'], 403);
                    return;
                }
            }
            // Admin has full access, no additional check needed
        }
    }

    // Build update query
    $updateFields = [];
    $params = [];

    if ($status) {
        $updateFields[] = "status = ?";
        $params[] = $status;
        if ($status === 'resolved' || $status === 'closed') {
            $updateFields[] = "resolved_at = NOW()";
        }
    }

    if ($priority) {
        $updateFields[] = "priority = ?";
        $params[] = $priority;
    }

    if ($room_id) {
        $updateFields[] = "room_id = ?";
        $params[] = $room_id;
    }

    $updateFields[] = "updated_at = NOW()";
    $params[] = $id;

    try {
        $query = "UPDATE tickets SET " . implode(", ", $updateFields) . " WHERE id = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);

        responseJson(['status' => 'success', 'message' => 'Cập nhật ticket thành công']);
    } catch (PDOException $e) {
        logError("Lỗi cập nhật ticket: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Delete Ticket (Admin only)
function deleteTicket($id) {
    $pdo = getDB();
    $user = verifyJWT();
    $role = $user['role'];

    if ($role !== 'admin') {
        responseJson(['status' => 'error', 'message' => 'Chỉ admin mới có thể xóa ticket'], 403);
        return;
    }

    try {
        $stmt = $pdo->prepare("UPDATE tickets SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$id]);

        if ($stmt->rowCount() === 0) {
            responseJson(['status' => 'error', 'message' => 'Ticket không tồn tại'], 404);
            return;
        }

        responseJson(['status' => 'success', 'message' => 'Xóa ticket thành công']);
    } catch (PDOException $e) {
        logError("Lỗi xóa ticket: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}
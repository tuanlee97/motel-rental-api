<?php
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';

// Customer: Create Ticket
function createTicket($userId) {
    $pdo = getDB();
    $user = verifyJWT();
    $current_user_id = $user['user_id'];
    $role = $user['role'];

    if ($role !== 'customer' || $current_user_id !== (int)$userId) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền truy cập'], 403);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!isset($input['subject']) || !isset($input['description']) || !isset($input['room_id'])) {
        responseJson(['status' => 'error', 'message' => 'Thiếu thông tin tiêu đề, mô tả hoặc phòng'], 400);
        return;
    }

    $subject = trim($input['subject']);
    $description = trim($input['description']);
    $room_id = (int)$input['room_id'];
    $contract_id = isset($input['contract_id']) ? (int)$input['contract_id'] : null;
    $priority = isset($input['priority']) && in_array($input['priority'], ['low', 'medium', 'high']) ? $input['priority'] : 'medium';

    if (empty($subject) || empty($description)) {
        responseJson(['status' => 'error', 'message' => 'Tiêu đề và mô tả không được để trống'], 400);
        return;
    }

    try {
        // Kiểm tra hợp đồng hoạt động
        $stmt = $pdo->prepare("
            SELECT c.id 
            FROM contracts c 
            WHERE c.user_id = ? AND c.room_id = ? AND c.status = 'active' AND c.deleted_at IS NULL
        ");
        $stmt->execute([$userId, $room_id]);
        $contract = $stmt->fetch();
        if (!$contract) {
            responseJson(['status' => 'error', 'message' => 'Không có hợp đồng hoạt động với phòng này'], 403);
            return;
        }
        $contract_id = $contract_id ?: $contract['id'];

        // Tạo ticket
        $query = "
            INSERT INTO tickets (user_id, room_id, contract_id, subject, description, priority, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'open', NOW())
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$userId, $room_id, $contract_id, $subject, $description, $priority]);

        $ticket_id = $pdo->lastInsertId();

        // Lấy thông tin ticket vừa tạo
        $stmt = $pdo->prepare("
            SELECT 
                t.id, t.subject, t.description, t.priority, t.status, t.created_at,
                r.name AS room_name, u.username AS user_name
            FROM tickets t
            JOIN rooms r ON t.room_id = r.id
            JOIN users u ON t.user_id = u.id
            WHERE t.id = ? AND t.deleted_at IS NULL
        ");
        $stmt->execute([$ticket_id]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

        responseJson([
            'status' => 'success',
            'data' => $ticket,
            'message' => 'Ticket đã được tạo thành công'
        ], 201);
    } catch (PDOException $e) {
        error_log("Lỗi tạo ticket: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Customer: Get Tickets
function getCustomerTickets($userId) {
    $pdo = getDB();
    $user = verifyJWT();
    $current_user_id = $user['user_id'];
    $role = $user['role'];

    if ($role !== 'customer' || $current_user_id !== (int)$userId) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền truy cập'], 403);
        return;
    }

    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $status = isset($_GET['status']) && in_array($_GET['status'], ['open', 'in_progress', 'resolved', 'closed']) ? $_GET['status'] : null;
    $priority = isset($_GET['priority']) && in_array($_GET['priority'], ['low', 'medium', 'high']) ? $_GET['priority'] : null;

    $conditions = ['t.user_id = ? AND t.deleted_at IS NULL'];
    $params = [$userId];

    if ($status) {
        $conditions[] = 't.status = ?';
        $params[] = $status;
    }
    if ($priority) {
        $conditions[] = 't.priority = ?';
        $params[] = $priority;
    }

    $where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $query = "
        SELECT 
            t.id, t.subject, t.description, t.priority, t.status, t.created_at,
            r.name AS room_name
        FROM tickets t
        JOIN rooms r ON t.room_id = r.id
        $where_clause
        ORDER BY t.created_at DESC
        LIMIT $limit OFFSET $offset
    ";

    try {
        // Đếm tổng số bản ghi
        $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets t $where_clause");
        $count_stmt->execute($params);
        $total_records = $count_stmt->fetchColumn();
        $total_pages = ceil($total_records / $limit);

        // Lấy danh sách tickets
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        responseJson([
            'status' => 'success',
            'data' => $tickets,
            'pagination' => [
                'current_page' => $page,
                'limit' => $limit,
                'total_records' => $total_records,
                'total_pages' => $total_pages,
            ],
        ]);
    } catch (PDOException $e) {
        error_log("Lỗi lấy tickets: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Admin/Owner/Employee: Get All Tickets
function getAllTickets() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if (!in_array($role, ['admin', 'owner', 'employee'])) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền truy cập'], 403);
        return;
    }

    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $status = isset($_GET['status']) && in_array($_GET['status'], ['open', 'in_progress', 'resolved', 'closed']) ? $_GET['status'] : null;
    $priority = isset($_GET['priority']) && in_array($_GET['priority'], ['low', 'medium', 'high']) ? $_GET['priority'] : null;
    $branch_id = isset($_GET['branch_id']) && is_numeric($_GET['branch_id']) ? (int)$_GET['branch_id'] : null;

    $conditions = ['t.deleted_at IS NULL'];
    $params = [];

    if ($role === 'owner') {
        $conditions[] = 'r.branch_id IN (SELECT id FROM branches WHERE owner_id = ? AND deleted_at IS NULL)';
        $params[] = $user_id;
    } elseif ($role === 'employee') {
        $conditions[] = 'r.branch_id IN (SELECT branch_id FROM employee_assignments WHERE employee_id = ?)';
        $params[] = $user_id;
    }

    if ($branch_id && ($role === 'admin' || ($role === 'owner' && verifyBranchOwnership($pdo, $user_id, $branch_id)) || ($role === 'employee' && verifyEmployeeAssignment($pdo, $user_id, $branch_id)))) {
        $conditions[] = 'r.branch_id = ?';
        $params[] = $branch_id;
    }

    if ($status) {
        $conditions[] = 't.status = ?';
        $params[] = $status;
    }
    if ($priority) {
        $conditions[] = 't.priority = ?';
        $params[] = $priority;
    }

    $where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $query = "
        SELECT 
            t.id, t.subject, t.description, t.priority, t.status, t.created_at,
            r.name AS room_name, u.username AS user_name, b.name AS branch_name
        FROM tickets t
        JOIN rooms r ON t.room_id = r.id
        JOIN users u ON t.user_id = u.id
        JOIN branches b ON r.branch_id = b.id
        $where_clause
        ORDER BY t.created_at DESC
        LIMIT $limit OFFSET $offset
    ";

    try {
        // Thống kê trạng thái
        $stats_stmt = $pdo->prepare("
            SELECT 
                COUNT(*) AS total_tickets,
                SUM(CASE WHEN t.status = 'open' THEN 1 ELSE 0 END) AS open_tickets,
                SUM(CASE WHEN t.status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_tickets,
                SUM(CASE WHEN t.status = 'resolved' THEN 1 ELSE 0 END) AS resolved_tickets,
                SUM(CASE WHEN t.status = 'closed' THEN 1 ELSE 0 END) AS closed_tickets
            FROM tickets t
            JOIN rooms r ON t.room_id = r.id
            $where_clause
        ");
        $stats_stmt->execute($params);
        $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

        // Đếm tổng số bản ghi
        $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets t JOIN rooms r ON t.room_id = r.id $where_clause");
        $count_stmt->execute($params);
        $total_records = $count_stmt->fetchColumn();
        $total_pages = ceil($total_records / $limit);

        // Lấy danh sách tickets
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        responseJson([
            'status' => 'success',
            'data' => [
                'statistics' => $stats,
                'tickets' => $tickets,
            ],
            'pagination' => [
                'current_page' => $page,
                'limit' => $limit,
                'total_records' => $total_records,
                'total_pages' => $total_pages,
            ],
        ]);
    } catch (PDOException $e) {
        error_log("Lỗi lấy danh sách tickets: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Admin/Owner/Employee: Update Ticket
function updateTicket($ticketId) {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if (!in_array($role, ['admin', 'owner', 'employee'])) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền truy cập'], 403);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $status = isset($input['status']) && in_array($input['status'], ['open', 'in_progress', 'resolved', 'closed']) ? $input['status'] : null;
    $priority = isset($input['priority']) && in_array($input['priority'], ['low', 'medium', 'high']) ? $input['priority'] : null;

    if (!$status && !$priority) {
        responseJson(['status' => 'error', 'message' => 'Cần cung cấp trạng thái hoặc mức độ ưu tiên'], 400);
        return;
    }

    try {
        // Kiểm tra quyền truy cập ticket
        $query = "
            SELECT t.id 
            FROM tickets t
            JOIN rooms r ON t.room_id = r.id
            WHERE t.id = ? AND t.deleted_at IS NULL
        ";
        $params = [$ticketId];

        if ($role === 'owner') {
            $query .= " AND r.branch_id IN (SELECT id FROM branches WHERE owner_id = ? AND deleted_at IS NULL)";
            $params[] = $user_id;
        } elseif ($role === 'employee') {
            $query .= " AND r.branch_id IN (SELECT branch_id FROM employee_assignments WHERE employee_id = ?)";
            $params[] = $user_id;
        }

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Ticket không tồn tại hoặc không có quyền truy cập'], 404);
            return;
        }

        // Cập nhật ticket
        $update_conditions = [];
        $update_params = [];

        if ($status) {
            $update_conditions[] = 'status = ?';
            $update_params[] = $status;
            if ($status === 'resolved') {
                $update_conditions[] = 'resolved_at = NOW()';
            } elseif ($status !== 'resolved') {
                $update_conditions[] = 'resolved_at = NULL';
            }
        }
        if ($priority) {
            $update_conditions[] = 'priority = ?';
            $update_params[] = $priority;
        }

        $update_conditions[] = 'updated_at = NOW()';
        $update_params[] = $ticketId;

        $update_query = "UPDATE tickets SET " . implode(', ', $update_conditions) . " WHERE id = ?";
        $stmt = $pdo->prepare($update_query);
        $stmt->execute($update_params);

        // Lấy ticket đã cập nhật
        $stmt = $pdo->prepare("
            SELECT 
                t.id, t.subject, t.description, t.priority, t.status, t.created_at, t.resolved_at,
                r.name AS room_name, u.username AS user_name
            FROM tickets t
            JOIN rooms r ON t.room_id = r.id
            JOIN users u ON t.user_id = u.id
            WHERE t.id = ? AND t.deleted_at IS NULL
        ");
        $stmt->execute([$ticketId]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

        responseJson([
            'status' => 'success',
            'data' => $ticket,
            'message' => 'Ticket đã được cập nhật'
        ]);
    } catch (PDOException $e) {
        error_log("Lỗi cập nhật ticket: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Admin: Delete Ticket
function deleteTicket($ticketId) {
    $pdo = getDB();
    $user = verifyJWT();
    $role = $user['role'];

    if ($role !== 'admin') {
        responseJson(['status' => 'error', 'message' => 'Chỉ admin được phép xóa ticket'], 403);
        return;
    }

    try {
        $stmt = $pdo->prepare("UPDATE tickets SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$ticketId]);

        if ($stmt->rowCount() === 0) {
            responseJson(['status' => 'error', 'message' => 'Ticket không tồn tại'], 404);
            return;
        }

        responseJson([
            'status' => 'success',
            'message' => 'Ticket đã được xóa'
        ]);
    } catch (PDOException $e) {
        error_log("Lỗi xóa ticket: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Admin/Owner/Employee: Update Maintenance Request
function updateMaintenanceRequest($requestId) {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if (!in_array($role, ['admin', 'owner', 'employee'])) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền truy cập'], 403);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!isset($input['status']) || !in_array($input['status'], ['pending', 'in_progress', 'completed'])) {
        responseJson(['status' => 'error', 'message' => 'Trạng thái không hợp lệ'], 400);
        return;
    }

    $status = $input['status'];

    try {
        // Kiểm tra quyền truy cập yêu cầu bảo trì
        $query = "
            SELECT mr.id 
            FROM maintenance_requests mr
            JOIN rooms r ON mr.room_id = r.id
            WHERE mr.id = ? AND mr.deleted_at IS NULL
        ";
        $params = [$requestId];

        if ($role === 'owner') {
            $query .= " AND r.branch_id IN (SELECT id FROM branches WHERE owner_id = ? AND deleted_at IS NULL)";
            $params[] = $user_id;
        } elseif ($role === 'employee') {
            $query .= " AND r.branch_id IN (SELECT branch_id FROM employee_assignments WHERE employee_id = ?)";
            $params[] = $user_id;
        }

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Yêu cầu bảo trì không tồn tại hoặc không có quyền truy cập'], 404);
            return;
        }

        // Cập nhật yêu cầu bảo trì
        $stmt = $pdo->prepare("
            UPDATE maintenance_requests 
            SET status = ?, updated_at = NOW()
            WHERE id = ? AND deleted_at IS NULL
        ");
        $stmt->execute([$status, $requestId]);

        // Lấy yêu cầu đã cập nhật
        $stmt = $pdo->prepare("
            SELECT 
                mr.id, mr.description, mr.status, mr.created_at, mr.updated_at,
                r.name AS room_name, u.username AS created_by
            FROM maintenance_requests mr
            JOIN rooms r ON mr.room_id = r.id
            JOIN users u ON mr.created_by = u.id
            WHERE mr.id = ? AND mr.deleted_at IS NULL
        ");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        responseJson([
            'status' => 'success',
            'data' => $request,
            'message' => 'Yêu cầu bảo trì đã được cập nhật'
        ]);
    } catch (PDOException $e) {
        error_log("Lỗi cập nhật yêu cầu bảo trì: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Admin: Delete Maintenance Request
function deleteMaintenanceRequest($requestId) {
    $pdo = getDB();
    $user = verifyJWT();
    $role = $user['role'];

    if ($role !== 'admin') {
        responseJson(['status' => 'error', 'message' => 'Chỉ admin được phép xóa yêu cầu bảo trì'], 403);
        return;
    }

    try {
        $stmt = $pdo->prepare("UPDATE maintenance_requests SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$requestId]);

        if ($stmt->rowCount() === 0) {
            responseJson(['status' => 'error', 'message' => 'Yêu cầu bảo trì không tồn tại'], 404);
            return;
        }

        responseJson([
            'status' => 'success',
            'message' => 'Yêu cầu bảo trì đã được xóa'
        ]);
    } catch (PDOException $e) {
        error_log("Lỗi xóa yêu cầu bảo trì: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Hàm hỗ trợ: Kiểm tra quyền sở hữu chi nhánh
function verifyBranchOwnership($pdo, $userId, $branchId) {
    $stmt = $pdo->prepare("SELECT id FROM branches WHERE id = ? AND owner_id = ? AND deleted_at IS NULL");
    $stmt->execute([$branchId, $userId]);
    return $stmt->fetch() !== false;
}

// Hàm hỗ trợ: Kiểm tra phân công nhân viên
function verifyEmployeeAssignment($pdo, $userId, $branchId) {
    $stmt = $pdo->prepare("SELECT branch_id FROM employee_assignments WHERE employee_id = ? AND branch_id = ?");
    $stmt->execute([$userId, $branchId]);
    return $stmt->fetch() !== false;
}
?>
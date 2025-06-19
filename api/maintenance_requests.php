<?php
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/utils/common.php';

// Customer: Create Maintenance Request
function createMaintenanceRequest() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    // Ensure the user is a customer
    if ($role !== 'customer') {
        responseJson(['status' => 'error', 'message' => 'Chỉ khách hàng mới có thể tạo yêu cầu bảo trì'], 403);
        return;
    }

    // Parse request body
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        responseJson(['status' => 'error', 'message' => 'Dữ liệu yêu cầu không hợp lệ'], 400);
        return;
    }

    // Extract and validate required fields
    $description = isset($data['description']) ? trim(sanitizeInput($data['description'])) : null;
    $room_id = isset($data['room_id']) ? (int)$data['room_id'] : null;
    $status = 'pending'; // Force status to 'pending' for new requests

    if (!$description || !$room_id) {
        responseJson(['status' => 'error', 'message' => 'Thiếu mô tả hoặc ID phòng'], 400);
        return;
    }

    // Verify the customer is renting the specified room (via active contract)
    try {
        $stmt = $pdo->prepare("
            SELECT id FROM contracts 
            WHERE user_id = ? AND room_id = ? AND status = 'active' AND deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([$user_id, $room_id]);
        $contract = $stmt->fetchColumn();

        if (!$contract) {
            responseJson(['status' => 'error', 'message' => 'Bạn không có quyền tạo yêu cầu bảo trì cho phòng này'], 403);
            return;
        }

        // Insert maintenance request into the database
        $stmt = $pdo->prepare("
            INSERT INTO maintenance_requests (room_id, description, status, created_by, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$room_id, $description, $status, $user_id]);

        $request_id = $pdo->lastInsertId();

        // Fetch the created maintenance request to return
        $stmt = $pdo->prepare("
            SELECT mr.id, mr.room_id, mr.description, mr.status, mr.created_by, mr.created_at, 
                   r.name AS room_name, b.name AS branch_name, b.id AS branch_id, u.name AS customer_name
            FROM maintenance_requests mr
            JOIN rooms r ON mr.room_id = r.id
            JOIN branches b ON r.branch_id = b.id
            JOIN users u ON mr.created_by = u.id
            WHERE mr.id = ?
        ");
        $stmt->execute([$request_id]);
        $maintenance_request = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$maintenance_request) {
            responseJson(['status' => 'error', 'message' => 'Không thể tìm thấy yêu cầu vừa tạo'], 500);
            return;
        }

        responseJson([
            'status' => 'success',
            'data' => $maintenance_request
        ], 201);
    } catch (PDOException $e) {
        error_log("Lỗi tạo yêu cầu bảo trì: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Get Maintenance Requests for a Customer
function getCustomerMaintenanceRequests($userId) {
    $pdo = getDB();
    $user = verifyJWT();
    $current_user_id = $user['user_id'];
    $role = $user['role'];

    // Ensure the user is a customer and accessing their own requests
    if ($role !== 'customer' || $current_user_id != $userId) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền truy cập'], 403);
        return;
    }

    // Pagination
    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $conditions = ["mr.created_by = ?", "mr.deleted_at IS NULL"];
    $params = [$current_user_id];

    // Add search condition
    if (!empty($_GET['search'])) {
        $search = '%' . sanitizeInput($_GET['search']) . '%';
        $conditions[] = "(mr.description LIKE ? OR r.name LIKE ?)";
        $params[] = $search;
        $params[] = $search;
    }

    // Add status filter
    if (!empty($_GET['status'])) {
        $conditions[] = "mr.status = ?";
        $params[] = sanitizeInput($_GET['status']);
    }

    $whereClause = "WHERE " . implode(" AND ", $conditions);

    $query = "
        SELECT 
            mr.id, mr.room_id, mr.description, mr.status, mr.created_by, mr.created_at,
            r.name AS room_name, b.name AS branch_name, b.id AS branch_id, u.name AS customer_name
        FROM maintenance_requests mr
        JOIN rooms r ON mr.room_id = r.id
        JOIN branches b ON r.branch_id = b.id
        JOIN users u ON mr.created_by = u.id
        $whereClause
        ORDER BY mr.created_at DESC
        LIMIT $limit OFFSET $offset
    ";

    try {
        // Count total records
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM maintenance_requests mr JOIN rooms r ON mr.room_id = r.id JOIN branches b ON r.branch_id = b.id $whereClause");
        $countStmt->execute($params);
        $totalRecords = $countStmt->fetchColumn();
        $totalPages = ceil($totalRecords / $limit);

        // Fetch maintenance requests
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch statistics
        $statsQuery = "
            SELECT 
                COUNT(*) AS total,
                SUM(CASE WHEN mr.status = 'pending' THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN mr.status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress,
                SUM(CASE WHEN mr.status = 'completed' THEN 1 ELSE 0 END) AS completed
            FROM maintenance_requests mr
            JOIN rooms r ON mr.room_id = r.id
            JOIN branches b ON r.branch_id = b.id
            $whereClause
        ";
        $statsStmt = $pdo->prepare($statsQuery);
        $statsStmt->execute($params);
        $statistics = $statsStmt->fetch(PDO::FETCH_ASSOC);

        responseJson([
            'status' => 'success',
            'data' => [
                'requests' => $requests,
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
        error_log("Lỗi lấy danh sách yêu cầu bảo trì: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Get All Maintenance Requests (Admin/Owner/Employee)
function getAllMaintenanceRequests() {
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
    $conditions = ['mr.deleted_at IS NULL', 'u.deleted_at IS NULL'];
    $params = [];

    // Role-based access control
    if ($role === 'admin') {
        // Admin can filter by owner_id (userId) or branch_id
        if (!empty($_GET['userId']) && is_numeric($_GET['userId'])) {
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
        responseJson(['status' => 'error', 'message' => 'Không có quyền truy cập danh sách yêu cầu bảo trì'], 403);
        return;
    }

    // Add search condition
    if (!empty($_GET['search'])) {
        $search = '%' . sanitizeInput($_GET['search']) . '%';
        $conditions[] = "(mr.description LIKE ? OR r.name LIKE ? OR u.name LIKE ?)";
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
    }

    // Add status filter
    if (!empty($_GET['status'])) {
        $conditions[] = "mr.status = ?";
        $params[] = sanitizeInput($_GET['status']);
    }

    $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

    $baseJoin = "
        FROM maintenance_requests mr
        JOIN rooms r ON mr.room_id = r.id
        JOIN branches b ON r.branch_id = b.id
        JOIN users u ON mr.created_by = u.id
    ";

    $query = "
        SELECT 
            mr.id, mr.room_id, mr.description, mr.status, mr.created_by, mr.created_at,
            r.name AS room_name, b.name AS branch_name, b.id AS branch_id, u.name AS customer_name
        $baseJoin
        $whereClause
        ORDER BY mr.created_at DESC
        LIMIT $limit OFFSET $offset
    ";

    try {
        // Count total records
        $countStmt = $pdo->prepare("SELECT COUNT(*) $baseJoin $whereClause");
        $countStmt->execute($params);
        $totalRecords = $countStmt->fetchColumn();
        $totalPages = ceil($totalRecords / $limit);

        // Fetch maintenance requests
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch statistics
        $statsQuery = "
            SELECT 
                COUNT(*) AS total,
                SUM(CASE WHEN mr.status = 'pending' THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN mr.status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress,
                SUM(CASE WHEN mr.status = 'completed' THEN 1 ELSE 0 END) AS completed
        $baseJoin
        $whereClause
        ";
        $statsStmt = $pdo->prepare($statsQuery);
        $statsStmt->execute($params);
        $statistics = $statsStmt->fetch(PDO::FETCH_ASSOC);

        responseJson([
            'status' => 'success',
            'data' => [
                'requests' => $requests,
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
        error_log("Lỗi lấy danh sách yêu cầu bảo trì: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Update Maintenance Request (Admin/Owner/Employee/Customer)
function updateMaintenanceRequest($id) {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    // Parse request body
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data || (!isset($data['status']) && !isset($data['room_id']))) {
        responseJson(['status' => 'error', 'message' => 'Thiếu trạng thái hoặc phòng để cập nhật'], 400);
        return;
    }

    $status = isset($data['status']) ? trim(sanitizeInput($data['status'])) : null;
    $room_id = isset($data['room_id']) ? (int)$data['room_id'] : null;

    // Validate status
    $valid_statuses = ['pending', 'in_progress', 'completed'];
    if ($status && !in_array($status, $valid_statuses)) {
        responseJson(['status' => 'error', 'message' => 'Trạng thái không hợp lệ'], 400);
        return;
    }

    // Fetch maintenance request details
    $stmt = $pdo->prepare("
        SELECT mr.room_id, mr.created_by, mr.status, r.branch_id
        FROM maintenance_requests mr
        JOIN rooms r ON mr.room_id = r.id
        WHERE mr.id = ? AND mr.deleted_at IS NULL
    ");
    $stmt->execute([$id]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        responseJson(['status' => 'error', 'message' => 'Yêu cầu bảo trì không tồn tại'], 404);
        return;
    }

    // Customer-specific checks
    if ($role === 'customer') {
        if ($request['created_by'] != $user_id) {
            responseJson(['status' => 'error', 'message' => 'Không có quyền cập nhật yêu cầu này'], 403);
            return;
        }
        if ($request['status'] !== 'pending') {
            responseJson(['status' => 'error', 'message' => 'Chỉ có thể cập nhật yêu cầu khi trạng thái là pending'], 403);
            return;
        }
        if ($status && $status !== 'completed') {
            responseJson(['status' => 'error', 'message' => 'Khách hàng chỉ có thể chuyển trạng thái sang completed'], 403);
            return;
        }
        // Customers cannot change room_id
        if ($room_id) {
            responseJson(['status' => 'error', 'message' => 'Khách hàng không thể thay đổi phòng'], 403);
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
        } elseif ($request['branch_id']) {
            $branch_id = $request['branch_id'];
        }

        if ($branch_id) {
            if ($role === 'owner') {
                $stmt = $pdo->prepare("SELECT id FROM branches WHERE id = ? AND owner_id = ? AND deleted_at IS NULL");
                $stmt->execute([$branch_id, $user_id]);
                if (!$stmt->fetchColumn()) {
                    responseJson(['status' => 'error', 'message' => 'Không có quyền cập nhật yêu cầu này'], 403);
                    return;
                }
            } elseif ($role === 'employee') {
                $stmt = $pdo->prepare("SELECT branch_id FROM employee_assignments WHERE employee_id = ? AND branch_id = ? AND deleted_at IS NULL");
                $stmt->execute([$user_id, $branch_id]);
                if (!$stmt->fetchColumn()) {
                    responseJson(['status' => 'error', 'message' => 'Không có quyền cập nhật yêu cầu này'], 403);
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
    }

    if ($room_id && $role !== 'customer') {
        $updateFields[] = "room_id = ?";
        $params[] = $room_id;
    }

    $updateFields[] = "updated_at = NOW()";
    $params[] = $id;

    try {
        $query = "UPDATE maintenance_requests SET " . implode(", ", $updateFields) . " WHERE id = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);

        responseJson(['status' => 'success', 'message' => 'Cập nhật yêu cầu bảo trì thành công']);
    } catch (PDOException $e) {
        error_log("Lỗi cập nhật yêu cầu bảo trì: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Delete Maintenance Request (Admin only)
function deleteMaintenanceRequest($id) {
    $pdo = getDB();
    $user = verifyJWT();
    $role = $user['role'];

    if ($role !== 'admin') {
        responseJson(['status' => 'error', 'message' => 'Chỉ admin mới có thể xóa yêu cầu bảo trì'], 403);
        return;
    }

    try {
        $stmt = $pdo->prepare("UPDATE maintenance_requests SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$id]);

        if ($stmt->rowCount() === 0) {
            responseJson(['status' => 'error', 'message' => 'Yêu cầu bảo trì không tồn tại'], 404);
            return;
        }

        responseJson(['status' => 'success', 'message' => 'Xóa yêu cầu bảo trì thành công']);
    } catch (PDOException $e) {
        error_log("Lỗi xóa yêu cầu bảo trì: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}
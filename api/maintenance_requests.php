```php
<?php
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';

// Customer: Create Maintenance Request
function createMaintenanceRequest($userId) {
    $pdo = getDB();
    $user = verifyJWT();
    $current_user_id = $user['user_id'];
    $role = $user['role'];

    if ($role !== 'customer' || $current_user_id !== (int)$userId) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền truy cập'], 403);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!isset($input['room_id']) || !isset($input['description'])) {
        responseJson(['status' => 'error', 'message' => 'Thiếu thông tin phòng hoặc mô tả'], 400);
        return;
    }

    $room_id = (int)$input['room_id'];
    $description = trim($input['description']);

    if (empty($description)) {
        responseJson(['status' => 'error', 'message' => 'Mô tả không được để trống'], 400);
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
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Không có hợp đồng hoạt động với phòng này'], 403);
            return;
        }

        // Tạo yêu cầu bảo trì
        $query = "
            INSERT INTO maintenance_requests (room_id, description, status, created_by, created_at)
            VALUES (?, ?, 'pending', ?, NOW())
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$room_id, $description, $userId]);

        $request_id = $pdo->lastInsertId();

        // Lấy thông tin yêu cầu vừa tạo
        $stmt = $pdo->prepare("
            SELECT 
                mr.id, mr.description, mr.status, mr.created_at,
                r.name AS room_name, u.username AS created_by
            FROM maintenance_requests mr
            JOIN rooms r ON mr.room_id = r.id
            JOIN users u ON mr.created_by = u.id
            WHERE mr.id = ? AND mr.deleted_at IS NULL
        ");
        $stmt->execute([$request_id]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        responseJson([
            'status' => 'success',
            'data' => $request,
            'message' => 'Yêu cầu bảo trì đã được tạo thành công'
        ], 201);
    } catch (PDOException $e) {
        error_log("Lỗi tạo yêu cầu bảo trì: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Customer: Get Maintenance Requests
function getCustomerMaintenanceRequests($userId) {
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

    $status = isset($_GET['status']) && in_array($_GET['status'], ['pending', 'in_progress', 'completed']) ? $_GET['status'] : null;

    $conditions = ['mr.created_by = ? AND mr.deleted_at IS NULL'];
    $params = [$userId];

    if ($status) {
        $conditions[] = 'mr.status = ?';
        $params[] = $status;
    }

    $where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $query = "
        SELECT 
            mr.id, mr.description, mr.status, mr.created_at,
            r.name AS room_name
        FROM maintenance_requests mr
        JOIN rooms r ON mr.room_id = r.id
        $where_clause
        ORDER BY mr.created_at DESC
        LIMIT $limit OFFSET $offset
    ";

    try {
        // Đếm tổng số bản ghi
        $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM maintenance_requests mr $where_clause");
        $count_stmt->execute($params);
        $total_records = $count_stmt->fetchColumn();
        $total_pages = ceil($total_records / $limit);

        // Lấy danh sách yêu cầu bảo trì
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

        responseJson([
            'status' => 'success',
            'data' => $requests,
            'pagination' => [
                'current_page' => $page,
                'limit' => $limit,
                'total_records' => $total_records,
                'total_pages' => $total_pages,
            ],
        ]);
    } catch (PDOException $e) {
        error_log("Lỗi lấy yêu cầu bảo trì: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Admin/Owner/Employee: Get All Maintenance Requests
function getAllMaintenanceRequests() {
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

    $status = isset($_GET['status']) && in_array($_GET['status'], ['pending', 'in_progress', 'completed']) ? $_GET['status'] : null;
    $branch_id = isset($_GET['branch_id']) && is_numeric($_GET['branch_id']) ? (int)$_GET['branch_id'] : null;

    $conditions = ['mr.deleted_at IS NULL'];
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
        $conditions[] = 'mr.status = ?';
        $params[] = $status;
    }

    $where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $query = "
        SELECT 
            mr.id, mr.description, mr.status, mr.created_at,
            r.name AS room_name, u.username AS created_by, b.name AS branch_name
        FROM maintenance_requests mr
        JOIN rooms r ON mr.room_id = r.id
        JOIN users u ON mr.created_by = u.id
        JOIN branches b ON r.branch_id = b.id
        $where_clause
        ORDER BY mr.created_at DESC
        LIMIT $limit OFFSET $offset
    ";

    try {
        // Thống kê trạng thái
        $stats_stmt = $pdo->prepare("
            SELECT 
                COUNT(*) AS total_requests,
                SUM(CASE WHEN mr.status = 'pending' THEN 1 ELSE 0 END) AS pending_requests,
                SUM(CASE WHEN mr.status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_requests,
                SUM(CASE WHEN mr.status = 'completed' THEN 1 ELSE 0 END) AS completed_requests
            FROM maintenance_requests mr
            JOIN rooms r ON mr.room_id = r.id
            $where_clause
        ");
        $stats_stmt->execute($params);
        $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

        // Đếm tổng số bản ghi
        $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM maintenance_requests mr JOIN rooms r ON mr.room_id = r.id $where_clause");
        $count_stmt->execute($params);
        $total_records = $count_stmt->fetchColumn();
        $total_pages = ceil($total_records / $limit);

        // Lấy danh sách yêu cầu bảo trì
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

        responseJson([
            'status' => 'success',
            'data' => [
                'statistics' => $stats,
                'requests' => $requests,
            ],
            'pagination' => [
                'current_page' => $page,
                'limit' => $limit,
                'total_records' => $total_records,
                'total_pages' => $total_pages,
            ],
        ]);
    } catch (PDOException $e) {
        error_log("Lỗi lấy danh sách yêu cầu bảo trì: " . $e->getMessage());
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
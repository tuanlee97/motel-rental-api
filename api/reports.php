<?php
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/utils/common.php';

// Admin: Revenue Report for All Branches
function getAllBranchesRevenueReport() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if ($role !== 'admin') {
        responseJson(['status' => 'error', 'message' => 'Chỉ admin được phép truy cập báo cáo'], 403);
        return;
    }

    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : null;
    $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : null;
    $month = isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['month']) ? $_GET['month'] : null;
    $branch_id = isset($_GET['branch_id']) && is_numeric($_GET['branch_id']) ? (int)$_GET['branch_id'] : null;
    $owner_id = isset($_GET['owner_id']) && is_numeric($_GET['owner_id']) ? (int)$_GET['owner_id'] : null;

    if ($month && ($start_date || $end_date)) {
        responseJson(['status' => 'error', 'message' => 'Không thể sử dụng month cùng với start_date hoặc end_date'], 400);
        return;
    }

    $conditions = ['i.deleted_at IS NULL'];
    $params = [];

    if ($month) {
        $conditions[] = "DATE_FORMAT(i.created_at, '%Y-%m') = ?";
        $params[] = $month;
    } else {
        if ($start_date && DateTime::createFromFormat('Y-m-d', $start_date)) {
            $conditions[] = 'i.created_at >= ?';
            $params[] = $start_date;
        }
        if ($end_date && DateTime::createFromFormat('Y-m-d', $end_date)) {
            $conditions[] = 'i.created_at <= ?';
            $params[] = $end_date;
        }
    }

    if ($branch_id) {
        $conditions[] = 'i.branch_id = ?';
        $params[] = $branch_id;
    }

    if ($owner_id) {
        $conditions[] = 'b.owner_id = ?';
        $params[] = $owner_id;
    }

    $where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $invoice_query = "
        SELECT 
            i.id, i.contract_id, i.amount AS amount, i.due_date, i.status, i.created_at,
            c.user_id, u.username AS user_name, u.name AS customer_name, r.name AS room_name, b.name AS branch_name
        FROM invoices i
        JOIN contracts c ON i.contract_id = c.id
        JOIN users u ON c.user_id = u.id
        JOIN rooms r ON c.room_id = r.id
        JOIN branches b ON i.branch_id = b.id
        $where_clause
        ORDER BY i.created_at DESC
        LIMIT $limit OFFSET $offset
    ";

    $monthly_query = "
        SELECT 
            DATE_FORMAT(i.created_at, '%Y-%m') AS name,
           SUM(i.amount) AS total
        FROM invoices i
        JOIN branches b ON i.branch_id = b.id
        $where_clause
        GROUP BY DATE_FORMAT(i.created_at, '%Y-%m')
        ORDER BY name ASC
    ";

    try {
        // Tổng doanh thu
        $total_stmt = $pdo->prepare("SELECT SUM(i.amount) AS total_revenue 
            FROM invoices i 
            JOIN branches b ON i.branch_id = b.id 
            $where_clause");
        $total_stmt->execute($params);
        $total_revenue = (float)$total_stmt->fetchColumn() ?? 0;

        // Đếm tổng số hóa đơn
        $count_stmt = $pdo->prepare("SELECT COUNT(*) 
            FROM invoices i 
            JOIN branches b ON i.branch_id = b.id 
            $where_clause");
        $count_stmt->execute($params);
        $total_records = $count_stmt->fetchColumn();
        $total_pages = ceil($total_records / $limit);

        // Lấy danh sách hóa đơn
        $stmt = $pdo->prepare($invoice_query);
        $stmt->execute($params);
        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Lấy dữ liệu doanh thu theo tháng
        $monthly_stmt = $pdo->prepare($monthly_query);
        $monthly_stmt->execute($params);
        $monthly_data = $monthly_stmt->fetchAll(PDO::FETCH_ASSOC);

        responseJson([
            'status' => 'success',
            'data' => [
                'total_revenue' => $total_revenue,
                'monthly_revenue' => $monthly_data,
                'invoices' => $invoices,
            ],
            'pagination' => [
                'current_page' => $page,
                'limit' => $limit,
                'total_records' => $total_records,
                'total_pages' => $total_pages,
            ]
        ]);
    } catch (PDOException $e) {
        logError("Lỗi lấy báo cáo doanh thu: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Admin: Room Status Report for All Branches
function getAllBranchesRoomStatusReport() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if ($role !== 'admin') {
        responseJson(['status' => 'error', 'message' => 'Chỉ admin được phép truy cập báo cáo'], 403);
        return;
    }

    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $status = isset($_GET['status']) ? $_GET['status'] : null;
    $branch_id = isset($_GET['branch_id']) && is_numeric($_GET['branch_id']) ? (int)$_GET['branch_id'] : null;
    $owner_id = isset($_GET['owner_id']) && is_numeric($_GET['owner_id']) ? (int)$_GET['owner_id'] : null;

    $conditions = ['r.deleted_at IS NULL'];
    $params = [];

    if ($status && in_array($status, ['available', 'occupied', 'maintenance'])) {
        $conditions[] = 'r.status = ?';
        $params[] = $status;
    }
    if ($branch_id) {
        $conditions[] = 'r.branch_id = ?';
        $params[] = $branch_id;
    }
    if ($owner_id) {
        $conditions[] = 'b.owner_id = ?';
        $params[] = $owner_id;
    }

    $where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $query = "
        SELECT 
            r.id, r.name, r.price, r.status, rt.name AS room_type, b.name AS branch_name,
            (SELECT COUNT(*) FROM contracts c WHERE c.room_id = r.id AND c.status = 'active' AND c.deleted_at IS NULL) AS active_contracts
        FROM rooms r
        JOIN room_types rt ON r.type_id = rt.id
        JOIN branches b ON r.branch_id = b.id
        $where_clause
        ORDER BY r.id DESC
        LIMIT $limit OFFSET $offset
    ";

    try {
        // Thống kê trạng thái phòng
        $stats_conditions = ['r.deleted_at IS NULL'];
        $stats_params = [];
        if ($owner_id) {
            $stats_conditions[] = 'b.owner_id = ?';
            $stats_params[] = $owner_id;
        }
        if ($branch_id) {
            $stats_conditions[] = 'r.branch_id = ?';
            $stats_params[] = $branch_id;
        }
        $stats_where_clause = !empty($stats_conditions) ? 'WHERE ' . implode(' AND ', $stats_conditions) : '';

        $stats_stmt = $pdo->prepare("
            SELECT 
                COUNT(*) AS total_rooms,
                SUM(CASE WHEN r.status = 'available' THEN 1 ELSE 0 END) AS available_rooms,
                SUM(CASE WHEN r.status = 'occupied' THEN 1 ELSE 0 END) AS occupied_rooms,
                SUM(CASE WHEN r.status = 'maintenance' THEN 1 ELSE 0 END) AS maintenance_rooms
            FROM rooms r
            JOIN branches b ON r.branch_id = b.id
            $stats_where_clause
        ");
        $stats_stmt->execute($stats_params);
        $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

        // Đếm tổng số bản ghi
        $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM rooms r JOIN branches b ON r.branch_id = b.id $where_clause");
        $count_stmt->execute($params);
        $total_records = $count_stmt->fetchColumn();
        $total_pages = ceil($total_records / $limit);

        // Lấy danh sách phòng
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

        responseJson([
            'status' => 'success',
            'data' => [
                'statistics' => $stats,
                'rooms' => $rooms,
            ],
            'pagination' => [
                'current_page' => $page,
                'limit' => $limit,
                'total_records' => $total_records,
                'total_pages' => $total_pages,
            ],
        ]);
    } catch (PDOException $e) {
        logError("Lỗi lấy báo cáo tình trạng phòng: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Admin: Contract Report for All Branches
function getAllBranchesContractReport() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if ($role !== 'admin') {
        responseJson(['status' => 'error', 'message' => 'Chỉ admin được phép truy cập báo cáo'], 403);
        return;
    }

    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $status = isset($_GET['status']) ? $_GET['status'] : null;
    $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : null;
    $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : null;
    $month = isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['month']) ? $_GET['month'] : null;
    $branch_id = isset($_GET['branch_id']) && is_numeric($_GET['branch_id']) ? (int)$_GET['branch_id'] : null;
    $owner_id = isset($_GET['owner_id']) && is_numeric($_GET['owner_id']) ? (int)$_GET['owner_id'] : null;

    if ($month && ($start_date || $end_date)) {
        responseJson(['status' => 'error', 'message' => 'Không thể sử dụng month cùng với start_date hoặc end_date'], 400);
        return;
    }

    $conditions = ['c.deleted_at IS NULL'];
    $params = [];

    if ($month) {
        $conditions[] = "DATE_FORMAT(c.created_at, '%Y-%m') = ?";
        $params[] = $month;
    } else {
        if ($status && in_array($status, ['active', 'expired', 'ended', 'cancelled'])) {
            $conditions[] = 'c.status = ?';
            $params[] = $status;
        }
        if ($start_date && DateTime::createFromFormat('Y-m-d', $start_date)) {
            $conditions[] = 'c.start_date >= ?';
            $params[] = $start_date;
        }
        if ($end_date && DateTime::createFromFormat('Y-m-d', $end_date)) {
            $conditions[] = 'c.end_date <= ?';
            $params[] = $end_date;
        }
    }

    if ($branch_id) {
        $conditions[] = 'r.branch_id = ?';
        $params[] = $branch_id;
    }
    if ($owner_id) {
        $conditions[] = 'b.owner_id = ?';
        $params[] = $owner_id;
    }

    $where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $query = "
        SELECT 
            c.id, c.room_id, c.user_id, c.start_date, c.end_date, c.status, c.created_at, c.deposit,
            r.name AS room_name, u.username AS user_name, b.name AS branch_name
        FROM contracts c
        JOIN rooms r ON c.room_id = r.id
        JOIN users u ON c.user_id = u.id
        JOIN branches b ON r.branch_id = b.id
        $where_clause
        ORDER BY c.created_at DESC
        LIMIT $limit OFFSET $offset
    ";

    try {
        // Thống kê trạng thái hợp đồng
        $stats_conditions = ['c.deleted_at IS NULL'];
        $stats_params = [];
        if ($owner_id) {
            $stats_conditions[] = 'b.owner_id = ?';
            $stats_params[] = $owner_id;
        }
        if ($branch_id) {
            $stats_conditions[] = 'r.branch_id = ?';
            $stats_params[] = $branch_id;
        }
        $stats_where_clause = !empty($stats_conditions) ? 'WHERE ' . implode(' AND ', $stats_conditions) : '';

        $stats_stmt = $pdo->prepare("
            SELECT 
                COUNT(*) AS total_contracts,
                SUM(CASE WHEN c.status = 'active' THEN 1 ELSE 0 END) AS active_contracts,
                SUM(CASE WHEN c.status = 'expired' THEN 1 ELSE 0 END) AS expired_contracts,
                SUM(CASE WHEN c.status = 'ended' THEN 1 ELSE 0 END) AS ended_contracts,
                SUM(CASE WHEN c.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_contracts
            FROM contracts c
            JOIN rooms r ON c.room_id = r.id
            JOIN branches b ON r.branch_id = b.id
            $stats_where_clause
        ");
        $stats_stmt->execute($stats_params);
        $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

        // Đếm tổng số bản ghi
        $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM contracts c JOIN rooms r ON c.room_id = r.id JOIN branches b ON r.branch_id = b.id $where_clause");
        $count_stmt->execute($params);
        $total_records = $count_stmt->fetchColumn();
        $total_pages = ceil($total_records / $limit);

        // Lấy danh sách hợp đồng
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        responseJson([
            'status' => 'success',
            'data' => [
                'statistics' => $stats,
                'contracts' => $contracts,
            ],
            'pagination' => [
                'current_page' => $page,
                'limit' => $limit,
                'total_records' => $total_records,
                'total_pages' => $total_pages,
            ],
        ]);
    } catch (PDOException $e) {
        logError("Lỗi lấy báo cáo hợp đồng: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Admin: Utility Usage Report for All Branches
function getAllBranchesUtilityUsageReport() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if ($role !== 'admin') {
        responseJson(['status' => 'error', 'message' => 'Chỉ admin được phép truy cập báo cáo'], 403);
        return;
    }

    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $month = isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['month']) ? $_GET['month'] : null;
    $branch_id = isset($_GET['branch_id']) && is_numeric($_GET['branch_id']) ? (int)$_GET['branch_id'] : null;
    $owner_id = isset($_GET['owner_id']) && is_numeric($_GET['owner_id']) ? (int)$_GET['owner_id'] : null;

    $conditions = ['u.deleted_at IS NULL'];
    $params = [];

    if ($month) {
        $conditions[] = 'u.month = ?';
        $params[] = $month;
    }
    if ($branch_id) {
        $conditions[] = 'r.branch_id = ?';
        $params[] = $branch_id;
    }
    if ($owner_id) {
        $conditions[] = 'b.owner_id = ?';
        $params[] = $owner_id;
    }

    $where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $query = "
        SELECT 
            u.id, u.room_id, u.contract_id, u.service_id, u.month, u.usage_amount, 
            u.old_reading, u.new_reading, u.recorded_at, 
            r.name AS room_name, 
            s.name AS service_name, s.price AS service_price, 
            b.name AS branch_name
        FROM utility_usage u
        JOIN rooms r ON u.room_id = r.id
        JOIN services s ON u.service_id = s.id
        JOIN branches b ON r.branch_id = b.id
        $where_clause
        ORDER BY u.recorded_at DESC
        LIMIT $limit OFFSET $offset
    ";

    try {
        // Thống kê sử dụng tiện ích
        $stats_conditions = ['u.deleted_at IS NULL'];
        $stats_params = [];
        if ($owner_id) {
            $stats_conditions[] = 'b.owner_id = ?';
            $stats_params[] = $owner_id;
        }
        if ($branch_id) {
            $stats_conditions[] = 'r.branch_id = ?';
            $stats_params[] = $branch_id;
        }
        $stats_where_clause = !empty($stats_conditions) ? 'WHERE ' . implode(' AND ', $stats_conditions) : '';

        $stats_stmt = $pdo->prepare("
            SELECT 
                s.type, s.name,
                SUM(u.usage_amount * s.price) AS total_cost,
                SUM(u.usage_amount) AS total_usage
            FROM utility_usage u
            JOIN services s ON u.service_id = s.id
            JOIN rooms r ON u.room_id = r.id
            JOIN branches b ON r.branch_id = b.id
            $stats_where_clause
            GROUP BY s.type, s.name
        ");
        $stats_stmt->execute($stats_params);
        $stats = $stats_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Đếm tổng số bản ghi
        $count_stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM utility_usage u
            JOIN rooms r ON u.room_id = r.id
            JOIN branches b ON r.branch_id = b.id
            $where_clause
        ");
        $count_stmt->execute($params);
        $total_records = $count_stmt->fetchColumn();
        $total_pages = ceil($total_records / $limit);

        // Lấy danh sách sử dụng tiện ích
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $usages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        responseJson([
            'status' => 'success',
            'data' => [
                'statistics' => $stats,
                'usages' => $usages,
            ],
            'pagination' => [
                'current_page' => $page,
                'limit' => $limit,
                'total_records' => $total_records,
                'total_pages' => $total_pages,
            ],
        ]);
    } catch (PDOException $e) {
        logError("Lỗi lấy báo cáo sử dụng tiện ích: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Admin: Maintenance Report for All Branches
function getAllBranchesMaintenanceReport() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if ($role !== 'admin') {
        responseJson(['status' => 'error', 'message' => 'Chỉ admin được phép truy cập báo cáo'], 403);
        return;
    }

    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $status = isset($_GET['status']) ? $_GET['status'] : null;
    $branch_id = isset($_GET['branch_id']) && is_numeric($_GET['branch_id']) ? (int)$_GET['branch_id'] : null;
    $owner_id = isset($_GET['owner_id']) && is_numeric($_GET['owner_id']) ? (int)$_GET['owner_id'] : null;

    $conditions = ['mr.deleted_at IS NULL'];
    $params = [];

    if ($status && in_array($status, ['pending', 'in_progress', 'completed'])) {
        $conditions[] = 'mr.status = ?';
        $params[] = $status;
    }
    if ($branch_id) {
        $conditions[] = 'r.branch_id = ?';
        $params[] = $branch_id;
    }
    if ($owner_id) {
        $conditions[] = 'b.owner_id = ?';
        $params[] = $owner_id;
    }

    $where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $query = "
        SELECT 
            mr.id, mr.room_id, mr.description, mr.status, mr.created_at,
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
        // Thống kê trạng thái yêu cầu bảo trì
        $stats_conditions = ['mr.deleted_at IS NULL'];
        $stats_params = [];
        if ($owner_id) {
            $stats_conditions[] = 'b.owner_id = ?';
            $stats_params[] = $owner_id;
        }
        if ($branch_id) {
            $stats_conditions[] = 'r.branch_id = ?';
            $stats_params[] = $branch_id;
        }
        $stats_where_clause = !empty($stats_conditions) ? 'WHERE ' . implode(' AND ', $stats_conditions) : '';

        $stats_stmt = $pdo->prepare("
            SELECT 
                COUNT(*) AS total_requests,
                SUM(CASE WHEN mr.status = 'pending' THEN 1 ELSE 0 END) AS pending_requests,
                SUM(CASE WHEN mr.status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_requests,
                SUM(CASE WHEN mr.status = 'completed' THEN 1 ELSE 0 END) AS completed_requests
            FROM maintenance_requests mr
            JOIN rooms r ON mr.room_id = r.id
            JOIN branches b ON r.branch_id = b.id
            $stats_where_clause
        ");
        $stats_stmt->execute($stats_params);
        $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

        // Đếm tổng số bản ghi
        $count_stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM maintenance_requests mr 
            JOIN rooms r ON mr.room_id = r.id 
            JOIN branches b ON r.branch_id = b.id 
            $where_clause
        ");
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
        logError("Lỗi lấy báo cáo yêu cầu bảo trì: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Owner: Revenue Report
function getRevenueReport($branchId) {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if ($role !== 'owner') {
        responseJson(['status' => 'error', 'message' => 'Chỉ chủ trọ được phép truy cập báo cáo'], 403);
        return;
    }

    // Kiểm tra quyền sở hữu chi nhánh
    $stmt = $pdo->prepare("SELECT id FROM branches WHERE id = ? AND owner_id = ? AND deleted_at IS NULL");
    $stmt->execute([$branchId, $user_id]);
    if (!$stmt->fetch()) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền truy cập chi nhánh này'], 403);
        return;
    }

    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : null;
    $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : null;
    $month = isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['month']) ? $_GET['month'] : null;

    if ($month && ($start_date || $end_date)) {
        responseJson(['status' => 'error', 'message' => 'Không thể sử dụng month cùng với start_date hoặc end_date'], 400);
        return;
    }

    $conditions = ['i.branch_id = ? AND i.deleted_at IS NULL'];
    $params = [$branchId];

    if ($month) {
        $conditions[] = "DATE_FORMAT(i.created_at, '%Y-%m') = ?";
        $params[] = $month;
    } else {
        if ($start_date && DateTime::createFromFormat('Y-m-d', $start_date)) {
            $conditions[] = 'i.created_at >= ?';
            $params[] = $start_date;
        }
        if ($end_date && DateTime::createFromFormat('Y-m-d', $end_date)) {
            $conditions[] = 'i.created_at <= ?';
            $params[] = $end_date;
        }
    }

    $where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $invoice_query = "
        SELECT 
            i.id, i.contract_id, i.amount AS amount, i.due_date, i.status, i.created_at,
            c.user_id, u.username AS user_name, u.name AS customer_name, r.name AS room_name
        FROM invoices i
        JOIN contracts c ON i.contract_id = c.id
        JOIN users u ON c.user_id = u.id
        JOIN rooms r ON c.room_id = r.id
        $where_clause
        ORDER BY i.created_at DESC
        LIMIT $limit OFFSET $offset
    ";

    $monthly_query = "
        SELECT 
            DATE_FORMAT(i.created_at, '%Y-%m') AS name,
            SUM(i.amount) AS total
        FROM invoices i
        $where_clause
        GROUP BY DATE_FORMAT(i.created_at, '%Y-%m')
        ORDER BY name ASC
    ";

    try {
        // Tổng doanh thu
        $total_stmt = $pdo->prepare("SELECT SUM(i.amount) AS total_revenue FROM invoices i $where_clause");
        $total_stmt->execute($params);
        $total_revenue = (float)$total_stmt->fetchColumn() ?? 0;

        // Đếm tổng số hóa đơn
        $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM invoices i $where_clause");
        $count_stmt->execute($params);
        $total_records = $count_stmt->fetchColumn();
        $total_pages = ceil($total_records / $limit);

        // Lấy danh sách hóa đơn
        $stmt = $pdo->prepare($invoice_query);
        $stmt->execute($params);
        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Lấy dữ liệu doanh thu theo tháng
        $monthly_stmt = $pdo->prepare($monthly_query);
        $monthly_stmt->execute($params);
        $monthly_data = $monthly_stmt->fetchAll(PDO::FETCH_ASSOC);

        responseJson([
            'status' => 'success',
            'data' => [
                'total_revenue' => $total_revenue,
                'monthly_revenue' => $monthly_data,
                'invoices' => $invoices,
            ],
            'pagination' => [
                'current_page' => $page,
                'limit' => $limit,
                'total_records' => $total_records,
                'total_pages' => $total_pages,
            ]
        ]);
    } catch (PDOException $e) {
        logError("Lỗi lấy báo cáo doanh thu: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Owner: Room Status Report
function getRoomStatusReport($branchId) {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if ($role !== 'owner') {
        responseJson(['status' => 'error', 'message' => 'Chỉ chủ trọ được phép truy cập báo cáo'], 403);
        return;
    }

    // Kiểm tra quyền sở hữu chi nhánh
    $stmt = $pdo->prepare("SELECT id FROM branches WHERE id = ? AND owner_id = ? AND deleted_at IS NULL");
    $stmt->execute([$branchId, $user_id]);
    if (!$stmt->fetch()) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền truy cập chi nhánh này'], 403);
        return;
    }

    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $status = isset($_GET['status']) ? $_GET['status'] : null;
    $conditions = ['r.branch_id = ? AND r.deleted_at IS NULL'];
    $params = [$branchId];

    if ($status && in_array($status, ['available', 'occupied', 'maintenance'])) {
        $conditions[] = 'r.status = ?';
        $params[] = $status;
    }

    $where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $query = "
        SELECT 
            r.id, r.name, r.price, r.status, rt.name AS room_type,
            (SELECT COUNT(*) FROM contracts c WHERE c.room_id = r.id AND c.status = 'active' AND c.deleted_at IS NULL) AS active_contracts
        FROM rooms r
        JOIN room_types rt ON r.type_id = rt.id
        $where_clause
        ORDER BY r.id DESC
        LIMIT $limit OFFSET $offset
    ";

    try {
        // Thống kê trạng thái phòng
        $stats_stmt = $pdo->prepare("
            SELECT 
                COUNT(*) AS total_rooms,
                SUM(CASE WHEN r.status = 'available' THEN 1 ELSE 0 END) AS available_rooms,
                SUM(CASE WHEN r.status = 'occupied' THEN 1 ELSE 0 END) AS occupied_rooms,
                SUM(CASE WHEN r.status = 'maintenance' THEN 1 ELSE 0 END) AS maintenance_rooms
            FROM rooms r
            WHERE r.branch_id = ? AND r.deleted_at IS NULL
        ");
        $stats_stmt->execute([$branchId]);
        $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

        // Đếm tổng số bản ghi
        $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM rooms r $where_clause");
        $count_stmt->execute($params);
        $total_records = $count_stmt->fetchColumn();
        $total_pages = ceil($total_records / $limit);

        // Lấy danh sách phòng
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

        responseJson([
            'status' => 'success',
            'data' => [
                'statistics' => $stats,
                'rooms' => $rooms,
            ],
            'pagination' => [
                'current_page' => $page,
                'limit' => $limit,
                'total_records' => $total_records,
                'total_pages' => $total_pages,
            ]
        ]);
    } catch (PDOException $e) {
        logError("Lỗi lấy báo cáo tình trạng phòng: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Owner: Contract Report
function getContractReport($branchId) {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if ($role !== 'owner') {
        responseJson(['status' => 'error', 'message' => 'Chỉ chủ trọ được phép truy cập báo cáo'], 403);
        return;
    }

    // Kiểm tra quyền sở hữu chi nhánh
    $stmt = $pdo->prepare("SELECT id FROM branches WHERE id = ? AND owner_id = ? AND deleted_at IS NULL");
    $stmt->execute([$branchId, $user_id]);
    if (!$stmt->fetch()) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền truy cập chi nhánh này'], 403);
        return;
    }

    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $status = isset($_GET['status']) ? $_GET['status'] : null;
    $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : null;
    $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : null;
    $month = isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['month']) ? $_GET['month'] : null;

    if ($month && ($start_date || $end_date)) {
        responseJson(['status' => 'error', 'message' => 'Không thể sử dụng month cùng với start_date hoặc end_date'], 400);
        return;
    }

    $conditions = ['r.branch_id = ? AND c.deleted_at IS NULL'];
    $params = [$branchId];

    if ($month) {
        $conditions[] = "DATE_FORMAT(c.created_at, '%Y-%m') = ?";
        $params[] = $month;
    } else {
        if ($status && in_array($status, ['active', 'expired', 'ended', 'cancelled'])) {
            $conditions[] = 'c.status = ?';
            $params[] = $status;
        }
        if ($start_date && DateTime::createFromFormat('Y-m-d', $start_date)) {
            $conditions[] = 'c.start_date >= ?';
            $params[] = $start_date;
        }
        if ($end_date && DateTime::createFromFormat('Y-m-d', $end_date)) {
            $conditions[] = 'c.end_date <= ?';
            $params[] = $end_date;
        }
    }

    $where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $query = "
        SELECT 
            c.id, c.room_id, c.user_id, c.start_date, c.end_date, c.status, c.created_at, c.deposit,
            r.name AS room_name, u.username AS user_name
        FROM contracts c
        JOIN rooms r ON c.room_id = r.id
        JOIN users u ON c.user_id = u.id
        $where_clause
        ORDER BY c.created_at DESC
        LIMIT $limit OFFSET $offset
    ";

    try {
        // Thống kê trạng thái hợp đồng
        $stats_stmt = $pdo->prepare("
            SELECT 
                COUNT(*) AS total_contracts,
                SUM(CASE WHEN c.status = 'active' THEN 1 ELSE 0 END) AS active_contracts,
                SUM(CASE WHEN c.status = 'expired' THEN 1 ELSE 0 END) AS expired_contracts,
                SUM(CASE WHEN c.status = 'ended' THEN 1 ELSE 0 END) AS ended_contracts,
                SUM(CASE WHEN c.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_contracts
            FROM contracts c
            JOIN rooms r ON c.room_id = r.id
            WHERE r.branch_id = ? AND c.deleted_at IS NULL
        ");
        $stats_stmt->execute([$branchId]);
        $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

        // Đếm tổng số bản ghi
        $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM contracts c JOIN rooms r ON c.room_id = r.id $where_clause");
        $count_stmt->execute($params);
        $total_records = $count_stmt->fetchColumn();
        $total_pages = ceil($total_records / $limit);

        // Lấy danh sách hợp đồng
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        responseJson([
            'status' => 'success',
            'data' => [
                'statistics' => $stats,
                'contracts' => $contracts,
            ],
            'pagination' => [
                'current_page' => $page,
                'limit' => $limit,
                'total_records' => $total_records,
                'total_pages' => $total_pages,
            ]
        ]);
    } catch (PDOException $e) {
        logError("Lỗi lấy báo cáo hợp đồng: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Owner: Utility Usage Report
function getUtilityUsageReport($branchId) {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if ($role !== 'owner') {
        responseJson(['status' => 'error', 'message' => 'Chỉ chủ trọ được phép truy cập báo cáo'], 403);
        return;
    }

    // Kiểm tra quyền sở hữu chi nhánh
    $stmt = $pdo->prepare("SELECT id FROM branches WHERE id = ? AND owner_id = ? AND deleted_at IS NULL");
    $stmt->execute([$branchId, $user_id]);
    if (!$stmt->fetch()) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền truy cập chi nhánh này'], 403);
        return;
    }

    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $month = isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['month']) ? $_GET['month'] : null;
    $conditions = ['r.branch_id = ? AND u.deleted_at IS NULL'];
    $params = [$branchId];

    if ($month) {
        $conditions[] = 'u.month = ?';
        $params[] = $month;
    }

    $where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $query = "
        SELECT 
            u.id, u.room_id, u.contract_id, u.service_id, u.month, u.usage_amount, 
            u.old_reading, u.new_reading, u.recorded_at,
            r.name AS room_name, s.name AS service_name, s.price AS service_price
        FROM utility_usage u
        JOIN rooms r ON u.room_id = r.id
        JOIN services s ON u.service_id = s.id
        $where_clause
        ORDER BY u.recorded_at DESC
        LIMIT $limit OFFSET $offset
    ";

    try {
        // Thống kê sử dụng tiện ích
        $stats_stmt = $pdo->prepare("
            SELECT 
                s.type, s.name,
                SUM(u.usage_amount * s.price) AS total_cost,
                SUM(u.usage_amount) AS total_usage
            FROM utility_usage u
            JOIN services s ON u.service_id = s.id
            JOIN rooms r ON u.room_id = r.id
            WHERE r.branch_id = ? AND u.deleted_at IS NULL
            GROUP BY s.type, s.name
        ");
        $stats_stmt->execute([$branchId]);
        $stats = $stats_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Đếm tổng số bản ghi
        $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM utility_usage u JOIN rooms r ON u.room_id = r.id $where_clause");
        $count_stmt->execute($params);
        $total_records = $count_stmt->fetchColumn();
        $total_pages = ceil($total_records / $limit);

        // Lấy danh sách sử dụng tiện ích
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $usages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        responseJson([
            'status' => 'success',
            'data' => [
                'statistics' => $stats,
                'usages' => $usages,
            ],
            'pagination' => [
                'current_page' => $page,
                'limit' => $limit,
                'total_records' => $total_records,
                'total_pages' => $total_pages,
            ],
        ]);
    } catch (PDOException $e) {
        logError("Lỗi lấy báo cáo sử dụng tiện ích: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Owner: Maintenance Report
function getMaintenanceReport($branchId) {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if ($role !== 'owner') {
        responseJson(['status' => 'error', 'message' => 'Chỉ chủ trọ được phép truy cập báo cáo'], 403);
        return;
    }

    // Kiểm tra quyền sở hữu chi nhánh
    $stmt = $pdo->prepare("SELECT id FROM branches WHERE id = ? AND owner_id = ? AND deleted_at IS NULL");
    $stmt->execute([$branchId, $user_id]);
    if (!$stmt->fetch()) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền truy cập chi nhánh này'], 403);
        return;
    }

    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $status = isset($_GET['status']) ? $_GET['status'] : null;
    $conditions = ['r.branch_id = ? AND mr.deleted_at IS NULL'];
    $params = [$branchId];

    if ($status && in_array($status, ['pending', 'in_progress', 'completed'])) {
        $conditions[] = 'mr.status = ?';
        $params[] = $status;
    }

    $where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $query = "
        SELECT 
            mr.id, mr.room_id, mr.description, mr.status, mr.created_at,
            r.name AS room_name, u.username AS created_by
        FROM maintenance_requests mr
        JOIN rooms r ON mr.room_id = r.id
        JOIN users u ON mr.created_by = u.id
        $where_clause
        ORDER BY mr.created_at DESC
        LIMIT $limit OFFSET $offset
    ";

    try {
        // Thống kê trạng thái yêu cầu bảo trì
        $stats_stmt = $pdo->prepare("
            SELECT 
                COUNT(*) AS total_requests,
                SUM(CASE WHEN mr.status = 'pending' THEN 1 ELSE 0 END) AS pending_requests,
                SUM(CASE WHEN mr.status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_requests,
                SUM(CASE WHEN mr.status = 'completed' THEN 1 ELSE 0 END) AS completed_requests
            FROM maintenance_requests mr
            JOIN rooms r ON mr.room_id = r.id
            WHERE r.branch_id = ? AND mr.deleted_at IS NULL
        ");
        $stats_stmt->execute([$branchId]);
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
        logError("Lỗi lấy báo cáo yêu cầu bảo trì: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Employee: Revenue Report for Assigned Branches
function getAssignedBranchesRevenueReport($employeeId) {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if ($role !== 'employee' || $user_id != $employeeId) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền truy cập'], 403);
        return;
    }

    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : null;
    $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : null;
    $branch_id = isset($_GET['branch_id']) && is_numeric($_GET['branch_id']) ? (int)$_GET['branch_id'] : null;
    $month = isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['month']) ? $_GET['month'] : null;

    if ($month && ($start_date || $end_date)) {
        responseJson(['status' => 'error', 'message' => 'Không thể sử dụng month cùng với start_date hoặc end_date'], 400);
        return;
    }

    $conditions = ['i.branch_id IN (SELECT branch_id FROM employee_assignments WHERE employee_id = ?) AND i.deleted_at IS NULL'];
    $params = [$employeeId];

    if ($branch_id) {
        $conditions[] = 'i.branch_id = ?';
        $params[] = $branch_id;
    }
    if ($month) {
        $conditions[] = "DATE_FORMAT(i.created_at, '%Y-%m') = ?";
        $params[] = $month;
    } else {
        if ($start_date && DateTime::createFromFormat('Y-m-d', $start_date)) {
            $conditions[] = 'i.created_at >= ?';
            $params[] = $start_date;
        }
        if ($end_date && DateTime::createFromFormat('Y-m-d', $end_date)) {
            $conditions[] = 'i.created_at <= ?';
            $params[] = $end_date;
        }
    }

    $where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $invoice_query = "
        SELECT 
            i.id, i.contract_id, i.amount AS amount, i.due_date, i.status, i.created_at,
            c.user_id, u.username AS user_name, u.name AS customer_name, r.name AS room_name, b.name AS branch_name
        FROM invoices i
        JOIN contracts c ON i.contract_id = c.id
        JOIN users u ON c.user_id = u.id
        JOIN rooms r ON c.room_id = r.id
        JOIN branches b ON i.branch_id = b.id
        $where_clause
        ORDER BY i.created_at DESC
        LIMIT $limit OFFSET $offset
    ";

    $monthly_query = "
        SELECT 
            DATE_FORMAT(i.created_at, '%Y-%m') AS name,
            SUM(i.amount) AS total
        FROM invoices i
        JOIN branches b ON i.branch_id = b.id
        $where_clause
        GROUP BY DATE_FORMAT(i.created_at, '%Y-%m')
        ORDER BY name ASC
    ";

    try {
        // Tổng doanh thu
        $total_stmt = $pdo->prepare("SELECT SUM(i.amount) AS total_revenue 
            FROM invoices i 
            JOIN branches b ON i.branch_id = b.id 
            $where_clause");
        $total_stmt->execute($params);
        $total_revenue = (float)$total_stmt->fetchColumn() ?? 0;

        // Đếm tổng số hóa đơn
        $count_stmt = $pdo->prepare("SELECT COUNT(*) 
            FROM invoices i 
            JOIN branches b ON i.branch_id = b.id 
            $where_clause");
        $count_stmt->execute($params);
        $total_records = $count_stmt->fetchColumn();
        $total_pages = ceil($total_records / $limit);

        // Lấy danh sách hóa đơn
        $stmt = $pdo->prepare($invoice_query);
        $stmt->execute($params);
        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Lấy dữ liệu doanh thu tháng
        $monthly_stmt = $pdo->prepare($monthly_query);
        $monthly_stmt->execute($params);
        $monthly_data = $monthly_stmt->fetchAll(PDO::FETCH_ASSOC);

        responseJson([
            'status' => 'success',
            'data' => [
                'total_revenue' => $total_revenue,
                'monthly_revenue' => $monthly_data,
                'invoices' => $invoices,
            ],
            'pagination' => [
                'current_page' => $page,
                'limit' => $limit,
                'total_records' => $total_records,
                'total_pages' => $total_pages,
            ],
        ]);
    } catch (PDOException $e) {
        logError("Lỗi lấy báo cáo doanh thu: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Employee: Room Status Report for Assigned Branches
function getAssignedBranchesRoomStatusReport($employeeId) {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if ($role !== 'employee' || $user_id != $employeeId) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền truy cập'], 404);
        return;
    }

    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $status = isset($_GET['status']) ? $_GET['status'] : null;
    $branch_id = isset($_GET['branch_id']) && is_numeric($_GET['branch_id']) ? (int)$_GET['branch_id'] : null;

    $conditions = ['r.branch_id IN (SELECT branch_id FROM employee_assignments WHERE employee_id = ?) AND r.deleted_at IS NULL'];
    $params = [$employeeId];

    if ($branch_id) {
        $conditions[] = 'r.branch_id = ?';
        $params[] = $branch_id;
    }
    if ($status && in_array($status, ['available', 'occupied', 'maintenance'])) {
        $conditions[] = 'r.status = ?';
        $params[] = $status;
    }

    $where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $query = "
        SELECT 
            r.id, r.name, r.price, r.status, rt.name AS room_type, b.name AS branch_name,
            (SELECT COUNT(*) FROM contracts c WHERE c.room_id = r.id AND c.status = 'active' AND c.deleted_at IS NULL) AS active_contracts
        FROM rooms r
        JOIN room_types rt ON r.type_id = rt.id
        JOIN branches b ON r.branch_id = b.id
        $where_clause
        ORDER BY r.id DESC
        LIMIT $limit OFFSET $offset
    ";

    try {
        // Thống kê trạng thái phòng
        $stats_conditions = ['r.branch_id IN (SELECT branch_id FROM employee_assignments WHERE employee_id = ?) AND r.deleted_at IS NULL'];
        $stats_params = [$employeeId];
        if ($branch_id) {
            $stats_conditions[] = 'r.branch_id = ?';
            $stats_params[] = $branch_id;
        }
        $stats_where = !empty($stats_conditions) ? 'WHERE ' . implode(' AND ', $stats_conditions) : '';

        $stats_stmt = $pdo->prepare("
            SELECT 
                COUNT(*) AS total_rooms,
                SUM(CASE WHEN r.status = 'available' THEN 1 ELSE 0 END) AS available_rooms,
                SUM(CASE WHEN r.status = 'occupied' THEN 1 ELSE 0 END) AS occupied_rooms,
                SUM(CASE WHEN r.status = 'maintenance' THEN 1 ELSE 0 END) AS maintenance_rooms
            FROM rooms r
            JOIN branches b ON r.branch_id = b.id
            $stats_where
        ");
        $stats_stmt->execute($stats_params);
        $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

        // Đếm tổng số bản ghi
        $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM rooms r JOIN branches b ON r.branch_id = b.id $where_clause");
        $count_stmt->execute($params);
        $total_records = $count_stmt->fetchColumn();
        $total_pages = ceil($total_records / $limit);

        // Lấy danh sách phòng
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

        responseJson([
            'status' => 'success',
            'data' => [
                'statistics' => $stats,
                'rooms' => $rooms,
            ],
            'pagination' => [
                'current_page' => $page,
                'limit' => $limit,
                'total_records' => $total_records,
                'total_pages' => $total_pages,
            ],
        ]);
    } catch (PDOException $e) {
        logError("Lỗi lấy báo cáo tình trạng phòng: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Employee: Contract Report for Assigned Branches
function getAssignedBranchesContractReport($employeeId) {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if ($role !== 'employee' || $user_id != $employeeId) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền truy cập'], 404);
        return;
    }

    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $status = isset($_GET['status']) ? $_GET['status'] : null;
    $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : null;
    $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : null;
    $branch_id = isset($_GET['branch_id']) && is_numeric($_GET['branch_id']) ? (int)$_GET['branch_id'] : null;
    $month = isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['month']) ? $_GET['month'] : null;

    if ($month && ($start_date || $end_date)) {
        responseJson(['status' => 'error', 'message' => 'Không thể sử dụng cho month cùng với start_date hoặc end_date'], 400);
        return;
    }

    $conditions = ['r.branch_id IN (SELECT branch_id FROM employee_assignments WHERE employee_id = ?) AND c.deleted_at IS NULL'];
    $params = [$employeeId];

    if ($branch_id) {
        $conditions[] = 'r.branch_id = ?';
        $params[] = $branch_id;
    }
    if ($month) {
        $conditions[] = "DATE_FORMAT(c.created_at, '%Y-%m') = ?";
        $params[] = $month;
    } else {
        if ($status && in_array($status, ['active', 'expired', 'ended', 'cancelled'])) {
            $conditions[] = 'c.status = ?';
            $params[] = $status;
        }
        if ($start_date && DateTime::createFromFormat('Y-m-d', $start_date)) {
            $conditions[] = 'c.start_date >= ?';
            $params[] = $start_date;
        }
        if ($end_date && DateTime::createFromFormat('Y-m-d', $end_date)) {
            $conditions[] = 'c.end_date <= ?';
            $params[] = $end_date;
        }
    }

    $where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $query = "
        SELECT 
            c.id, c.room_id, c.user_id, c.start_date, c.end_date, c.status, c.created_at, c.deposit,
            r.name AS room_name, u.username AS user_name, b.name AS branch_name
        FROM contracts c
        JOIN rooms r ON c.room_id = r.id
        JOIN users u ON c.user_id = u.id
        JOIN branches b ON r.branch_id = b.id
        $where_clause
        ORDER BY c.created_at DESC
        LIMIT $limit OFFSET $offset
    ";

    try {
        // Thống kê trạng thái hợp đồng
        $stats_conditions = ['r.branch_id IN (SELECT branch_id FROM employee_assignments WHERE employee_id = ?) AND c.deleted_at IS NULL'];
        $stats_params = [$employeeId];
        if ($branch_id) {
            $stats_conditions[] = 'r.branch_id = ?';
            $stats_params[] = $branch_id;
        }
        $stats_where = !empty($stats_conditions) ? 'WHERE ' . implode(' AND ', $stats_conditions) : '';

        $stats_stmt = $pdo->prepare("
            SELECT 
                COUNT(*) AS total_contracts,
                SUM(CASE WHEN c.status = 'active' THEN 1 ELSE 0 END) AS active_contracts,
                SUM(CASE WHEN c.status = 'expired' THEN 1 ELSE 0 END) AS expired_contracts,
                SUM(CASE WHEN c.status = 'ended' THEN 1 ELSE 0 END) AS ended_contracts,
                SUM(CASE WHEN c.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_contracts
            FROM contracts c
            JOIN rooms r ON c.room_id = r.id
            JOIN branches b ON r.branch_id = b.id
            $stats_where
        ");
        $stats_stmt->execute($stats_params);
        $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

        // Đếm tổng số bản ghi
        $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM contracts c JOIN rooms r ON c.room_id = r.id JOIN branches b ON r.branch_id = b.id $where_clause");
        $count_stmt->execute($params);
        $total_records = $count_stmt->fetchColumn();
        $total_pages = ceil($total_records / $limit);

        // Lấy danh sách hợp đồng
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        responseJson([
            'status' => 'success',
            'data' => [
                'statistics' => $stats,
                'contracts' => $contracts,
            ],
            'pagination' => [
                'current_page' => $page,
                'limit' => $limit,
                'total_records' => $total_records,
                'total_pages' => $total_pages,
            ],
        ]);
    } catch (PDOException $e) {
        logError("Lỗi lấy báo cáo hợp đồng: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Employee: Utility Usage Report for Assigned Branches
function getAssignedBranchesUtilityUsageReport($employeeId) {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if ($role !== 'employee' || $user_id != $employeeId) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền truy cập'], 404);
        return;
    }

    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $month = isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['month']) ? $_GET['month'] : null;
    $branch_id = isset($_GET['branch_id']) && is_numeric($_GET['branch_id']) ? (int)$_GET['branch_id'] : null;

    $conditions = ['r.branch_id IN (SELECT branch_id FROM employee_assignments WHERE employee_id = ?) AND u.deleted_at IS NULL'];
    $params = [$employeeId];

    if ($branch_id) {
        $conditions[] = 'r.branch_id = ?';
        $params[] = $branch_id;
    }
    if ($month) {
        $conditions[] = 'u.month = ?';
        $params[] = $month;
    }

    $where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $query = "
        SELECT 
            u.id, u.room_id, u.contract_id, u.service_id, u.month, u.usage_amount, 
            u.old_reading, u.new_reading, u.recorded_at,
            r.name AS room_name, s.name AS service_name, s.price AS service_price,
            b.name AS branch_name
        FROM utility_usage u
        JOIN rooms r ON u.room_id = r.id
        JOIN services s ON u.service_id = s.id
        JOIN branches b ON r.branch_id = b.id
        $where_clause
        ORDER BY u.recorded_at DESC
        LIMIT $limit OFFSET $offset
    ";

    try {
        // Thống kê sử dụng dịch vụ
        $stats_conditions = ['u.deleted_at IS NULL'];
        $stats_params = [$employeeId];
        if ($branch_id) {
            $stats_conditions[] = 'r.branch_id = ?';
            $stats_params[] = $branch_id;
        }
        $stats_where = !empty($stats_conditions) ? 'WHERE r.branch_id IN (SELECT branch_id FROM employee_assignments WHERE employee_id = ?) AND ' . implode(' AND ', $stats_conditions) : '';

        $stats_stmt = $pdo->prepare("
            SELECT 
                s.type, s.name,
                SUM(u.usage_amount * s.price) AS total_cost,
                SUM(u.usage_amount) AS total_usage
            FROM utility_usage u
            JOIN services s ON u.service_id = s.id
            JOIN rooms r ON u.room_id = r.id
            JOIN branches b ON r.branch_id = b.id
            $stats_where
            GROUP BY s.type, s.name
        ");
        $stats_stmt->execute($stats_params);
        $stats = $stats_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Đếm tổng số bản ghi
        $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM utility_usage u JOIN rooms r ON u.room_id = r.id JOIN branches b ON r.branch_id = b.id $where_clause");
        $count_stmt->execute($params);
        $total_records = $count_stmt->fetchColumn();
        $total_pages = ceil($total_records / $limit);

        // Lấy danh sách sử dụng tiện ích
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $usages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        responseJson([
            'status' => 'success',
            'data' => [
                'statistics' => $stats,
                'usages' => $usages,
            ],
            'pagination' => [
                'current_page' => $page,
                'limit' => $limit,
                'total_records' => $total_records,
                'total_pages' => $total_pages,
            ],
        ]);
    } catch (PDOException $e) {
        logError("Lỗi lấy báo cáo sử dụng tiện ích: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Employee: Maintenance Report for Assigned Branches (Tiếp tục từ hàm trước)
function getAssignedBranchesMaintenanceReport($employeeId) {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if ($role !== 'employee' || $user_id != $employeeId) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền truy cập'], 403);
        return;
    }

    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $status = isset($_GET['status']) ? $_GET['status'] : null;
    $branch_id = isset($_GET['branch_id']) && is_numeric($_GET['branch_id']) ? (int)$_GET['branch_id'] : null;

    $conditions = ['r.branch_id IN (SELECT branch_id FROM employee_assignments WHERE employee_id = ?) AND mr.deleted_at IS NULL'];
    $params = [$employeeId];

    if ($branch_id) {
        $conditions[] = 'r.branch_id = ?';
        $params[] = $branch_id;
    }
    if ($status && in_array($status, ['pending', 'in_progress', 'completed'])) {
        $conditions[] = 'mr.status = ?';
        $params[] = $status;
    }

    $where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $query = "
        SELECT 
            mr.id, mr.room_id, mr.description, mr.status, mr.created_at,
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
        // Thống kê trạng thái yêu cầu bảo trì
        $stats_conditions = ['r.branch_id IN (SELECT branch_id FROM employee_assignments WHERE employee_id = ?) AND mr.deleted_at IS NULL'];
        $stats_params = [$employeeId];
        if ($branch_id) {
            $stats_conditions[] = 'r.branch_id = ?';
            $stats_params[] = $branch_id;
        }
        $stats_where_clause = !empty($stats_conditions) ? 'WHERE ' . implode(' AND ', $stats_conditions) : '';

        $stats_stmt = $pdo->prepare("
            SELECT 
                COUNT(*) AS total_requests,
                SUM(CASE WHEN mr.status = 'pending' THEN 1 ELSE 0 END) AS pending_requests,
                SUM(CASE WHEN mr.status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_requests,
                SUM(CASE WHEN mr.status = 'completed' THEN 1 ELSE 0 END) AS completed_requests
            FROM maintenance_requests mr
            JOIN rooms r ON mr.room_id = r.id
            JOIN branches b ON r.branch_id = b.id
            $stats_where_clause
        ");
        $stats_stmt->execute($stats_params);
        $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

        // Đếm tổng số bản ghi
        $count_stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM maintenance_requests mr 
            JOIN rooms r ON mr.room_id = r.id 
            JOIN branches b ON r.branch_id = b.id 
            $where_clause
        ");
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
        logError("Lỗi lấy báo cáo yêu cầu bảo trì: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Customer: Get Contracts with Statistics
function getCustomerContracts($customerId) {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if ($role !== 'customer' || $user_id != $customerId) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền truy cập'], 403);
        return;
    }

    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $status = isset($_GET['status']) ? $_GET['status'] : null;
    $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : null;
    $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : null;
    $month = isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['month']) ? $_GET['month'] : null;

    if ($month && ($start_date || $end_date)) {
        responseJson(['status' => 'error', 'message' => 'Không thể sử dụng month cùng với start_date hoặc end_date'], 400);
        return;
    }

    $conditions = ['c.user_id = ? AND c.deleted_at IS NULL'];
    $params = [$customerId];

    if ($status && in_array($status, ['active', 'expired', 'ended', 'cancelled'])) {
        $conditions[] = 'c.status = ?';
        $params[] = $status;
    }
    if ($month) {
        $conditions[] = "DATE_FORMAT(c.created_at, '%Y-%m') = ?";
        $params[] = $month;
    } else {
        if ($start_date && DateTime::createFromFormat('Y-m-d', $start_date)) {
            $conditions[] = 'c.start_date >= ?';
            $params[] = $start_date;
        }
        if ($end_date && DateTime::createFromFormat('Y-m-d', $end_date)) {
            $conditions[] = 'c.end_date <= ?';
            $params[] = $end_date;
        }
    }

    $where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $query = "
        SELECT 
            c.id, c.room_id, c.user_id, c.start_date, c.end_date, c.status, c.created_at, c.deposit,
            r.name AS room_name, b.name AS branch_name
        FROM contracts c
        JOIN rooms r ON c.room_id = r.id
        JOIN branches b ON r.branch_id = b.id
        $where_clause
        ORDER BY c.created_at DESC
        LIMIT $limit OFFSET $offset
    ";

    try {
        // Thống kê trạng thái hợp đồng
        $stats_conditions = ['c.user_id = ? AND c.deleted_at IS NULL'];
        $stats_params = [$customerId];
        $stats_where = !empty($stats_conditions) ? 'WHERE ' . implode(' AND ', $stats_conditions) : '';

        $stats_stmt = $pdo->prepare("
            SELECT 
                COUNT(*) AS total_contracts,
                SUM(CASE WHEN c.status = 'active' THEN 1 ELSE 0 END) AS active_contracts,
                SUM(CASE WHEN c.status = 'expired' THEN 1 ELSE 0 END) AS expired_contracts,
                SUM(CASE WHEN c.status = 'ended' THEN 1 ELSE 0 END) AS ended_contracts,
                SUM(CASE WHEN c.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_contracts
            FROM contracts c
            JOIN rooms r ON c.room_id = r.id
            JOIN branches b ON r.branch_id = b.id
            $stats_where
        ");
        $stats_stmt->execute($stats_params);
        $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

        // Đếm tổng số bản ghi
        $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM contracts c JOIN rooms r ON c.room_id = r.id JOIN branches b ON r.branch_id = b.id $where_clause");
        $count_stmt->execute($params);
        $total_records = $count_stmt->fetchColumn();
        $total_pages = ceil($total_records / $limit);

        // Lấy danh sách hợp đồng
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        responseJson([
            'status' => 'success',
            'data' => [
                'statistics' => $stats,
                'contracts' => $contracts,
            ],
            'pagination' => [
                'current_page' => $page,
                'limit' => $limit,
                'total_records' => $total_records,
                'total_pages' => $total_pages,
            ],
        ]);
    } catch (PDOException $e) {
        logError("Lỗi lấy báo cáo hợp đồng: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Customer: Get Invoices
function getCustomerInvoices($customerId) {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if ($role !== 'customer' || $user_id != $customerId) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền truy cập'], 403);
        return;
    }

    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $status = isset($_GET['status']) ? $_GET['status'] : null;
    $month = isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['month']) ? $_GET['month'] : null;

    $conditions = ['c.user_id = ? AND i.deleted_at IS NULL'];
    $params = [$customerId];

    if ($status && in_array($status, ['pending', 'paid', 'overdue'])) {
        $conditions[] = 'i.status = ?';
        $params[] = $status;
    }
    if ($month) {
        $conditions[] = "DATE_FORMAT(i.created_at, '%Y-%m') = ?";
        $params[] = $month;
    }

    $where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $query = "
        SELECT 
            i.id, i.contract_id, i.amount AS amount, i.due_date, i.status, i.created_at,
            r.name AS room_name, b.name AS branch_name
        FROM invoices i
        JOIN contracts c ON i.contract_id = c.id
        JOIN rooms r ON c.room_id = r.id
        JOIN branches b ON r.branch_id = b.id
        $where_clause
        ORDER BY i.created_at DESC
        LIMIT $limit OFFSET $offset
    ";

    try {
        // Thống kê trạng thái hóa đơn
        $stats_stmt = $pdo->prepare("
            SELECT 
                COUNT(*) AS total_invoices,
                SUM(CASE WHEN i.status = 'pending' THEN 1 ELSE 0 END) AS pending_invoices,
                SUM(CASE WHEN i.status = 'paid' THEN 1 ELSE 0 END) AS paid_invoices,
                SUM(CASE WHEN i.status = 'overdue' THEN 1 ELSE 0 END) AS overdue_invoices,
                SUM(i.amount) AS total_amount
            FROM invoices i
            JOIN contracts c ON i.contract_id = c.id
            WHERE c.user_id = ? AND i.deleted_at IS NULL
        ");
        $stats_stmt->execute([$customerId]);
        $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

        // Đếm tổng số hóa đơn
        $count_stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM invoices i 
            JOIN contracts c ON i.contract_id = c.id 
            JOIN rooms r ON c.room_id = r.id 
            JOIN branches b ON r.branch_id = b.id 
            $where_clause
        ");
        $count_stmt->execute($params);
        $total_records = $count_stmt->fetchColumn();
        $total_pages = ceil($total_records / $limit);

        // Lấy danh sách hóa đơn
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

        responseJson([
            'status' => 'success',
            'data' => [
                'statistics' => $stats,
                'invoices' => $invoices,
            ],
            'pagination' => [
                'current_page' => $page,
                'limit' => $limit,
                'total_records' => $total_records,
                'total_pages' => $total_pages,
            ],
        ]);
    } catch (PDOException $e) {
        logError("Lỗi lấy danh sách hóa đơn: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Customer: Get Utility Usage
function getCustomerUtilityUsage($customerId) {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if ($role !== 'customer' || $user_id != $customerId) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền truy cập'], 403);
        return;
    }

    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $month = isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['month']) ? $_GET['month'] : null;

    $conditions = ['c.user_id = ? AND u.deleted_at IS NULL'];
    $params = [$customerId];

    if ($month) {
        $conditions[] = 'u.month = ?';
        $params[] = $month;
    }

    $where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $query = "
        SELECT 
            u.id, u.room_id, u.contract_id, u.service_id, u.month, u.usage_amount, 
            u.old_reading, u.new_reading, u.recorded_at,
            r.name AS room_name, s.name AS service_name, s.price AS service_price,
            b.name AS branch_name
        FROM utility_usage u
        JOIN contracts c ON u.contract_id = c.id
        JOIN rooms r ON u.room_id = r.id
        JOIN services s ON u.service_id = s.id
        JOIN branches b ON r.branch_id = b.id
        $where_clause
        ORDER BY u.recorded_at DESC
        LIMIT $limit OFFSET $offset
    ";

    try {
        // Thống kê sử dụng tiện ích
        $stats_stmt = $pdo->prepare("
            SELECT 
                s.type, s.name,
                SUM(u.usage_amount * s.price) AS total_cost,
                SUM(u.usage_amount) AS total_usage
            FROM utility_usage u
            JOIN services s ON u.service_id = s.id
            JOIN contracts c ON u.contract_id = c.id
            WHERE c.user_id = ? AND u.deleted_at IS NULL
            GROUP BY s.type, s.name
        ");
        $stats_stmt->execute([$customerId]);
        $stats = $stats_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Đếm tổng số bản ghi
        $count_stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM utility_usage u 
            JOIN contracts c ON u.contract_id = c.id 
            JOIN rooms r ON u.room_id = r.id 
            JOIN branches b ON r.branch_id = b.id 
            $where_clause
        ");
        $count_stmt->execute($params);
        $total_records = $count_stmt->fetchColumn();
        $total_pages = ceil($total_records / $limit);

        // Lấy danh sách sử dụng tiện ích
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $usages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        responseJson([
            'status' => 'success',
            'data' => [
                'statistics' => $stats,
                'usages' => $usages,
            ],
            'pagination' => [
                'current_page' => $page,
                'limit' => $limit,
                'total_records' => $total_records,
                'total_pages' => $total_pages,
            ],
        ]);
    } catch (PDOException $e) {
        logError("Lỗi lấy báo cáo sử dụng tiện ích: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Customer: Get Invoice Details
function getCustomerInvoiceDetails($customerId, $invoiceId) {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if ($role !== 'customer' || $user_id != $customerId) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền truy cập'], 403);
        return;
    }

    try {
        // Kiểm tra hóa đơn thuộc khách hàng
        $stmt = $pdo->prepare("
            SELECT 
                i.id, i.contract_id, i.amount AS amount, i.due_date, i.status, i.created_at,
                r.name AS room_name, b.name AS branch_name, c.start_date, c.end_date
            FROM invoices i
            JOIN contracts c ON i.contract_id = c.id
            JOIN rooms r ON c.room_id = r.id
            JOIN branches b ON r.branch_id = b.id
            WHERE i.id = ? AND c.user_id = ? AND i.deleted_at IS NULL
        ");
        $stmt->execute([$invoiceId, $customerId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$invoice) {
            responseJson(['status' => 'error', 'message' => 'Hóa đơn không tồn tại hoặc không thuộc về bạn'], 404);
            return;
        }

        // Lấy chi tiết sử dụng tiện ích liên quan đến hóa đơn
        $usage_stmt = $pdo->prepare("
            SELECT 
                u.id, u.service_id, u.month, u.usage_amount, u.old_reading, u.new_reading,
                s.name AS service_name, s.price AS service_price
            FROM utility_usage u
            JOIN services s ON u.service_id = s.id
            WHERE u.contract_id = ? AND u.month = DATE_FORMAT(i.created_at, '%Y-%m') AND u.deleted_at IS NULL
        ");
        $usage_stmt->execute([$invoice['contract_id']]);
        $usages = $usage_stmt->fetchAll(PDO::FETCH_ASSOC);

        responseJson([
            'status' => 'success',
            'data' => [
                'invoice' => $invoice,
                'utility_usages' => $usages,
            ],
        ]);
    } catch (PDOException $e) {
        logError("Lỗi lấy chi tiết hóa đơn: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Admin: Get All Branches
function getAllBranches() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if ($role !== 'admin') {
        responseJson(['status' => 'error', 'message' => 'Chỉ admin được phép truy cập danh sách chi nhánh'], 403);
        return;
    }

    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $owner_id = isset($_GET['owner_id']) && is_numeric($_GET['owner_id']) ? (int)$_GET['owner_id'] : null;

    $conditions = ['b.deleted_at IS NULL'];
    $params = [];

    if ($owner_id) {
        $conditions[] = 'b.owner_id = ?';
        $params[] = $owner_id;
    }

    $where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $query = "
        SELECT 
            b.id, b.name, b.address, b.created_at, 
            u.username AS owner_name, 
            (SELECT COUNT(*) FROM rooms r WHERE r.branch_id = b.id AND r.deleted_at IS NULL) AS total_rooms
        FROM branches b
        LEFT JOIN users u ON b.owner_id = u.id
        $where_clause
        ORDER BY b.created_at DESC
        LIMIT $limit OFFSET $offset
    ";

    try {
        // Đếm tổng số chi nhánh
        $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM branches b $where_clause");
        $count_stmt->execute($params);
        $total_records = $count_stmt->fetchColumn();
        $total_pages = ceil($total_records / $limit);

        // Lấy danh sách chi nhánh
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);

        responseJson([
            'status' => 'success',
            'data' => [
                'branches' => $branches,
            ],
            'pagination' => [
                'current_page' => $page,
                'limit' => $limit,
                'total_records' => $total_records,
                'total_pages' => $total_pages,
            ],
        ]);
    } catch (PDOException $e) {
        logError("Lỗi lấy danh sách chi nhánh: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}
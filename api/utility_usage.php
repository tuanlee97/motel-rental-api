<?php
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/utils/common.php';

// Tạo hoặc cập nhật số điện, nước (POST /utility_usage)
function createUtilityUsage() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    // Kiểm tra quyền người dùng
    if (!in_array($role, ['admin', 'owner', 'employee'])) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền nhập số điện, nước'], 403);
        return;
    }

    // Nhận dữ liệu đầu vào
    $input = json_decode(file_get_contents('php://input'), true);
    error_log("Input data: " . json_encode($input));
    validateRequiredFields($input, ['room_id', 'service_id', 'month', 'usage_amount', 'old_reading', 'new_reading']);
    $data = sanitizeInput($input);
    $room_id = (int)$data['room_id'];
    $service_id = (int)$data['service_id'];
    $month = $data['month'];
    $usage_amount = (float)$data['usage_amount'];
    $old_reading = (float)$data['old_reading'];
    $new_reading = (float)$data['new_reading'];

    // Kiểm tra định dạng tháng (YYYY-MM)
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        responseJson(['status' => 'error', 'message' => 'Định dạng tháng không hợp lệ (YYYY-MM)'], 400);
        return;
    }

    // Kiểm tra usage_amount, old_reading, new_reading không âm
    if ($usage_amount < 0) {
        responseJson(['status' => 'error', 'message' => 'Số lượng sử dụng không được âm'], 400);
        return;
    }
    if ($old_reading < 0) {
        responseJson(['status' => 'error', 'message' => 'Số cũ không được âm'], 400);
        return;
    }
    if ($new_reading < 0) {
        responseJson(['status' => 'error', 'message' => 'Số mới không được âm'], 400);
        return;
    }

    // Kiểm tra new_reading >= old_reading
    if ($new_reading < $old_reading) {
        responseJson(['status' => 'error', 'message' => 'Số mới phải lớn hơn hoặc bằng số cũ'], 400);
        return;
    }

    // Kiểm tra usage_amount = new_reading - old_reading
    if (abs($usage_amount - ($new_reading - $old_reading)) > 0.01) {
        responseJson(['status' => 'error', 'message' => 'Số lượng sử dụng phải bằng số mới trừ số cũ'], 400);
        return;
    }

    try {
        // Kiểm tra phòng và dịch vụ tồn tại
        checkResourceExists($pdo, 'rooms', $room_id);
        checkResourceExists($pdo, 'services', $service_id);

        // Kiểm tra dịch vụ là điện hoặc nước
        $stmt = $pdo->prepare("SELECT type, name FROM services WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$service_id]);
        $service = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$service || !in_array($service['type'], ['electricity', 'water'])) {
            responseJson(['status' => 'error', 'message' => 'Dịch vụ phải là điện hoặc nước'], 400);
            return;
        }

        // Kiểm tra quyền owner/employee
        if ($role === 'owner' || $role === 'employee') {
            $stmt = $pdo->prepare("
                SELECT 1 FROM rooms r
                JOIN branches b ON r.branch_id = b.id
                WHERE r.id = ? AND (b.owner_id = ? OR EXISTS (
                    SELECT 1 FROM employee_assignments ea WHERE ea.branch_id = b.id AND ea.employee_id = ?
                ))
            ");
            $stmt->execute([$room_id, $user_id, $user_id]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Không có quyền nhập liệu cho phòng này'], 403);
                return;
            }
        }

        // Bắt đầu giao dịch
        $pdo->beginTransaction();

        // Kiểm tra bản ghi đã tồn tại
        $stmt = $pdo->prepare("
            SELECT id FROM utility_usage
            WHERE room_id = ? AND service_id = ? AND month = ? AND deleted_at IS NULL
        ");
        $stmt->execute([$room_id, $service_id, $month]);
        $existing_usage = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing_usage) {
            // Cập nhật bản ghi hiện có
            $stmt = $pdo->prepare("
                UPDATE utility_usage
                SET usage_amount = ?, old_reading = ?, new_reading = ?, recorded_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$usage_amount, $old_reading, $new_reading, $existing_usage['id']]);
            $usage_id = $existing_usage['id'];
            $action = 'cập nhật';
        } else {
            // Tạo bản ghi mới
            $stmt = $pdo->prepare("
                INSERT INTO utility_usage (room_id, service_id, month, usage_amount, old_reading, new_reading, recorded_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$room_id, $service_id, $month, $usage_amount, $old_reading, $new_reading]);
            $usage_id = $pdo->lastInsertId();
            $action = 'nhập';
        }

        // Cam kết giao dịch
        $pdo->commit();

        // Gửi thông báo
        createNotification(
            $pdo,
            $user_id,
            "Đã $action số {$service['name']} (Số cũ: $old_reading, Số mới: $new_reading, Dùng: $usage_amount) cho phòng $room_id, tháng $month."
        );

        responseJson([
            'status' => 'success',
            'message' => "Nhập số {$service['name']} thành công",
            'data' => [
                'id' => $usage_id,
                'room_id' => $room_id,
                'service_id' => $service_id,
                'month' => $month,
                'usage_amount' => $usage_amount,
                'old_reading' => $old_reading,
                'new_reading' => $new_reading,
                'service_name' => $service['name']
            ]
        ]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Lỗi nhập số điện, nước: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Lấy danh sách hoặc chi tiết số điện, nước (GET /utility_usage)
function getUtilityUsage() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    // Kiểm tra quyền người dùng
    if (!in_array($role, ['admin', 'owner', 'employee'])) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền xem số điện, nước'], 403);
        return;
    }

    // Nhận tham số truy vấn
    $room_id = isset($_GET['room_id']) ? (int)$_GET['room_id'] : null;
    $month = isset($_GET['month']) ? $_GET['month'] : null;
    $branch_id = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : null;
    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    // Xây dựng điều kiện truy vấn
    $conditions = ['u.deleted_at IS NULL'];
    $params = [];

    if ($room_id) {
        $conditions[] = 'u.room_id = :room_id';
        $params['room_id'] = $room_id;
    }

    if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
        $conditions[] = 'u.month = :month';
        $params['month'] = $month;
    }

    if ($branch_id) {
        $conditions[] = 'r.branch_id = :branch_id';
        $params['branch_id'] = $branch_id;
    }

    // Kiểm tra quyền owner/employee
    if ($role === 'owner' || $role === 'employee') {
        $conditions[] = 'r.branch_id IN (
            SELECT id FROM branches WHERE owner_id = :owner_id OR id IN (
                SELECT branch_id FROM employee_assignments WHERE employee_id = :employee_id
            )
        )';
        $params['owner_id'] = $user_id;
        $params['employee_id'] = $user_id;
    }

    $where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

    // Truy vấn danh sách
    $query = "
    SELECT u.id, u.room_id, u.service_id, u.month, u.usage_amount, u.old_reading, u.new_reading, u.recorded_at,
        s.name AS service_name, r.name AS room_name, b.name AS branch_name
    FROM utility_usage u
    JOIN services s ON u.service_id = s.id
    JOIN rooms r ON u.room_id = r.id
    JOIN branches b ON r.branch_id = b.id
    $where_clause
    ORDER BY u.recorded_at DESC
    LIMIT $limit OFFSET $offset
    ";

    try {
    // Đếm tổng số bản ghi
    $count_query = "
        SELECT COUNT(*) FROM utility_usage u
        JOIN rooms r ON u.room_id = r.id
        $where_clause
    ";
    $count_stmt = $pdo->prepare($count_query);
    $count_params = array_filter($params, function($key) {
        return !in_array($key, ['limit', 'offset']);
    }, ARRAY_FILTER_USE_KEY);
    $count_stmt->execute($count_params);
    $total_records = $count_stmt->fetchColumn();
    $total_pages = ceil($total_records / $limit);

    // // Log the SQL query and parameters
    // error_log("SQL Query: $query");
    // error_log("Parameters: " . json_encode($params));

    // Lấy danh sách
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $usages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    responseJson([
        'status' => 'success',
        'data' => $usages,
        'pagination' => [
            'current_page' => $page,
            'limit' => $limit,
            'total_records' => $total_records,
            'total_pages' => $total_pages
        ]
    ]);
    } catch (PDOException $e) {
    error_log("Lỗi lấy danh sách số điện, nước: " . $e->getMessage());
    responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}
// Cập nhật số điện, nước (PUT /utility_usage/{id})
function updateUtilityUsage($usage_id) {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    // Kiểm tra quyền người dùng
    if (!in_array($role, ['admin', 'owner', 'employee'])) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền cập nhật số điện, nước'], 403);
        return;
    }

    // Nhận dữ liệu đầu vào
    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['usage_amount', 'old_reading', 'new_reading']);
    $data = sanitizeInput($input);
    $usage_amount = (float)$data['usage_amount'];
    $old_reading = (float)$data['old_reading'];
    $new_reading = (float)$data['new_reading'];

    // Kiểm tra usage_amount, old_reading, new_reading không âm
    if ($usage_amount < 0) {
        responseJson(['status' => 'error', 'message' => 'Số lượng sử dụng không được âm'], 400);
        return;
    }
    if ($old_reading < 0) {
        responseJson(['status' => 'error', 'message' => 'Số cũ không được âm'], 400);
        return;
    }
    if ($new_reading < 0) {
        responseJson(['status' => 'error', 'message' => 'Số mới không được âm'], 400);
        return;
    }

    // Kiểm tra new_reading >= old_reading
    if ($new_reading < $old_reading) {
        responseJson(['status' => 'error', 'message' => 'Số mới phải lớn hơn hoặc bằng số cũ'], 400);
        return;
    }

    // Kiểm tra usage_amount = new_reading - old_reading
    if (abs($usage_amount - ($new_reading - $old_reading)) > 0.01) {
        responseJson(['status' => 'error', 'message' => 'Số lượng sử dụng phải bằng số mới trừ số cũ'], 400);
        return;
    }

    try {
        // Kiểm tra bản ghi tồn tại
        $stmt = $pdo->prepare("
            SELECT u.room_id, u.service_id, u.month, s.name
            FROM utility_usage u
            JOIN services s ON u.service_id = s.id
            WHERE u.id = ? AND u.deleted_at IS NULL
        ");
        $stmt->execute([$usage_id]);
        $usage = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$usage) {
            responseJson(['status' => 'error', 'message' => 'Bản ghi không tồn tại'], 404);
            return;
        }
        $room_id = $usage['room_id'];
        $service_name = $usage['name'];
        $month = $usage['month'];

        // Kiểm tra quyền owner/employee
        if ($role === 'owner' || $role === 'employee') {
            $stmt = $pdo->prepare("
                SELECT 1 FROM rooms r
                JOIN branches b ON r.branch_id = b.id
                WHERE r.id = ? AND (b.owner_id = ? OR EXISTS (
                    SELECT 1 FROM employee_assignments ea WHERE ea.branch_id = b.id AND ea.employee_id = ?
                ))
            ");
            $stmt->execute([$room_id, $user_id, $user_id]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Không có quyền cập nhật bản ghi này'], 403);
                return;
            }
        }

        // Bắt đầu giao dịch
        $pdo->beginTransaction();

        // Cập nhật bản ghi
        $stmt = $pdo->prepare("
            UPDATE utility_usage
            SET usage_amount = ?, old_reading = ?, new_reading = ?, recorded_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$usage_amount, $old_reading, $new_reading, $usage_id]);

        // Cam kết giao dịch
        $pdo->commit();

        // Gửi thông báo
        createNotification(
            $pdo,
            $user_id,
            "Đã cập nhật số {$service_name} (Số cũ: $old_reading, Số mới: $new_reading, Dùng: $usage_amount) cho phòng $room_id, tháng $month."
        );

        responseJson([
            'status' => 'success',
            'message' => 'Cập nhật số điện, nước thành công',
            'data' => [
                'id' => $usage_id,
                'room_id' => $room_id,
                'service_id' => $usage['service_id'],
                'month' => $month,
                'usage_amount' => $usage_amount,
                'old_reading' => $old_reading,
                'new_reading' => $new_reading,
                'service_name' => $service_name
            ]
        ]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Lỗi cập nhật số điện, nước: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Xóa mềm số điện, nước (DELETE /utility_usage/{id})
function deleteUtilityUsage($usage_id) {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    // Kiểm tra quyền người dùng
    if (!in_array($role, ['admin', 'owner', 'employee'])) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền xóa số điện, nước'], 403);
        return;
    }

    try {
        // Kiểm tra bản ghi tồn tại
        $stmt = $pdo->prepare("
            SELECT u.room_id, u.service_id, u.month, s.name
            FROM utility_usage u
            JOIN services s ON u.service_id = s.id
            WHERE u.id = ? AND u.deleted_at IS NULL
        ");
        $stmt->execute([$usage_id]);
        $usage = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$usage) {
            responseJson(['status' => 'error', 'message' => 'Bản ghi không tồn tại'], 404);
            return;
        }
        $room_id = $usage['room_id'];
        $service_name = $usage['name'];
        $month = $usage['month'];

        // Kiểm tra quyền owner/employee
        if ($role === 'owner' || $role === 'employee') {
            $stmt = $pdo->prepare("
                SELECT 1 FROM rooms r
                JOIN branches b ON r.branch_id = b.id
                WHERE r.id = ? AND (b.owner_id = ? OR EXISTS (
                    SELECT 1 FROM employee_assignments ea WHERE ea.branch_id = b.id AND ea.employee_id = ?
                ))
            ");
            $stmt->execute([$room_id, $user_id, $user_id]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Không có quyền xóa bản ghi này'], 403);
                return;
            }
        }

        // Bắt đầu giao dịch
        $pdo->beginTransaction();

        // Xóa mềm bản ghi
        $stmt = $pdo->prepare("
            UPDATE utility_usage
            SET deleted_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$usage_id]);

        // Cam kết giao dịch
        $pdo->commit();

        // Gửi thông báo
        createNotification(
            $pdo,
            $user_id,
            "Đã xóa số {$service_name} cho phòng $room_id, tháng $month."
        );

        responseJson([
            'status' => 'success',
            'message' => 'Xóa số điện, nước thành công',
            'data' => ['id' => $usage_id]
        ]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Lỗi xóa số điện, nước: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}
// Lấy new_reading gần nhất (GET /utility_usage/latest)
function getLatestUtilityReading() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    // Kiểm tra quyền người dùng
    if (!in_array($role, ['admin', 'owner', 'employee'])) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền xem số điện, nước'], 403);
        return;
    }

    // Nhận tham số truy vấn
    $room_id = isset($_GET['room_id']) ? (int)$_GET['room_id'] : null;
    $service_id = isset($_GET['service_id']) ? (int)$_GET['service_id'] : null;
    $branch_id = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : null;

    // Kiểm tra tham số bắt buộc
    if (!$room_id || !$service_id) {
        responseJson(['status' => 'error', 'message' => 'room_id và service_id là bắt buộc'], 400);
        return;
    }

    // Xây dựng điều kiện truy vấn
    $conditions = ['u.deleted_at IS NULL', 'u.room_id = :room_id', 'u.service_id = :service_id'];
    $params = ['room_id' => $room_id, 'service_id' => $service_id];

    if ($branch_id) {
        $conditions[] = 'r.branch_id = :branch_id';
        $params['branch_id'] = $branch_id;
    }

    // Kiểm tra quyền owner/employee
    if ($role === 'owner' || $role === 'employee') {
        $conditions[] = 'r.branch_id IN (
            SELECT id FROM branches WHERE owner_id = :owner_id OR id IN (
                SELECT branch_id FROM employee_assignments WHERE employee_id = :employee_id
            )
        )';
        $params['owner_id'] = $user_id;
        $params['employee_id'] = $user_id;
    }

    $where_clause = 'WHERE ' . implode(' AND ', $conditions);

    // Truy vấn bản ghi gần nhất
    $query = "
        SELECT u.new_reading, u.recorded_at
        FROM utility_usage u
        JOIN rooms r ON u.room_id = r.id
        $where_clause
        ORDER BY u.recorded_at DESC
        LIMIT 1
    ";

    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $latest = $stmt->fetch(PDO::FETCH_ASSOC);

        responseJson([
            'status' => 'success',
            'data' => $latest ? ['new_reading' => $latest['new_reading'], 'recorded_at' => $latest['recorded_at']] : ['new_reading' => 0]
        ]);
    } catch (PDOException $e) {
        error_log("Lỗi lấy new_reading gần nhất: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Lấy tổng hợp số liệu sử dụng (GET /utility_usage/summary)
function getUtilityUsageSummary() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    // Kiểm tra quyền người dùng
    if (!in_array($role, ['admin', 'owner', 'employee'])) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền xem tổng hợp số điện, nước'], 403);
        return;
    }

    // Nhận tham số truy vấn
    $room_id = isset($_GET['room_id']) ? (int)$_GET['room_id'] : null;
    $service_id = isset($_GET['service_id']) ? (int)$_GET['service_id'] : null;
    $month = isset($_GET['month']) ? $_GET['month'] : null;
    $branch_id = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : null;

    // Xây dựng điều kiện truy vấn
    $conditions = ['u.deleted_at IS NULL'];
    $params = [];

    if ($room_id) {
        $conditions[] = 'u.room_id = :room_id';
        $params['room_id'] = $room_id;
    }

    if ($service_id) {
        $conditions[] = 'u.service_id = :service_id';
        $params['service_id'] = $service_id;
    }

    if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
        $conditions[] = 'u.month = :month';
        $params['month'] = $month;
    }

    if ($branch_id) {
        $conditions[] = 'r.branch_id = :branch_id';
        $params['branch_id'] = $branch_id;
    }

    // Kiểm tra quyền owner/employee
    if ($role === 'owner' || $role === 'employee') {
        $conditions[] = 'r.branch_id IN (
            SELECT id FROM branches WHERE owner_id = :owner_id OR id IN (
                SELECT branch_id FROM employee_assignments WHERE employee_id = :employee_id
            )
        )';
        $params['owner_id'] = $user_id;
        $params['employee_id'] = $user_id;
    }

    $where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

    // Truy vấn tổng hợp
    $query = "
        SELECT 
            u.month,
            u.service_id,
            s.name AS service_name,
            SUM(u.usage_amount) AS total_usage,
            COUNT(u.id) AS record_count
        FROM utility_usage u
        JOIN services s ON u.service_id = s.id
        JOIN rooms r ON u.room_id = r.id
        $where_clause
        GROUP BY u.month, u.service_id, s.name
    ";

    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $summary = $stmt->fetchAll(PDO::FETCH_ASSOC);

        responseJson([
            'status' => 'success',
            'data' => $summary
        ]);
    } catch (PDOException $e) {
        error_log("Lỗi lấy tổng hợp số điện, nước: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Thêm hàng loạt bản ghi (POST /utility_usage/bulk)
function createBulkUtilityUsage() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    // Kiểm tra quyền người dùng
    if (!in_array($role, ['admin', 'owner', 'employee'])) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền nhập số điện, nước'], 403);
        return;
    }

    // Nhận dữ liệu đầu vào
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input) || empty($input)) {
        responseJson(['status' => 'error', 'message' => 'Dữ liệu đầu vào phải là mảng các bản ghi'], 400);
        return;
    }

    $valid_entries = [];
    foreach ($input as $entry) {
        validateRequiredFields($entry, ['room_id', 'service_id', 'month', 'usage_amount', 'old_reading', 'new_reading']);
        $data = sanitizeInput($entry);
        $room_id = (int)$data['room_id'];
        $service_id = (int)$data['service_id'];
        $month = $data['month'];
        $usage_amount = (float)$data['usage_amount'];
        $old_reading = (float)$data['old_reading'];
        $new_reading = (float)$data['new_reading'];

        // Kiểm tra định dạng và giá trị
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            responseJson(['status' => 'error', 'message' => 'Định dạng tháng không hợp lệ (YYYY-MM)'], 400);
            return;
        }
        if ($usage_amount < 0 || $old_reading < 0 || $new_reading < 0) {
            responseJson(['status' => 'error', 'message' => 'Giá trị không được âm'], 400);
            return;
        }
        if ($new_reading < $old_reading) {
            responseJson(['status' => 'error', 'message' => 'Số mới phải lớn hơn hoặc bằng số cũ'], 400);
            return;
        }
        if (abs($usage_amount - ($new_reading - $old_reading)) > 0.01) {
            responseJson(['status' => 'error', 'message' => 'Số lượng sử dụng phải bằng số mới trừ số cũ'], 400);
            return;
        }

        $valid_entries[] = [
            'room_id' => $room_id,
            'service_id' => $service_id,
            'month' => $month,
            'usage_amount' => $usage_amount,
            'old_reading' => $old_reading,
            'new_reading' => $new_reading
        ];
    }

    try {
        $pdo->beginTransaction();

        foreach ($valid_entries as $entry) {
            $room_id = $entry['room_id'];
            $service_id = $entry['service_id'];
            $month = $entry['month'];
            $usage_amount = $entry['usage_amount'];
            $old_reading = $entry['old_reading'];
            $new_reading = $entry['new_reading'];

            // Kiểm tra phòng và dịch vụ tồn tại
            checkResourceExists($pdo, 'rooms', $room_id);
            checkResourceExists($pdo, 'services', $service_id);

            // Kiểm tra dịch vụ là điện hoặc nước
            $stmt = $pdo->prepare("SELECT type, name FROM services WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$service_id]);
            $service = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$service || !in_array($service['type'], ['electricity', 'water'])) {
                responseJson(['status' => 'error', 'message' => 'Dịch vụ phải là điện hoặc nước'], 400);
                return;
            }

            // Kiểm tra quyền owner/employee
            if ($role === 'owner' || $role === 'employee') {
                $stmt = $pdo->prepare("
                    SELECT 1 FROM rooms r
                    JOIN branches b ON r.branch_id = b.id
                    WHERE r.id = ? AND (b.owner_id = ? OR EXISTS (
                        SELECT 1 FROM employee_assignments ea WHERE ea.branch_id = b.id AND ea.employee_id = ?
                    ))
                ");
                $stmt->execute([$room_id, $user_id, $user_id]);
                if (!$stmt->fetch()) {
                    responseJson(['status' => 'error', 'message' => 'Không có quyền nhập liệu cho phòng này'], 403);
                    return;
                }
            }

            // Kiểm tra bản ghi đã tồn tại
            $stmt = $pdo->prepare("
                SELECT id FROM utility_usage
                WHERE room_id = ? AND service_id = ? AND month = ? AND deleted_at IS NULL
            ");
            $stmt->execute([$room_id, $service_id, $month]);
            $existing_usage = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing_usage) {
                $stmt = $pdo->prepare("
                    UPDATE utility_usage
                    SET usage_amount = ?, old_reading = ?, new_reading = ?, recorded_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$usage_amount, $old_reading, $new_reading, $existing_usage['id']]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO utility_usage (room_id, service_id, month, usage_amount, old_reading, new_reading, recorded_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$room_id, $service_id, $month, $usage_amount, $old_reading, $new_reading]);
            }
        }

        $pdo->commit();

        responseJson([
            'status' => 'success',
            'message' => 'Nhập hàng loạt số điện, nước thành công',
            'data' => ['count' => count($valid_entries)]
        ]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Lỗi nhập hàng loạt số điện, nước: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}
?>
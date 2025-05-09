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
        $conditions[] = 'u.room_id = ?';
        $params[] = $room_id;
    }

    if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
        $conditions[] = 'u.month = ?';
        $params[] = $month;
    }

    if ($branch_id) {
        $conditions[] = 'r.branch_id = ?';
        $params[] = $branch_id;
    }

    // Kiểm tra quyền owner/employee
    if ($role === 'owner' || $role === 'employee') {
        $conditions[] = 'r.branch_id IN (
            SELECT id FROM branches WHERE owner_id = ? OR id IN (
                SELECT branch_id FROM employee_assignments WHERE employee_id = ?
            )
        )';
        $params[] = $user_id;
        $params[] = $user_id;
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
        LIMIT ? OFFSET ?
    ";
    $params[] = $limit;
    $params[] = $offset;

    try {
        // Đếm tổng số bản ghi
        $count_stmt = $pdo->prepare("
            SELECT COUNT(*) FROM utility_usage u
            JOIN rooms r ON u.room_id = r.id
            $where_clause
        ");
        $count_stmt->execute(array_slice($params, 0, -2));
        $total_records = $count_stmt->fetchColumn();
        $total_pages = ceil($total_records / $limit);

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
?>
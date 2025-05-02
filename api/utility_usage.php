<?php
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';

function getUtilityUsage() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    // Phân trang
    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    // Điều kiện lọc
    $conditions = [];
    $params = [];

    // Tìm kiếm theo tháng
    if (!empty($_GET['month'])) {
        $month = sanitizeInput($_GET['month']);
        if (preg_match('/^\d{4}-\d{2}$/', $month)) {
            $conditions[] = "u.month = ?";
            $params[] = $month;
        }
    }

    // Phân quyền
    if ($role === 'admin') {
        // Admin thấy tất cả
    } elseif ($role === 'owner') {
        $conditions[] = "r.branch_id IN (SELECT id FROM branches WHERE owner_id = ?)";
        $params[] = $user_id;
    } elseif ($role === 'employee') {
        $conditions[] = "r.branch_id IN (SELECT branch_id FROM employee_assignments WHERE employee_id = ?)";
        $params[] = $user_id;
    } elseif ($role === 'customer') {
        $conditions[] = "u.room_id IN (
            SELECT ro.room_id FROM room_occupants ro WHERE ro.user_id = ?
        )";
        $params[] = $user_id;
    }

    // Xây dựng truy vấn
    $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
    $query = "
        SELECT u.id, u.room_id, u.service_id, u.month, u.usage_amount, u.custom_price, 
               COALESCE(u.custom_price, s.price) AS effective_price, s.name AS service_name
        FROM utility_usage u
        JOIN rooms r ON u.room_id = r.id
        JOIN services s ON u.service_id = s.id
        $whereClause
        LIMIT $limit OFFSET $offset
    ";

    try {
        // Đếm tổng số bản ghi
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM utility_usage u JOIN rooms r ON u.room_id = r.id JOIN services s ON u.service_id = s.id $whereClause");
        $countStmt->execute($params);
        $totalRecords = $countStmt->fetchColumn();
        $totalPages = ceil($totalRecords / $limit);

        // Truy vấn dữ liệu
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $usage = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        logError("Lỗi cơ sở dữ liệu: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
        return;
    }

    responseJson([
        'status' => 'success',
        'data' => $usage,
        'pagination' => [
            'current_page' => $page,
            'limit' => $limit,
            'total_records' => $totalRecords,
            'total_pages' => $totalPages
        ]
    ]);
}

function getUtilityUsageById() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];
    $usage_id = getResourceIdFromUri('#/utility_usage/([0-9]+)#');

    // Điều kiện phân quyền
    $condition = "";
    $params = [$usage_id];

    if ($role === 'admin') {
        // Admin thấy tất cả
    } elseif ($role === 'owner') {
        $condition = "AND r.branch_id IN (SELECT id FROM branches WHERE owner_id = ?)";
        $params[] = $user_id;
    } elseif ($role === 'employee') {
        $condition = "AND r.branch_id IN (SELECT branch_id FROM employee_assignments WHERE employee_id = ?)";
        $params[] = $user_id;
    } elseif ($role === 'customer') {
        $condition = "AND u.room_id IN (SELECT ro.room_id FROM room_occupants ro WHERE ro.user_id = ?)";
        $params[] = $user_id;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT u.id, u.room_id, u.service_id, u.month, u.usage_amount, u.custom_price, 
                   COALESCE(u.custom_price, s.price) AS effective_price, s.name AS service_name
            FROM utility_usage u
            JOIN rooms r ON u.room_id = r.id
            JOIN services s ON u.service_id = s.id
            WHERE u.id = ? $condition
        ");
        $stmt->execute($params);
        $usage = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$usage) {
            responseJson(['status' => 'error', 'message' => 'Bản ghi sử dụng dịch vụ không tồn tại hoặc bạn không có quyền truy cập'], 404);
            return;
        }

        responseJson(['status' => 'success', 'data' => $usage]);
    } catch (PDOException $e) {
        logError("Lỗi lấy bản ghi sử dụng dịch vụ ID $usage_id: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

function createUtilityUsage() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if ($role !== 'owner' && $role !== 'employee') {
        responseJson(['status' => 'error', 'message' => 'Không có quyền tạo bản ghi sử dụng dịch vụ'], 403);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['room_id', 'service_id', 'month', 'usage_amount']);

    $room_id = (int)$input['room_id'];
    $service_id = (int)$input['service_id'];
    $month = sanitizeInput($input['month']);
    $usage_amount = filter_var($input['usage_amount'], FILTER_VALIDATE_FLOAT);
    $custom_price = isset($input['custom_price']) ? filter_var($input['custom_price'], FILTER_VALIDATE_FLOAT) : null;

    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        responseJson(['status' => 'error', 'message' => 'Định dạng tháng không hợp lệ'], 400);
        return;
    }
    if ($usage_amount === false || $usage_amount < 0) {
        responseJson(['status' => 'error', 'message' => 'Số lượng sử dụng không hợp lệ'], 400);
        return;
    }
    if ($custom_price !== null && ($custom_price === false || $custom_price < 0)) {
        responseJson(['status' => 'error', 'message' => 'Giá tùy chỉnh không hợp lệ'], 400);
        return;
    }

    // Kiểm tra quyền truy cập phòng và dịch vụ
    $branch_id_query = ($role === 'owner') 
        ? "SELECT id FROM branches WHERE owner_id = ? AND id = (SELECT branch_id FROM rooms WHERE id = ?)"
        : "SELECT branch_id FROM employee_assignments WHERE employee_id = ? AND branch_id = (SELECT branch_id FROM rooms WHERE id = ?)";
    $stmt = $pdo->prepare($branch_id_query);
    $stmt->execute([$user_id, $room_id]);
    if (!$stmt->fetch()) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền ghi nhận sử dụng dịch vụ cho phòng này'], 403);
        return;
    }

    // Kiểm tra dịch vụ thuộc chi nhánh
    $stmt = $pdo->prepare("SELECT id FROM services WHERE id = ? AND branch_id = (SELECT branch_id FROM rooms WHERE id = ?)");
    $stmt->execute([$service_id, $room_id]);
    if (!$stmt->fetch()) {
        responseJson(['status' => 'error', 'message' => 'Dịch vụ không thuộc chi nhánh của phòng'], 400);
        return;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO utility_usage (room_id, service_id, month, usage_amount, custom_price)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$room_id, $service_id, $month, $usage_amount, $custom_price]);

        $usage_id = $pdo->lastInsertId();
        createNotification($pdo, $user_id, "Bản ghi sử dụng dịch vụ cho phòng ID $room_id, tháng $month đã được tạo.");
        responseJson(['status' => 'success', 'data' => ['id' => $usage_id]]);
    } catch (PDOException $e) {
        logError("Lỗi tạo bản ghi sử dụng dịch vụ: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function updateUtilityUsage() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];
    $usage_id = getResourceIdFromUri('#/utility_usage/([0-9]+)#');

    if ($role !== 'owner' && $role !== 'employee') {
        responseJson(['status' => 'error', 'message' => 'Không có quyền cập nhật bản ghi sử dụng dịch vụ'], 403);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (empty($input)) {
        responseJson(['status' => 'error', 'message' => 'Không có dữ liệu được cung cấp'], 400);
        return;
    }

    // Kiểm tra quyền truy cập
    $branch_id_query = ($role === 'owner') 
        ? "SELECT r.branch_id FROM utility_usage u JOIN rooms r ON u.room_id = r.id JOIN branches b ON r.branch_id = b.id WHERE u.id = ? AND b.owner_id = ?"
        : "SELECT r.branch_id FROM utility_usage u JOIN rooms r ON u.room_id = r.id JOIN employee_assignments ea ON r.branch_id = ea.branch_id WHERE u.id = ? AND ea.employee_id = ?";
    $stmt = $pdo->prepare($branch_id_query);
    $stmt->execute([$usage_id, $user_id]);
    if (!$stmt->fetch()) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền cập nhật bản ghi này'], 403);
        return;
    }

    $updates = [];
    $params = [];

    if (!empty($input['month']) && preg_match('/^\d{4}-\d{2}$/', $input['month'])) {
        $updates[] = "month = ?";
        $params[] = sanitizeInput($input['month']);
    } elseif (!empty($input['month'])) {
        responseJson(['status' => 'error', 'message' => 'Định dạng tháng không hợp lệ'], 400);
        return;
    }

    if (isset($input['usage_amount'])) {
        $usage_amount = filter_var($input['usage_amount'], FILTER_VALIDATE_FLOAT);
        if ($usage_amount === false || $usage_amount < 0) {
            responseJson(['status' => 'error', 'message' => 'Số lượng sử dụng không hợp lệ'], 400);
            return;
        }
        $updates[] = "usage_amount = ?";
        $params[] = $usage_amount;
    }

    if (isset($input['custom_price'])) {
        $custom_price = filter_var($input['custom_price'], FILTER_VALIDATE_FLOAT);
        if ($custom_price !== null && ($custom_price === false || $custom_price < 0)) {
            responseJson(['status' => 'error', 'message' => 'Giá tùy chỉnh không hợp lệ'], 400);
            return;
        }
        $updates[] = "custom_price = ?";
        $params[] = $custom_price;
    }

    if (empty($updates)) {
        responseJson(['status' => 'error', 'message' => 'Không có trường nào để cập nhật'], 400);
        return;
    }

    try {
        checkResourceExists($pdo, 'utility_usage', $usage_id);
        $query = "UPDATE utility_usage SET " . implode(', ', $updates) . " WHERE id = ?";
        $params[] = $usage_id;
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);

        createNotification($pdo, $user_id, "Bản ghi sử dụng dịch vụ ID $usage_id đã được cập nhật.");
        responseJson(['status' => 'success', 'message' => 'Cập nhật bản ghi thành công']);
    } catch (Exception $e) {
        logError("Lỗi cập nhật bản ghi sử dụng dịch vụ ID $usage_id: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function patchUtilityUsage() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];
    $usage_id = getResourceIdFromUri('#/utility_usage/([0-9]+)#');

    if ($role !== 'owner' && $role !== 'employee') {
        responseJson(['status' => 'error', 'message' => 'Không có quyền cập nhật bản ghi sử dụng dịch vụ'], 403);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (empty($input)) {
        responseJson(['status' => 'error', 'message' => 'Không có dữ liệu được cung cấp'], 400);
        return;
    }

    // Kiểm tra quyền truy cập
    $branch_id_query = ($role === 'owner') 
        ? "SELECT r.branch_id FROM utility_usage u JOIN rooms r ON u.room_id = r.id JOIN branches b ON r.branch_id = b.id WHERE u.id = ? AND b.owner_id = ?"
        : "SELECT r.branch_id FROM utility_usage u JOIN rooms r ON u.room_id = r.id JOIN employee_assignments ea ON r.branch_id = ea.branch_id WHERE u.id = ? AND ea.employee_id = ?";
    $stmt = $pdo->prepare($branch_id_query);
    $stmt->execute([$usage_id, $user_id]);
    if (!$stmt->fetch()) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền cập nhật bản ghi này'], 403);
        return;
    }

    $updates = [];
    $params = [];

    if (!empty($input['month']) && preg_match('/^\d{4}-\d{2}$/', $input['month'])) {
        $updates[] = "month = ?";
        $params[] = sanitizeInput($input['month']);
    } elseif (!empty($input['month'])) {
        responseJson(['status' => 'error', 'message' => 'Định dạng tháng không hợp lệ'], 400);
        return;
    }

    if (isset($input['usage_amount'])) {
        $usage_amount = filter_var($input['usage_amount'], FILTER_VALIDATE_FLOAT);
        if ($usage_amount === false || $usage_amount < 0) {
            responseJson(['status' => 'error', 'message' => 'Số lượng sử dụng không hợp lệ'], 400);
            return;
        }
        $updates[] = "usage_amount = ?";
        $params[] = $usage_amount;
    }

    if (isset($input['custom_price'])) {
        $custom_price = filter_var($input['custom_price'], FILTER_VALIDATE_FLOAT);
        if ($custom_price !== null && ($custom_price === false || $custom_price < 0)) {
            responseJson(['status' => 'error', 'message' => 'Giá tùy chỉnh không hợp lệ'], 400);
            return;
        }
        $updates[] = "custom_price = ?";
        $params[] = $custom_price;
    }

    if (empty($updates)) {
        responseJson(['status' => 'error', 'message' => 'Không có trường nào để cập nhật'], 400);
        return;
    }

    try {
        checkResourceExists($pdo, 'utility_usage', $usage_id);
        $query = "UPDATE utility_usage SET " . implode(', ', $updates) . " WHERE id = ?";
        $params[] = $usage_id;
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);

        createNotification($pdo, $user_id, "Bản ghi sử dụng dịch vụ ID $usage_id đã được cập nhật một phần.");
        responseJson(['status' => 'success', 'message' => 'Cập nhật bản ghi thành công']);
    } catch (Exception $e) {
        logError("Lỗi patch bản ghi sử dụng dịch vụ ID $usage_id: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function deleteUtilityUsage() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];
    $usage_id = getResourceIdFromUri('#/utility_usage/([0-9]+)#');

    if ($role !== 'owner') {
        responseJson(['status' => 'error', 'message' => 'Không có quyền xóa bản ghi sử dụng dịch vụ'], 403);
        return;
    }

    // Kiểm tra quyền truy cập
    $stmt = $pdo->prepare("
        SELECT r.branch_id 
        FROM utility_usage u 
        JOIN rooms r ON u.room_id = r.id 
        JOIN branches b ON r.branch_id = b.id 
        WHERE u.id = ? AND b.owner_id = ?
    ");
    $stmt->execute([$usage_id, $user_id]);
    if (!$stmt->fetch()) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền xóa bản ghi này'], 403);
        return;
    }

    try {
        checkResourceExists($pdo, 'utility_usage', $usage_id);
        $stmt = $pdo->prepare("DELETE FROM utility_usage WHERE id = ?");
        $stmt->execute([$usage_id]);

        createNotification($pdo, $user_id, "Bản ghi sử dụng dịch vụ ID $usage_id đã được xóa.");
        responseJson(['status' => 'success', 'message' => 'Xóa bản ghi thành công']);
    } catch (Exception $e) {
        logError("Lỗi xóa bản ghi sử dụng dịch vụ ID $usage_id: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}
?>
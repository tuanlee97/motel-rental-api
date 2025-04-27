<?php
require_once __DIR__ . '/../../core/database.php';
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/common.php';

function getServices() {
    $pdo = getDB();
    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $conditions = [];
    $params = [];

    if (!empty($_GET['search'])) {
        $search = '%' . sanitizeInput($_GET['search']) . '%';
        $conditions[] = "(s.name LIKE ? OR s.description LIKE ?)";
        $params[] = $search;
        $params[] = $search;
    }

    $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
    $query = "SELECT s.* FROM services s $whereClause";

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM services s $whereClause");
    $countStmt->execute($params);
    $totalRecords = $countStmt->fetchColumn();
    $totalPages = ceil($totalRecords / $limit);

    $query .= " LIMIT $limit OFFSET $offset"; 

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $services = $stmt->fetchAll();

    responseJson([
        'status' => 'success',
        'data' => $services,
        'pagination' => [
            'current_page' => $page,
            'limit' => $limit,
            'total_records' => $totalRecords,
            'total_pages' => $totalPages
        ]
    ]);
}

function createService() {
    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['name', 'default_price', 'unit']);
    $user = verifyJWT();

    $name = sanitizeInput($input['name']);
    $description = !empty($input['description']) ? sanitizeInput($input['description']) : null;
    $defaultPrice = filter_var($input['default_price'], FILTER_VALIDATE_FLOAT);
    $unit = sanitizeInput($input['unit']);

    if (!$defaultPrice || $defaultPrice < 0) {
        responseJson(['status' => 'error', 'message' => 'Dữ liệu không hợp lệ'], 400);
    }

    $pdo = getDB();
    try {
        if ($user['role'] !== 'admin') {
            responseJson(['status' => 'error', 'message' => 'Chỉ admin có quyền tạo dịch vụ'], 403);
        }

        $stmt = $pdo->prepare("SELECT id FROM services WHERE name = ?");
        $stmt->execute([$name]);
        if ($stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Tên dịch vụ đã tồn tại'], 409);
        }

        $stmt = $pdo->prepare("
            INSERT INTO services (name, description, default_price, unit)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$name, $description, $defaultPrice, $unit]);

        $serviceId = $pdo->lastInsertId();
        createNotification($pdo, $user['user_id'], "Dịch vụ ID $serviceId đã được tạo.");
        responseJson(['status' => 'success', 'data' => ['service_id' => $serviceId]]);
    } catch (Exception $e) {
        logError('Lỗi tạo service: ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function getServiceById() {
    $serviceId = getResourceIdFromUri('#/services/([0-9]+)#');
    $pdo = getDB();
    try {
        $stmt = $pdo->prepare("SELECT id, name, description, default_price, unit FROM services WHERE id = ?");
        $stmt->execute([$serviceId]);
        $service = $stmt->fetch();

        if (!$service) {
            responseJson(['status' => 'error', 'message' => 'Không tìm thấy dịch vụ'], 404);
        }
        responseJson(['status' => 'success', 'data' => $service]);
    } catch (Exception $e) {
        logError('Lỗi lấy service ID ' . $serviceId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi truy vấn'], 500);
    }
}

function updateService() {
    $serviceId = getResourceIdFromUri('#/services/([0-9]+)#');
    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['name', 'default_price', 'unit']);
    $user = verifyJWT();

    $name = sanitizeInput($input['name']);
    $description = !empty($input['description']) ? sanitizeInput($input['description']) : null;
    $defaultPrice = filter_var($input['default_price'], FILTER_VALIDATE_FLOAT);
    $unit = sanitizeInput($input['unit']);

    if (!$defaultPrice || $defaultPrice < 0) {
        responseJson(['status' => 'error', 'message' => 'Dữ liệu không hợp lệ'], 400);
    }

    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'services', $serviceId);
        if ($user['role'] !== 'admin') {
            responseJson(['status' => 'error', 'message' => 'Chỉ admin có quyền chỉnh sửa dịch vụ'], 403);
        }

        $stmt = $pdo->prepare("SELECT id FROM services WHERE name = ? AND id != ?");
        $stmt->execute([$name, $serviceId]);
        if ($stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Tên dịch vụ đã tồn tại'], 409);
        }

        $stmt = $pdo->prepare("
            UPDATE services SET name = ?, description = ?, default_price = ?, unit = ?
            WHERE id = ?
        ");
        $stmt->execute([$name, $description, $defaultPrice, $unit, $serviceId]);

        createNotification($pdo, $user['user_id'], "Dịch vụ ID $serviceId đã được cập nhật.");
        responseJson(['status' => 'success', 'message' => 'Cập nhật dịch vụ thành công']);
    } catch (Exception $e) {
        logError('Lỗi cập nhật service ID ' . $serviceId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function patchService() {
    $serviceId = getResourceIdFromUri('#/services/([0-9]+)#');
    $input = json_decode(file_get_contents('php://input'), true);
    $user = verifyJWT();

    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'services', $serviceId);
        if ($user['role'] !== 'admin') {
            responseJson(['status' => 'error', 'message' => 'Chỉ admin có quyền chỉnh sửa dịch vụ'], 403);
        }

        $updates = [];
        $params = [];
        if (!empty($input['name'])) {
            $name = sanitizeInput($input['name']);
            $stmt = $pdo->prepare("SELECT id FROM services WHERE name = ? AND id != ?");
            $stmt->execute([$name, $serviceId]);
            if ($stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Tên dịch vụ đã tồn tại'], 409);
            }
            $updates[] = "name = ?";
            $params[] = $name;
        }
        if (isset($input['description'])) {
            $updates[] = "description = ?";
            $params[] = sanitizeInput($input['description']);
        }
        if (isset($input['default_price'])) {
            $defaultPrice = filter_var($input['default_price'], FILTER_VALIDATE_FLOAT);
            if ($defaultPrice === false || $defaultPrice < 0) {
                responseJson(['status' => 'error', 'message' => 'Giá mặc định không hợp lệ'], 400);
            }
            $updates[] = "default_price = ?";
            $params[] = $defaultPrice;
        }
        if (!empty($input['unit'])) {
            $updates[] = "unit = ?";
            $params[] = sanitizeInput($input['unit']);
        }

        if (empty($updates)) {
            responseJson(['status' => 'error', 'message' => 'Không có dữ liệu để cập nhật'], 400);
        }

        $query = "UPDATE services SET " . implode(', ', $updates) . " WHERE id = ?";
        $params[] = $serviceId;
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);

        createNotification($pdo, $user['user_id'], "Dịch vụ ID $serviceId đã được cập nhật.");
        responseJson(['status' => 'success', 'message' => 'Cập nhật dịch vụ thành công']);
    } catch (Exception $e) {
        logError('Lỗi patch service ID ' . $serviceId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function deleteService() {
    $serviceId = getResourceIdFromUri('#/services/([0-9]+)#');
    $user = verifyJWT();
    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'services', $serviceId);
        if ($user['role'] !== 'admin') {
            responseJson(['status' => 'error', 'message' => 'Chỉ admin có quyền xóa dịch vụ'], 403);
        }

        $stmt = $pdo->prepare("SELECT id FROM utility_usage WHERE service_id = ?");
        $stmt->execute([$serviceId]);
        if ($stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Không thể xóa dịch vụ vì đã có bản ghi sử dụng'], 409);
        }

        $stmt = $pdo->prepare("DELETE FROM services WHERE id = ?");
        $stmt->execute([$serviceId]);
        responseJson(['status' => 'success', 'message' => 'Xóa dịch vụ thành công']);
    } catch (Exception $e) {
        logError('Lỗi xóa service ID ' . $serviceId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}
?>
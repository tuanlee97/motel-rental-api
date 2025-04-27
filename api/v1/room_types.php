<?php
require_once __DIR__ . '/common.php';

function getRoomTypes() {
    $pdo = getDB();

    // Phân trang
    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    // Điều kiện lọc
    $conditions = [];
    $params = [];

    if (!empty($_GET['min_price']) && filter_var($_GET['min_price'], FILTER_VALIDATE_FLOAT)) {
        $conditions[] = "default_price >= ?";
        $params[] = $_GET['min_price'];
    }
    if (!empty($_GET['max_price']) && filter_var($_GET['max_price'], FILTER_VALIDATE_FLOAT)) {
        $conditions[] = "default_price <= ?";
        $params[] = $_GET['max_price'];
    }

    // Tìm kiếm
    if (!empty($_GET['search'])) {
        $search = '%' . sanitizeInput($_GET['search']) . '%';
        $conditions[] = "(name LIKE ? OR description LIKE ?)";
        $params[] = $search;
        $params[] = $search;
    }

    // Xây dựng truy vấn
    $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
    $query = "SELECT * FROM room_types $whereClause";

    // Đếm tổng số bản ghi
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM room_types $whereClause");
    $countStmt->execute($params);
    $totalRecords = $countStmt->fetchColumn();
    $totalPages = ceil($totalRecords / $limit);

    // Truy vấn dữ liệu với phân trang
    $query .= " LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $roomTypes = $stmt->fetchAll();

    // Trả về phản hồi với thông tin phân trang
    responseJson([
        'status' => 'success',
        'data' => $roomTypes,
        'pagination' => [
            'current_page' => $page,
            'limit' => $limit,
            'total_records' => $totalRecords,
            'total_pages' => $totalPages
        ]
    ]);
}

function createRoomType() {
    $pdo = getDB();
    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['name', 'default_price']);

    $name = sanitizeInput($input['name']);
    $description = sanitizeInput($input['description'] ?? '');
    $defaultPrice = filter_var($input['default_price'], FILTER_VALIDATE_FLOAT);

    if ($defaultPrice < 0) {
        responseJson(['status' => 'error', 'message' => 'Giá mặc định không hợp lệ'], 400);
    }

    $stmt = $pdo->prepare("
        INSERT INTO room_types (name, description, default_price)
        VALUES (?, ?, ?)
    ");
    $stmt->execute([$name, $description, $defaultPrice]);
    responseJson(['status' => 'success', 'message' => 'Tạo loại phòng thành công']);
}

function getRoomTypeById() {
    $pdo = getDB();
    $id = getResourceIdFromUri('#^/api/v1/room_types/([0-9]+)$#');
    checkResourceExists($pdo, 'room_types', $id);

    $stmt = $pdo->prepare("SELECT * FROM room_types WHERE id = ?");
    $stmt->execute([$id]);
    $roomType = $stmt->fetch();
    responseJson(['status' => 'success', 'data' => $roomType]);
}

function updateRoomType() {
    $pdo = getDB();
    $id = getResourceIdFromUri('#^/api/v1/room_types/([0-9]+)$#');
    checkResourceExists($pdo, 'room_types', $id);

    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['name', 'default_price']);

    $name = sanitizeInput($input['name']);
    $description = sanitizeInput($input['description'] ?? '');
    $defaultPrice = filter_var($input['default_price'], FILTER_VALIDATE_FLOAT);

    if ($defaultPrice < 0) {
        responseJson(['status' => 'error', 'message' => 'Giá mặc định không hợp lệ'], 400);
    }

    $stmt = $pdo->prepare("
        UPDATE room_types
        SET name = ?, description = ?, default_price = ?
        WHERE id = ?
    ");
    $stmt->execute([$name, $description, $defaultPrice, $id]);
    responseJson(['status' => 'success', 'message' => 'Cập nhật loại phòng thành công']);
}

function patchRoomType() {
    $pdo = getDB();
    $id = getResourceIdFromUri('#^/api/v1/room_types/([0-9]+)$#');
    checkResourceExists($pdo, 'room_types', $id);

    $input = json_decode(file_get_contents('php://input'), true);
    $allowedFields = ['name', 'description', 'default_price'];
    $updates = [];
    $params = [];

    foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
            $updates[] = "$field = ?";
            if ($field === 'default_price') {
                $params[] = filter_var($input[$field], FILTER_VALIDATE_FLOAT);
            } else {
                $params[] = sanitizeInput($input[$field]);
            }
        }
    }

    if (empty($updates)) {
        responseJson(['status' => 'error', 'message' => 'Không có trường nào để cập nhật'], 400);
    }

    if (isset($input['default_price']) && $input['default_price'] < 0) {
        responseJson(['status' => 'error', 'message' => 'Giá mặc định không hợp lệ'], 400);
    }

    $params[] = $id;
    $query = "UPDATE room_types SET " . implode(', ', $updates) . " WHERE id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);

    if ($stmt->rowCount() > 0) {
        responseJson(['status' => 'success', 'message' => 'Cập nhật loại phòng thành công']);
    } else {
        responseJson(['status' => 'success', 'message' => 'Không có thay đổi']);
    }
}

function deleteRoomType() {
    $pdo = getDB();
    $id = getResourceIdFromUri('#^/api/v1/room_types/([0-9]+)$#');
    checkResourceExists($pdo, 'room_types', $id);

    try {
        $stmt = $pdo->prepare("DELETE FROM room_types WHERE id = ?");
        $stmt->execute([$id]);
        responseJson(['status' => 'success', 'message' => 'Xóa loại phòng thành công']);
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            responseJson(['status' => 'error', 'message' => 'Không thể xóa loại phòng vì có phòng đang sử dụng'], 409);
        }
        throw $e;
    }
}
?>
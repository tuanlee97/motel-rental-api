<?php
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/utils/common.php';

function getRooms() {
    $pdo = getDB();

    // Phân trang
    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    // Điều kiện lọc
    $conditions = [];
    $params = [];

    if (!empty($_GET['status']) && in_array($_GET['status'], ['available', 'occupied', 'maintenance'])) {
        $conditions[] = "r.status = ?";
        $params[] = $_GET['status'];
    }
    if (!empty($_GET['branch_id']) && filter_var($_GET['branch_id'], FILTER_VALIDATE_INT)) {
        $conditions[] = "r.branch_id = ?";
        $params[] = $_GET['branch_id'];
    }
    if (!empty($_GET['type_id']) && filter_var($_GET['type_id'], FILTER_VALIDATE_INT)) {
        $conditions[] = "r.type_id = ?";
        $params[] = $_GET['type_id'];
    }
    if (!empty($_GET['min_price']) && filter_var($_GET['min_price'], FILTER_VALIDATE_FLOAT)) {
        $conditions[] = "r.price >= ?";
        $params[] = $_GET['min_price'];
    }
    if (!empty($_GET['max_price']) && filter_var($_GET['max_price'], FILTER_VALIDATE_FLOAT)) {
        $conditions[] = "r.price <= ?";
        $params[] = $_GET['max_price'];
    }

    // Tìm kiếm
    if (!empty($_GET['search'])) {
        $search = '%' . sanitizeInput($_GET['search']) . '%';
        $conditions[] = "(r.name LIKE ? OR r.description LIKE ?)";
        $params[] = $search;
        $params[] = $search;
    }

    // Xây dựng truy vấn
    $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
    $query = "
        SELECT r.*, b.name AS branch_name, rt.name AS type_name 
        FROM rooms r 
        JOIN branches b ON r.branch_id = b.id 
        JOIN room_types rt ON r.type_id = rt.id 
        $whereClause
    ";

    // Đếm tổng số bản ghi
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM rooms r $whereClause");
    $countStmt->execute($params);
    $totalRecords = $countStmt->fetchColumn();
    $totalPages = ceil($totalRecords / $limit);

    // Truy vấn dữ liệu với phân trang
    $query .= " LIMIT $limit OFFSET $offset"; 

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $rooms = $stmt->fetchAll();

    // Trả về phản hồi với thông tin phân trang
    responseJson([
        'status' => 'success',
        'data' => $rooms,
        'pagination' => [
            'current_page' => $page,
            'limit' => $limit,
            'total_records' => $totalRecords,
            'total_pages' => $totalPages
        ]
    ]);
}


function createRoom() {
    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['branch_id', 'type_id', 'name', 'price']);
    $user = verifyJWT();
    $branchId = filter_var($input['branch_id'], FILTER_VALIDATE_INT);
    $typeId = filter_var($input['type_id'], FILTER_VALIDATE_INT);
    $name = sanitizeInput($input['name']);
    $price = filter_var($input['price'], FILTER_VALIDATE_FLOAT);
    $status = in_array($input['status'] ?? 'available', ['available', 'occupied', 'maintenance']) ? $input['status'] : 'available';

    if (!$branchId || !$typeId || !$price) {
        responseJson(['status' => 'error', 'message' => 'Dữ liệu không hợp lệ'], 400);
    }

    $pdo = getDB();
    try {
        $stmt = $pdo->prepare("SELECT id FROM branches WHERE id = ? AND owner_id = ?");
        $stmt->execute([$branchId, $user['user_id']]);
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Chi nhánh không hợp lệ hoặc bạn không có quyền'], 403);
        }

        $stmt = $pdo->prepare("SELECT id FROM room_types WHERE id = ?");
        $stmt->execute([$typeId]);
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Loại phòng không hợp lệ'], 400);
        }

        $stmt = $pdo->prepare("
            INSERT INTO rooms (branch_id, type_id, name, price, status)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$branchId, $typeId, $name, $price, $status]);

        $roomId = $pdo->lastInsertId();
        responseJson(['status' => 'success', 'data' => ['room_id' => $roomId]]);
    } catch (Exception $e) {
        logError('Lỗi tạo room: ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function getRoomById() {
    $roomId = getResourceIdFromUri('#/rooms/([0-9]+)#');
    $pdo = getDB();
    try {
        $stmt = $pdo->prepare("
            SELECT r.id, r.branch_id, r.type_id, r.name, r.price, r.status, r.created_at, 
                   b.name AS branch_name, rt.name AS type_name
            FROM rooms r
            JOIN branches b ON r.branch_id = b.id
            JOIN room_types rt ON r.type_id = rt.id
            WHERE r.id = ?
        ");
        $stmt->execute([$roomId]);
        $room = $stmt->fetch();

        if (!$room) {
            responseJson(['status' => 'error', 'message' => 'Không tìm thấy phòng'], 404);
        }
        responseJson(['status' => 'success', 'data' => $room]);
    } catch (Exception $e) {
        logError('Lỗi lấy room ID ' . $roomId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi truy vấn'], 500);
    }
}

function updateRoom() {
    $roomId = getResourceIdFromUri('#/rooms/([0-9]+)#');
    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['branch_id', 'type_id', 'name', 'price']);

    $user = verifyJWT();
    $branchId = filter_var($input['branch_id'], FILTER_VALIDATE_INT);
    $typeId = filter_var($input['type_id'], FILTER_VALIDATE_INT);
    $name = sanitizeInput($input['name']);
    $price = filter_var($input['price'], FILTER_VALIDATE_FLOAT);
    $status = in_array($input['status'] ?? 'available', ['available', 'occupied', 'maintenance']) ? $input['status'] : 'available';

    if (!$branchId || !$typeId || !$price) {
        responseJson(['status' => 'error', 'message' => 'Dữ liệu không hợp lệ'], 400);
    }

    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'rooms', $roomId);
        $stmt = $pdo->prepare("SELECT b.id FROM branches b JOIN rooms r ON r.branch_id = b.id WHERE r.id = ? AND b.owner_id = ?");
        $stmt->execute([$roomId, $user['user_id']]);
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Phòng không hợp lệ hoặc bạn không có quyền'], 403);
        }

        $stmt = $pdo->prepare("SELECT id FROM room_types WHERE id = ?");
        $stmt->execute([$typeId]);
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Loại phòng không hợp lệ'], 400);
        }

        $stmt = $pdo->prepare("
            UPDATE rooms SET branch_id = ?, type_id = ?, name = ?, price = ?, status = ?
            WHERE id = ?
        ");
        $stmt->execute([$branchId, $typeId, $name, $price, $status, $roomId]);

        responseJson(['status' => 'success', 'message' => 'Cập nhật phòng thành công']);
    } catch (Exception $e) {
        logError('Lỗi cập nhật room ID ' . $roomId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function patchRoom() {
    $roomId = getResourceIdFromUri('#/rooms/([0-9]+)#');
    $input = json_decode(file_get_contents('php igény://input'), true);
    $user = verifyJWT();

    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'rooms', $roomId);
        $stmt = $pdo->prepare("SELECT b.id FROM branches b JOIN rooms r ON r.branch_id = b.id WHERE r.id = ? AND b.owner_id = ?");
        $stmt->execute([$roomId, $user['user_id']]);
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Phòng không hợp lệ hoặc bạn không có quyền'], 403);
        }

        $updates = [];
        $params = [];
        if (!empty($input['branch_id'])) {
            $branchId = filter_var($input['branch_id'], FILTER_VALIDATE_INT);
            if (!$branchId) {
                responseJson(['status' => 'error', 'message' => 'Branch ID không hợp lệ'], 400);
            }
            $stmt = $pdo->prepare("SELECT id FROM branches WHERE id = ? AND owner_id = ?");
            $stmt->execute([$branchId, $user['user_id']]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Chi nhánh không hợp lệ'], 400);
            }
            $updates[] = "branch_id = ?";
            $params[] = $branchId;
        }
        if (!empty($input['type_id'])) {
            $typeId = filter_var($input['type_id'], FILTER_VALIDATE_INT);
            if (!$typeId) {
                responseJson(['status' => 'error', 'message' => 'Type ID không hợp lệ'], 400);
            }
            $stmt = $pdo->prepare("SELECT id FROM room_types WHERE id = ?");
            $stmt->execute([$typeId]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Loại phòng không hợp lệ'], 400);
            }
            $updates[] = "type_id = ?";
            $params[] = $typeId;
        }
        if (!empty($input['name'])) {
            $updates[] = "name = ?";
            $params[] = sanitizeInput($input['name']);
        }
        if (isset($input['price'])) {
            $price = filter_var($input['price'], FILTER_VALIDATE_FLOAT);
            if ($price === false) {
                responseJson(['status' => 'error', 'message' => 'Giá không hợp lệ'], 400);
            }
            $updates[] = "price = ?";
            $params[] = $price;
        }
        if (!empty($input['status'])) {
            $status = in_array($input['status'], ['available', 'occupied', 'maintenance']) ? $input['status'] : 'available';
            $updates[] = "status = ?";
            $params[] = $status;
        }

        if (empty($updates)) {
            responseJson(['status' => 'error', 'message' => 'Không có dữ liệu để cập nhật'], 400);
        }

        $query = "UPDATE rooms SET " . implode(', ', $updates) . " WHERE id = ?";
        $params[] = $roomId;
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);

        responseJson(['status' => 'success', 'message' => 'Cập nhật phòng thành công']);
    } catch (Exception $e) {
        logError('Lỗi patch room ID ' . $roomId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function deleteRoom() {
    $roomId = getResourceIdFromUri('#/rooms/([0-9]+)#');
    $user = verifyJWT();
    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'rooms', $roomId);
        $stmt = $pdo->prepare("SELECT b.id FROM branches b JOIN rooms r ON r.branch_id = b.id WHERE r.id = ? AND b.owner_id = ?");
        $stmt->execute([$roomId, $user['user_id']]);
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Phòng không hợp lệ hoặc bạn không có quyền'], 403);
        }

        $stmt = $pdo->prepare("DELETE FROM rooms WHERE id = ?");
        $stmt->execute([$roomId]);
        responseJson(['status' => 'success', 'message' => 'Xóa phòng thành công']);
    } catch (Exception $e) {
        logError('Lỗi xóa room ID ' . $roomId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}
?>
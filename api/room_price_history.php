<?php
require_once __DIR__ . '/utils/common.php';

function validateDate($date) {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) {
        responseJson(['status' => 'error', 'message' => 'Ngày không hợp lệ, định dạng phải là YYYY-MM-DD'], 400);
    }
    return $date;
}

function getRoomPriceHistory() {
    $pdo = getDB();
    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $conditions = [];
    $params = [];

    if (!empty($_GET['room_id']) && filter_var($_GET['room_id'], FILTER_VALIDATE_INT)) {
        $conditions[] = "rph.room_id = ?";
        $params[] = $_GET['room_id'];
    }
    if (!empty($_GET['start_date'])) {
        $conditions[] = "rph.change_date >= ?";
        $params[] = validateDate($_GET['start_date']);
    }
    if (!empty($_GET['end_date'])) {
        $conditions[] = "rph.change_date <= ?";
        $params[] = validateDate($_GET['end_date']);
    }

    $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
    $query = "
        SELECT rph.*, r.name AS room_name
        FROM room_price_history rph
        LEFT JOIN rooms r ON rph.room_id = r.id
        $whereClause
    ";

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM room_price_history rph $whereClause");
    $countStmt->execute($params);
    $totalRecords = $countStmt->fetchColumn();
    $totalPages = ceil($totalRecords / $limit);

    $query .= " LIMIT $limit OFFSET $offset"; 

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $history = $stmt->fetchAll();

    responseJson([
        'status' => 'success',
        'data' => $history,
        'pagination' => [
            'current_page' => $page,
            'limit' => $limit,
            'total_records' => $totalRecords,
            'total_pages' => $totalPages
        ]
    ]);
}


function createRoomPriceHistory() {
    $pdo = getDB();
    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['room_id', 'price', 'effective_date']);

    $roomId = filter_var($input['room_id'], FILTER_VALIDATE_INT);
    $price = filter_var($input['price'], FILTER_VALIDATE_FLOAT);
    $effectiveDate = validateDate($input['effective_date']);

    if (!$roomId || $price < 0) {
        responseJson(['status' => 'error', 'message' => 'Dữ liệu không hợp lệ'], 400);
    }

    checkResourceExists($pdo, 'rooms', $roomId);

    $stmt = $pdo->prepare("
        INSERT INTO room_price_history (room_id, price, effective_date)
        VALUES (?, ?, ?)
    ");
    $stmt->execute([$roomId, $price, $effectiveDate]);

    $stmt = $pdo->prepare("SELECT owner_id FROM branches WHERE id = (SELECT branch_id FROM rooms WHERE id = ?)");
    $stmt->execute([$roomId]);
    $ownerId = $stmt->fetchColumn();
    createNotification($pdo, $ownerId, "Giá phòng ID $roomId đã được cập nhật: $price từ ngày $effectiveDate");
    responseJson(['status' => 'success', 'message' => 'Thêm lịch sử giá thành công']);
}

function getRoomPriceHistoryById() {
    $pdo = getDB();
    $id = getResourceIdFromUri('#^/api/v1/room_price_history/([0-9]+)$#');
    checkResourceExists($pdo, 'room_price_history', $id);

    $stmt = $pdo->prepare("
        SELECT rph.*, r.name AS room_name, b.name AS branch_name
        FROM room_price_history rph
        JOIN rooms r ON rph.room_id = r.id
        JOIN branches b ON r.branch_id = b.id
        WHERE rph.id = ?
    ");
    $stmt->execute([$id]);
    $history = $stmt->fetch();
    responseJson(['status' => 'success', 'data' => $history]);
}

function updateRoomPriceHistory() {
    $pdo = getDB();
    $id = getResourceIdFromUri('#^/api/v1/room_price_history/([0-9]+)$#');
    checkResourceExists($pdo, 'room_price_history', $id);

    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['room_id', 'price', 'effective_date']);

    $roomId = filter_var($input['room_id'], FILTER_VALIDATE_INT);
    $price = filter_var($input['price'], FILTER_VALIDATE_FLOAT);
    $effectiveDate = validateDate($input['effective_date']);

    if (!$roomId || $price < 0) {
        responseJson(['status' => 'error', 'message' => 'Dữ liệu không hợp lệ'], 400);
    }

    checkResourceExists($pdo, 'rooms', $roomId);

    $stmt = $pdo->prepare("
        UPDATE room_price_history
        SET room_id = ?, price = ?, effective_date = ?
        WHERE id = ?
    ");
    $stmt->execute([$roomId, $price, $effectiveDate, $id]);

    $stmt = $pdo->prepare("SELECT owner_id FROM branches WHERE id = (SELECT branch_id FROM rooms WHERE id = ?)");
    $stmt->execute([$roomId]);
    $ownerId = $stmt->fetchColumn();
    createNotification($pdo, $ownerId, "Lịch sử giá phòng ID $roomId đã được cập nhật: $price từ ngày $effectiveDate");
    responseJson(['status' => 'success', 'message' => 'Cập nhật lịch sử giá thành công']);
}

function patchRoomPriceHistory() {
    $pdo = getDB();
    $id = getResourceIdFromUri('#^/api/v1/room_price_history/([0-9]+)$#');
    checkResourceExists($pdo, 'room_price_history', $id);

    $input = json_decode(file_get_contents('php://input'), true);
    $allowedFields = ['room_id', 'price', 'effective_date'];
    $updates = [];
    $params = [];

    foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
            $updates[] = "$field = ?";
            if ($field === 'effective_date') {
                $params[] = validateDate($input[$field]);
            } elseif ($field === 'price') {
                $params[] = filter_var($input[$field], FILTER_VALIDATE_FLOAT);
            } else {
                $params[] = filter_var($input[$field], FILTER_VALIDATE_INT);
            }
        }
    }

    if (empty($updates)) {
        responseJson(['status' => 'error', 'message' => 'Không có trường nào để cập nhật'], 400);
    }

    if (isset($input['room_id'])) {
        checkResourceExists($pdo, 'rooms', $input['room_id']);
    }

    if (isset($input['price']) && $input['price'] < 0) {
        responseJson(['status' => 'error', 'message' => 'Giá không hợp lệ'], 400);
    }

    $params[] = $id;
    $query = "UPDATE room_price_history SET " . implode(', ', $updates) . " WHERE id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);

    if ($stmt->rowCount() > 0) {
        $roomId = $input['room_id'] ?? $pdo->query("SELECT room_id FROM room_price_history WHERE id = $id")->fetchColumn();
        $stmt = $pdo->prepare("SELECT owner_id FROM branches WHERE id = (SELECT branch_id FROM rooms WHERE id = ?)");
        $stmt->execute([$roomId]);
        $ownerId = $stmt->fetchColumn();
        createNotification($pdo, $ownerId, "Lịch sử giá phòng ID $roomId đã được cập nhật");
        responseJson(['status' => 'success', 'message' => 'Cập nhật lịch sử giá thành công']);
    } else {
        responseJson(['status' => 'success', 'message' => 'Không có thay đổi']);
    }
}

function deleteRoomPriceHistory() {
    $pdo = getDB();
    $id = getResourceIdFromUri('#^/api/v1/room_price_history/([0-9]+)$#');
    checkResourceExists($pdo, 'room_price_history', $id);

    $stmt = $pdo->prepare("SELECT room_id FROM room_price_history WHERE id = ?");
    $stmt->execute([$id]);
    $roomId = $stmt->fetchColumn();

    $stmt = $pdo->prepare("DELETE FROM room_price_history WHERE id = ?");
    $stmt->execute([$id]);

    $stmt = $pdo->prepare("SELECT owner_id FROM branches WHERE id = (SELECT branch_id FROM rooms WHERE id = ?)");
    $stmt->execute([$roomId]);
    $ownerId = $stmt->fetchColumn();
    createNotification($pdo, $ownerId, "Lịch sử giá phòng ID $roomId đã bị xóa");
    responseJson(['status' => 'success', 'message' => 'Xóa lịch sử giá thành công']);
}
?>
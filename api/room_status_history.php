<?php
require_once __DIR__ . '/utils/common.php';

function validateDate($date) {
    $d = DateTime::createFromFormat('Y-m-d H:i:s', $date);
    if (!$d || $d->format('Y-m-d H:i:s') !== $date) {
        responseJson(['status' => 'error', 'message' => 'Ngày không hợp lệ, định dạng phải là YYYY-MM-DD HH:MM:SS'], 400);
    }
    return $date;
}

function getRoomStatusHistory() {
    $pdo = getDB();
    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $conditions = [];
    $params = [];

    if (!empty($_GET['room_id']) && filter_var($_GET['room_id'], FILTER_VALIDATE_INT)) {
        $conditions[] = "rsh.room_id = ?";
        $params[] = $_GET['room_id'];
    }
    if (!empty($_GET['status']) && in_array($_GET['status'], ['available', 'occupied', 'maintenance'])) {
        $conditions[] = "rsh.status = ?";
        $params[] = $_GET['status'];
    }
    if (!empty($_GET['start_date'])) {
        $conditions[] = "rsh.change_date >= ?";
        $params[] = validateDate($_GET['start_date']);
    }
    if (!empty($_GET['end_date'])) {
        $conditions[] = "rsh.change_date <= ?";
        $params[] = validateDate($_GET['end_date']);
    }

    $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
    $query = "
        SELECT rsh.*, r.name AS room_name
        FROM room_status_history rsh
        LEFT JOIN rooms r ON rsh.room_id = r.id
        $whereClause
    ";

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM room_status_history rsh $whereClause");
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

function createRoomStatusHistory() {
    $pdo = getDB();
    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['room_id', 'status', 'change_date']);

    $roomId = filter_var($input['room_id'], FILTER_VALIDATE_INT);
    $status = $input['status'];
    $changeDate = validateDate($input['change_date']);
    $reason = sanitizeInput($input['reason'] ?? '');

    if (!$roomId || !in_array($status, ['available', 'occupied', 'maintenance'])) {
        responseJson(['status' => 'error', 'message' => 'Dữ liệu không hợp lệ'], 400);
    }

    checkResourceExists($pdo, 'rooms', $roomId);

    $stmt = $pdo->prepare("
        INSERT INTO room_status_history (room_id, status, change_date, reason)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$roomId, $status, $changeDate, $reason]);

    $stmt = $pdo->prepare("UPDATE rooms SET status = ? WHERE id = ?");
    $stmt->execute([$status, $roomId]);

    $stmt = $pdo->prepare("SELECT owner_id FROM branches WHERE id = (SELECT branch_id FROM rooms WHERE id = ?)");
    $stmt->execute([$roomId]);
    $ownerId = $stmt->fetchColumn();
    createNotification($pdo, $ownerId, "Trạng thái phòng ID $roomId đã thay đổi thành: $status");
    responseJson(['status' => 'success', 'message' => 'Thêm lịch sử trạng thái thành công']);
}

function getRoomStatusHistoryById() {
    $pdo = getDB();
    $id = getResourceIdFromUri('#^/api/v1/room_status_history/([0-9]+)$#');
    checkResourceExists($pdo, 'room_status_history', $id);

    $stmt = $pdo->prepare("
        SELECT rsh.*, r.name AS room_name, b.name AS branch_name
        FROM room_status_history rsh
        JOIN rooms r ON rsh.room_id = r.id
        JOIN branches b ON r.branch_id = b.id
        WHERE rsh.id = ?
    ");
    $stmt->execute([$id]);
    $history = $stmt->fetch();
    responseJson(['status' => 'success', 'data' => $history]);
}

function updateRoomStatusHistory() {
    $pdo = getDB();
    $id = getResourceIdFromUri('#^/api/v1/room_status_history/([0-9]+)$#');
    checkResourceExists($pdo, 'room_status_history', $id);

    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['room_id', 'status', 'change_date']);

    $roomId = filter_var($input['room_id'], FILTER_VALIDATE_INT);
    $status = $input['status'];
    $changeDate = validateDate($input['change_date']);
    $reason = sanitizeInput($input['reason'] ?? '');

    if (!$roomId || !in_array($status, ['available', 'occupied', 'maintenance'])) {
        responseJson(['status' => 'error', 'message' => 'Dữ liệu không hợp lệ'], 400);
    }

    checkResourceExists($pdo, 'rooms', $roomId);

    $stmt = $pdo->prepare("
        UPDATE room_status_history
        SET room_id = ?, status = ?, change_date = ?, reason = ?
        WHERE id = ?
    ");
    $stmt->execute([$roomId, $status, $changeDate, $reason, $id]);

    $stmt = $pdo->prepare("SELECT owner_id FROM branches WHERE id = (SELECT branch_id FROM rooms WHERE id = ?)");
    $stmt->execute([$roomId]);
    $ownerId = $stmt->fetchColumn();
    createNotification($pdo, $ownerId, "Lịch sử trạng thái phòng ID $roomId đã được cập nhật");
    responseJson(['status' => 'success', 'message' => 'Cập nhật lịch sử trạng thái thành công']);
}

function patchRoomStatusHistory() {
    $pdo = getDB();
    $id = getResourceIdFromUri('#^/api/v1/room_status_history/([0-9]+)$#');
    checkResourceExists($pdo, 'room_status_history', $id);

    $input = json_decode(file_get_contents('php://input'), true);
    $allowedFields = ['room_id', 'status', 'change_date', 'reason'];
    $updates = [];
    $params = [];

    foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
            $updates[] = "$field = ?";
            if ($field === 'change_date') {
                $params[] = validateDate($input[$field]);
            } elseif ($field === 'status') {
                $params[] = in_array($input[$field], ['available', 'occupied', 'maintenance']) ? $input[$field] : null;
            } elseif ($field === 'reason') {
                $params[] = sanitizeInput($input[$field]);
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

    if (isset($input['status']) && !in_array($input['status'], ['available', 'occupied', 'maintenance'])) {
        responseJson(['status' => 'error', 'message' => 'Trạng thái không hợp lệ'], 400);
    }

    $params[] = $id;
    $query = "UPDATE room_status_history SET " . implode(', ', $updates) . " WHERE id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);

    if ($stmt->rowCount() > 0) {
        $roomId = $input['room_id'] ?? $pdo->query("SELECT room_id FROM room_status_history WHERE id = $id")->fetchColumn();
        $stmt = $pdo->prepare("SELECT owner_id FROM branches WHERE id = (SELECT branch_id FROM rooms WHERE id = ?)");
        $stmt->execute([$roomId]);
        $ownerId = $stmt->fetchColumn();
        createNotification($pdo, $ownerId, "Lịch sử trạng thái phòng ID $roomId đã được cập nhật");
        responseJson(['status' => 'success', 'message' => 'Cập nhật lịch sử trạng thái thành công']);
    } else {
        responseJson(['status' => 'success', 'message' => 'Không có thay đổi']);
    }
}

function deleteRoomStatusHistory() {
    $pdo = getDB();
    $id = getResourceIdFromUri('#^/api/v1/room_status_history/([0-9]+)$#');
    checkResourceExists($pdo, 'room_status_history', $id);

    $stmt = $pdo->prepare("SELECT room_id FROM room_status_history WHERE id = ?");
    $stmt->execute([$id]);
    $roomId = $stmt->fetchColumn();

    $stmt = $pdo->prepare("DELETE FROM room_status_history WHERE id = ?");
    $stmt->execute([$id]);

    $stmt = $pdo->prepare("SELECT owner_id FROM branches WHERE id = (SELECT branch_id FROM rooms WHERE id = ?)");
    $stmt->execute([$roomId]);
    $ownerId = $stmt->fetchColumn();
    createNotification($pdo, $ownerId, "Lịch sử trạng thái phòng ID $roomId đã bị xóa");
    responseJson(['status' => 'success', 'message' => 'Xóa lịch sử trạng thái thành công']);
}
?>
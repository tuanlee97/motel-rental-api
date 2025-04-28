<?php
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/utils/common.php';

function getRoomOccupants() {
    $pdo = getDB();
    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $conditions = [];
    $params = [];

    if (!empty($_GET['room_id']) && filter_var($_GET['room_id'], FILTER_VALIDATE_INT)) {
        $conditions[] = "ro.room_id = ?";
        $params[] = $_GET['room_id'];
    }
    if (!empty($_GET['contract_id']) && filter_var($_GET['contract_id'], FILTER_VALIDATE_INT)) {
        $conditions[] = "ro.contract_id = ?";
        $params[] = $_GET['contract_id'];
    }

    if (!empty($_GET['search'])) {
        $search = '%' . sanitizeInput($_GET['search']) . '%';
        $conditions[] = "u.name LIKE ?";
        $params[] = $search;
    }

    $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
    $query = "
        SELECT ro.*, u.name AS user_name, r.name AS room_name
        FROM room_occupants ro
        LEFT JOIN users u ON ro.user_id = u.id
        LEFT JOIN rooms r ON ro.room_id = r.id
        $whereClause
    ";

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM room_occupants ro $whereClause");
    $countStmt->execute($params);
    $totalRecords = $countStmt->fetchColumn();
    $totalPages = ceil($totalRecords / $limit);

    $query .= " LIMIT $limit OFFSET $offset"; 

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $occupants = $stmt->fetchAll();

    responseJson([
        'status' => 'success',
        'data' => $occupants,
        'pagination' => [
            'current_page' => $page,
            'limit' => $limit,
            'total_records' => $totalRecords,
            'total_pages' => $totalPages
        ]
    ]);
}

function createRoomOccupant() {
    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['room_id', 'user_id', 'move_in_date']);
    $user = verifyJWT();

    $roomId = filter_var($input['room_id'], FILTER_VALIDATE_INT);
    $userId = filter_var($input['user_id'], FILTER_VALIDATE_INT);
    $moveInDate = validateDate($input['move_in_date']);
    $moveOutDate = !empty($input['move_out_date']) ? validateDate($input['move_out_date']) : null;

    if (!$roomId || !$userId) {
        responseJson(['status' => 'error', 'message' => 'Dữ liệu không hợp lệ'], 400);
    }

    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'rooms', $roomId);
        checkResourceExists($pdo, 'users', $userId);
        if ($user['role'] === 'owner') {
            $stmt = $pdo->prepare("SELECT r.id FROM rooms r JOIN branches b ON r.branch_id = b.id WHERE r.id = ? AND b.owner_id = ?");
            $stmt->execute([$roomId, $user['user_id']]);
        } elseif ($user['role'] === 'employee') {
            $stmt = $pdo->prepare("SELECT r.id FROM rooms r WHERE r.id = ? AND r.branch_id IN (SELECT branch_id FROM employee_assignments WHERE employee_id = ?)");
            $stmt->execute([$roomId, $user['user_id']]);
        } else {
            responseJson(['status' => 'error', 'message' => 'Không có quyền thêm người ở phòng'], 403);
        }
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Phòng không hợp lệ hoặc bạn không có quyền'], 403);
        }

        $stmt = $pdo->prepare("SELECT id FROM room_occupants WHERE room_id = ? AND user_id = ? AND (move_out_date IS NULL OR move_out_date >= NOW())");
        $stmt->execute([$roomId, $userId]);
        if ($stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Người dùng này đã được ghi nhận ở phòng này'], 409);
        }

        $stmt = $pdo->prepare("
            INSERT INTO room_occupants (room_id, user_id, move_in_date, move_out_date)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$roomId, $userId, $moveInDate, $moveOutDate]);

        $occupantId = $pdo->lastInsertId();
        createNotification($pdo, $userId, "Người ở phòng ID $occupantId đã được thêm vào phòng ID $roomId.");
        responseJson(['status' => 'success', 'data' => ['occupant_id' => $occupantId]]);
    } catch (Exception $e) {
        logError('Lỗi tạo room_occupant: ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function getRoomOccupantById() {
    $occupantId = getResourceIdFromUri('#/room_occupants/([0-9]+)#');
    $user = verifyJWT();
    $pdo = getDB();
    try {
        $query = "
            SELECT ro.id, ro.room_id, ro.user_id, ro.move_in_date, ro.move_out_date, 
                   r.name AS room_name, u.username AS user_name
            FROM room_occupants ro
            JOIN rooms r ON ro.room_id = r.id
            JOIN users u ON ro.user_id = u.id
            WHERE ro.id = ?
        ";
        $params = [$occupantId];
        if ($user['role'] === 'customer') {
            $query .= " AND ro.user_id = ?";
            $params[] = $user['user_id'];
        } elseif ($user['role'] === 'employee') {
            $query .= " AND r.branch_id IN (SELECT branch_id FROM employee_assignments WHERE employee_id = ?)";
            $params[] = $user['user_id'];
        } elseif ($user['role'] === 'owner') {
            $query .= " AND r.branch_id IN (SELECT id FROM branches WHERE owner_id = ?)";
            $params[] = $user['user_id'];
        }

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $occupant = $stmt->fetch();

        if (!$occupant) {
            responseJson(['status' => 'error', 'message' => 'Không tìm thấy người ở phòng'], 404);
        }
        responseJson(['status' => 'success', 'data' => $occupant]);
    } catch (Exception $e) {
        logError('Lỗi lấy room_occupant ID ' . $occupantId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi truy vấn'], 500);
    }
}

function updateRoomOccupant() {
    $occupantId = getResourceIdFromUri('#/room_occupants/([0-9]+)#');
    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['room_id', 'user_id', 'move_in_date']);
    $user = verifyJWT();

    $roomId = filter_var($input['room_id'], FILTER_VALIDATE_INT);
    $userId = filter_var($input['user_id'], FILTER_VALIDATE_INT);
    $moveInDate = validateDate($input['move_in_date']);
    $moveOutDate = !empty($input['move_out_date']) ? validateDate($input['move_out_date']) : null;

    if (!$roomId || !$userId) {
        responseJson(['status' => 'error', 'message' => 'Dữ liệu không hợp lệ'], 400);
    }

    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'room_occupants', $occupantId);
        checkResourceExists($pdo, 'rooms', $roomId);
        checkResourceExists($pdo, 'users', $userId);
        if ($user['role'] === 'owner') {
            $stmt = $pdo->prepare("SELECT r.id FROM rooms r JOIN branches b ON r.branch_id = b.id WHERE r.id = ? AND b.owner_id = ?");
            $stmt->execute([$roomId, $user['user_id']]);
        } elseif ($user['role'] === 'employee') {
            $stmt = $pdo->prepare("SELECT r.id FROM rooms r WHERE r.id = ? AND r.branch_id IN (SELECT branch_id FROM employee_assignments WHERE employee_id = ?)");
            $stmt->execute([$roomId, $user['user_id']]);
        } else {
            responseJson(['status' => 'error', 'message' => 'Không có quyền chỉnh sửa người ở phòng'], 403);
        }
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Phòng không hợp lệ hoặc bạn không có quyền'], 403);
        }

        $stmt = $pdo->prepare("SELECT id FROM room_occupants WHERE room_id = ? AND user_id = ? AND (move_out_date IS NULL OR move_out_date >= NOW()) AND id != ?");
        $stmt->execute([$roomId, $userId, $occupantId]);
        if ($stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Người dùng này đã được ghi nhận ở phòng này'], 409);
        }

        $stmt = $pdo->prepare("
            UPDATE room_occupants SET room_id = ?, user_id = ?, move_in_date = ?, move_out_date = ?
            WHERE id = ?
        ");
        $stmt->execute([$roomId, $userId, $moveInDate, $moveOutDate, $occupantId]);

        createNotification($pdo, $userId, "Người ở phòng ID $occupantId đã được cập nhật.");
        responseJson(['status' => 'success', 'message' => 'Cập nhật người ở phòng thành công']);
    } catch (Exception $e) {
        logError('Lỗi cập nhật room_occupant ID ' . $occupantId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function patchRoomOccupant() {
    $occupantId = getResourceIdFromUri('#/room_occupants/([0-9]+)#');
    $input = json_decode(file_get_contents('php://input'), true);
    $user = verifyJWT();

    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'room_occupants', $occupantId);
        if ($user['role'] === 'owner') {
            $stmt = $pdo->prepare("SELECT ro.id FROM room_occupants ro JOIN rooms r ON ro.room_id = r.id JOIN branches b ON r.branch_id = b.id WHERE ro.id = ? AND b.owner_id = ?");
            $stmt->execute([$occupantId, $user['user_id']]);
        } elseif ($user['role'] === 'employee') {
            $stmt = $pdo->prepare("SELECT ro.id FROM room_occupants ro JOIN rooms r ON ro.room_id = r.id WHERE ro.id = ? AND r.branch_id IN (SELECT branch_id FROM employee_assignments WHERE employee_id = ?)");
            $stmt->execute([$occupantId, $user['user_id']]);
        } else {
            responseJson(['status' => 'error', 'message' => 'Không có quyền chỉnh sửa người ở phòng'], 403);
        }
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Người ở phòng không hợp lệ hoặc bạn không có quyền'], 403);
        }

        $updates = [];
        $params = [];
        if (!empty($input['room_id'])) {
            $roomId = filter_var($input['room_id'], FILTER_VALIDATE_INT);
            checkResourceExists($pdo, 'rooms', $roomId);
            if ($user['role'] === 'owner') {
                $stmt = $pdo->prepare("SELECT r.id FROM rooms r JOIN branches b ON r.branch_id = b.id WHERE r.id = ? AND b.owner_id = ?");
                $stmt->execute([$roomId, $user['user_id']]);
            } elseif ($user['role'] === 'employee') {
                $stmt = $pdo->prepare("SELECT r.id FROM rooms r WHERE r.id = ? AND r.branch_id IN (SELECT branch_id FROM employee_assignments WHERE employee_id = ?)");
                $stmt->execute([$roomId, $user['user_id']]);
            }
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Phòng không hợp lệ hoặc bạn không có quyền'], 403);
            }
            $updates[] = "room_id = ?";
            $params[] = $roomId;
        }
        if (!empty($input['user_id'])) {
            $userId = filter_var($input['user_id'], FILTER_VALIDATE_INT);
            checkResourceExists($pdo, 'users', $userId);
            $updates[] = "user_id = ?";
            $params[] = $userId;
        }
        if (!empty($input['move_in_date'])) {
            $moveInDate = validateDate($input['move_in_date']);
            $updates[] = "move_in_date = ?";
            $params[] = $moveInDate;
        }
        if (isset($input['move_out_date'])) {
            $moveOutDate = $input['move_out_date'] ? validateDate($input['move_out_date']) : null;
            $updates[] = "move_out_date = ?";
            $params[] = $moveOutDate;
        }

        if (empty($updates)) {
            responseJson(['status' => 'error', 'message' => 'Không có dữ liệu để cập nhật'], 400);
        }

        if (!empty($input['room_id']) || !empty($input['user_id'])) {
            $stmt = $pdo->prepare("SELECT room_id, user_id FROM room_occupants WHERE id = ?");
            $stmt->execute([$occupantId]);
            $current = $stmt->fetch();
            $newRoomId = $input['room_id'] ?? $current['room_id'];
            $newUserId = $input['user_id'] ?? $current['user_id'];
            $stmt = $pdo->prepare("SELECT id FROM room_occupants WHERE room_id = ? AND user_id = ? AND (move_out_date IS NULL OR move_out_date >= NOW()) AND id != ?");
            $stmt->execute([$newRoomId, $newUserId, $occupantId]);
            if ($stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Người dùng này đã được ghi nhận ở phòng này'], 409);
            }
        }

        $query = "UPDATE room_occupants SET " . implode(', ', $updates) . " WHERE id = ?";
        $params[] = $occupantId;
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);

        createNotification($pdo, $user['user_id'], "Người ở phòng ID $occupantId đã được cập nhật.");
        responseJson(['status' => 'success', 'message' => 'Cập nhật người ở phòng thành công']);
    } catch (Exception $e) {
        logError('Lỗi patch room_occupant ID ' . $occupantId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function deleteRoomOccupant() {
    $occupantId = getResourceIdFromUri('#/room_occupants/([0-9]+)#');
    $user = verifyJWT();
    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'room_occupants', $occupantId);
        if ($user['role'] === 'owner') {
            $stmt = $pdo->prepare("SELECT ro.id FROM room_occupants ro JOIN rooms r ON ro.room_id = r.id JOIN branches b ON r.branch_id = b.id WHERE ro.id = ? AND b.owner_id = ?");
            $stmt->execute([$occupantId, $user['user_id']]);
        } elseif ($user['role'] === 'employee') {
            $stmt = $pdo->prepare("SELECT ro.id FROM room_occupants ro JOIN rooms r ON ro.room_id = r.id WHERE ro.id = ? AND r.branch_id IN (SELECT branch_id FROM employee_assignments WHERE employee_id = ?)");
            $stmt->execute([$occupantId, $user['user_id']]);
        } else {
            responseJson(['status' => 'error', 'message' => 'Không có quyền xóa người ở phòng'], 403);
        }
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Người ở phòng không hợp lệ hoặc bạn không có quyền'], 403);
        }

        $stmt = $pdo->prepare("DELETE FROM room_occupants WHERE id = ?");
        $stmt->execute([$occupantId]);
        responseJson(['status' => 'success', 'message' => 'Xóa người ở phòng thành công']);
    } catch (Exception $e) {
        logError('Lỗi xóa room_occupant ID ' . $occupantId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

// Hàm hỗ trợ validate ngày
function validateDate($date) {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) {
        responseJson(['status' => 'error', 'message' => 'Định dạng ngày không hợp lệ (Y-m-d)'], 400);
    }
    return $date;
}
?>
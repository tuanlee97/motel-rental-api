<?php
require_once __DIR__ . '/../../core/database.php';
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/common.php';

function getContracts() {
    $pdo = getDB();

    // Phân trang
    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    // Điều kiện lọc
    $conditions = [];
    $params = [];

    if (!empty($_GET['status']) && in_array($_GET['status'], ['active', 'expired', 'cancelled'])) {
        $conditions[] = "c.status = ?";
        $params[] = $_GET['status'];
    }
    if (!empty($_GET['user_id']) && filter_var($_GET['user_id'], FILTER_VALIDATE_INT)) {
        $conditions[] = "c.user_id = ?";
        $params[] = $_GET['user_id'];
    }
    if (!empty($_GET['room_id']) && filter_var($_GET['room_id'], FILTER_VALIDATE_INT)) {
        $conditions[] = "c.room_id = ?";
        $params[] = $_GET['room_id'];
    }
    if (!empty($_GET['branch_id']) && filter_var($_GET['branch_id'], FILTER_VALIDATE_INT)) {
        $conditions[] = "c.branch_id = ?";
        $params[] = $_GET['branch_id'];
    }

    // Tìm kiếm
    if (!empty($_GET['search'])) {
        $search = '%' . sanitizeInput($_GET['search']) . '%';
        $conditions[] = "u.name LIKE ?";
        $params[] = $search;
    }

    // Xây dựng truy vấn
    $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
    $query = "
        SELECT c.*, u.name AS customer_name, r.name AS room_name, b.name AS branch_name
        FROM contracts c
        JOIN users u ON c.user_id = u.id
        JOIN rooms r ON c.room_id = r.id
        JOIN branches b ON c.branch_id = b.id
        $whereClause
    ";

    // Đếm tổng số bản ghi
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM contracts c JOIN users u ON c.user_id = u.id $whereClause");
    $countStmt->execute($params);
    $totalRecords = $countStmt->fetchColumn();
    $totalPages = ceil($totalRecords / $limit);

    // Truy vấn dữ liệu với phân trang
    $query .= " LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $contracts = $stmt->fetchAll();

    responseJson([
        'status' => 'success',
        'data' => $contracts,
        'pagination' => [
            'current_page' => $page,
            'limit' => $limit,
            'total_records' => $totalRecords,
            'total_pages' => $totalPages
        ]
    ]);
}

function createContract() {
    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['user_id', 'room_id', 'start_date', 'end_date', 'deposit', 'rental_price']);
    $user = verifyJWT();

    $userId = filter_var($input['user_id'], FILTER_VALIDATE_INT);
    $roomId = filter_var($input['room_id'], FILTER_VALIDATE_INT);
    $startDate = validateDate($input['start_date']);
    $endDate = validateDate($input['end_date']);
    $deposit = filter_var($input['deposit'], FILTER_VALIDATE_FLOAT);
    $rentalPrice = filter_var($input['rental_price'], FILTER_VALIDATE_FLOAT);
    $status = in_array($input['status'] ?? 'active', ['active', 'expired', 'terminated']) ? $input['status'] : 'active';

    if (!$userId || !$roomId || !$deposit || !$rentalPrice || $deposit < 0 || $rentalPrice < 0) {
        responseJson(['status' => 'error', 'message' => 'Dữ liệu không hợp lệ'], 400);
    }

    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'users', $userId);
        checkResourceExists($pdo, 'rooms', $roomId);
        if ($user['role'] === 'owner') {
            $stmt = $pdo->prepare("SELECT r.id FROM rooms r JOIN branches b ON r.branch_id = b.id WHERE r.id = ? AND b.owner_id = ?");
            $stmt->execute([$roomId, $user['user_id']]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Phòng không hợp lệ hoặc bạn không có quyền'], 403);
            }
        } elseif ($user['role'] === 'employee') {
            $stmt = $pdo->prepare("SELECT r.id FROM rooms r WHERE r.id = ? AND r.branch_id IN (SELECT branch_id FROM employee_assignments WHERE employee_id = ?)");
            $stmt->execute([$roomId, $user['user_id']]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Phòng không hợp lệ hoặc bạn không có quyền'], 403);
            }
        } elseif ($user['role'] === 'customer' && $user['user_id'] !== $userId) {
            responseJson(['status' => 'error', 'message' => 'Bạn không có quyền tạo hợp đồng cho người dùng khác'], 403);
        } else {
            responseJson(['status' => 'error', 'message' => 'Không có quyền tạo hợp đồng'], 403);
        }

        $stmt = $pdo->prepare("SELECT id FROM contracts WHERE room_id = ? AND status = 'active'");
        $stmt->execute([$roomId]);
        if ($stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Phòng này đã có hợp đồng đang hoạt động'], 409);
        }

        $stmt = $pdo->prepare("
            INSERT INTO contracts (user_id, room_id, start_date, end_date, deposit, rental_price, status)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$userId, $roomId, $startDate, $endDate, $deposit, $rentalPrice, $status]);

        $contractId = $pdo->lastInsertId();
        createNotification($pdo, $userId, "Hợp đồng ID $contractId đã được tạo cho phòng ID $roomId.");
        responseJson(['status' => 'success', 'data' => ['contract_id' => $contractId]]);
    } catch (Exception $e) {
        logError('Lỗi tạo contract: ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function getContractById() {
    $contractId = getResourceIdFromUri('#/contracts/([0-9]+)#');
    $user = verifyJWT();
    $pdo = getDB();
    try {
        $query = "
            SELECT c.id, c.user_id, c.room_id, c.start_date, c.end_date, c.deposit, c.rental_price, c.status, c.created_at,
                   u.username AS user_name, r.name AS room_name
            FROM contracts c
            JOIN users u ON c.user_id = u.id
            JOIN rooms r ON c.room_id = r.id
            WHERE c.id = ?
        ";
        $params = [$contractId];
        if ($user['role'] === 'customer') {
            $query .= " AND c.user_id = ?";
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
        $contract = $stmt->fetch();

        if (!$contract) {
            responseJson(['status' => 'error', 'message' => 'Không tìm thấy hợp đồng'], 404);
        }
        responseJson(['status' => 'success', 'data' => $contract]);
    } catch (Exception $e) {
        logError('Lỗi lấy contract ID ' . $contractId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi truy vấn'], 500);
    }
}

function updateContract() {
    $contractId = getResourceIdFromUri('#/contracts/([0-9]+)#');
    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['user_id', 'room_id', 'start_date', 'end_date', 'deposit', 'rental_price', 'status']);
    $user = verifyJWT();

    $userId = filter_var($input['user_id'], FILTER_VALIDATE_INT);
    $roomId = filter_var($input['room_id'], FILTER_VALIDATE_INT);
    $startDate = validateDate($input['start_date']);
    $endDate = validateDate($input['end_date']);
    $deposit = filter_var($input['deposit'], FILTER_VALIDATE_FLOAT);
    $rentalPrice = filter_var($input['rental_price'], FILTER_VALIDATE_FLOAT);
    $status = in_array($input['status'], ['active', 'expired', 'terminated']) ? $input['status'] : 'active';

    if (!$userId || !$roomId || !$deposit || !$rentalPrice || $deposit < 0 || $rentalPrice < 0) {
        responseJson(['status' => 'error', 'message' => 'Dữ liệu không hợp lệ'], 400);
    }

    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'contracts', $contractId);
        checkResourceExists($pdo, 'users', $userId);
        checkResourceExists($pdo, 'rooms', $roomId);

        if ($user['role'] === 'owner') {
            $stmt = $pdo->prepare("SELECT r.id FROM rooms r JOIN branches b ON r.branch_id = b.id WHERE r.id = ? AND b.owner_id = ?");
            $stmt->execute([$roomId, $user['user_id']]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Phòng không hợp lệ hoặc bạn không có quyền'], 403);
            }
        } elseif ($user['role'] === 'employee') {
            $stmt = $pdo->prepare("SELECT r.id FROM rooms r WHERE r.id = ? AND r.branch_id IN (SELECT branch_id FROM employee_assignments WHERE employee_id = ?)");
            $stmt->execute([$roomId, $user['user_id']]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Phòng không hợp lệ hoặc bạn không có quyền'], 403);
            }
        } else {
            responseJson(['status' => 'error', 'message' => 'Không có quyền chỉnh sửa hợp đồng'], 403);
        }

        $stmt = $pdo->prepare("SELECT id FROM contracts WHERE room_id = ? AND status = 'active' AND id != ?");
        $stmt->execute([$roomId, $contractId]);
        if ($stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Phòng này đã có hợp đồng đang hoạt động'], 409);
        }

        $stmt = $pdo->prepare("
            UPDATE contracts SET user_id = ?, room_id = ?, start_date = ?, end_date = ?, deposit = ?, rental_price = ?, status = ?
            WHERE id = ?
        ");
        $stmt->execute([$userId, $roomId, $startDate, $endDate, $deposit, $rentalPrice, $status, $contractId]);

        createNotification($pdo, $userId, "Hợp đồng ID $contractId đã được cập nhật.");
        responseJson(['status' => 'success', 'message' => 'Cập nhật hợp đồng thành công']);
    } catch (Exception $e) {
        logError('Lỗi cập nhật contract ID ' . $contractId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function patchContract() {
    $contractId = getResourceIdFromUri('#/contracts/([0-9]+)#');
    $input = json_decode(file_get_contents('php://input'), true);
    $user = verifyJWT();

    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'contracts', $contractId);
        if ($user['role'] === 'owner') {
            $stmt = $pdo->prepare("SELECT c.id FROM contracts c JOIN rooms r ON c.room_id = r.id JOIN branches b ON r.branch_id = b.id WHERE c.id = ? AND b.owner_id = ?");
            $stmt->execute([$contractId, $user['user_id']]);
        } elseif ($user['role'] === 'employee') {
            $stmt = $pdo->prepare("SELECT c.id FROM contracts c JOIN rooms r ON c.room_id = r.id WHERE c.id = ? AND r.branch_id IN (SELECT branch_id FROM employee_assignments WHERE employee_id = ?)");
            $stmt->execute([$contractId, $user['user_id']]);
        } else {
            responseJson(['status' => 'error', 'message' => 'Không có quyền chỉnh sửa hợp đồng'], 403);
        }
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Hợp đồng không hợp lệ hoặc bạn không có quyền'], 403);
        }

        $updates = [];
        $params = [];
        if (!empty($input['user_id'])) {
            $userId = filter_var($input['user_id'], FILTER_VALIDATE_INT);
            checkResourceExists($pdo, 'users', $userId);
            $updates[] = "user_id = ?";
            $params[] = $userId;
        }
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
            $stmt = $pdo->prepare("SELECT id FROM contracts WHERE room_id = ? AND status = 'active' AND id != ?");
            $stmt->execute([$roomId, $contractId]);
            if ($stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Phòng này đã có hợp đồng đang hoạt động'], 409);
            }
            $updates[] = "room_id = ?";
            $params[] = $roomId;
        }
        if (!empty($input['start_date'])) {
            $startDate = validateDate($input['start_date']);
            $updates[] = "start_date = ?";
            $params[] = $startDate;
        }
        if (!empty($input['end_date'])) {
            $endDate = validateDate($input['end_date']);
            $updates[] = "end_date = ?";
            $params[] = $endDate;
        }
        if (isset($input['deposit'])) {
            $deposit = filter_var($input['deposit'], FILTER_VALIDATE_FLOAT);
            if ($deposit === false || $deposit < 0) {
                responseJson(['status' => 'error', 'message' => 'Tiền đặt cọc không hợp lệ'], 400);
            }
            $updates[] = "deposit = ?";
            $params[] = $deposit;
        }
        if (isset($input['rental_price'])) {
            $rentalPrice = filter_var($input['rental_price'], FILTER_VALIDATE_FLOAT);
            if ($rentalPrice === false || $rentalPrice < 0) {
                responseJson(['status' => 'error', 'message' => 'Giá thuê không hợp lệ'], 400);
            }
            $updates[] = "rental_price = ?";
            $params[] = $rentalPrice;
        }
        if (!empty($input['status'])) {
            $status = in_array($input['status'], ['active', 'expired', 'terminated']) ? $input['status'] : 'active';
            $updates[] = "status = ?";
            $params[] = $status;
        }

        if (empty($updates)) {
            responseJson(['status' => 'error', 'message' => 'Không có dữ liệu để cập nhật'], 400);
        }

        $query = "UPDATE contracts SET " . implode(', ', $updates) . " WHERE id = ?";
        $params[] = $contractId;
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);

        createNotification($pdo, $user['user_id'], "Hợp đồng ID $contractId đã được cập nhật.");
        responseJson(['status' => 'success', 'message' => 'Cập nhật hợp đồng thành công']);
    } catch (Exception $e) {
        logError('Lỗi patch contract ID ' . $contractId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function deleteContract() {
    $contractId = getResourceIdFromUri('#/contracts/([0-9]+)#');
    $user = verifyJWT();
    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'contracts', $contractId);
        if ($user['role'] === 'owner') {
            $stmt = $pdo->prepare("SELECT c.id FROM contracts c JOIN rooms r ON c.room_id = r.id JOIN branches b ON r.branch_id = b.id WHERE c.id = ? AND b.owner_id = ?");
            $stmt->execute([$contractId, $user['user_id']]);
        } elseif ($user['role'] === 'employee') {
            $stmt = $pdo->prepare("SELECT c.id FROM contracts c JOIN rooms r ON c.room_id = r.id WHERE c.id = ? AND r.branch_id IN (SELECT branch_id FROM employee_assignments WHERE employee_id = ?)");
            $stmt->execute([$contractId, $user['user_id']]);
        } else {
            responseJson(['status' => 'error', 'message' => 'Không có quyền xóa hợp đồng'], 403);
        }
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Hợp đồng không hợp lệ hoặc bạn không có quyền'], 403);
        }

        $stmt = $pdo->prepare("DELETE FROM contracts WHERE id = ?");
        $stmt->execute([$contractId]);
        responseJson(['status' => 'success', 'message' => 'Xóa hợp đồng thành công']);
    } catch (Exception $e) {
        logError('Lỗi xóa contract ID ' . $contractId . ': ' . $e->getMessage());
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
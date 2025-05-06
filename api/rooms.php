<?php
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/utils/common.php';

// Lấy danh sách phòng
function getRooms() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $conditions = [];
    $params = [];

    if (!empty($_GET['branch_id'])) {
        $branch_id = (int)$_GET['branch_id'];
        $conditions[] = "r.branch_id = ?";
        $params[] = $branch_id;
    } elseif ($role === 'owner' && $pdo->query("SELECT COUNT(*) FROM branches WHERE owner_id = $user_id")->fetchColumn() === 1) {
        $branch_id = $pdo->query("SELECT id FROM branches WHERE owner_id = $user_id LIMIT 1")->fetchColumn();
        $conditions[] = "r.branch_id = ?";
        $params[] = $branch_id;
    } else {
        if ($role !== 'admin') {
            $conditions[] = "r.branch_id IN (SELECT id FROM branches WHERE owner_id = ?)";
            $params[] = $user_id;
        }
    }

    if (!empty($_GET['status'])) {
        $conditions[] = "r.status = ?";
        $params[] = $_GET['status'];
    }

    $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
    $query = "
        SELECT r.id, r.branch_id, r.type_id, r.name, r.price, r.status, r.created_at, rt.name AS type_name, b.name AS branch_name
        FROM rooms r
        JOIN room_types rt ON r.type_id = rt.id
        JOIN branches b ON r.branch_id = b.id
        $whereClause
        LIMIT $limit OFFSET $offset
    ";

    try {
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM rooms r $whereClause");
        $countStmt->execute($params);
        $totalRecords = $countStmt->fetchColumn();
        $totalPages = ceil($totalRecords / $limit);

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

        responseJson([
            'status' => 'success',
            'data' => $rooms,
            'pagination' => ['current_page' => $page, 'limit' => $limit, 'total_records' => $totalRecords, 'total_pages' => $totalPages]
        ]);
    } catch (PDOException $e) {
        logError("Lỗi lấy danh sách phòng: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Tạo phòng
function createRoom() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if ($role !== 'admin' && $role !== 'owner') {
        responseJson(['status' => 'error', 'message' => 'Không có quyền tạo phòng'], 403);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    error_log("Input data: " . json_encode($input));
    validateRequiredFields($input, ['branch_id', 'type_id', 'name']);
    $data = sanitizeInput($input);

    if ($role === 'owner') {
        $stmt = $pdo->prepare("SELECT 1 FROM branches WHERE id = ? AND owner_id = ?");
        $stmt->execute([$data['branch_id'], $user_id]);
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Không có quyền tạo phòng cho chi nhánh này'], 403);
            return;
        }
    }

    try {
        checkResourceExists($pdo, 'branches', $data['branch_id']);
        checkResourceExists($pdo, 'room_types', $data['type_id']);
        $stmt = $pdo->prepare("INSERT INTO rooms (branch_id, type_id, name, price, status, created_at) VALUES (?, ?, ?, ?, 'available', NOW())");
        $stmt->execute([$data['branch_id'], $data['type_id'], $data['name'], $data['price'] ?? 0]);
        responseJson(['status' => 'success', 'message' => 'Tạo phòng thành công']);
    } catch (PDOException $e) {
        logError("Lỗi tạo phòng: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}
// Cập nhật thông tin phòng
function updateRoom() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];
    $room_id = getResourceIdFromUri('#/rooms/([0-9]+)#');

    // Kiểm tra quyền truy cập
    if ($role !== 'admin' && $role !== 'owner' && $role !== 'employee') {
        responseJson(['status' => 'error', 'message' => 'Không có quyền cập nhật phòng'], 403);
        return;
    }

    // Lấy dữ liệu đầu vào từ request
    $input = json_decode(file_get_contents('php://input'), true);
    error_log("Input data: " . json_encode($input));
    validateRequiredFields($input, ['branch_id', 'type_id', 'name']);
    $data = sanitizeInput($input);

    try {
        // Kiểm tra sự tồn tại của phòng, chi nhánh và loại phòng
        checkResourceExists($pdo, 'rooms', $room_id);
        checkResourceExists($pdo, 'branches', $data['branch_id']);
        checkResourceExists($pdo, 'room_types', $data['type_id']);

        // Nếu người dùng là owner hoặc employee, kiểm tra quyền sở hữu chi nhánh
        if ($role === 'owner' || $role === 'employee') {
            $stmt = $pdo->prepare("SELECT 1 FROM rooms r JOIN branches b ON r.branch_id = b.id WHERE r.id = ? AND b.owner_id = ?");
            $stmt->execute([$room_id, $user_id]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Không có quyền cập nhật phòng này'], 403);
                return;
            }
        }

        // Cập nhật thông tin phòng
        $stmt = $pdo->prepare("UPDATE rooms SET branch_id = ?, type_id = ?, name = ?, price = ? WHERE id = ?");
        $stmt->execute([$data['branch_id'], $data['type_id'], $data['name'], $data['price'] ?? 0, $room_id]);

        responseJson(['status' => 'success', 'message' => 'Cập nhật phòng thành công']);
    } catch (PDOException $e) {
        logError("Lỗi cập nhật phòng ID $room_id: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}
// Cập nhật trạng thái phòng
function updateRoomStatus() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];
    $room_id = getResourceIdFromUri('#/rooms/([0-9]+)#');

    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['status']);
    $status = in_array($input['status'], ['available', 'occupied', 'maintenance']) ? $input['status'] : null;

    if (!$status) {
        responseJson(['status' => 'error', 'message' => 'Trạng thái không hợp lệ'], 400);
        return;
    }

    if ($role !== 'admin' && $role !== 'owner' && $role !== 'employee') {
        responseJson(['status' => 'error', 'message' => 'Không có quyền cập nhật trạng thái phòng'], 403);
        return;
    }

    try {
        checkResourceExists($pdo, 'rooms', $room_id);
        if ($role === 'owner' || $role === 'employee') {
            $stmt = $pdo->prepare("SELECT 1 FROM rooms r JOIN branches b ON r.branch_id = b.id WHERE r.id = ? AND b.owner_id = ?");
            $stmt->execute([$room_id, $user_id]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Không có quyền cập nhật phòng này'], 403);
                return;
            }
        }
        $stmt = $pdo->prepare("UPDATE rooms SET status = ? WHERE id = ?");
        $stmt->execute([$status, $room_id]);
        responseJson(['status' => 'success', 'message' => 'Cập nhật trạng thái phòng thành công']);
    } catch (PDOException $e) {
        logError("Lỗi cập nhật trạng thái phòng ID $room_id: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Tạo hợp đồng
function createContract() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if ($role !== 'admin' && $role !== 'owner' && $role !== 'employee') {
        responseJson(['status' => 'error', 'message' => 'Không có quyền tạo hợp đồng'], 403);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['room_id', 'user_id', 'start_date', 'end_date']);
    $data = sanitizeInput($input);

    try {
        checkResourceExists($pdo, 'rooms', $data['room_id']);
        checkResourceExists($pdo, 'users', $data['user_id']);
        if ($role === 'owner' || $role === 'employee') {
            $stmt = $pdo->prepare("SELECT 1 FROM rooms r JOIN branches b ON r.branch_id = b.id WHERE r.id = ? AND b.owner_id = ?");
            $stmt->execute([$data['room_id'], $user_id]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Không có quyền tạo hợp đồng cho phòng này'], 403);
                return;
            }
        }
        $stmt = $pdo->prepare("INSERT INTO contracts (room_id, user_id, start_date, end_date, status, created_at, created_by, branch_id) VALUES (?, ?, ?, ?, 'active', NOW(), ?, (SELECT branch_id FROM rooms WHERE id = ?))");
        $stmt->execute([$data['room_id'], $data['user_id'], $data['start_date'], $data['end_date'], $user_id, $data['room_id']]);
        $contractId = $pdo->lastInsertId();

        // Cập nhật trạng thái phòng thành occupied
        $stmt = $pdo->prepare("UPDATE rooms SET status = 'occupied' WHERE id = ?");
        $stmt->execute([$data['room_id']]);

        createNotification($pdo, $data['user_id'], "Hợp đồng thuê phòng ID $contractId đã được tạo.");
        responseJson(['status' => 'success', 'message' => 'Tạo hợp đồng thành công']);
    } catch (PDOException $e) {
        logError("Lỗi tạo hợp đồng: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}
function deleteRoom() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];
    $room_id = getResourceIdFromUri('#/rooms/([0-9]+)#');

    // Kiểm tra quyền xóa
    if ($role !== 'admin' && $role !== 'owner') {
        responseJson(['status' => 'error', 'message' => 'Không có quyền xóa phòng'], 403);
        return;
    }

    try {
        // Kiểm tra sự tồn tại của phòng
        checkResourceExists($pdo, 'rooms', $room_id);

        // Nếu là owner, kiểm tra quyền sở hữu chi nhánh
        if ($role === 'owner') {
            $stmt = $pdo->prepare("SELECT 1 FROM rooms r JOIN branches b ON r.branch_id = b.id WHERE r.id = ? AND b.owner_id = ?");
            $stmt->execute([$room_id, $user_id]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Không có quyền xóa phòng này'], 403);
                return;
            }
        }

        // Bắt đầu transaction để đảm bảo tính toàn vẹn dữ liệu
        $pdo->beginTransaction();

        // Xóa các hợp đồng liên quan (nếu có)
        $stmt = $pdo->prepare("DELETE FROM contracts WHERE room_id = ?");
        $stmt->execute([$room_id]);

        // Xóa phòng
        $stmt = $pdo->prepare("DELETE FROM rooms WHERE id = ?");
        $stmt->execute([$room_id]);

        // Commit transaction
        $pdo->commit();

        responseJson(['status' => 'success', 'message' => 'Xóa phòng thành công']);
    } catch (PDOException $e) {
        $pdo->rollBack();
        logError("Lỗi xóa phòng ID $room_id: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}
?>
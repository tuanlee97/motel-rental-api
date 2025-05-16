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
    } elseif ($role === 'owner') {
        $stmt = $pdo->prepare("SELECT id FROM branches WHERE owner_id = ? AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([$user_id]);
        $branch_id = $stmt->fetchColumn();
        if ($branch_id) {
            $conditions[] = "r.branch_id = ?";
            $params[] = $branch_id;
        }
    } elseif ($role === 'employee') {
        $stmt = $pdo->prepare("SELECT branch_id FROM employee_assignments WHERE employee_id = ? LIMIT 1");
        $stmt->execute([$user_id]);
        $branch_id = $stmt->fetchColumn();
        if ($branch_id) {
            $conditions[] = "r.branch_id = ?";
            $params[] = $branch_id;
        }
    } else {
        if ($role !== 'admin') {
            $conditions[] = "r.branch_id IN (SELECT id FROM branches WHERE owner_id = ? AND deleted_at IS NULL)";
            $params[] = $user_id;
        }
    }

    if (!empty($_GET['status'])) {
        $conditions[] = "r.status = ?";
        $params[] = $_GET['status'];
    }

    $conditions[] = "r.deleted_at IS NULL";
    $conditions[] = "rt.deleted_at IS NULL";
    $conditions[] = "b.deleted_at IS NULL";

    $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
    $query = "
        SELECT 
            r.id, 
            r.branch_id, 
            r.type_id, 
            r.name, 
            r.price, 
            r.status, 
            r.created_at,
            r.deleted_at, 
            rt.name AS type_name, 
            b.name AS branch_name,
            c.id AS contract_id
        FROM rooms r
        JOIN room_types rt ON r.type_id = rt.id
        JOIN branches b ON r.branch_id = b.id
        LEFT JOIN contracts c ON r.id = c.room_id AND c.status IN ('active', 'expired', 'ended', 'cancelled') 
        AND c.end_date IS NULL AND c.deleted_at IS NULL
        $whereClause
        LIMIT $limit OFFSET $offset
    ";

    try {
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM rooms r JOIN room_types rt ON r.type_id = rt.id JOIN branches b ON r.branch_id = b.id LEFT JOIN contracts c ON r.id = c.room_id $whereClause");
        $countStmt->execute($params);
        $totalRecords = $countStmt->fetchColumn();
        $totalPages = ceil($totalRecords / $limit);

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    } catch (PDOException $e) {
        error_log("Lỗi lấy danh sách phòng: " . $e->getMessage());
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
        $stmt = $pdo->prepare("SELECT 1 FROM branches WHERE id = ? AND owner_id = ? AND deleted_at IS NULL");
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
        error_log("Lỗi tạo phòng: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Cập nhật thông tin phòng (bao gồm cả trạng thái)
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

    // Xác định các trường bắt buộc và tùy chọn
    $requiredFields = [];
    if (isset($input['status']) || isset($input['branch_id']) || isset($input['type_id']) || isset($input['name'])) {
        $requiredFields = ['branch_id', 'type_id', 'name'];
        validateRequiredFields($input, $requiredFields);
    } elseif (isset($input['status'])) {
        validateRequiredFields($input, ['status']);
    } else {
        responseJson(['status' => 'error', 'message' => 'Không có dữ liệu để cập nhật'], 400);
        return;
    }

    $data = sanitizeInput($input);

    // Validate status nếu có
    if (isset($data['status']) && !in_array($data['status'], ['available', 'occupied', 'maintenance'])) {
        responseJson(['status' => 'error', 'message' => 'Trạng thái không hợp lệ'], 400);
        return;
    }

    try {
        // Kiểm tra sự tồn tại của phòng
        $stmt = $pdo->prepare("SELECT 1 FROM rooms WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$room_id]);
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Phòng không tồn tại hoặc đã bị xóa'], 404);
            return;
        }

        // Kiểm tra quyền sở hữu chi nhánh nếu là owner hoặc employee
        if ($role === 'owner' || $role === 'employee') {
            $stmt = $pdo->prepare("SELECT 1 FROM rooms r JOIN branches b ON r.branch_id = b.id WHERE r.id = ? AND b.owner_id = ? AND r.deleted_at IS NULL AND b.deleted_at IS NULL");
            $stmt->execute([$room_id, $user_id]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Không có quyền cập nhật phòng này'], 403);
                return;
            }
        }

        // Chuẩn bị câu lệnh UPDATE
        $setClause = [];
        $params = [];
        if (isset($data['branch_id'])) {
            checkResourceExists($pdo, 'branches', $data['branch_id']);
            $setClause[] = "branch_id = ?";
            $params[] = $data['branch_id'];
        }
        if (isset($data['type_id'])) {
            checkResourceExists($pdo, 'room_types', $data['type_id']);
            $setClause[] = "type_id = ?";
            $params[] = $data['type_id'];
        }
        if (isset($data['name'])) {
            $setClause[] = "name = ?";
            $params[] = $data['name'];
        }
        if (isset($data['price'])) {
            $setClause[] = "price = ?";
            $params[] = $data['price'] ?? 0;
        }
        if (isset($data['status'])) {
            $setClause[] = "status = ?";
            $params[] = $data['status'];
        }

        if (empty($setClause)) {
            responseJson(['status' => 'error', 'message' => 'Không có trường nào để cập nhật'], 400);
            return;
        }

        $params[] = $room_id;
        $query = "UPDATE rooms SET " . implode(", ", $setClause) . " WHERE id = ? AND deleted_at IS NULL";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);

        responseJson(['status' => 'success', 'message' => 'Cập nhật phòng thành công']);
    } catch (PDOException $e) {
        error_log("Lỗi cập nhật phòng ID $room_id: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Xóa mềm phòng
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
        $stmt = $pdo->prepare("SELECT 1 FROM rooms WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$room_id]);
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Phòng không tồn tại hoặc đã bị xóa'], 404);
            return;
        }

        // Nếu là owner, kiểm tra quyền sở hữu chi nhánh
        if ($role === 'owner') {
            $stmt = $pdo->prepare("SELECT 1 FROM rooms r JOIN branches b ON r.branch_id = b.id WHERE r.id = ? AND b.owner_id = ? AND r.deleted_at IS NULL AND b.deleted_at IS NULL");
            $stmt->execute([$room_id, $user_id]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Không có quyền xóa phòng này'], 403);
                return;
            }
        }

        // Bắt đầu transaction để đảm bảo tính toàn vẹn dữ liệu
        $pdo->beginTransaction();

        // Xóa mềm các hợp đồng liên quan
        $stmt = $pdo->prepare("UPDATE contracts SET deleted_at = NOW() WHERE room_id = ? AND deleted_at IS NULL");
        $stmt->execute([$room_id]);

        // Xóa mềm các bản ghi trong utility_usage
        $stmt = $pdo->prepare("UPDATE utility_usage SET deleted_at = NOW() WHERE room_id = ? AND deleted_at IS NULL");
        $stmt->execute([$room_id]);

        // Xóa mềm các yêu cầu bảo trì
        $stmt = $pdo->prepare("UPDATE maintenance_requests SET deleted_at = NOW() WHERE room_id = ? AND deleted_at IS NULL");
        $stmt->execute([$room_id]);

        // Xóa mềm các thông tin người ở trong phòng
        $stmt = $pdo->prepare("UPDATE room_occupants SET deleted_at = NOW() WHERE room_id = ? AND deleted_at IS NULL");
        $stmt->execute([$room_id]);

        // Xóa mềm phòng
        $stmt = $pdo->prepare("UPDATE rooms SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$room_id]);

        // Commit transaction
        $pdo->commit();

        responseJson(['status' => 'success', 'message' => 'Xóa mềm phòng thành công']);
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Lỗi xóa mềm phòng ID $room_id: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}
?>
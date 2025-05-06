<?php
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/utils/common.php';

// Lấy danh sách hợp đồng
function getContracts() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $conditions = [];
    $params = [];

    // Xử lý branch_id
    if (!empty($_GET['branch_id'])) {
        $branch_id = (int)$_GET['branch_id'];
        $conditions[] = "c.branch_id = ?";
        $params[] = $branch_id;
    } elseif ($role === 'owner') {
        $stmt = $pdo->prepare("SELECT id FROM branches WHERE owner_id = ? LIMIT 1");
        $stmt->execute([$user_id]);
        $branch_id = $stmt->fetchColumn();
        if ($branch_id) {
            $conditions[] = "c.branch_id = ?";
            $params[] = $branch_id;
        }
    } elseif ($role === 'employee') {
        $stmt = $pdo->prepare("SELECT branch_id FROM employee_assignments WHERE employee_id = ? LIMIT 1");
        $stmt->execute([$user_id]);
        $branch_id = $stmt->fetchColumn();
        if ($branch_id) {
            $conditions[] = "c.branch_id = ?";
            $params[] = $branch_id;
        }
    } else {
        if ($role !== 'admin') {
            $conditions[] = "c.branch_id IN (SELECT id FROM branches WHERE owner_id = ?)";
            $params[] = $user_id;
        }
    }

    if (!empty($_GET['status'])) {
        $conditions[] = "c.status = ?";
        $params[] = $_GET['status'];
    }

    if (!empty($_GET['search'])) {
        $conditions[] = "(c.id LIKE ? OR u.username LIKE ? OR r.name LIKE ?)";
        $search = '%' . $_GET['search'] . '%';
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
    }

    $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
    $query = "
        SELECT c.id, c.room_id, c.user_id, c.start_date, c.end_date, c.status, c.created_at, c.deposit,
               c.branch_id, r.name AS room_name, u.username AS user_name, b.name AS branch_name
        FROM contracts c
        JOIN rooms r ON c.room_id = r.id
        JOIN users u ON c.user_id = u.id
        JOIN branches b ON c.branch_id = b.id
        $whereClause
        LIMIT $limit OFFSET $offset
    ";

    try {
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM contracts c JOIN rooms r ON c.room_id = r.id JOIN users u ON c.user_id = u.id JOIN branches b ON c.branch_id = b.id $whereClause");
        $countStmt->execute($params);
        $totalRecords = $countStmt->fetchColumn();
        $totalPages = ceil($totalRecords / $limit);

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    } catch (PDOException $e) {
        error_log("Lỗi lấy danh sách hợp đồng: " . $e->getMessage());
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
    validateRequiredFields($input, ['room_id', 'user_id', 'start_date', 'end_date', 'branch_id']);
    $data = sanitizeInput($input);

    try {
        // Kiểm tra quyền truy cập
        checkResourceExists($pdo, 'rooms', $data['room_id']);
        checkResourceExists($pdo, 'users', $data['user_id']);
        checkResourceExists($pdo, 'branches', $data['branch_id']);

        if ($role === 'owner' || $role === 'employee') {
            $stmt = $pdo->prepare("
                SELECT 1 
                FROM rooms r 
                JOIN branches b ON r.branch_id = b.id 
                WHERE r.id = ? AND (b.owner_id = ? OR EXISTS (
                    SELECT 1 FROM employee_assignments ea 
                    WHERE ea.branch_id = b.id AND ea.employee_id = ?
                ))
            ");
            $stmt->execute([$data['room_id'], $user_id, $user_id]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Không có quyền tạo hợp đồng cho phòng này'], 403);
                return;
            }
        }

        // Kiểm tra phòng có đang available
        $stmt = $pdo->prepare("SELECT status FROM rooms WHERE id = ?");
        $stmt->execute([$data['room_id']]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($room['status'] !== 'available') {
            responseJson(['status' => 'error', 'message' => 'Phòng không khả dụng'], 400);
            return;
        }

        // Tạo hợp đồng
        $stmt = $pdo->prepare("
            INSERT INTO contracts 
            (room_id, user_id, start_date, end_date, status, created_at, created_by, branch_id, deposit) 
            VALUES (?, ?, ?, ?, 'active', NOW(), ?, ?, ?)
        ");
        $stmt->execute([
            $data['room_id'],
            $data['user_id'],
            $data['start_date'],
            $data['end_date'],
            $user_id,
            $data['branch_id'],
            $data['deposit'] ?? 0
        ]);
        $contractId = $pdo->lastInsertId();

        // Cập nhật trạng thái phòng
        $stmt = $pdo->prepare("UPDATE rooms SET status = 'occupied' WHERE id = ?");
        $stmt->execute([$data['room_id']]);

        createNotification($pdo, $data['user_id'], "Hợp đồng thuê phòng ID $contractId đã được tạo.");
        responseJson(['status' => 'success', 'message' => 'Tạo hợp đồng thành công', 'data' => ['id' => $contractId]]);
    } catch (PDOException $e) {
        error_log("Lỗi tạo hợp đồng: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Cập nhật hợp đồng
function updateContract() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if ($role !== 'admin' && $role !== 'owner' && $role !== 'employee') {
        responseJson(['status' => 'error', 'message' => 'Không có quyền cập nhật hợp đồng'], 403);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['id', 'room_id', 'user_id', 'start_date', 'end_date', 'branch_id']);
    $data = sanitizeInput($input);

    try {
        // Kiểm tra quyền truy cập
        checkResourceExists($pdo, 'contracts', $data['id']);
        checkResourceExists($pdo, 'rooms', $data['room_id']);
        checkResourceExists($pdo, 'users', $data['user_id']);
        checkResourceExists($pdo, 'branches', $data['branch_id']);

        if ($role === 'owner' || $role === 'employee') {
            $stmt = $pdo->prepare("
                SELECT 1 
                FROM contracts c 
                JOIN rooms r ON c.room_id = r.id 
                JOIN branches b ON c.branch_id = b.id 
                WHERE c.id = ? AND (b.owner_id = ? OR EXISTS (
                    SELECT 1 FROM employee_assignments ea 
                    WHERE ea.branch_id = b.id AND ea.employee_id = ?
                ))
            ");
            $stmt->execute([$data['id'], $user_id, $user_id]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Không có quyền cập nhật hợp đồng này'], 403);
                return;
            }
        }

        // Kiểm tra trạng thái phòng nếu thay đổi room_id
        if ($data['room_id'] !== $data['current_room_id']) {
            $stmt = $pdo->prepare("SELECT status FROM rooms WHERE id = ?");
            $stmt->execute([$data['room_id']]);
            $room = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($room['status'] !== 'available') {
                responseJson(['status' => 'error', 'message' => 'Phòng không khả dụng'], 400);
                return;
            }
        }

        // Cập nhật hợp đồng
        $stmt = $pdo->prepare("
            UPDATE contracts 
            SET room_id = ?, user_id = ?, start_date = ?, end_date = ?, 
                status = ?, deposit = ?, branch_id = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $data['room_id'],
            $data['user_id'],
            $data['start_date'],
            $data['end_date'],
            $data['status'] ?? 'active',
            $data['deposit'] ?? 0,
            $data['branch_id'],
            $data['id']
        ]);

        // Cập nhật trạng thái phòng
        if ($data['room_id'] !== $data['current_room_id']) {
            $stmt = $pdo->prepare("UPDATE rooms SET status = 'occupied' WHERE id = ?");
            $stmt->execute([$data['room_id']]);
            $stmt = $pdo->prepare("UPDATE rooms SET status = 'available' WHERE id = ?");
            $stmt->execute([$data['current_room_id']]);
        }

        createNotification($pdo, $data['user_id'], "Hợp đồng ID {$data['id']} đã được cập nhật.");
        responseJson(['status' => 'success', 'message' => 'Cập nhật hợp đồng thành công', 'data' => $data]);
    } catch (PDOException $e) {
        error_log("Lỗi cập nhật hợp đồng: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Xóa hợp đồng
function deleteContract() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if ($role !== 'admin' && $role !== 'owner') {
        responseJson(['status' => 'error', 'message' => 'Không có quyền xóa hợp đồng'], 403);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['id']);
    $contract_id = (int)$input['id'];

    try {
        checkResourceExists($pdo, 'contracts', $contract_id);

        if ($role === 'owner') {
            $stmt = $pdo->prepare("
                SELECT 1 
                FROM contracts c 
                JOIN branches b ON c.branch_id = b.id 
                WHERE c.id = ? AND b.owner_id = ?
            ");
            $stmt->execute([$contract_id, $user_id]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Không có quyền xóa hợp đồng này'], 403);
                return;
            }
        }

        // Lấy room_id trước khi xóa
        $stmt = $pdo->prepare("SELECT room_id, user_id FROM contracts WHERE id = ?");
        $stmt->execute([$contract_id]);
        $contract = $stmt->fetch(PDO::FETCH_ASSOC);

        // Xóa hợp đồng (các bảng liên quan như payments, invoices sẽ tự động xóa do ON DELETE CASCADE)
        $stmt = $pdo->prepare("DELETE FROM contracts WHERE id = ?");
        $stmt->execute([$contract_id]);

        // Cập nhật trạng thái phòng
        $stmt = $pdo->prepare("UPDATE rooms SET status = 'available' WHERE id = ?");
        $stmt->execute([$contract['room_id']]);

        createNotification($pdo, $contract['user_id'], "Hợp đồng ID $contract_id đã bị xóa.");
        responseJson(['status' => 'success', 'message' => 'Xóa hợp đồng thành công']);
    } catch (PDOException $e) {
        error_log("Lỗi xóa hợp đồng` đồng: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}
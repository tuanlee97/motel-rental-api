<?php
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/utils/common.php';

function getMaintenanceRequests() {
    $pdo = getDB();
    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $conditions = [];
    $params = [];

    if (!empty($_GET['room_id']) && filter_var($_GET['room_id'], FILTER_VALIDATE_INT)) {
        $conditions[] = "mr.room_id = ?";
        $params[] = $_GET['room_id'];
    }
    if (!empty($_GET['status']) && in_array($_GET['status'], ['pending', 'in_progress', 'completed'])) {
        $conditions[] = "mr.status = ?";
        $params[] = $_GET['status'];
    }
    if (!empty($_GET['priority']) && in_array($_GET['priority'], ['low', 'medium', 'high'])) {
        $conditions[] = "mr.priority = ?";
        $params[] = $_GET['priority'];
    }

    if (!empty($_GET['search'])) {
        $search = '%' . sanitizeInput($_GET['search']) . '%';
        $conditions[] = "mr.description LIKE ?";
        $params[] = $search;
    }

    $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
    $query = "
        SELECT mr.*, r.name AS room_name, b.name AS branch_name
        FROM maintenance_requests mr
        LEFT JOIN rooms r ON mr.room_id = r.id
        LEFT JOIN branches b ON mr.branch_id = b.id
        $whereClause
    ";

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM maintenance_requests mr $whereClause");
    $countStmt->execute($params);
    $totalRecords = $countStmt->fetchColumn();
    $totalPages = ceil($totalRecords / $limit);

    $query .= " LIMIT $limit OFFSET $offset"; 

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $requests = $stmt->fetchAll();

    responseJson([
        'status' => 'success',
        'data' => $requests,
        'pagination' => [
            'current_page' => $page,
            'limit' => $limit,
            'total_records' => $totalRecords,
            'total_pages' => $totalPages
        ]
    ]);
}
function createMaintenanceRequest() {
    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['room_id', 'description']);
    $user = verifyJWT();

    $roomId = filter_var($input['room_id'], FILTER_VALIDATE_INT);
    $description = sanitizeInput($input['description']);
    $status = in_array($input['status'] ?? 'pending', ['pending', 'in_progress', 'completed']) ? $input['status'] : 'pending';

    if (!$roomId) {
        responseJson(['status' => 'error', 'message' => 'Dữ liệu không hợp lệ'], 400);
    }

    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'rooms', $roomId);
        if ($user['role'] === 'customer') {
            $stmt = $pdo->prepare("SELECT id FROM contracts WHERE room_id = ? AND user_id = ? AND status = 'active'");
            $stmt->execute([$roomId, $user['user_id']]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Bạn không có quyền yêu cầu bảo trì cho phòng này'], 403);
            }
        } elseif ($user['role'] === 'owner') {
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
            responseJson(['status' => 'error', 'message' => 'Không có quyền tạo yêu cầu bảo trì'], 403);
        }

        $stmt = $pdo->prepare("
            INSERT INTO maintenance_requests (room_id, user_id, description, status)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$roomId, $user['user_id'], $description, $status]);

        $requestId = $pdo->lastInsertId();
        createNotification($pdo, $user['user_id'], "Yêu cầu bảo trì ID $requestId đã được tạo.");
        responseJson(['status' => 'success', 'data' => ['request_id' => $requestId]]);
    } catch (Exception $e) {
        logError('Lỗi tạo maintenance_request: ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function getMaintenanceRequestById() {
    $requestId = getResourceIdFromUri('#/maintenance_requests/([0-9]+)#');
    $user = verifyJWT();
    $pdo = getDB();
    try {
        $query = "
            SELECT mr.id, mr.room_id, mr.user_id, mr.description, mr.status, mr.created_at, mr.updated_at,
                   r.name AS room_name, u.username AS user_name
            FROM maintenance_requests mr
            JOIN rooms r ON mr.room_id = r.id
            JOIN users u ON mr.user_id = u.id
            WHERE mr.id = ?
        ";
        $params = [$requestId];
        if ($user['role'] === 'owner') {
            $query .= " AND r.branch_id IN (SELECT id FROM branches WHERE owner_id = ?)";
            $params[] = $user['user_id'];
        } elseif ($user['role'] === 'customer') {
            $query .= " AND mr.user_id = ?";
            $params[] = $user['user_id'];
        } elseif ($user['role'] === 'employee') {
            $query .= " AND r.branch_id IN (SELECT branch_id FROM employee_assignments WHERE employee_id = ?)";
            $params[] = $user['user_id'];
        }

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $request = $stmt->fetch();

        if (!$request) {
            responseJson(['status' => 'error', 'message' => 'Không tìm thấy yêu cầu bảo trì'], 404);
        }
        responseJson(['status' => 'success', 'data' => $request]);
    } catch (Exception $e) {
        logError('Lỗi lấy maintenance_request ID ' . $requestId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi truy vấn'], 500);
    }
}

function updateMaintenanceRequest() {
    $requestId = getResourceIdFromUri('#/maintenance_requests/([0-9]+)#');
    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['room_id', 'description', 'status']);
    $user = verifyJWT();

    $roomId = filter_var($input['room_id'], FILTER_VALIDATE_INT);
    $description = sanitizeInput($input['description']);
    $status = in_array($input['status'], ['pending', 'in_progress', 'completed']) ? $input['status'] : 'pending';

    if (!$roomId) {
        responseJson(['status' => 'error', 'message' => 'Dữ liệu không hợp lệ'], 400);
    }

    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'maintenance_requests', $requestId);
        if ($user['role'] === 'owner') {
            $stmt = $pdo->prepare("SELECT r.id FROM rooms r JOIN branches b ON r.branch_id = b.id WHERE r.id = ? AND b.owner_id = ?");
            $stmt->execute([$roomId, $user['user_id']]);
        } elseif ($user['role'] === 'employee') {
            $stmt = $pdo->prepare("SELECT r.id FROM rooms r WHERE r.id = ? AND r.branch_id IN (SELECT branch_id FROM employee_assignments WHERE employee_id = ?)");
            $stmt->execute([$roomId, $user['user_id']]);
        } else {
            responseJson(['status' => 'error', 'message' => 'Không có quyền chỉnh sửa yêu cầu bảo trì'], 403);
        }
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Phòng không hợp lệ hoặc bạn không có quyền'], 403);
        }

        $stmt = $pdo->prepare("
            UPDATE maintenance_requests SET room_id = ?, description = ?, status = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$roomId, $description, $status, $requestId]);

        createNotification($pdo, $user['user_id'], "Yêu cầu bảo trì ID $requestId đã được cập nhật.");
        responseJson(['status' => 'success', 'message' => 'Cập nhật yêu cầu bảo trì thành công']);
    } catch (Exception $e) {
        logError('Lỗi cập nhật maintenance_request ID ' . $requestId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function patchMaintenanceRequest() {
    $requestId = getResourceIdFromUri('#/maintenance_requests/([0-9]+)#');
    $input = json_decode(file_get_contents('php://input'), true);
    $user = verifyJWT();

    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'maintenance_requests', $requestId);
        if ($user['role'] === 'owner') {
            $stmt = $pdo->prepare("SELECT mr.id FROM maintenance_requests mr JOIN rooms r ON mr.room_id = r.id JOIN branches b ON r.branch_id = b.id WHERE mr.id = ? AND b.owner_id = ?");
            $stmt->execute([$requestId, $user['user_id']]);
        } elseif ($user['role'] === 'employee') {
            $stmt = $pdo->prepare("SELECT mr.id FROM maintenance_requests mr JOIN rooms r ON mr.room_id = r.id WHERE mr.id = ? AND r.branch_id IN (SELECT branch_id FROM employee_assignments WHERE employee_id = ?)");
            $stmt->execute([$requestId, $user['user_id']]);
        } elseif ($user['role'] === 'customer') {
            $stmt = $pdo->prepare("SELECT id FROM maintenance_requests WHERE id = ? AND user_id = ?");
            $stmt->execute([$requestId, $user['user_id']]);
        } else {
            responseJson(['status' => 'error', 'message' => 'Không có quyền chỉnh sửa yêu cầu bảo trì'], 403);
        }
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Yêu cầu bảo trì không hợp lệ hoặc bạn không có quyền'], 403);
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
            } elseif ($user['role'] === 'customer') {
                $stmt = $pdo->prepare("SELECT id FROM contracts WHERE room_id = ? AND user_id = ? AND status = 'active'");
                $stmt->execute([$roomId, $user['user_id']]);
            }
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Phòng không hợp lệ hoặc bạn không có quyền'], 403);
            }
            $updates[] = "room_id = ?";
            $params[] = $roomId;
        }
        if (!empty($input['description'])) {
            $updates[] = "description = ?";
            $params[] = sanitizeInput($input['description']);
        }
        if (!empty($input['status'])) {
            $status = in_array($input['status'], ['pending', 'in_progress', 'completed']) ? $input['status'] : 'pending';
            if ($user['role'] === 'customer' && $status !== 'pending') {
                responseJson(['status' => 'error', 'message' => 'Khách hàng chỉ có thể đặt trạng thái pending'], 403);
            }
            $updates[] = "status = ?";
            $params[] = $status;
        }

        if (empty($updates)) {
            responseJson(['status' => 'error', 'message' => 'Không có dữ liệu để cập nhật'], 400);
        }

        $updates[] = "updated_at = NOW()";
        $query = "UPDATE maintenance_requests SET " . implode(', ', $updates) . " WHERE id = ?";
        $params[] = $requestId;
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);

        createNotification($pdo, $user['user_id'], "Yêu cầu bảo trì ID $requestId đã được cập nhật.");
        responseJson(['status' => 'success', 'message' => 'Cập nhật yêu cầu bảo trì thành công']);
    } catch (Exception $e) {
        logError('Lỗi patch maintenance_request ID ' . $requestId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function deleteMaintenanceRequest() {
    $requestId = getResourceIdFromUri('#/maintenance_requests/([0-9]+)#');
    $user = verifyJWT();
    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'maintenance_requests', $requestId);
        if ($user['role'] === 'owner') {
            $stmt = $pdo->prepare("SELECT mr.id FROM maintenance_requests mr JOIN rooms r ON mr.room_id = r.id JOIN branches b ON r.branch_id = b.id WHERE mr.id = ? AND b.owner_id = ?");
            $stmt->execute([$requestId, $user['user_id']]);
        } elseif ($user['role'] === 'employee') {
            $stmt = $pdo->prepare("SELECT mr.id FROM maintenance_requests mr JOIN rooms r ON mr.room_id = r.id WHERE mr.id = ? AND r.branch_id IN (SELECT branch_id FROM employee_assignments WHERE employee_id = ?)");
            $stmt->execute([$requestId, $user['user_id']]);
        } else {
            responseJson(['status' => 'error', 'message' => 'Không có quyền xóa yêu cầu bảo trì'], 403);
        }
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Yêu cầu bảo trì không hợp lệ hoặc bạn không có quyền'], 403);
        }

        $stmt = $pdo->prepare("DELETE FROM maintenance_requests WHERE id = ?");
        $stmt->execute([$requestId]);
        responseJson(['status' => 'success', 'message' => 'Xóa yêu cầu bảo trì thành công']);
    } catch (Exception $e) {
        logError('Lỗi xóa maintenance_request ID ' . $requestId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}
?>
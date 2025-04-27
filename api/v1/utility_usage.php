<?php
require_once __DIR__ . '/../../core/database.php';
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/common.php';

function getUtilityUsage() {
    $pdo = getDB();
    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $conditions = [];
    $params = [];

    if (!empty($_GET['room_id']) && filter_var($_GET['room_id'], FILTER_VALIDATE_INT)) {
        $conditions[] = "uu.room_id = ?";
        $params[] = $_GET['room_id'];
    }
    if (!empty($_GET['contract_id']) && filter_var($_GET['contract_id'], FILTER_VALIDATE_INT)) {
        $conditions[] = "uu.contract_id = ?";
        $params[] = $_GET['contract_id'];
    }
    if (!empty($_GET['type']) && in_array($_GET['type'], ['electricity', 'water'])) {
        $conditions[] = "uu.type = ?";
        $params[] = $_GET['type'];
    }
    if (!empty($_GET['month']) && is_numeric($_GET['month']) && $_GET['month'] >= 1 && $_GET['month'] <= 12) {
        $conditions[] = "uu.month = ?";
        $params[] = (int)$_GET['month'];
    }
    if (!empty($_GET['year']) && is_numeric($_GET['year']) && $_GET['year'] >= 2000) {
        $conditions[] = "uu.year = ?";
        $params[] = (int)$_GET['year'];
    }

    $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
    $query = "
        SELECT uu.*, r.name AS room_name, c.user_id, u.name AS customer_name
        FROM utility_usage uu
        LEFT JOIN rooms r ON uu.room_id = r.id
        LEFT JOIN contracts c ON uu.contract_id = c.id
        LEFT JOIN users u ON c.user_id = u.id
        $whereClause
    ";

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM utility_usage uu $whereClause");
    $countStmt->execute($params);
    $totalRecords = $countStmt->fetchColumn();
    $totalPages = ceil($totalRecords / $limit);

    $query .= " LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $usage = $stmt->fetchAll();

    responseJson([
        'status' => 'success',
        'data' => $usage,
        'pagination' => [
            'current_page' => $page,
            'limit' => $limit,
            'total_records' => $totalRecords,
            'total_pages' => $totalPages
        ]
    ]);
}
function createUtilityUsage() {
    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['room_id', 'service_id', 'month', 'usage_amount']);
    $user = verifyJWT();

    $roomId = filter_var($input['room_id'], FILTER_VALIDATE_INT);
    $serviceId = filter_var($input['service_id'], FILTER_VALIDATE_INT);
    $month = validateMonth($input['month']);
    $usageAmount = filter_var($input['usage_amount'], FILTER_VALIDATE_FLOAT);
    $customPrice = !empty($input['custom_price']) ? filter_var($input['custom_price'], FILTER_VALIDATE_FLOAT) : null;

    if (!$roomId || !$serviceId || !$month || !$usageAmount || ($customPrice !== null && $customPrice === false)) {
        responseJson(['status' => 'error', 'message' => 'Dữ liệu không hợp lệ'], 400);
    }

    $pdo = getDB();
    try {
        if ($user['role'] === 'owner') {
            $stmt = $pdo->prepare("SELECT r.id FROM rooms r JOIN branches b ON r.branch_id = b.id WHERE r.id = ? AND b.owner_id = ?");
            $stmt->execute([$roomId, $user['user_id']]);
        } elseif ($user['role'] === 'employee') {
            $stmt = $pdo->prepare("SELECT r.id FROM rooms r WHERE r.id = ? AND r.branch_id IN (SELECT branch_id FROM employee_assignments WHERE employee_id = ?)");
            $stmt->execute([$roomId, $user['user_id']]);
        } else {
            responseJson(['status' => 'error', 'message' => 'Không có quyền tạo sử dụng dịch vụ'], 403);
        }
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Phòng không hợp lệ hoặc bạn không có quyền'], 403);
        }

        checkResourceExists($pdo, 'services', $serviceId);
        $stmt = $pdo->prepare("SELECT id FROM utility_usage WHERE room_id = ? AND service_id = ? AND month = ?");
        $stmt->execute([$roomId, $serviceId, $month]);
        if ($stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Sử dụng dịch vụ cho phòng, dịch vụ và tháng này đã tồn tại'], 409);
        }

        $stmt = $pdo->prepare("
            INSERT INTO utility_usage (room_id, service_id, month, usage_amount, custom_price)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$roomId, $serviceId, $month, $usageAmount, $customPrice]);

        $usageId = $pdo->lastInsertId();
        createNotification($pdo, $user['user_id'], "Sử dụng dịch vụ ID $usageId đã được ghi nhận.");
        responseJson(['status' => 'success', 'data' => ['usage_id' => $usageId]]);
    } catch (Exception $e) {
        logError('Lỗi tạo utility_usage: ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function getUtilityUsageById() {
    $usageId = getResourceIdFromUri('#/utility_usages/([0-9]+)#');
    $user = verifyJWT();
    $pdo = getDB();
    try {
        $query = "
            SELECT uu.id, uu.room_id, uu.service_id, uu.month, uu.usage_amount, uu.custom_price, uu.recorded_at,
                   r.name AS room_name, s.name AS service_name
            FROM utility_usage uu
            JOIN rooms r ON uu.room_id = r.id
            JOIN services s ON uu.service_id = s.id
            WHERE uu.id = ?
        ";
        $params = [$usageId];
        if ($user['role'] === 'owner') {
            $query .= " AND r.branch_id IN (SELECT id FROM branches WHERE owner_id = ?)";
            $params[] = $user['user_id'];
        } elseif ($user['role'] === 'customer') {
            $query .= " AND r.id IN (SELECT room_id FROM contracts WHERE user_id = ?)";
            $params[] = $user['user_id'];
        } elseif ($user['role'] === 'employee') {
            $query .= " AND r.branch_id IN (SELECT branch_id FROM employee_assignments WHERE employee_id = ?)";
            $params[] = $user['user_id'];
        }

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $usage = $stmt->fetch();

        if (!$usage) {
            responseJson(['status' => 'error', 'message' => 'Không tìm thấy sử dụng dịch vụ'], 404);
        }
        responseJson(['status' => 'success', 'data' => $usage]);
    } catch (Exception $e) {
        logError('Lỗi lấy utility_usage ID ' . $usageId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi truy vấn'], 500);
    }
}

function updateUtilityUsage() {
    $usageId = getResourceIdFromUri('#/utility_usages/([0-9]+)#');
    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['room_id', 'service_id', 'month', 'usage_amount']);
    $user = verifyJWT();

    $roomId = filter_var($input['room_id'], FILTER_VALIDATE_INT);
    $serviceId = filter_var($input['service_id'], FILTER_VALIDATE_INT);
    $month = validateMonth($input['month']);
    $usageAmount = filter_var($input['usage_amount'], FILTER_VALIDATE_FLOAT);
    $customPrice = !empty($input['custom_price']) ? filter_var($input['custom_price'], FILTER_VALIDATE_FLOAT) : null;

    if (!$roomId || !$serviceId || !$month || !$usageAmount || ($customPrice !== null && $customPrice === false)) {
        responseJson(['status' => 'error', 'message' => 'Dữ liệu không hợp lệ'], 400);
    }

    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'utility_usage', $usageId);
        if ($user['role'] === 'owner') {
            $stmt = $pdo->prepare("SELECT r.id FROM rooms r JOIN branches b ON r.branch_id = b.id WHERE r.id = ? AND b.owner_id = ?");
            $stmt->execute([$roomId, $user['user_id']]);
        } elseif ($user['role'] === 'employee') {
            $stmt = $pdo->prepare("SELECT r.id FROM rooms r WHERE r.id = ? AND r.branch_id IN (SELECT branch_id FROM employee_assignments WHERE employee_id = ?)");
            $stmt->execute([$roomId, $user['user_id']]);
        } else {
            responseJson(['status' => 'error', 'message' => 'Không có quyền chỉnh sửa sử dụng dịch vụ'], 403);
        }
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Phòng không hợp lệ hoặc bạn không có quyền'], 403);
        }

        checkResourceExists($pdo, 'services', $serviceId);
        $stmt = $pdo->prepare("SELECT id FROM utility_usage WHERE room_id = ? AND service_id = ? AND month = ? AND id != ?");
        $stmt->execute([$roomId, $serviceId, $month, $usageId]);
        if ($stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Sử dụng dịch vụ cho phòng, dịch vụ và tháng này đã tồn tại'], 409);
        }

        $stmt = $pdo->prepare("
            UPDATE utility_usage SET room_id = ?, service_id = ?, month = ?, usage_amount = ?, custom_price = ?
            WHERE id = ?
        ");
        $stmt->execute([$roomId, $serviceId, $month, $usageAmount, $customPrice, $usageId]);

        createNotification($pdo, $user['user_id'], "Sử dụng dịch vụ ID $usageId đã được cập nhật.");
        responseJson(['status' => 'success', 'message' => 'Cập nhật sử dụng dịch vụ thành công']);
    } catch (Exception $e) {
        logError('Lỗi cập nhật utility_usage ID ' . $usageId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function patchUtilityUsage() {
    $usageId = getResourceIdFromUri('#/utility_usages/([0-9]+)#');
    $input = json_decode(file_get_contents('php://input'), true);
    $user = verifyJWT();

    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'utility_usage', $usageId);
        if ($user['role'] === 'owner') {
            $stmt = $pdo->prepare("SELECT uu.id FROM utility_usage uu JOIN rooms r ON uu.room_id = r.id JOIN branches b ON r.branch_id = b.id WHERE uu.id = ? AND b.owner_id = ?");
            $stmt->execute([$usageId, $user['user_id']]);
        } elseif ($user['role'] === 'employee') {
            $stmt = $pdo->prepare("SELECT uu.id FROM utility_usage uu JOIN rooms r ON uu.room_id = r.id WHERE uu.id = ? AND r.branch_id IN (SELECT branch_id FROM employee_assignments WHERE employee_id = ?)");
            $stmt->execute([$usageId, $user['user_id']]);
        } else {
            responseJson(['status' => 'error', 'message' => 'Không có quyền chỉnh sửa sử dụng dịch vụ'], 403);
        }
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Sử dụng dịch vụ không hợp lệ hoặc bạn không có quyền'], 403);
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
                responseJson(['status' => 'error', 'message' => 'Phòng không hợp lệ'], 403);
            }
            $updates[] = "room_id = ?";
            $params[] = $roomId;
        }
        if (!empty($input['service_id'])) {
            $serviceId = filter_var($input['service_id'], FILTER_VALIDATE_INT);
            checkResourceExists($pdo, 'services', $serviceId);
            $updates[] = "service_id = ?";
            $params[] = $serviceId;
        }
        if (!empty($input['month'])) {
            $month = validateMonth($input['month']);
            $updates[] = "month = ?";
            $params[] = $month;
        }
        if (isset($input['usage_amount'])) {
            $usageAmount = filter_var($input['usage_amount'], FILTER_VALIDATE_FLOAT);
            if ($usageAmount === false) {
                responseJson(['status' => 'error', 'message' => 'Số lượng sử dụng không hợp lệ'], 400);
            }
            $updates[] = "usage_amount = ?";
            $params[] = $usageAmount;
        }
        if (isset($input['custom_price'])) {
            $customPrice = $input['custom_price'] ? filter_var($input['custom_price'], FILTER_VALIDATE_FLOAT) : null;
            if ($customPrice === false) {
                responseJson(['status' => 'error', 'message' => 'Giá tùy chỉnh không hợp lệ'], 400);
            }
            $updates[] = "custom_price = ?";
            $params[] = $customPrice;
        }

        if (empty($updates)) {
            responseJson(['status' => 'error', 'message' => 'Không có dữ liệu để cập nhật'], 400);
        }

        // Kiểm tra trùng lặp
        $stmt = $pdo->prepare("SELECT room_id, service_id, month FROM utility_usage WHERE id = ?");
        $stmt->execute([$usageId]);
        $current = $stmt->fetch();
        $newRoomId = $input['room_id'] ?? $current['room_id'];
        $newServiceId = $input['service_id'] ?? $current['service_id'];
        $newMonth = $input['month'] ?? $current['month'];
        $stmt = $pdo->prepare("SELECT id FROM utility_usage WHERE room_id = ? AND service_id = ? AND month = ? AND id != ?");
        $stmt->execute([$newRoomId, $newServiceId, $newMonth, $usageId]);
        if ($stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Sử dụng dịch vụ cho phòng, dịch vụ và tháng này đã tồn tại'], 409);
        }

        $query = "UPDATE utility_usage SET " . implode(', ', $updates) . " WHERE id = ?";
        $params[] = $usageId;
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);

        createNotification($pdo, $user['user_id'], "Sử dụng dịch vụ ID $usageId đã được cập nhật.");
        responseJson(['status' => 'success', 'message' => 'Cập nhật sử dụng dịch vụ thành công']);
    } catch (Exception $e) {
        logError('Lỗi patch utility_usage ID ' . $usageId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function deleteUtilityUsage() {
    $usageId = getResourceIdFromUri('#/utility_usages/([0-9]+)#');
    $user = verifyJWT();
    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'utility_usage', $usageId);
        if ($user['role'] === 'owner') {
            $stmt = $pdo->prepare("SELECT uu.id FROM utility_usage uu JOIN rooms r ON uu.room_id = r.id JOIN branches b ON r.branch_id = b.id WHERE uu.id = ? AND b.owner_id = ?");
            $stmt->execute([$usageId, $user['user_id']]);
        } elseif ($user['role'] === 'employee') {
            $stmt = $pdo->prepare("SELECT uu.id FROM utility_usage uu JOIN rooms r ON uu.room_id = r.id WHERE uu.id = ? AND r.branch_id IN (SELECT branch_id FROM employee_assignments WHERE employee_id = ?)");
            $stmt->execute([$usageId, $user['user_id']]);
        } else {
            responseJson(['status' => 'error', 'message' => 'Không có quyền xóa sử dụng dịch vụ'], 403);
        }
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Sử dụng dịch vụ không hợp lệ hoặc bạn không có quyền'], 403);
        }

        $stmt = $pdo->prepare("DELETE FROM utility_usage WHERE id = ?");
        $stmt->execute([$usageId]);
        responseJson(['status' => 'success', 'message' => 'Xóa sử dụng dịch vụ thành công']);
    } catch (Exception $e) {
        logError('Lỗi xóa utility_usage ID ' . $usageId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

// Hàm hỗ trợ validate tháng (YYYY-MM)
function validateMonth($month) {
    $d = DateTime::createFromFormat('Y-m', $month);
    if (!$d || $d->format('Y-m') !== $month) {
        responseJson(['status' => 'error', 'message' => 'Định dạng tháng không hợp lệ (YYYY-MM)'], 400);
    }
    return $month;
}
?>
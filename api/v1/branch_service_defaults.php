<?php
require_once __DIR__ . '/../../core/database.php';
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/common.php';

function getBranchServiceDefaults() {
    $pdo = getDB();
    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $conditions = [];
    $params = [];

    if (!empty($_GET['branch_id']) && filter_var($_GET['branch_id'], FILTER_VALIDATE_INT)) {
        $conditions[] = "bsd.branch_id = ?";
        $params[] = $_GET['branch_id'];
    }
    if (!empty($_GET['service_id']) && filter_var($_GET['service_id'], FILTER_VALIDATE_INT)) {
        $conditions[] = "bsd.service_id = ?";
        $params[] = $_GET['service_id'];
    }

    $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
    $query = "
        SELECT bsd.*, b.name AS branch_name, s.name AS service_name
        FROM branch_service_defaults bsd
        LEFT JOIN branches b ON bsd.branch_id = b.id
        LEFT JOIN services s ON bsd.service_id = s.id
        $whereClause
    ";

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM branch_service_defaults bsd $whereClause");
    $countStmt->execute($params);
    $totalRecords = $countStmt->fetchColumn();
    $totalPages = ceil($totalRecords / $limit);

    $query .= " LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $defaults = $stmt->fetchAll();

    responseJson([
        'status' => 'success',
        'data' => $defaults,
        'pagination' => [
            'current_page' => $page,
            'limit' => $limit,
            'total_records' => $totalRecords,
            'total_pages' => $totalPages
        ]
    ]);
}


function createBranchServiceDefault() {
    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['branch_id', 'service_id', 'custom_price']);
    $user = verifyJWT();

    $branchId = filter_var($input['branch_id'], FILTER_VALIDATE_INT);
    $serviceId = filter_var($input['service_id'], FILTER_VALIDATE_INT);
    $customPrice = filter_var($input['custom_price'], FILTER_VALIDATE_FLOAT);

    if (!$branchId || !$serviceId || !$customPrice || $customPrice < 0) {
        responseJson(['status' => 'error', 'message' => 'Dữ liệu không hợp lệ'], 400);
    }

    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'branches', $branchId);
        checkResourceExists($pdo, 'services', $serviceId);
        if ($user['role'] === 'owner') {
            $stmt = $pdo->prepare("SELECT id FROM branches WHERE id = ? AND owner_id = ?");
            $stmt->execute([$branchId, $user['user_id']]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Chi nhánh không hợp lệ hoặc bạn không có quyền'], 403);
            }
        } elseif ($user['role'] !== 'admin') {
            responseJson(['status' => 'error', 'message' => 'Không có quyền tạo giá dịch vụ mặc định'], 403);
        }

        $stmt = $pdo->prepare("SELECT id FROM branch_service_defaults WHERE branch_id = ? AND service_id = ?");
        $stmt->execute([$branchId, $serviceId]);
        if ($stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Giá dịch vụ mặc định cho chi nhánh này đã tồn tại'], 409);
        }

        $stmt = $pdo->prepare("
            INSERT INTO branch_service_defaults (branch_id, service_id, custom_price)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$branchId, $serviceId, $customPrice]);

        $defaultId = $pdo->lastInsertId();
        createNotification($pdo, $user['user_id'], "Giá dịch vụ mặc định ID $defaultId đã được tạo.");
        responseJson(['status' => 'success', 'data' => ['default_id' => $defaultId]]);
    } catch (Exception $e) {
        logError('Lỗi tạo branch_service_default: ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function getBranchServiceDefaultById() {
    $defaultId = getResourceIdFromUri('#/branch_service_defaults/([0-9]+)#');
    $user = verifyJWT();
    $pdo = getDB();
    try {
        $query = "
            SELECT bsd.id, bsd.branch_id, bsd.service_id, bsd.custom_price, b.name AS branch_name, s.name AS service_name
            FROM branch_service_defaults bsd
            JOIN branches b ON bsd.branch_id = b.id
            JOIN services s ON bsd.service_id = s.id
            WHERE bsd.id = ?
        ";
        $params = [$defaultId];
        if ($user['role'] === 'owner') {
            $query .= " AND b.owner_id = ?";
            $params[] = $user['user_id'];
        } elseif ($user['role'] === 'employee') {
            $query .= " AND b.id IN (SELECT branch_id FROM employee_assignments WHERE employee_id = ?)";
            $params[] = $user['user_id'];
        }

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $default = $stmt->fetch();

        if (!$default) {
            responseJson(['status' => 'error', 'message' => 'Không tìm thấy giá dịch vụ mặc định'], 404);
        }
        responseJson(['status' => 'success', 'data' => $default]);
    } catch (Exception $e) {
        logError('Lỗi lấy branch_service_default ID ' . $defaultId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi truy vấn'], 500);
    }
}

function updateBranchServiceDefault() {
    $defaultId = getResourceIdFromUri('#/branch_service_defaults/([0-9]+)#');
    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['branch_id', 'service_id', 'custom_price']);
    $user = verifyJWT();

    $branchId = filter_var($input['branch_id'], FILTER_VALIDATE_INT);
    $serviceId = filter_var($input['service_id'], FILTER_VALIDATE_INT);
    $customPrice = filter_var($input['custom_price'], FILTER_VALIDATE_FLOAT);

    if (!$branchId || !$serviceId || !$customPrice || $customPrice < 0) {
        responseJson(['status' => 'error', 'message' => 'Dữ liệu không hợp lệ'], 400);
    }

    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'branch_service_defaults', $defaultId);
        checkResourceExists($pdo, 'branches', $branchId);
        checkResourceExists($pdo, 'services', $serviceId);
        if ($user['role'] === 'owner') {
            $stmt = $pdo->prepare("SELECT id FROM branches WHERE id = ? AND owner_id = ?");
            $stmt->execute([$branchId, $user['user_id']]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Chi nhánh không hợp lệ hoặc bạn không có quyền'], 403);
            }
        } elseif ($user['role'] !== 'admin') {
            responseJson(['status' => 'error', 'message' => 'Không có quyền chỉnh sửa giá dịch vụ mặc định'], 403);
        }

        $stmt = $pdo->prepare("SELECT id FROM branch_service_defaults WHERE branch_id = ? AND service_id = ? AND id != ?");
        $stmt->execute([$branchId, $serviceId, $defaultId]);
        if ($stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Giá dịch vụ mặc định cho chi nhánh này đã tồn tại'], 409);
        }

        $stmt = $pdo->prepare("
            UPDATE branch_service_defaults SET branch_id = ?, service_id = ?, custom_price = ?
            WHERE id = ?
        ");
        $stmt->execute([$branchId, $serviceId, $customPrice, $defaultId]);

        createNotification($pdo, $user['user_id'], "Giá dịch vụ mặc định ID $defaultId đã được cập nhật.");
        responseJson(['status' => 'success', 'message' => 'Cập nhật giá dịch vụ mặc định thành công']);
    } catch (Exception $e) {
        logError('Lỗi cập nhật branch_service_default ID ' . $defaultId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function patchBranchServiceDefault() {
    $defaultId = getResourceIdFromUri('#/branch_service_defaults/([0-9]+)#');
    $input = json_decode(file_get_contents('php://input'), true);
    $user = verifyJWT();

    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'branch_service_defaults', $defaultId);
        if ($user['role'] === 'owner') {
            $stmt = $pdo->prepare("SELECT bsd.id FROM branch_service_defaults bsd JOIN branches b ON bsd.branch_id = b.id WHERE bsd.id = ? AND b.owner_id = ?");
            $stmt->execute([$defaultId, $user['user_id']]);
        } elseif ($user['role'] !== 'admin') {
            responseJson(['status' => 'error', 'message' => 'Không có quyền chỉnh sửa giá dịch vụ mặc định'], 403);
        }
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Giá dịch vụ mặc định không hợp lệ hoặc bạn không có quyền'], 403);
        }

        $updates = [];
        $params = [];
        if (!empty($input['branch_id'])) {
            $branchId = filter_var($input['branch_id'], FILTER_VALIDATE_INT);
            checkResourceExists($pdo, 'branches', $branchId);
            if ($user['role'] === 'owner') {
                $stmt = $pdo->prepare("SELECT id FROM branches WHERE id = ? AND owner_id = ?");
                $stmt->execute([$branchId, $user['user_id']]);
                if (!$stmt->fetch()) {
                    responseJson(['status' => 'error', 'message' => 'Chi nhánh không hợp lệ'], 403);
                }
            }
            $updates[] = "branch_id = ?";
            $params[] = $branchId;
        }
        if (!empty($input['service_id'])) {
            $serviceId = filter_var($input['service_id'], FILTER_VALIDATE_INT);
            checkResourceExists($pdo, 'services', $serviceId);
            $updates[] = "service_id = ?";
            $params[] = $serviceId;
        }
        if (isset($input['custom_price'])) {
            $customPrice = filter_var($input['custom_price'], FILTER_VALIDATE_FLOAT);
            if ($customPrice === false || $customPrice < 0) {
                responseJson(['status' => 'error', 'message' => 'Giá tùy chỉnh không hợp lệ'], 400);
            }
            $updates[] = "custom_price = ?";
            $params[] = $customPrice;
        }

        if (empty($updates)) {
            responseJson(['status' => 'error', 'message' => 'Không có dữ liệu để cập nhật'], 400);
        }

        $stmt = $pdo->prepare("SELECT branch_id, service_id FROM branch_service_defaults WHERE id = ?");
        $stmt->execute([$defaultId]);
        $current = $stmt->fetch();
        $newBranchId = $input['branch_id'] ?? $current['branch_id'];
        $newServiceId = $input['service_id'] ?? $current['service_id'];
        $stmt = $pdo->prepare("SELECT id FROM branch_service_defaults WHERE branch_id = ? AND service_id = ? AND id != ?");
        $stmt->execute([$newBranchId, $newServiceId, $defaultId]);
        if ($stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Giá dịch vụ mặc định cho chi nhánh này đã tồn tại'], 409);
        }

        $query = "UPDATE branch_service_defaults SET " . implode(', ', $updates) . " WHERE id = ?";
        $params[] = $defaultId;
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);

        createNotification($pdo, $user['user_id'], "Giá dịch vụ mặc định ID $defaultId đã được cập nhật.");
        responseJson(['status' => 'success', 'message' => 'Cập nhật giá dịch vụ mặc định thành công']);
    } catch (Exception $e) {
        logError('Lỗi patch branch_service_default ID ' . $defaultId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function deleteBranchServiceDefault() {
    $defaultId = getResourceIdFromUri('#/branch_service_defaults/([0-9]+)#');
    $user = verifyJWT();
    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'branch_service_defaults', $defaultId);
        if ($user['role'] === 'owner') {
            $stmt = $pdo->prepare("SELECT bsd.id FROM branch_service_defaults bsd JOIN branches b ON bsd.branch_id = b.id WHERE bsd.id = ? AND b.owner_id = ?");
            $stmt->execute([$defaultId, $user['user_id']]);
        } elseif ($user['role'] !== 'admin') {
            responseJson(['status' => 'error', 'message' => 'Không có quyền xóa giá dịch vụ mặc định'], 403);
        }
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Giá dịch vụ mặc định không hợp lệ hoặc bạn không có quyền'], 403);
        }

        $stmt = $pdo->prepare("DELETE FROM branch_service_defaults WHERE id = ?");
        $stmt->execute([$defaultId]);
        responseJson(['status' => 'success', 'message' => 'Xóa giá dịch vụ mặc định thành công']);
    } catch (Exception $e) {
        logError('Lỗi xóa branch_service_default ID ' . $defaultId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}
?>
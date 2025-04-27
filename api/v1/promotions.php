<?php
require_once __DIR__ . '/../../core/database.php';
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/common.php';

function getPromotions() {
    $pdo = getDB();
    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $conditions = [];
    $params = [];

    if (!empty($_GET['branch_id']) && filter_var($_GET['branch_id'], FILTER_VALIDATE_INT)) {
        $conditions[] = "p.branch_id = ?";
        $params[] = $_GET['branch_id'];
    }
    if (!empty($_GET['status']) && in_array($_GET['status'], ['active', 'expired'])) {
        $conditions[] = "p.status = ?";
        $params[] = $_GET['status'];
    }

    if (!empty($_GET['search'])) {
        $search = '%' . sanitizeInput($_GET['search']) . '%';
        $conditions[] = "(p.name LIKE ? OR p.description LIKE ?)";
        $params[] = $search;
        $params[] = $search;
    }

    $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
    $query = "
        SELECT p.*, b.name AS branch_name
        FROM promotions p
        LEFT JOIN branches b ON p.branch_id = b.id
        $whereClause
    ";

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM promotions p $whereClause");
    $countStmt->execute($params);
    $totalRecords = $countStmt->fetchColumn();
    $totalPages = ceil($totalRecords / $limit);

    $query .= " LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $promotions = $stmt->fetchAll();

    responseJson([
        'status' => 'success',
        'data' => $promotions,
        'pagination' => [
            'current_page' => $page,
            'limit' => $limit,
            'total_records' => $totalRecords,
            'total_pages' => $totalPages
        ]
    ]);
}

function createPromotion() {
    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['branch_id', 'code', 'discount_percentage', 'start_date', 'end_date']);
    $user = verifyJWT();

    $branchId = filter_var($input['branch_id'], FILTER_VALIDATE_INT);
    $code = sanitizeInput($input['code']);
    $discountPercentage = filter_var($input['discount_percentage'], FILTER_VALIDATE_FLOAT);
    $startDate = validateDate($input['start_date']);
    $endDate = validateDate($input['end_date']);
    $status = in_array($input['status'] ?? 'active', ['active', 'inactive']) ? $input['status'] : 'active';

    if (!$branchId || !$discountPercentage || $discountPercentage < 0 || $discountPercentage > 100) {
        responseJson(['status' => 'error', 'message' => 'Dữ liệu không hợp lệ'], 400);
    }

    $pdo = getDB();
    try {
        if ($user['role'] === 'owner') {
            $stmt = $pdo->prepare("SELECT id FROM branches WHERE id = ? AND owner_id = ?");
            $stmt->execute([$branchId, $user['user_id']]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Chi nhánh không hợp lệ hoặc bạn không có quyền'], 403);
            }
        } elseif ($user['role'] !== 'admin') {
            responseJson(['status' => 'error', 'message' => 'Không có quyền tạo khuyến mãi'], 403);
        }

        $stmt = $pdo->prepare("SELECT id FROM promotions WHERE branch_id = ? AND code = ?");
        $stmt->execute([$branchId, $code]);
        if ($stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Mã khuyến mãi đã tồn tại'], 409);
        }

        $stmt = $pdo->prepare("
            INSERT INTO promotions (branch_id, code, discount_percentage, start_date, end_date, status)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$branchId, $code, $discountPercentage, $startDate, $endDate, $status]);

        $promotionId = $pdo->lastInsertId();
        createNotification($pdo, $user['user_id'], "Khuyến mãi ID $promotionId đã được tạo.");
        responseJson(['status' => 'success', 'data' => ['promotion_id' => $promotionId]]);
    } catch (Exception $e) {
        logError('Lỗi tạo promotion: ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function getPromotionById() {
    $promotionId = getResourceIdFromUri('#/promotions/([0-9]+)#');
    $user = verifyJWT();
    $pdo = getDB();
    try {
        $query = "SELECT id, branch_id, code, discount_percentage, start_date, end_date, status, created_at FROM promotions WHERE id = ?";
        $params = [$promotionId];
        if ($user['role'] === 'owner') {
            $query .= " AND branch_id IN (SELECT id FROM branches WHERE owner_id = ?)";
            $params[] = $user['user_id'];
        }

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $promotion = $stmt->fetch();

        if (!$promotion) {
            responseJson(['status' => 'error', 'message' => 'Không tìm thấy khuyến mãi'], 404);
        }
        responseJson(['status' => 'success', 'data' => $promotion]);
    } catch (Exception $e) {
        logError('Lỗi lấy promotion ID ' . $promotionId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi truy vấn'], 500);
    }
}

function updatePromotion() {
    $promotionId = getResourceIdFromUri('#/promotions/([0-9]+)#');
    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['branch_id', 'code', 'discount_percentage', 'start_date', 'end_date']);
    $user = verifyJWT();

    $branchId = filter_var($input['branch_id'], FILTER_VALIDATE_INT);
    $code = sanitizeInput($input['code']);
    $discountPercentage = filter_var($input['discount_percentage'], FILTER_VALIDATE_FLOAT);
    $startDate = validateDate($input['start_date']);
    $endDate = validateDate($input['end_date']);
    $status = in_array($input['status'] ?? 'active', ['active', 'inactive']) ? $input['status'] : 'active';

    if (!$branchId || !$discountPercentage || $discountPercentage < 0 || $discountPercentage > 100) {
        responseJson(['status' => 'error', 'message' => 'Dữ liệu không hợp lệ'], 400);
    }

    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'promotions', $promotionId);
        if ($user['role'] === 'owner') {
            $stmt = $pdo->prepare("SELECT id FROM branches WHERE id = ? AND owner_id = ?");
            $stmt->execute([$branchId, $user['user_id']]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Chi nhánh không hợp lệ hoặc bạn không có quyền'], 403);
            }
        } elseif ($user['role'] !== 'admin') {
            responseJson(['status' => 'error', 'message' => 'Không có quyền chỉnh sửa khuyến mãi'], 403);
        }

        $stmt = $pdo->prepare("SELECT id FROM promotions WHERE branch_id = ? AND code = ? AND id != ?");
        $stmt->execute([$branchId, $code, $promotionId]);
        if ($stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Mã khuyến mãi đã tồn tại'], 409);
        }

        $stmt = $pdo->prepare("
            UPDATE promotions SET branch_id = ?, code = ?, discount_percentage = ?, start_date = ?, end_date = ?, status = ?
            WHERE id = ?
        ");
        $stmt->execute([$branchId, $code, $discountPercentage, $startDate, $endDate, $status, $promotionId]);

        createNotification($pdo, $user['user_id'], "Khuyến mãi ID $promotionId đã được cập nhật.");
        responseJson(['status' => 'success', 'message' => 'Cập nhật khuyến mãi thành công']);
    } catch (Exception $e) {
        logError('Lỗi cập nhật promotion ID ' . $promotionId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function patchPromotion() {
    $promotionId = getResourceIdFromUri('#/promotions/([0-9]+)#');
    $input = json_decode(file_get_contents('php://input'), true);
    $user = verifyJWT();

    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'promotions', $promotionId);
        if ($user['role'] === 'owner') {
            $stmt = $pdo->prepare("SELECT branch_id FROM promotions WHERE id = ? AND branch_id IN (SELECT id FROM branches WHERE owner_id = ?)");
            $stmt->execute([$promotionId, $user['user_id']]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Khuyến mãi không hợp lệ hoặc bạn không có quyền'], 403);
            }
        } elseif ($user['role'] !== 'admin') {
            responseJson(['status' => 'error', 'message' => 'Không có quyền chỉnh sửa khuyến mãi'], 403);
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
        if (!empty($input['code'])) {
            $code = sanitizeInput($input['code']);
            $stmt = $pdo->prepare("SELECT id FROM promotions WHERE branch_id = (SELECT branch_id FROM promotions WHERE id = ?) AND code = ? AND id != ?");
            $stmt->execute([$promotionId, $code, $promotionId]);
            if ($stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Mã khuyến mãi đã tồn tại'], 409);
            }
            $updates[] = "code = ?";
            $params[] = $code;
        }
        if (isset($input['discount_percentage'])) {
            $discountPercentage = filter_var($input['discount_percentage'], FILTER_VALIDATE_FLOAT);
            if ($discountPercentage === false || $discountPercentage < 0 || $discountPercentage > 100) {
                responseJson(['status' => 'error', 'message' => 'Phần trăm giảm giá không hợp lệ'], 400);
            }
            $updates[] = "discount_percentage = ?";
            $params[] = $discountPercentage;
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
        if (!empty($input['status'])) {
            $status = in_array($input['status'], ['active', 'inactive']) ? $input['status'] : 'active';
            $updates[] = "status = ?";
            $params[] = $status;
        }

        if (empty($updates)) {
            responseJson(['status' => 'error', 'message' => 'Không có dữ liệu để cập nhật'], 400);
        }

        $query = "UPDATE promotions SET " . implode(', ', $updates) . " WHERE id = ?";
        $params[] = $promotionId;
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);

        createNotification($pdo, $user['user_id'], "Khuyến mãi ID $promotionId đã được cập nhật.");
        responseJson(['status' => 'success', 'message' => 'Cập nhật khuyến mãi thành công']);
    } catch (Exception $e) {
        logError('Lỗi patch promotion ID ' . $promotionId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function deletePromotion() {
    $promotionId = getResourceIdFromUri('#/promotions/([0-9]+)#');
    $user = verifyJWT();
    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'promotions', $promotionId);
        if ($user['role'] === 'owner') {
            $stmt = $pdo->prepare("SELECT branch_id FROM promotions WHERE id = ? AND branch_id IN (SELECT id FROM branches WHERE owner_id = ?)");
            $stmt->execute([$promotionId, $user['user_id']]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Khuyến mãi không hợp lệ hoặc bạn không có quyền'], 403);
            }
        } elseif ($user['role'] !== 'admin') {
            responseJson(['status' => 'error', 'message' => 'Không có quyền xóa khuyến mãi'], 403);
        }

        $stmt = $pdo->prepare("DELETE FROM promotions WHERE id = ?");
        $stmt->execute([$promotionId]);
        responseJson(['status' => 'success', 'message' => 'Xóa khuyến mãi thành công']);
    } catch (Exception $e) {
        logError('Lỗi xóa promotion ID ' . $promotionId . ': ' . $e->getMessage());
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
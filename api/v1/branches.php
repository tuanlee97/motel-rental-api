<?php
require_once __DIR__ . '/../../core/database.php';
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/common.php';

function getBranches() {
    $pdo = getDB();
    $user = verifyJWT(); // Get authenticated user
    // Pagination
    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    // Filter conditions
    $conditions = [];
    $params = [];

    if ($user['role'] === 'owner') {
        $conditions[] = "b.owner_id = ?";
        $params[] = $user['user_id'];
    }
    // Allow admin to filter by owner_id if provided
    if ($user['role'] === 'admin' && !empty($_GET['owner_id']) && filter_var($_GET['owner_id'], FILTER_VALIDATE_INT)) {
        $conditions[] = "b.owner_id = ?";
        $params[] = $_GET['owner_id'];
    }
    // if (!empty($_GET['owner_id']) && filter_var($_GET['owner_id'], FILTER_VALIDATE_INT)) {
    //     $conditions[] = "b.owner_id = ?";
    //     $params[] = $_GET['owner_id'];
    // }

    // Search
    if (!empty($_GET['search'])) {
        $search = '%' . sanitizeInput($_GET['search']) . '%';
        $conditions[] = "(b.name LIKE ? OR b.address LIKE ?)";
        $params[] = $search;
        $params[] = $search;
    }

    // Build query
    $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
    $query = "
        SELECT b.*, u.name AS owner_name
        FROM branches b
        LEFT JOIN users u ON b.owner_id = u.id
        $whereClause
    ";

    // Count total records
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM branches b $whereClause");
    $countStmt->execute($params);
    $totalRecords = $countStmt->fetchColumn();
    $totalPages = ceil($totalRecords / $limit);

    // Query data with pagination
    $query .= " LIMIT $limit OFFSET $offset"; 

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $branches = $stmt->fetchAll();

    responseJson([
        'status' => 'success',
        'data' => $branches,
        'pagination' => [
            'current_page' => $page,
            'limit' => $limit,
            'total_records' => $totalRecords,
            'total_pages' => $totalPages
        ]
    ]);
}


function createBranch() {
    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['name', 'address']);
    $user = verifyJWT();
    $name = sanitizeInput($input['name']);
    $address = sanitizeInput($input['address']);
    $phone = sanitizeInput($input['phone'] ?? '');

    $pdo = getDB();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO branches (owner_id, name, address, phone)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$user['user_id'], $name, $address, $phone]);

        $branchId = $pdo->lastInsertId();
        responseJson(['status' => 'success', 'data' => ['branch_id' => $branchId]]);
    } catch (Exception $e) {
        logError('Lỗi tạo branch: ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function getBranchById() {
    $branchId = getResourceIdFromUri('#/branches/([0-9]+)#');
    $pdo = getDB();
    $user = verifyJWT();
    try {
        $query = "SELECT id, owner_id, name, address, phone, revenue, created_at FROM branches WHERE id = ?";
        if ($user['role'] === 'owner') {
            $query .= " AND owner_id = ?";
            $stmt = $pdo->prepare($query);
            $stmt->execute([$branchId, $user['user_id']]);
        } else {
            $stmt = $pdo->prepare($query);
            $stmt->execute([$branchId]);
        }

        $branch = $stmt->fetch();
        if (!$branch) {
            responseJson(['status' => 'error', 'message' => 'Không tìm thấy chi nhánh'], 404);
        }
        responseJson(['status' => 'success', 'data' => $branch]);
    } catch (Exception $e) {
        logError('Lỗi lấy branch ID ' . $branchId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi truy vấn'], 500);
    }
}

function updateBranch() {
    $branchId = getResourceIdFromUri('#/branches/([0-9]+)#');
    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['name', 'address']);

    $user = verifyJWT();
    $name = sanitizeInput($input['name']);
    $address = sanitizeInput($input['address']);
    $phone = sanitizeInput($input['phone'] ?? '');

    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'branches', $branchId);
        $stmt = $pdo->prepare("SELECT id FROM branches WHERE id = ? AND owner_id = ?");
        $stmt->execute([$branchId, $user['user_id']]);
        if ($user['role'] === 'owner' && !$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Bạn không có quyền chỉnh sửa chi nhánh này'], 403);
        }

        $stmt = $pdo->prepare("
            UPDATE branches SET name = ?, address = ?, phone = ?
            WHERE id = ?
        ");
        $stmt->execute([$name, $address, $phone, $branchId]);

        responseJson(['status' => 'success', 'message' => 'Cập nhật chi nhánh thành công']);
    } catch (Exception $e) {
        logError('Lỗi cập nhật branch ID ' . $branchId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function patchBranch() {
    $branchId = getResourceIdFromUri('#/branches/([0-9]+)#');
    $input = json_decode(file_get_contents('php://input'), true);
    $user = verifyJWT();

    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'branches', $branchId);
        $stmt = $pdo->prepare("SELECT id FROM branches WHERE id = ? AND owner_id = ?");
        $stmt->execute([$branchId, $user['user_id']]);
        if ($user['role'] === 'owner' && !$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Bạn không có quyền chỉnh sửa chi nhánh này'], 403);
        }

        $updates = [];
        $params = [];
        if (!empty($input['name'])) {
            $updates[] = "name = ?";
            $params[] = sanitizeInput($input['name']);
        }
        if (!empty($input['address'])) {
            $updates[] = "address = ?";
            $params[] = sanitizeInput($input['address']);
        }
        if (isset($input['phone'])) {
            $updates[] = "phone = ?";
            $params[] = sanitizeInput($input['phone']);
        }

        if (empty($updates)) {
            responseJson(['status' => 'error', 'message' => 'Không có dữ liệu để cập nhật'], 400);
        }

        $query = "UPDATE branches SET " . implode(', ', $updates) . " WHERE id = ?";
        $params[] = $branchId;
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);

        responseJson(['status' => 'success', 'message' => 'Cập nhật chi nhánh thành công']);
    } catch (Exception $e) {
        logError('Lỗi patch branch ID ' . $branchId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function deleteBranch() {
    $branchId = getResourceIdFromUri('#/branches/([0-9]+)#');
    $user = verifyJWT();
    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'branches', $branchId);
        $stmt = $pdo->prepare("SELECT id FROM branches WHERE id = ? AND owner_id = ?");
        $stmt->execute([$branchId, $user['user_id']]);
        if ($user['role'] === 'owner' && !$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Bạn không có quyền xóa chi nhánh này'], 403);
        }

        $stmt = $pdo->prepare("DELETE FROM branches WHERE id = ?");
        $stmt->execute([$branchId]);
        responseJson(['status' => 'success', 'message' => 'Xóa chi nhánh thành công']);
    } catch (Exception $e) {
        logError('Lỗi xóa branch ID ' . $branchId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}
?>
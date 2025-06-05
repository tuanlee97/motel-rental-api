<?php
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/utils/common.php';

// Lấy danh sách loại phòng
function getRoomTypes() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    // Lấy các query parameters
    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $conditions = [];
    $params = [];

    // Nếu có truyền branch_id
    if (!empty($_GET['branch_id'])) {
        $branch_id = (int)$_GET['branch_id'];
        $conditions[] = "rt.branch_id = ?";
        $params[] = $branch_id;
    } else {
        // Nếu là owner và chỉ có 1 chi nhánh → mặc định dùng chi nhánh đó
        if ($role === 'owner') {
            $stmt = $pdo->prepare("SELECT id FROM branches WHERE owner_id = ?");
            $stmt->execute([$user_id]);
            $branches = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (count($branches) === 1) {
                $conditions[] = "rt.branch_id = ?";
                $params[] = $branches[0];
            } else {
                // Có nhiều chi nhánh → lấy theo owner
                $conditions[] = "rt.branch_id IN (SELECT id FROM branches WHERE owner_id = ?)";
                $params[] = $user_id;
            }
        } elseif ($role !== 'admin') {
            // Nếu là staff, không phải admin → giới hạn theo owner
            $conditions[] = "rt.branch_id IN (SELECT id FROM branches WHERE owner_id = ?)";
            $params[] = $user_id;
        }
        // admin thì không cần thêm điều kiện
    }

    $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

    $query = "
        SELECT rt.id, rt.branch_id, rt.name, rt.description, rt.created_at, b.name AS branch_name
        FROM room_types rt
        JOIN branches b ON rt.branch_id = b.id
        $whereClause
        ORDER BY rt.created_at DESC
        LIMIT $limit OFFSET $offset
    ";

    try {
        // Lấy tổng số bản ghi
        $countQuery = "SELECT COUNT(*) FROM room_types rt $whereClause";
        $countStmt = $pdo->prepare($countQuery);
        $countStmt->execute($params);
        $totalRecords = $countStmt->fetchColumn();
        $totalPages = ceil($totalRecords / $limit);

        // Lấy dữ liệu
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $roomTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        responseJson([
            'status' => 'success',
            'data' => $roomTypes,
            'pagination' => [
                'current_page' => $page,
                'limit' => $limit,
                'total_records' => $totalRecords,
                'total_pages' => $totalPages
            ]
        ]);
    } catch (PDOException $e) {
        logError("Lỗi lấy loại phòng: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Tạo loại phòng
function createRoomType() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if ($role !== 'admin' && $role !== 'owner') {
        responseJson(['status' => 'error', 'message' => 'Không có quyền tạo loại phòng'], 403);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['branch_id', 'name']);
    $data = sanitizeInput($input);

    if ($role === 'owner') {
        $stmt = $pdo->prepare("SELECT 1 FROM branches WHERE id = ? AND owner_id = ?");
        $stmt->execute([$data['branch_id'], $user_id]);
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Không có quyền tạo loại phòng cho chi nhánh này'], 403);
            return;
        }
    }

    try {
        checkResourceExists($pdo, 'branches', $data['branch_id']);
        $stmt = $pdo->prepare("INSERT INTO room_types (branch_id, name, description, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$data['branch_id'], $data['name'], $data['description'] ?? null]);
        responseJson(['status' => 'success', 'message' => 'Tạo loại phòng thành công']);
    } catch (PDOException $e) {
        error_log("Lỗi tạo loại phòng: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}
?>
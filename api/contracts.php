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

    if (!empty($_GET['branch_id'])) {
        $branch_id = (int)$_GET['branch_id'];
        $conditions[] = "c.branch_id = ?";
        $params[] = $branch_id;
    } elseif ($role === 'owner' && $pdo->query("SELECT COUNT(*) FROM branches WHERE owner_id = $user_id")->fetchColumn() === 1) {
        $branch_id = $pdo->query("SELECT id FROM branches WHERE owner_id = $user_id LIMIT 1")->fetchColumn();
        $conditions[] = "c.branch_id = ?";
        $params[] = $branch_id;
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

    $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
    $query = "
        SELECT c.id, c.room_id, c.user_id, c.start_date, c.end_date, c.status, c.created_at, c.deposit,
               r.name AS room_name, u.username AS user_name, b.name AS branch_name
        FROM contracts c
        JOIN rooms r ON c.room_id = r.id
        JOIN users u ON c.user_id = u.id
        JOIN branches b ON c.branch_id = b.id
        $whereClause
        LIMIT $limit OFFSET $offset
    ";

    try {
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM contracts c $whereClause");
        $countStmt->execute($params);
        $totalRecords = $countStmt->fetchColumn();
        $totalPages = ceil($totalRecords / $limit);

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        responseJson([
            'status' => 'success',
            'data' => $contracts,
            'pagination' => ['current_page' => $page, 'limit' => $limit, 'total_records' => $totalRecords, 'total_pages' => $totalPages]
        ]);
    } catch (PDOException $e) {
        logError("Lỗi lấy danh sách hợp đồng: " . $e->getMessage());
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
        $stmt = $pdo->prepare("INSERT INTO contracts (room_id, user_id, start_date, end_date, status, created_at, created_by, branch_id, deposit) VALUES (?, ?, ?, ?, 'active', NOW(), ?, (SELECT branch_id FROM rooms WHERE id = ?), ?)");
        $stmt->execute([$data['room_id'], $data['user_id'], $data['start_date'], $data['end_date'], $user_id, $data['room_id'], $data['deposit'] ?? 0]);
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
?>
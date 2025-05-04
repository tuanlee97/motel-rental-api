<?php
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/utils/common.php';

// Thêm người ở cùng
function addRoomOccupant() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];
    $contract_id = getResourceIdFromUri('#/contracts/([0-9]+)#/occupants');

    if ($role !== 'admin' && $role !== 'owner' && $role !== 'employee') {
        responseJson(['status' => 'error', 'message' => 'Không có quyền thêm người ở'], 403);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['user_id']);
    $data = sanitizeInput($input);

    try {
        checkResourceExists($pdo, 'contracts', $contract_id);
        checkResourceExists($pdo, 'users', $data['user_id']);
        if ($role === 'owner' || $role === 'employee') {
            $stmt = $pdo->prepare("SELECT 1 FROM contracts c JOIN rooms r ON c.room_id = r.id JOIN branches b ON r.branch_id = b.id WHERE c.id = ? AND b.owner_id = ?");
            $stmt->execute([$contract_id, $user_id]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Không có quyền thêm người ở cho hợp đồng này'], 403);
                return;
            }
        }
        $stmt = $pdo->prepare("INSERT INTO room_occupants (room_id, user_id, relation, created_at) VALUES ((SELECT room_id FROM contracts WHERE id = ?), ?, ?, NOW())");
        $stmt->execute([$contract_id, $data['user_id'], $data['relation'] ?? null]);
        responseJson(['status' => 'success', 'message' => 'Thêm người ở thành công']);
    } catch (PDOException $e) {
        logError("Lỗi thêm người ở cho hợp đồng ID $contract_id: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}
?>
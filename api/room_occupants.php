<?php
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/utils/common.php';

// Thêm người ở cùng
function createRoomOccupant() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['roomId'], $input['data']) || !is_array($input['data'])) {
        responseJson(['message' => 'Thiếu roomId hoặc data'], 400);
        return;
    }

    $roomId = $input['roomId'];
    $occupants = $input['data'];

    try {
        $stmt = $pdo->prepare("INSERT INTO room_occupants (room_id, user_id, relation) VALUES (:room_id, :user_id, :relation)");

        foreach ($occupants as $occ) {
            if (!isset($occ['user_id'])) {
                continue; // Bỏ qua nếu thiếu user_id
            }

            $stmt->execute([
                ':room_id' => $roomId,
                ':user_id' => $occ['user_id'],
                ':relation' => $occ['relation'] ?? null
            ]);
        }

        responseJson(['message' => 'Thêm người ở cùng thành công']);
    } catch (PDOException $e) {
        error_log("DB Error: " . $e->getMessage());
        responseJson(['message' => 'Lỗi cơ sở dữ liệu'], 500);
    } catch (Exception $e) {
        error_log("Unhandled Error: " . $e->getMessage());
        responseJson(['message' => 'Lỗi không xác định'], 500);
    }
}
// Lấy danh sách occupants theo room_id
function getOccupantsByRoom() {
    $pdo = getDB();  // Kết nối đến cơ sở dữ liệu
    $user = verifyJWT();  // Xác thực JWT và lấy thông tin người dùng hiện tại
    $user_id = $user['user_id'];
    $role = $user['role'];

    // Lấy room_id từ tham số GET
    if (empty($_GET['room_id']) || !is_numeric($_GET['room_id'])) {
        responseJson(['status' => 'error', 'message' => 'room_id không hợp lệ'], 400);
        return;
    }

    $room_id = (int)$_GET['room_id'];

    // Kiểm tra quyền truy cập của người dùng
    if ($role !== 'admin' && $role !== 'owner' && $role !== 'employee') {
        responseJson(['status' => 'error', 'message' => 'Không có quyền truy cập'], 403);
        return;
    }

    try {
        // Kiểm tra sự tồn tại của phòng
        checkResourceExists($pdo, 'rooms', $room_id);

        // Truy vấn để lấy danh sách occupants của phòng
        $query = "
            SELECT u.id AS user_id, u.name AS user_name, u.email AS user_email
            FROM occupants o
            JOIN users u ON o.user_id = u.id
            WHERE o.room_id = ?
        ";

        $stmt = $pdo->prepare($query);
        $stmt->execute([$room_id]);

        // Lấy danh sách occupants
        $occupants = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Trả về kết quả
        responseJson([
            'status' => 'success',
            'data' => $occupants
        ]);
    } catch (PDOException $e) {
        // Xử lý lỗi
        error_log("Lỗi lấy danh sách occupants cho phòng ID $room_id: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

?>
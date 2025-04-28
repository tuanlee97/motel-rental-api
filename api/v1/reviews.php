<?php
require_once __DIR__ . '/../../core/database.php';
require_once __DIR__ . '/../../core/helpers.php';
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/common.php';

function getReviews() {
    $pdo = getDB();
    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $conditions = [];
    $params = [];

    if (!empty($_GET['room_id']) && filter_var($_GET['room_id'], FILTER_VALIDATE_INT)) {
        $conditions[] = "rv.room_id = ?";
        $params[] = $_GET['room_id'];
    }
    if (!empty($_GET['branch_id']) && filter_var($_GET['branch_id'], FILTER_VALIDATE_INT)) {
        $conditions[] = "rv.branch_id = ?";
        $params[] = $_GET['branch_id'];
    }
    if (!empty($_GET['rating']) && is_numeric($_GET['rating']) && $_GET['rating'] >= 1 && $_GET['rating'] <= 5) {
        $conditions[] = "rv.rating = ?";
        $params[] = (int)$_GET['rating'];
    }

    if (!empty($_GET['search'])) {
        $search = '%' . sanitizeInput($_GET['search']) . '%';
        $conditions[] = "rv.comment LIKE ?";
        $params[] = $search;
    }

    $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
    $query = "
        SELECT rv.*, u.name AS user_name, r.name AS room_name, b.name AS branch_name
        FROM reviews rv
        LEFT JOIN users u ON rv.user_id = u.id
        LEFT JOIN rooms r ON rv.room_id = r.id
        LEFT JOIN branches b ON rv.branch_id = b.id
        $whereClause
    ";

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM reviews rv $whereClause");
    $countStmt->execute($params);
    $totalRecords = $countStmt->fetchColumn();
    $totalPages = ceil($totalRecords / $limit);

    $query .= " LIMIT $limit OFFSET $offset"; 

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $reviews = $stmt->fetchAll();

    responseJson([
        'status' => 'success',
        'data' => $reviews,
        'pagination' => [
            'current_page' => $page,
            'limit' => $limit,
            'total_records' => $totalRecords,
            'total_pages' => $totalPages
        ]
    ]);
}

function createReview() {
    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['room_id', 'rating']);
    $user = verifyJWT();

    $roomId = filter_var($input['room_id'], FILTER_VALIDATE_INT);
    $rating = filter_var($input['rating'], FILTER_VALIDATE_INT);
    $comment = !empty($input['comment']) ? sanitizeInput($input['comment']) : null;

    if (!$roomId || !$rating || $rating < 1 || $rating > 5) {
        responseJson(['status' => 'error', 'message' => 'Dữ liệu không hợp lệ'], 400);
    }

    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'rooms', $roomId);
        if ($user['role'] === 'customer') {
            $stmt = $pdo->prepare("SELECT id FROM contracts WHERE room_id = ? AND user_id = ? AND status = 'active'");
            $stmt->execute([$roomId, $user['user_id']]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Bạn không có quyền đánh giá phòng này'], 403);
            }
        } else {
            responseJson(['status' => 'error', 'message' => 'Chỉ khách hàng có quyền tạo đánh giá'], 403);
        }

        $stmt = $pdo->prepare("SELECT id FROM reviews WHERE room_id = ? AND user_id = ?");
        $stmt->execute([$roomId, $user['user_id']]);
        if ($stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Bạn đã đánh giá phòng này'], 409);
        }

        $stmt = $pdo->prepare("
            INSERT INTO reviews (room_id, user_id, rating, comment)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$roomId, $user['user_id'], $rating, $comment]);

        $reviewId = $pdo->lastInsertId();
        createNotification($pdo, $user['user_id'], "Đánh giá ID $reviewId đã được tạo cho phòng ID $roomId.");
        responseJson(['status' => 'success', 'data' => ['review_id' => $reviewId]]);
    } catch (Exception $e) {
        logError('Lỗi tạo review: ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function getReviewById() {
    $reviewId = getResourceIdFromUri('#/reviews/([0-9]+)#');
    $pdo = getDB();
    try {
        $stmt = $pdo->prepare("
            SELECT r.id, r.room_id, r.user_id, r.rating, r.comment, r.created_at,
                   rm.name AS room_name, u.username AS user_name
            FROM reviews r
            JOIN rooms rm ON r.room_id = rm.id
            JOIN users u ON r.user_id = u.id
            WHERE r.id = ?
        ");
        $stmt->execute([$reviewId]);
        $review = $stmt->fetch();

        if (!$review) {
            responseJson(['status' => 'error', 'message' => 'Không tìm thấy đánh giá'], 404);
        }
        responseJson(['status' => 'success', 'data' => $review]);
    } catch (Exception $e) {
        logError('Lỗi lấy review ID ' . $reviewId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi truy vấn'], 500);
    }
}

function updateReview() {
    $reviewId = getResourceIdFromUri('#/reviews/([0-9]+)#');
    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['room_id', 'rating']);
    $user = verifyJWT();

    $roomId = filter_var($input['room_id'], FILTER_VALIDATE_INT);
    $rating = filter_var($input['rating'], FILTER_VALIDATE_INT);
    $comment = !empty($input['comment']) ? sanitizeInput($input['comment']) : null;

    if (!$roomId || !$rating || $rating < 1 || $rating > 5) {
        responseJson(['status' => 'error', 'message' => 'Dữ liệu không hợp lệ'], 400);
    }

    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'reviews', $reviewId);
        if ($user['role'] === 'customer') {
            $stmt = $pdo->prepare("SELECT id FROM reviews WHERE id = ? AND user_id = ?");
            $stmt->execute([$reviewId, $user['user_id']]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Bạn không có quyền chỉnh sửa đánh giá này'], 403);
            }
        } else {
            responseJson(['status' => 'error', 'message' => 'Chỉ khách hàng có quyền chỉnh sửa đánh giá'], 403);
        }

        checkResourceExists($pdo, 'rooms', $roomId);
        $stmt = $pdo->prepare("SELECT id FROM contracts WHERE room_id = ? AND user_id = ? AND status = 'active'");
        $stmt->execute([$roomId, $user['user_id']]);
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Bạn không có quyền đánh giá phòng này'], 403);
        }

        $stmt = $pdo->prepare("
            UPDATE reviews SET room_id = ?, rating = ?, comment = ?
            WHERE id = ?
        ");
        $stmt->execute([$roomId, $rating, $comment, $reviewId]);

        createNotification($pdo, $user['user_id'], "Đánh giá ID $reviewId đã được cập nhật.");
        responseJson(['status' => 'success', 'message' => 'Cập nhật đánh giá thành công']);
    } catch (Exception $e) {
        logError('Lỗi cập nhật review ID ' . $reviewId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function patchReview() {
    $reviewId = getResourceIdFromUri('#/reviews/([0-9]+)#');
    $input = json_decode(file_get_contents('php://input'), true);
    $user = verifyJWT();

    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'reviews', $reviewId);
        if ($user['role'] === 'customer') {
            $stmt = $pdo->prepare("SELECT id FROM reviews WHERE id = ? AND user_id = ?");
            $stmt->execute([$reviewId, $user['user_id']]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Bạn không có quyền chỉnh sửa đánh giá này'], 403);
            }
        } else {
            responseJson(['status' => 'error', 'message' => 'Chỉ khách hàng có quyền chỉnh sửa đánh giá'], 403);
        }

        $updates = [];
        $params = [];
        if (!empty($input['room_id'])) {
            $roomId = filter_var($input['room_id'], FILTER_VALIDATE_INT);
            checkResourceExists($pdo, 'rooms', $roomId);
            $stmt = $pdo->prepare("SELECT id FROM contracts WHERE room_id = ? AND user_id = ? AND status = 'active'");
            $stmt->execute([$roomId, $user['user_id']]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Bạn không có quyền đánh giá phòng này'], 403);
            }
            $updates[] = "room_id = ?";
            $params[] = $roomId;
        }
        if (!empty($input['rating'])) {
            $rating = filter_var($input['rating'], FILTER_VALIDATE_INT);
            if (!$rating || $rating < 1 || $rating > 5) {
                responseJson(['status' => 'error', 'message' => 'Điểm đánh giá không hợp lệ'], 400);
            }
            $updates[] = "rating = ?";
            $params[] = $rating;
        }
        if (isset($input['comment'])) {
            $updates[] = "comment = ?";
            $params[] = sanitizeInput($input['comment']);
        }

        if (empty($updates)) {
            responseJson(['status' => 'error', 'message' => 'Không có dữ liệu để cập nhật'], 400);
        }

        $query = "UPDATE reviews SET " . implode(', ', $updates) . " WHERE id = ?";
        $params[] = $reviewId;
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);

        createNotification($pdo, $user['user_id'], "Đánh giá ID $reviewId đã được cập nhật.");
        responseJson(['status' => 'success', 'message' => 'Cập nhật đánh giá thành công']);
    } catch (Exception $e) {
        logError('Lỗi patch review ID ' . $reviewId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function deleteReview() {
    $reviewId = getResourceIdFromUri('#/reviews/([0-9]+)#');
    $user = verifyJWT();
    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'reviews', $reviewId);
        if ($user['role'] === 'customer') {
            $stmt = $pdo->prepare("SELECT id FROM reviews WHERE id = ? AND user_id = ?");
            $stmt->execute([$reviewId, $user['user_id']]);
        } elseif ($user['role'] === 'admin') {
            $stmt = $pdo->prepare("SELECT id FROM reviews WHERE id = ?");
            $stmt->execute([$reviewId]);
        } else {
            responseJson(['status' => 'error', 'message' => 'Không có quyền xóa đánh giá'], 403);
        }
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Đánh giá không hợp lệ hoặc bạn không có quyền'], 403);
        }

        $stmt = $pdo->prepare("DELETE FROM reviews WHERE id = ?");
        $stmt->execute([$reviewId]);
        responseJson(['status' => 'success', 'message' => 'Xóa đánh giá thành công']);
    } catch (Exception $e) {
        logError('Lỗi xóa review ID ' . $reviewId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}
?>
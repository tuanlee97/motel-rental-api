<?php
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/utils/common.php';

// Xử lý tải lên mã QR (POST /upload-qr)
function uploadQrCode() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if (!in_array($role, ['admin', 'owner'])) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền tải lên mã QR'], 403);
        return;
    }

    if (empty($_FILES['qr_code'])) {
        responseJson(['status' => 'error', 'message' => 'Không có file được tải lên'], 400);
        return;
    }

    $file = $_FILES['qr_code'];
    $file_name = basename($file['name']);
    $file_tmp = $file['tmp_name'];
    $file_size = $file['size'];
    $file_error = $file['error'];

    // Kiểm tra lỗi file
    if ($file_error !== UPLOAD_ERR_OK) {
        responseJson(['status' => 'error', 'message' => 'Lỗi tải lên file'], 400);
        return;
    }

    // Kiểm tra định dạng file (chỉ chấp nhận hình ảnh)
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
    if (!in_array($file['type'], $allowed_types)) {
        responseJson(['status' => 'error', 'message' => 'Chỉ chấp nhận file hình ảnh (jpg, png, gif)'], 400);
        return;
    }

    // Kiểm tra kích thước file (tối đa 5MB)
    if ($file_size > 5 * 1024 * 1024) {
        responseJson(['status' => 'error', 'message' => 'Kích thước file vượt quá 5MB'], 400);
        return;
    }

    // Tạo thư mục nếu chưa tồn tại
    $upload_dir = __DIR__ . '/../uploads/qr_codes/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    // Tạo tên file duy nhất
    $extension = pathinfo($file_name, PATHINFO_EXTENSION);
    $unique_name = uniqid() . '.' . $extension;
    $target_file = $upload_dir . $unique_name;

    // Di chuyển file đến thư mục đích
    if (move_uploaded_file($file_tmp, $target_file)) {
        // Tạo URL công khai cho file
        $base_url = 'http://' . $_SERVER['HTTP_HOST'] . '/uploads/qr_codes/';
        $file_url = $base_url . $unique_name;

        // Cập nhật qr_code_url trong bảng users
        $stmt = $pdo->prepare("UPDATE users SET qr_code_url = ? WHERE id = ?");
        $stmt->execute([$file_url, $user_id]);

        responseJson([
            'status' => 'success',
            'message' => 'Tải lên mã QR thành công',
            'data' => ['url' => $file_url]
        ]);
    } else {
        responseJson(['status' => 'error', 'message' => 'Lỗi di chuyển file'], 500);
    }
}
?>
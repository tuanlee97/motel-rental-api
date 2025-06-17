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
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'File vượt quá giới hạn kích thước upload_max_filesize trong php.ini',
            UPLOAD_ERR_FORM_SIZE => 'File vượt quá giới hạn MAX_FILE_SIZE trong form',
            UPLOAD_ERR_PARTIAL => 'File chỉ được tải lên một phần',
            UPLOAD_ERR_NO_FILE => 'Không có file được tải lên',
            UPLOAD_ERR_NO_TMP_DIR => 'Thiếu thư mục tạm',
            UPLOAD_ERR_CANT_WRITE => 'Không thể ghi file lên đĩa',
            UPLOAD_ERR_EXTENSION => 'Phần mở rộng file không được phép'
        ];
        $message = $errors[$file_error] ?? 'Lỗi tải lên file không xác định';
        responseJson(['status' => 'error', 'message' => $message], 400);
        return;
    }

    // Kiểm tra định dạng file
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
        if (!mkdir($upload_dir, 0755, true)) {
            error_log("Failed to create directory: $upload_dir");
            responseJson(['status' => 'error', 'message' => 'Không thể tạo thư mục lưu trữ'], 500);
            return;
        }
    }

    // Kiểm tra quyền ghi thư mục
    if (!is_writable($upload_dir)) {
        error_log("Directory not writable: $upload_dir");
        responseJson(['status' => 'error', 'message' => 'Thư mục lưu trữ không có quyền ghi'], 500);
        return;
    }

    // Tạo tên file duy nhất
    $extension = pathinfo($file_name, PATHINFO_EXTENSION);
    $unique_name = uniqid() . '.' . $extension;
    $target_file = $upload_dir . $unique_name;

    // Di chuyển file đến thư mục đích
    if (move_uploaded_file($file_tmp, $target_file)) {
        // Tạo URL công khai cho file
        $base_url = rtrim(getBasePath(), '/') . '/uploads/qr_codes/';
        $file_url = $base_url . $unique_name;
        
        // Cập nhật qr_code_url trong bảng users
        try {
            $stmt = $pdo->prepare("UPDATE users SET qr_code_url = ? WHERE id = ?");
            $stmt->execute([$file_url, $user_id]);
        } catch (PDOException $e) {
            error_log("Database error: " . $e->getMessage());
            unlink($target_file);
            responseJson(['status' => 'error', 'message' => 'Lỗi cập nhật cơ sở dữ liệu'], 500);
            return;
        }

        responseJson([
            'status' => 'success',
            'message' => 'Tải lên mã QR thành công',
            'data' => ['url' => $file_url]
        ]);
    } else {
        error_log("Failed to move uploaded file from $file_tmp to $target_file");
        responseJson(['status' => 'error', 'message' => 'Lỗi di chuyển file'], 500);
    }
}

// Xử lý tải lên ảnh căn cước công dân (POST /upload-id-card)
function uploadIdCard() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    // Lấy userId từ form-data
    $target_user_id = isset($_POST['userId']) ? (int)$_POST['userId'] : $user_id;

    // Kiểm tra quyền tải lên CCCD
    try {
        if (!in_array($role, ['admin', 'owner', 'employee'])) {
            responseJson(['status' => 'error', 'message' => 'Bạn không có quyền tải lên CCCD'], 403);
            return;
        }

        if ($role === 'admin') {
            // Admin có quyền tải lên CCCD cho bất kỳ ai, chỉ cần kiểm tra target_user_id tồn tại
            $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$target_user_id]);
            $target_user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$target_user) {
                responseJson(['status' => 'error', 'message' => 'Người dùng không tồn tại'], 404);
                return;
            }
            $target_username = $target_user['username'];
        } elseif ($role === 'owner') {
            // Owner chỉ tải lên CCCD cho người dùng thuộc chi nhánh của họ
            $stmt = $pdo->prepare("
                SELECT u.username
                FROM users u
                LEFT JOIN branch_customers bc ON u.id = bc.user_id
                LEFT JOIN employee_assignments ea ON u.id = ea.employee_id
                JOIN branches b ON (bc.branch_id = b.id OR ea.branch_id = b.id)
                WHERE u.id = ? AND b.owner_id = ? AND u.deleted_at IS NULL
            ");
            $stmt->execute([$target_user_id, $user_id]);
            $target_user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$target_user) {
                responseJson(['status' => 'error', 'message' => 'Bạn không có quyền tải lên CCCD cho người dùng này'], 403);
                return;
            }
            $target_username = $target_user['username'];
        } elseif ($role === 'employee') {
            // Employee chỉ tải lên CCCD cho khách hàng trong chi nhánh được phân công hoặc do họ tạo
            $stmt = $pdo->prepare("
                SELECT u.username
                FROM users u
                JOIN branch_customers bc ON u.id = bc.user_id
                JOIN employee_assignments ea ON bc.branch_id = ea.branch_id
                WHERE u.id = ? AND ea.employee_id = ? AND u.role = 'customer' AND u.deleted_at IS NULL
            ");
            $stmt->execute([$target_user_id, $user_id]);
            $target_user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$target_user) {
                $stmt = $pdo->prepare("
                    SELECT u.username
                    FROM users u
                    JOIN branch_customers bc ON u.id = bc.user_id
                    WHERE u.id = ? AND bc.created_by = ? AND u.role = 'customer' AND u.deleted_at IS NULL
                ");
                $stmt->execute([$target_user_id, $user_id]);
                $target_user = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$target_user) {
                    responseJson(['status' => 'error', 'message' => 'Bạn không có quyền tải lên CCCD cho người dùng này'], 403);
                    return;
                }
            }
            $target_username = $target_user['username'];
        }
    } catch (PDOException $e) {
        error_log("Database error during permission check: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi kiểm tra quyền truy cập'], 500);
        return;
    }

    // Kiểm tra xem có ít nhất một file được tải lên
    if (empty($_FILES['front_id_card']) && empty($_FILES['back_id_card'])) {
        responseJson(['status' => 'error', 'message' => 'Cần tải lên ít nhất một ảnh CCCD'], 400);
        return;
    }

    $files = [];
    if (!empty($_FILES['front_id_card'])) {
        $files['front_id_card'] = $_FILES['front_id_card'];
    }
    if (!empty($_FILES['back_id_card'])) {
        $files['back_id_card'] = $_FILES['back_id_card'];
    }

    $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
    $max_file_size = 5 * 1024 * 1024; // 5MB
    $upload_dir = __DIR__ . '/../uploads/cccd/' . $target_username . '/';

    if (!file_exists($upload_dir)) {
        if (!mkdir($upload_dir, 0755, true)) {
            error_log("Failed to create directory: $upload_dir");
            responseJson(['status' => 'error', 'message' => 'Không thể tạo thư mục lưu trữ'], 500);
            return;
        }
    }

    if (!is_writable($upload_dir)) {
        error_log("Directory not writable: $upload_dir");
        responseJson(['status' => 'error', 'message' => 'Thư mục lưu trữ không có quyền ghi'], 500);
        return;
    }

    $file_urls = [];
    $uploaded_files = [];

    // Lấy URL hiện tại từ database
    try {
        $stmt = $pdo->prepare("SELECT front_id_card_url, back_id_card_url FROM users WHERE id = ?");
        $stmt->execute([$target_user_id]);
        $current_urls = $stmt->fetch(PDO::FETCH_ASSOC);
        $file_urls['front_id_card'] = $current_urls['front_id_card_url'] ?? null;
        $file_urls['back_id_card'] = $current_urls['back_id_card_url'] ?? null;
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi truy vấn cơ sở dữ liệu'], 500);
        return;
    }

    // Xử lý từng file
    foreach ($files as $key => $file) {
        $file_name = basename($file['name']);
        $file_tmp = $file['tmp_name'];
        $file_size = $file['size'];
        $file_error = $file['error'];
        $file_type = $file['type'];

        if ($file_error !== UPLOAD_ERR_OK) {
            $errors = [
                UPLOAD_ERR_INI_SIZE => 'File vượt quá giới hạn kích thước upload_max_filesize trong php.ini',
                UPLOAD_ERR_FORM_SIZE => 'File vượt quá giới hạn MAX_FILE_SIZE trong form',
                UPLOAD_ERR_PARTIAL => 'File chỉ được tải lên một phần',
                UPLOAD_ERR_NO_FILE => 'Không có file được tải lên',
                UPLOAD_ERR_NO_TMP_DIR => 'Thiếu thư mục tạm',
                UPLOAD_ERR_CANT_WRITE => 'Không thể ghi file lên đĩa',
                UPLOAD_ERR_EXTENSION => 'Phần mở rộng file không được phép'
            ];
            $message = $errors[$file_error] ?? 'Lỗi tải lên file không xác định';
            responseJson(['status' => 'error', 'message' => $message], 400);
            return;
        }

        if (!in_array($file_type, $allowed_types)) {
            responseJson(['status' => 'error', 'message' => 'Chỉ chấp nhận file hình ảnh (jpg, png, gif)'], 400);
            return;
        }

        if ($file_size > $max_file_size) {
            responseJson(['status' => 'error', 'message' => 'Kích thước file vượt quá 5MB'], 400);
            return;
        }

        $extension = pathinfo($file_name, PATHINFO_EXTENSION);
        $unique_name = uniqid() . '_' . $key . '.' . $extension;
        $target_file = $upload_dir . $unique_name;

        if (!move_uploaded_file($file_tmp, $target_file)) {
            error_log("Failed to move uploaded file from $file_tmp to $target_file");
            responseJson(['status' => 'error', 'message' => 'Lỗi di chuyển file'], 500);
            return;
        }

        $base_url = rtrim(getBasePath(), '/') . '/uploads/cccd/' . $target_username . '/';
        $file_urls[$key] = $base_url . $unique_name;
        $uploaded_files[] = $target_file;
    }

    // Cập nhật cơ sở dữ liệu
    try {
        $stmt = $pdo->prepare("UPDATE users SET front_id_card_url = ?, back_id_card_url = ? WHERE id = ?");
        $stmt->execute([$file_urls['front_id_card'], $file_urls['back_id_card'], $target_user_id]);

        // Gửi thông báo cho người dùng được cập nhật CCCD
        createNotification($pdo, $target_user_id, "Ảnh CCCD đã được cập nhật bởi $role (ID: $user_id).");
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        foreach ($uploaded_files as $file) {
            unlink($file);
        }
        responseJson(['status' => 'error', 'message' => 'Lỗi cập nhật cơ sở dữ liệu'], 500);
        return;
    }

    responseJson([
        'status' => 'success',
        'message' => 'Tải lên ảnh CCCD thành công',
        'data' => [
            'front_id_card_url' => $file_urls['front_id_card'],
            'back_id_card_url' => $file_urls['back_id_card']
        ]
    ]);
}
// Xử lý xóa ảnh căn cước công dân (POST /delete-id-card)
function deleteIdCard() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    // Lấy dữ liệu từ request body
    $input = json_decode(file_get_contents('php://input'), true);
    $side = $input['side'] ?? null; // 'front' hoặc 'back'
    $target_user_id = isset($input['target_user_id']) ? (int)$input['target_user_id'] : $user_id;

    // Kiểm tra side hợp lệ
    if (!in_array($side, ['front', 'back'])) {
        responseJson(['status' => 'error', 'message' => 'Phải chỉ định mặt trước hoặc mặt sau để xóa'], 400);
        return;
    }

    // Kiểm tra quyền xóa CCCD
    try {
        if (!in_array($role, ['admin', 'owner', 'employee'])) {
            responseJson(['status' => 'error', 'message' => 'Bạn không có quyền xóa CCCD'], 403);
            return;
        }

        if ($role === 'admin') {
            // Admin có quyền xóa CCCD của bất kỳ ai, chỉ cần kiểm tra target_user_id tồn tại
            $stmt = $pdo->prepare("SELECT 1 FROM users WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$target_user_id]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Người dùng không tồn tại'], 404);
                return;
            }
        } elseif ($role === 'owner') {
            // Owner chỉ xóa CCCD của người dùng thuộc chi nhánh của họ
            $stmt = $pdo->prepare("
                SELECT 1 FROM users u
                LEFT JOIN branch_customers bc ON u.id = bc.user_id
                LEFT JOIN employee_assignments ea ON u.id = ea.employee_id
                JOIN branches b ON (bc.branch_id = b.id OR ea.branch_id = b.id)
                WHERE u.id = ? AND b.owner_id = ? AND u.deleted_at IS NULL
            ");
            $stmt->execute([$target_user_id, $user_id]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Bạn không có quyền xóa CCCD của người dùng này'], 403);
                return;
            }
        } elseif ($role === 'employee') {
            // Employee chỉ xóa CCCD của khách hàng trong chi nhánh được phân công hoặc do họ tạo
            $stmt = $pdo->prepare("
                SELECT 1 FROM users u
                JOIN branch_customers bc ON u.id = bc.user_id
                JOIN employee_assignments ea ON bc.branch_id = ea.branch_id
                WHERE u.id = ? AND ea.employee_id = ? AND u.role = 'customer' AND u.deleted_at IS NULL
            ");
            $stmt->execute([$target_user_id, $user_id]);
            if (!$stmt->fetch()) {
                $stmt = $pdo->prepare("
                    SELECT 1 FROM users u
                    JOIN branch_customers bc ON u.id = bc.user_id
                    WHERE u.id = ? AND bc.created_by = ? AND u.role = 'customer' AND u.deleted_at IS NULL
                ");
                $stmt->execute([$target_user_id, $user_id]);
                if (!$stmt->fetch()) {
                    responseJson(['status' => 'error', 'message' => 'Bạn không có quyền xóa CCCD của người dùng này'], 403);
                    return;
                }
            }
        }
    } catch (PDOException $e) {
        error_log("Database error during permission check: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi kiểm tra quyền truy cập'], 500);
        return;
    }

    // Xác định cột cần cập nhật
    $column = $side === 'front' ? 'front_id_card_url' : 'back_id_card_url';

    // Lấy URL hiện tại từ cơ sở dữ liệu
    try {
        $stmt = $pdo->prepare("SELECT $column FROM users WHERE id = ?");
        $stmt->execute([$target_user_id]);
        $current_url = $stmt->fetchColumn();

        if (!$current_url) {
            responseJson(['status' => 'error', 'message' => 'Không tìm thấy ảnh để xóa'], 404);
            return;
        }

        // Xóa file từ hệ thống
        $parsed_path = parse_url($current_url, PHP_URL_PATH);
        $file_path = realpath(__DIR__ . '/../' . ltrim($parsed_path, '/'));
        if ($file_path && file_exists($file_path)) {
            if (!unlink($file_path)) {
                error_log("Failed to delete file: $file_path");
                responseJson(['status' => 'error', 'message' => 'Lỗi xóa file'], 500);
                return;
            }
            error_log("Deleted file: $file_path");
        } else {
            error_log("File does not exist: $file_path (URL: $current_url)");
        }

        // Cập nhật cơ sở dữ liệu
        $stmt = $pdo->prepare("UPDATE users SET $column = NULL WHERE id = ?");
        $stmt->execute([$target_user_id]);
        $affected_rows = $stmt->rowCount();
        error_log("UPDATE users SET $column = NULL WHERE id = $target_user_id; Affected rows: $affected_rows");

        if ($affected_rows === 0) {
            error_log("No rows updated for user_id: $target_user_id");
            responseJson(['status' => 'error', 'message' => 'Không thể cập nhật cơ sở dữ liệu'], 500);
            return;
        }

        // Gửi thông báo cho người dùng bị xóa CCCD
        createNotification($pdo, $target_user_id, "Ảnh mặt $side CCCD đã được xóa bởi $role (ID: $user_id).");

        responseJson([
            'status' => 'success',
            'message' => "Xóa ảnh mặt $side CCCD thành công"
        ]);
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cập nhật cơ sở dữ liệu: ' . $e->getMessage()], 500);
        return;
    }
}
?>
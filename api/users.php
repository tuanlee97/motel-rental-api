<?php
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/utils/common.php';

function getUsers() {
    $pdo = getDB();

    // Phân trang
    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    // Điều kiện lọc
    $conditions = [];
    $params = [];

    if (!empty($_GET['role']) && in_array($_GET['role'], ['admin', 'owner', 'employee', 'customer'])) {
        $conditions[] = "u.role = ?";
        $params[] = $_GET['role'];
    }
    if (!empty($_GET['branch_id']) && filter_var($_GET['branch_id'], FILTER_VALIDATE_INT)) {
        $conditions[] = "(bc.branch_id = ? OR ea.branch_id = ?)";
        $params[] = $_GET['branch_id'];
        $params[] = $_GET['branch_id'];
    }

    // Tìm kiếm
    if (!empty($_GET['search'])) {
        $search = '%' . sanitizeInput($_GET['search']) . '%';
        $conditions[] = "(u.username LIKE ? OR u.name LIKE ? OR u.email LIKE ?)";
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
    }

    // Xây dựng truy vấn
    $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
    $query = "
        SELECT u.id, u.username, u.name, u.email, u.role, u.created_at, u.phone, u.status
        FROM users u
        LEFT JOIN branch_customers bc ON u.id = bc.user_id
        LEFT JOIN employee_assignments ea ON u.id = ea.employee_id
        $whereClause
        GROUP BY u.id
        LIMIT $limit OFFSET $offset
    ";

    try {
        // Đếm tổng số bản ghi
        $countStmt = $pdo->prepare("SELECT COUNT(DISTINCT u.id) FROM users u LEFT JOIN branch_customers bc ON u.id = bc.user_id LEFT JOIN employee_assignments ea ON u.id = ea.employee_id $whereClause");
        $countStmt->execute($params);
        $totalRecords = $countStmt->fetchColumn();
        error_log("Total Records: $totalRecords");
        $totalPages = ceil($totalRecords / $limit);
        error_log("Total Pages: $totalPages");
    
        // Truy vấn dữ liệu với phân trang
        if ($limit < 1 || $offset < 0) {
            throw new Exception("Invalid pagination parameters");
        }

        $stmt = $pdo->prepare($query);
        if (!$stmt) {
            throw new PDOException("Failed to prepare query: " . implode(", ", $pdo->errorInfo()));
        }

        error_log("Final Query: $query");
        error_log("Params: " . json_encode($params)); // Log parameters for debugging
        $stmt->execute($params);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC); // Explicitly use FETCH_ASSOC for clarity
        error_log("Users: " . json_encode($users)); // Log as JSON to avoid array-to-string issues
    } catch (PDOException $e) {
        error_log("PDO Error: " . $e->getMessage());
        responseJson([
            'status' => 'error',
            'message' => 'Database error: ' . $e->getMessage()
        ], 500);
        return;
    } catch (Exception $e) {
        error_log("General Error: " . $e->getMessage());
        responseJson([
            'status' => 'error',
            'message' => 'Unexpected error: ' . $e->getMessage()
        ], 500);
        return;
    }

    // Proceed with response
    responseJson([
        'status' => 'success',
        'data' => $users,
        'pagination' => [
            'current_page' => $page,
            'limit' => $limit,
            'total_records' => $totalRecords,
            'total_pages' => $totalPages
        ]
    ]);
}

function createUser() {
    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['username', 'email', 'password', 'role']);
    $userData = sanitizeUserInput($input);
    $userData['email'] = validateEmail($userData['email']);
    $password = password_hash($input['password'], PASSWORD_DEFAULT);
    $role = in_array($input['role'], ['admin', 'owner', 'employee', 'customer']) ? $input['role'] : 'customer';

    $pdo = getDB();
    try {
        checkUserExists($pdo, $userData['email'], $userData['username']);
        $stmt = $pdo->prepare("
            INSERT INTO users (username, name, email, password, phone, role, status, provider)
            VALUES (?, ?, ?, ?, ?, ?, 'inactive', 'email')
        ");
        $stmt->execute([$userData['username'], $userData['name'], $userData['email'], $password, $userData['phone'], $role]);

        $userId = $pdo->lastInsertId();
        $jwt = generateJWT($userId, $role);
        $token = $jwt['token'];
        createNotification($pdo, $userId, "Chào mừng {$userData['username']} đã tham gia hệ thống!");
        responseJson(['status' => 'success', 'data' => ['user' => $userData]]);
    } catch (Exception $e) {
        logError('Lỗi tạo user: ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function updateUser() {
    $userId = getResourceIdFromUri('#/users/([0-9]+)#');
    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['username', 'email', 'role']);

    $userData = sanitizeUserInput($input);
    $userData['email'] = validateEmail($userData['email']);
    $role = in_array($input['role'], ['admin', 'owner', 'employee', 'customer']) ? $input['role'] : 'customer';
    $password = !empty($input['password']) ? password_hash($input['password'], PASSWORD_DEFAULT) : null;

    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'users', $userId);
        checkUserExists($pdo, $userData['email'], $userData['username'], $userId);

        $query = "UPDATE users SET username = ?, name = ?, email = ?, phone = ?, role = ?";
        $params = [$userData['username'], $userData['name'], $userData['email'], $userData['phone'], $role];
        if ($password) {
            $query .= ", password = ?";
            $params[] = $password;
        }
        $query .= " WHERE id = ?";
        $params[] = $userId;

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);

        createNotification($pdo, $userId, "Thông tin tài khoản {$userData['username']} đã được cập nhật.");
        responseJson(['status' => 'success', 'message' => 'Cập nhật user thành công']);
    } catch (Exception $e) {
        logError('Lỗi cập nhật user ID ' . $userId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function patchUser() {
    $userId = getResourceIdFromUri('#/users/([0-9]+)#');
    $input = json_decode(file_get_contents('php://input'), true);
    $user = verifyJWT();

    // Chỉ cho phép user chỉnh sửa thông tin của chính mình, trừ admin
    if ($user['role'] !== 'admin' && $user['user_id'] != $userId) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền chỉnh sửa'], 403);
    }

    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'users', $userId);
        $updates = [];
        $params = [];

        if (!empty($input['username'])) {
            $username = sanitizeInput($input['username']);
            checkUserExists($pdo, null, $username, $userId);
            $updates[] = "username = ?";
            $params[] = $username;
        }
        if (!empty($input['email'])) {
            $email = validateEmail($input['email']);
            checkUserExists($pdo, $email, null, $userId);
            $updates[] = "email = ?";
            $params[] = $email;
        }
        if (!empty($input['password'])) {
            $updates[] = "password = ?";
            $params[] = password_hash($input['password'], PASSWORD_DEFAULT);
        }
        if (!empty($input['name'])) {
            $updates[] = "name = ?";
            $params[] = sanitizeInput($input['name']);
        }
        if (isset($input['phone'])) {
            $updates[] = "phone = ?";
            $params[] = sanitizeInput($input['phone']);
        }

        if (empty($updates)) {
            responseJson(['status' => 'error', 'message' => 'Không có dữ liệu để cập nhật'], 400);
        }

        $query = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
        $params[] = $userId;
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);

        createNotification($pdo, $userId, "Thông tin tài khoản đã được cập nhật.");
        responseJson(['status' => 'success', 'message' => 'Cập nhật user thành công']);
    } catch (Exception $e) {
        logError('Lỗi patch user ID ' . $userId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function deleteUser() {
    $userId = getResourceIdFromUri('#/users/([0-9]+)#');
    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'users', $userId);
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        responseJson(['status' => 'success', 'message' => 'Xóa user thành công']);
    } catch (Exception $e) {
        logError('Lỗi xóa user ID ' . $userId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function registerUser() {
    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['username', 'email', 'password']);
    $userData = sanitizeUserInput($input);
    $userData['email'] = validateEmail($userData['email']);
    $password = password_hash($input['password'], PASSWORD_DEFAULT);

    $pdo = getDB();
    try {
        checkUserExists($pdo, $userData['email'], $userData['username']);
        $stmt = $pdo->prepare("
            INSERT INTO users (username, name, email, password, phone, role, status, provider)
            VALUES (?, ?, ?, ?, ?, 'customer', 'inactive', 'email')
        ");
        $stmt->execute([$userData['username'], $userData['name'], $userData['email'], $password, $userData['phone']]);

        $userId = $pdo->lastInsertId();
        $jwt = generateJWT($userId, 'customer');
        createNotification($pdo, $userId, "Chào mừng {$userData['username']} đã đăng ký!");
        responseJson(['status' => 'success', 'data' => ['user' => $userData]]);
    } catch (Exception $e) {
        logError('Lỗi đăng ký user: ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function registerGoogleUser() {
    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['token']);
    $googleData = verifyGoogleToken($input['token']);
    if (!$googleData) {
        responseJson(['status' => 'error', 'message' => 'Token Google không hợp lệ'], 401);
    }

    $email = $googleData['email'];
    $username = sanitizeInput($googleData['given_name'] . '_' . time());
    $name = sanitizeInput($googleData['name']);
    $pdo = getDB();

    try {
        $stmt = $pdo->prepare("SELECT id, provider FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            if ($user['provider'] !== 'google') {
                responseJson(['status' => 'error', 'message' => 'Email đã được sử dụng bởi phương thức khác'], 409);
            }
            $userId = $user['id'];
            $jwt = generateJWT($userId, 'customer');
            $token = $jwt['token'];
            $user['exp'] = $jwt['exp'] ?? null;
            responseJson(['status' => 'success', 'data' => ['token' => $token, 'user' => $user]]);
            return;
        }

        $stmt = $pdo->prepare("
            INSERT INTO users (username, name, email, password, role, status, provider)
            VALUES (?, ?, ?, ?, 'customer', 'active', 'google')
        ");
        $stmt->execute([$username, $name, $email, '']);

        $userId = $pdo->lastInsertId();

        $jwt = generateJWT($userId, 'customer');
        $token = $jwt['token'];
        $user['exp'] = $jwt['exp'] ?? null;
        createNotification($pdo, $userId, "Chào mừng $username đã đăng ký qua Google!");
        responseJson(['status' => 'success', 'data' => ['token' => $token, 'user' => $user]]);
    } catch (Exception $e) {
        logError('Lỗi đăng ký Google user: ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}
?>
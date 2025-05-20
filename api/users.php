<?php
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/utils/common.php';

function getUsers() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    // Phân trang
    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    // Điều kiện lọc
    $conditions = [];
    $params = [];

    // Xử lý vai trò
    if (!empty($_GET['role'])) {
        $roles = array_filter(array_map('trim', explode(',', $_GET['role'])));
        $validRoles = ['admin', 'owner', 'employee', 'customer'];
        $roles = array_intersect($roles, $validRoles);
        if (!empty($roles)) {
            $placeholders = implode(',', array_fill(0, count($roles), '?'));
            $conditions[] = "u.role IN ($placeholders)";
            $params = array_merge($params, $roles);
        }
    }

    // Tìm kiếm
    if (!empty($_GET['search'])) {
        $search = '%' . sanitizeInput($_GET['search']) . '%';
        $conditions[] = "(u.username LIKE ? OR u.name LIKE ? OR u.email LIKE ?)";
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
    }

    // Phân quyền
    if ($role === 'admin') {
        // Admin thấy tất cả
    } elseif ($role === 'owner') {
        $conditions[] = "(
            u.id IN (
                SELECT ea.employee_id FROM employee_assignments ea 
                JOIN branches b ON ea.branch_id = b.id 
                WHERE b.owner_id = ?
            ) OR u.id IN (
                SELECT bc.user_id FROM branch_customers bc 
                JOIN branches b ON bc.branch_id = b.id 
                WHERE b.owner_id = ?
            )
        )";
        $params[] = $user_id;
        $params[] = $user_id;
    } elseif ($role === 'employee') {
        $conditions[] = "(
            u.id IN (
                SELECT bc.user_id FROM branch_customers bc 
                WHERE bc.created_by = ?
            ) OR u.id IN (
                SELECT ea.employee_id FROM employee_assignments ea 
                WHERE ea.created_by = ?
            )
        )";
        $params[] = $user_id;
        $params[] = $user_id;
    } elseif ($role === 'customer') {
        $conditions[] = "(
            u.id = ? OR u.id IN (
                SELECT ro.user_id FROM room_occupants ro
                JOIN room_occupants ro2 ON ro.room_id = ro2.room_id
                WHERE ro2.user_id = ?
            )
        )";
        $params[] = $user_id;
        $params[] = $user_id;
    }

    // Xây dựng truy vấn
    $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
    $query = "
        SELECT u.id, u.username, u.name, u.email, u.phone, u.role, u.created_at, u.status, u.bank_details, u.qr_code_url, u.dob
        FROM users u
        LEFT JOIN branch_customers bc ON u.id = bc.user_id
        LEFT JOIN employee_assignments ea ON u.id = ea.employee_id
        $whereClause
        GROUP BY u.id
        LIMIT $limit OFFSET $offset
    ";

    try {
        $countStmt = $pdo->prepare("SELECT COUNT(DISTINCT u.id) FROM users u LEFT JOIN branch_customers bc ON u.id = bc.user_id LEFT JOIN employee_assignments ea ON u.id = ea.employee_id $whereClause");
        $countStmt->execute($params);
        $totalRecords = $countStmt->fetchColumn();
        $totalPages = ceil($totalRecords / $limit);

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($users as &$user) {
            $user['bank_details'] = $user['bank_details'] ? json_decode($user['bank_details'], true) : null;
        }

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
    } catch (PDOException $e) {
        logError("Lỗi cơ sở dữ liệu: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
        return;
    }
}

function createUser() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['username', 'email', 'password', 'role']);
    $userData = sanitizeUserInput($input);
    $userData['email'] = validateEmail($userData['email']);
    $password = password_hash($input['password'], PASSWORD_DEFAULT);
    $input_role = in_array($input['role'], ['employee', 'customer']) ? $input['role'] : null;

    if (!$input_role) {
        responseJson(['status' => 'error', 'message' => 'Vai trò không hợp lệ'], 400);
        return;
    }

    $allowed = false;
    switch ($role) {
        case 'admin':
            $allowed = true;
            break;
        case 'owner':
            $allowed = in_array($input_role, ['customer', 'employee']);
            break;
        case 'employee':
            $allowed = $input_role === 'customer';
            break;
        default:
            $allowed = false;
    }
    
    if (!$allowed) {
        responseJson(['status' => 'error', 'message' => 'Bạn không có quyền tạo người dùng với vai trò này'], 403);
        return;
    }

    try {
        checkUserExists($pdo, $userData['email'], $userData['username']);
        $stmt = $pdo->prepare("
            INSERT INTO users (username, name, email, password, phone, role, status, provider, created_by, bank_details, qr_code_url, dob)
            VALUES (?, ?, ?, ?, ?, ?, 'inactive', 'email', ?, ?, ?, ?)
        ");
        $stmt->execute([
            $userData['username'],
            $userData['name'],
            $userData['email'],
            $password,
            $userData['phone'],
            $input_role,
            $user_id,
            isset($userData['bank_details']) ? json_encode($userData['bank_details']) : null,
            $userData['qr_code_url'] ?? null,
            $userData['dob'] ?? null
        ]);

        $newUserId = $pdo->lastInsertId();

        if ($input_role === 'customer' && ($role === 'owner' || $role === 'employee')) {
            $branch_id = ($role === 'owner') 
                ? $pdo->query("SELECT id FROM branches WHERE owner_id = $user_id")->fetchColumn()
                : $pdo->query("SELECT branch_id FROM employee_assignments WHERE employee_id = $user_id")->fetchColumn();
            if ($branch_id) {
                $stmt = $pdo->prepare("INSERT INTO branch_customers (branch_id, user_id, created_by) VALUES (?, ?, ?)");
                $stmt->execute([$branch_id, $newUserId, $user_id]);
            }
        } elseif ($input_role === 'employee' && ($role === 'owner' || $role === 'employee')) {
            $branch_id = ($role === 'owner') 
                ? $pdo->query("SELECT id FROM branches WHERE owner_id = $user_id")->fetchColumn()
                : $pdo->query("SELECT branch_id FROM employee_assignments WHERE employee_id = $user_id")->fetchColumn();
            if ($branch_id) {
                $stmt = $pdo->prepare("INSERT INTO employee_assignments (employee_id, branch_id, created_by) VALUES (?, ?, ?)");
                $stmt->execute([$newUserId, $branch_id, $user_id]);
            }
        }

        createNotification($pdo, $newUserId, "Chào mừng {$userData['username']} đã tham gia hệ thống!");
        responseJson(['status' => 'success', 'data' => ['user' => $userData]]);
    } catch (Exception $e) {
        logError("Lỗi tạo người dùng: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function updateUser() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];
    $target_user_id = getResourceIdFromUri('#/users/([0-9]+)#');

    $input = json_decode(file_get_contents('php://input'), true);
    if (empty($input)) {
        responseJson(['status' => 'error', 'message' => 'Không có dữ liệu được cung cấp'], 400);
        return;
    }

    $userData = sanitizeUserInput($input);
    $updates = [];
    $params = [];

    if (isset($input['username']) && !empty(trim($input['username']))) {
        $updates[] = "username = ?";
        $params[] = $userData['username'];
    } elseif (isset($input['username'])) {
        responseJson(['status' => 'error', 'message' => 'Username không được để trống'], 400);
        return;
    }

    if (isset($input['email'])) {
        $userData['email'] = validateEmail($userData['email']);
        $updates[] = "email = ?";
        $params[] = $userData['email'];
    }

    if (isset($input['name'])) {
        $updates[] = "name = ?";
        $params[] = $userData['name'];
    }

    if (isset($input['phone'])) {
        $updates[] = "phone = ?";
        $params[] = $userData['phone'];
    }

    if (isset($input['bank_details'])) {
        if (!is_array($input['bank_details']) || empty($input['bank_details'])) {
            responseJson(['status' => 'error', 'message' => 'Bank details must be a non-empty array'], 400);
            return;
        }
        foreach ($input['bank_details'] as $account) {
            if (!isset($account['bank_name']) || !isset($account['account_number']) || !isset($account['account_holder'])) {
                responseJson(['status' => 'error', 'message' => 'Each bank account must have bank_name, account_number, and account_holder'], 400);
                return;
            }
        }
        $updates[] = "bank_details = ?";
        $params[] = json_encode($input['bank_details']);
    }

    if (isset($input['qr_code_url'])) {
        $updates[] = "qr_code_url = ?";
        $params[] = sanitizeInput($input['qr_code_url']);
    }

    if (isset($input['dob'])) {
        $updates[] = "dob = ?";
        $params[] = $input['dob'];
    }

    if (isset($input['role'])) {
        $input_role = in_array($input['role'], ['employee', 'customer']) ? $input['role'] : null;
        if (!$input_role) {
            responseJson(['status' => 'error', 'message' => 'Vai trò không hợp lệ'], 400);
            return;
        }
        $updates[] = "role = ?";
        $params[] = $input_role;
    }

    if (isset($input['password']) && !empty(trim($input['password']))) {
        $password = password_hash($input['password'], PASSWORD_DEFAULT);
        $updates[] = "password = ?";
        $params[] = $password;
    } elseif (isset($input['password'])) {
        responseJson(['status' => 'error', 'message' => 'Mật khẩu không được để trống'], 400);
        return;
    }

    if (isset($input['status']) && in_array($input['status'], ['active', 'inactive', 'suspended'])) {
        $updates[] = "status = ?";
        $params[] = $input['status'];
    } elseif (isset($input['status'])) {
        responseJson(['status' => 'error', 'message' => 'Trạng thái không hợp lệ'], 400);
        return;
    }

    if (empty($updates)) {
        responseJson(['status' => 'error', 'message' => 'Không có trường nào để cập nhật'], 400);
        return;
    }

    if ($role !== 'admin') {
        if ($role === 'owner') {
            $stmt = $pdo->prepare("
                SELECT 1 FROM users u
                LEFT JOIN branch_customers bc ON u.id = bc.user_id
                LEFT JOIN employee_assignments ea ON u.id = ea.employee_id
                JOIN branches b ON (bc.branch_id = b.id OR ea.branch_id = b.id)
                WHERE u.id = ? AND b.owner_id = ?
            ");
            $stmt->execute([$target_user_id, $user_id]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Không có quyền cập nhật người dùng này'], 403);
                return;
            }
        } elseif ($role === 'employee') {
            $stmt = $pdo->prepare("
                SELECT 1 FROM users u
                LEFT JOIN branch_customers bc ON u.id = bc.user_id
                LEFT JOIN employee_assignments ea ON u.id = ea.employee_id
                WHERE u.id = ? AND (bc.created_by = ? OR ea.created_by = ?)
            ");
            $stmt->execute([$target_user_id, $user_id, $user_id]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Không có quyền cập nhật người dùng này'], 403);
                return;
            }
        } else {
            responseJson(['status' => 'error', 'message' => 'Không có quyền cập nhật'], 403);
            return;
        }
    }

    try {
        checkResourceExists($pdo, 'users', $target_user_id);
        if (isset($input['email']) || isset($input['username'])) {
            checkUserExists($pdo, $userData['email'] ?? '', $userData['username'] ?? '', $target_user_id);
        }

        $query = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
        $params[] = $target_user_id;
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);

        createNotification($pdo, $target_user_id, "Thông tin tài khoản {$userData['username']} đã được cập nhật.");
        responseJson(['status' => 'success', 'message' => 'Cập nhật người dùng thành công']);
    } catch (Exception $e) {
        logError("Lỗi cập nhật người dùng ID $target_user_id: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function patchUser() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];
    $target_user_id = getResourceIdFromUri('#/users/([0-9]+)#');

    if ($role !== 'admin' && $role !== 'owner' && $role !== 'employee' && $user_id != $target_user_id) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền chỉnh sửa'], 403);
        return;
    }

    if ($role === 'owner') {
        $stmt = $pdo->prepare("
            SELECT 1 FROM users u
            LEFT JOIN branch_customers bc ON u.id = bc.user_id
            LEFT JOIN employee_assignments ea ON u.id = ea.employee_id
            JOIN branches b ON (bc.branch_id = b.id OR ea.branch_id = b.id)
            WHERE u.id = ? AND b.owner_id = ?
        ");
        $stmt->execute([$target_user_id, $user_id]);
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Không có quyền cập nhật người dùng này'], 403);
            return;
        }
    } elseif ($role === 'employee') {
        $stmt = $pdo->prepare("
            SELECT 1 FROM users u
            LEFT JOIN branch_customers bc ON u.id = bc.user_id
            LEFT JOIN employee_assignments ea ON u.id = ea.employee_id
            WHERE u.id = ? AND (bc.created_by = ? OR ea.created_by = ?)
        ");
        $stmt->execute([$target_user_id, $user_id, $user_id]);
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Không có quyền cập nhật người dùng này'], 403);
            return;
        }
    }

    try {
        checkResourceExists($pdo, 'users', $target_user_id);
        $input = json_decode(file_get_contents('php://input'), true);
        if (empty($input)) {
            responseJson(['status' => 'error', 'message' => 'Không có dữ liệu được cung cấp'], 400);
            return;
        }

        $updates = [];
        $params = [];

        if (!empty($input['username'])) {
            $username = sanitizeInput($input['username']);
            checkUserExists($pdo, null, $username, $target_user_id);
            $updates[] = "username = ?";
            $params[] = $username;
        }
        if (!empty($input['email'])) {
            $email = validateEmail($input['email']);
            checkUserExists($pdo, $email, null, $target_user_id);
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
        if (isset($input['bank_details'])) {
            if (!is_array($input['bank_details']) || empty($input['bank_details'])) {
                responseJson(['status' => 'error', 'message' => 'Bank details must be a non-empty array'], 400);
                return;
            }
            foreach ($input['bank_details'] as $account) {
                if (!isset($account['bank_name']) || !isset($account['account_number']) || !isset($account['account_holder'])) {
                    responseJson(['status' => 'error', 'message' => 'Each bank account must have bank_name, account_number, and account_holder'], 400);
                    return;
                }
            }
            $updates[] = "bank_details = ?";
            $params[] = json_encode($input['bank_details']);
        }
        if (isset($input['qr_code_url'])) {
            $updates[] = "qr_code_url = ?";
            $params[] = sanitizeInput($input['qr_code_url']);
        }
        if (isset($input['dob'])) {
            $dob = DateTime::createFromFormat('Y-m-d', $input['dob']);
            if (!$dob || $dob->format('Y-m-d') !== $input['dob']) {
                responseJson(['status' => 'error', 'message' => 'Invalid date of birth format (YYYY-MM-DD)'], 400);
                return;
            }
            $updates[] = "dob = ?";
            $params[] = $input['dob'];
        }

        if (empty($updates)) {
            responseJson(['status' => 'error', 'message' => 'Không có dữ liệu để cập nhật'], 400);
            return;
        }

        $query = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
        $params[] = $target_user_id;
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);

        createNotification($pdo, $target_user_id, "Thông tin tài khoản đã được cập nhật.");
        responseJson(['status' => 'success', 'message' => 'Cập nhật người dùng thành công']);
    } catch (Exception $e) {
        logError("Lỗi patch người dùng ID $target_user_id: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function deleteUser() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];
    $target_user_id = getResourceIdFromUri('#/users/([0-9]+)#');

    if ($role !== 'admin') {
        if ($role === 'owner') {
            $stmt = $pdo->prepare("
                SELECT 1 FROM users u
                LEFT JOIN branch_customers bc ON u.id = bc.user_id
                LEFT JOIN employee_assignments ea ON u.id = ea.employee_id
                JOIN branches b ON (bc.branch_id = b.id OR ea.branch_id = b.id)
                WHERE u.id = ? AND b.owner_id = ?
            ");
            $stmt->execute([$target_user_id, $user_id]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Không có quyền xóa người dùng này'], 403);
                return;
            }
        } elseif ($role === 'employee') {
            $stmt = $pdo->prepare("
                SELECT 1 FROM users u
                LEFT JOIN branch_customers bc ON u.id = bc.user_id
                LEFT JOIN employee_assignments ea ON u.id = ea.employee_id
                WHERE u.id = ? AND (bc.created_by = ? OR ea.created_by = ?)
            ");
            $stmt->execute([$target_user_id, $user_id, $user_id]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Không có quyền xóa người dùng này'], 403);
                return;
            }
        } else {
            responseJson(['status' => 'error', 'message' => 'Không có quyền xóa'], 403);
            return;
        }
    }

    try {
        checkResourceExists($pdo, 'users', $target_user_id);
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$target_user_id]);
        responseJson(['status' => 'success', 'message' => 'Xóa người dùng thành công']);
    } catch (Exception $e) {
        logError("Lỗi xóa người dùng ID $target_user_id: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function registerUser() {
    $pdo = getDB();
    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['username', 'email', 'password']);
    $userData = sanitizeUserInput($input);
    $userData['email'] = validateEmail($userData['email']);
    $password = password_hash($input['password'], PASSWORD_DEFAULT);

    try {
        checkUserExists($pdo, $userData['email'], $userData['username']);
        $stmt = $pdo->prepare("
            INSERT INTO users (username, name, email, password, phone, role, status, provider, bank_details, qr_code_url, dob)
            VALUES (?, ?, ?, ?, ?, 'customer', 'inactive', 'email', ?, ?, ?)
        ");
        $stmt->execute([
            $userData['username'],
            $userData['name'],
            $userData['email'],
            $password,
            $userData['phone'],
            isset($userData['bank_details']) ? json_encode($userData['bank_details']) : null,
            $userData['qr_code_url'] ?? null,
            $userData['dob'] ?? null
        ]);

        $userId = $pdo->lastInsertId();
        $jwt = generateJWT($userId, 'customer');
        createNotification($pdo, $userId, "Chào mừng {$userData['username']} đã đăng ký!");
        responseJson(['status' => 'success', 'data' => ['user' => $userData]]);
    } catch (Exception $e) {
        logError("Lỗi đăng ký người dùng: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function registerGoogleUser() {
    $pdo = getDB();
    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['token']);
    $googleData = verifyGoogleToken($input['token']);
    if (!$googleData) {
        responseJson(['status' => 'error', 'message' => 'Token Google không hợp lệ'], 401);
        return;
    }

    $email = $googleData['email'];
    $username = sanitizeInput($googleData['given_name'] . '_' . time());
    $name = sanitizeInput($googleData['name']);

    try {
        $stmt = $pdo->prepare("SELECT id, provider FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            if ($user['provider'] !== 'google') {
                responseJson(['status' => 'error', 'message' => 'Email đã được sử dụng bởi phương thức khác'], 409);
                return;
            }
            $userId = $user['id'];
            $jwt = generateJWT($userId, 'customer');
            $token = $jwt['token'];
            responseJson(['status' => 'success', 'data' => ['token' => $token, 'user' => $user]]);
            return;
        }

        $stmt = $pdo->prepare("
            INSERT INTO users (username, name, email, password, role, status, provider)
            VALUES (?, ?, ?, '', 'customer', 'active', 'google')
        ");
        $stmt->execute([$username, $name, $email]);

        $userId = $pdo->lastInsertId();
        $jwt = generateJWT($userId, 'customer');
        $token = $jwt['token'];
        createNotification($pdo, $userId, "Chào mừng $username đã đăng ký qua Google!");
        responseJson(['status' => 'success', 'data' => ['token' => $token, 'user' => ['id' => $userId, 'username' => $username]]]);
    } catch (Exception $e) {
        logError("Lỗi đăng ký Google user: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function getCurrentUser() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];

    try {
        $stmt = $pdo->prepare("
            SELECT id, username, name, email, phone, role, status, bank_details, qr_code_url, dob
            FROM users
            WHERE id = ? AND deleted_at IS NULL
        ");
        $stmt->execute([$user_id]);
        $userData = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$userData) {
            responseJson(['status' => 'error', 'message' => 'Người dùng không tồn tại'], 404);
            return;
        }

        $userData['bank_details'] = $userData['bank_details'] ? json_decode($userData['bank_details'], true) : null;

        responseJson(['status' => 'success', 'data' => $userData]);
    } catch (PDOException $e) {
        logError("Lỗi lấy thông tin người dùng ID $user_id: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}
?>
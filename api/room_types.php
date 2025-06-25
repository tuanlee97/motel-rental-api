<?php
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/utils/common.php';

// Lấy danh sách loại phòng
function getRoomTypes() {
    try {
        $pdo = getDB();
        $user = verifyJWT();
        if (!$user) {
            responseJson(['status' => 'error', 'message' => 'Không xác thực được người dùng'], 401);
            return;
        }
        $user_id = $user['user_id'];
        $role = $user['role'];

        // Lấy các query parameters
        $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
        $offset = ($page - 1) * $limit;

        $conditions = ['rt.deleted_at IS NULL'];
        $params = [];

        // Nếu có truyền branch_id
        if (!empty($_GET['branch_id']) && is_numeric($_GET['branch_id'])) {
            $branch_id = (int)$_GET['branch_id'];
            $conditions[] = "rt.branch_id = ?";
            $params[] = $branch_id;
        } else {
            // Nếu là owner và chỉ có 1 chi nhánh → mặc định dùng chi nhánh đó
            if ($role === 'owner') {
                $stmt = $pdo->prepare("SELECT id FROM branches WHERE owner_id = ? AND deleted_at IS NULL");
                $stmt->execute([$user_id]);
                $branches = $stmt->fetchAll(PDO::FETCH_COLUMN);

                if (count($branches) === 1) {
                    $conditions[] = "rt.branch_id = ?";
                    $params[] = $branches[0];
                } else {
                    // Có nhiều chi nhánh → lấy theo owner
                    $conditions[] = "rt.branch_id IN (SELECT id FROM branches WHERE owner_id = ? AND deleted_at IS NULL)";
                    $params[] = $user_id;
                }
            } elseif ($role !== 'admin') {
                // Nếu là staff, không phải admin → giới hạn theo owner
                $conditions[] = "rt.branch_id IN (SELECT id FROM branches WHERE owner_id = ? AND deleted_at IS NULL)";
                $params[] = $user_id;
            }
            // admin thì không cần thêm điều kiện
        }

        $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
        $query = "
            SELECT rt.id, rt.branch_id, rt.name, rt.description, rt.created_at, rt.updated_at, b.name AS branch_name
            FROM room_types rt
            LEFT JOIN branches b ON rt.branch_id = b.id
            $whereClause
            ORDER BY rt.created_at DESC
            LIMIT $limit OFFSET $offset
        ";

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
                'total_records' => (int)$totalRecords,
                'total_pages' => (int)$totalPages
            ]
        ]);
    } catch (PDOException $e) {
        $errorMsg = "Lỗi lấy danh sách loại phòng: " . $e->getMessage();
        logError($errorMsg);
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu: ' . $e->getMessage()], 500);
    } catch (Exception $e) {
        $errorMsg = "Lỗi không xác định: " . $e->getMessage();
        logError($errorMsg);
        responseJson(['status' => 'error', 'message' => 'Lỗi hệ thống'], 500);
    }
}

// Lấy thông tin loại phòng theo ID
function getRoomTypeById($id) {
    try {
        $pdo = getDB();
        $user = verifyJWT();
        if (!$user) {
            responseJson(['status' => 'error', 'message' => 'Không xác thực được người dùng'], 401);
            return;
        }
        $user_id = $user['user_id'];
        $role = $user['role'];

        $conditions = ['rt.id = ?', 'rt.deleted_at IS NULL'];
        $params = [(int)$id];

        // Kiểm tra quyền truy cập cho owner và staff
        if ($role === 'owner' || $role !== 'admin') {
            $conditions[] = "rt.branch_id IN (SELECT id FROM branches WHERE owner_id = ? AND deleted_at IS NULL)";
            $params[] = $user_id;
        }

        $whereClause = "WHERE " . implode(" AND ", $conditions);
        $query = "
            SELECT rt.id, rt.branch_id, rt.name, rt.description, rt.created_at, rt.updated_at, b.name AS branch_name
            FROM room_types rt
            LEFT JOIN branches b ON rt.branch_id = b.id
            $whereClause
        ";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $roomType = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$roomType) {
            responseJson(['status' => 'error', 'message' => 'Loại phòng không tồn tại'], 404);
            return;
        }

        responseJson(['status' => 'success', 'data' => $roomType]);
    } catch (PDOException $e) {
        $errorMsg = "Lỗi lấy thông tin loại phòng: " . $e->getMessage();
        logError($errorMsg);
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu: ' . $e->getMessage()], 500);
    }
}

// Tạo loại phòng
function createRoomType() {
    try {
        $pdo = getDB();
        $user = verifyJWT();
        if (!$user) {
            responseJson(['status' => 'error', 'message' => 'Không xác thực được người dùng'], 401);
            return;
        }
        $user_id = $user['user_id'];
        $role = $user['role'];

        if ($role !== 'admin' && $role !== 'owner') {
            logError("Không có quyền tạo loại phòng");
            responseJson(['status' => 'error', 'message' => 'Không có quyền tạo loại phòng'], 403);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        validateRequiredFields($input, ['branch_id', 'name']);
        $data = sanitizeInput($input);

        $name = $data['name'];
        $description = $data['description'] ?? null;
        $branch_id = (int)$data['branch_id'];

        if ($role === 'owner') {
            $stmt = $pdo->prepare("SELECT 1 FROM branches WHERE id = ? AND owner_id = ? AND deleted_at IS NULL");
            $stmt->execute([$branch_id, $user_id]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Không có quyền tạo loại phòng cho chi nhánh này'], 403);
                return;
            }
        }

        checkResourceExists($pdo, 'branches', $branch_id);

        $stmt = $pdo->prepare("
            INSERT INTO room_types (branch_id, name, description, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$branch_id, $name, $description]);

        responseJson(['status' => 'success', 'message' => 'Tạo loại phòng thành công']);
    } catch (PDOException $e) {
        $errorMsg = "Lỗi tạo loại phòng: " . $e->getMessage();
        logError($errorMsg);
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu: ' . $e->getMessage()], 500);
    }
}

// Cập nhật toàn bộ thông tin loại phòng
function updateRoomType($id) {
    try {
        $pdo = getDB();
        $user = verifyJWT();
        if (!$user) {
            logError("Lỗi xác thực JWT");
            responseJson(['status' => 'error', 'message' => 'Không xác thực được người dùng'], 401);
            return;
        }
        $user_id = $user['user_id'];
        $role = $user['role'];

        if ($role !== 'admin' && $role !== 'owner') {
            logError("Không có quyền cập nhật loại phòng");
            responseJson(['status' => 'error', 'message' => 'Không có quyền cập nhật loại phòng'], 403);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        validateRequiredFields($input, ['name', 'branch_id']);
        $data = sanitizeInput($input);

        $name = $data['name'];
        $description = $data['description'] ?? null;
        $branch_id = (int)$data['branch_id'];

        if ($role === 'owner') {
            $stmt = $pdo->prepare("SELECT 1 FROM branches WHERE id = ? AND owner_id = ? AND deleted_at IS NULL");
            $stmt->execute([$branch_id, $user_id]);
            if (!$stmt->fetch()) {
                logError("Owner không có quyền cho branch_id: $branch_id");
                responseJson(['status' => 'error', 'message' => 'Không có quyền cập nhật loại phòng cho chi nhánh này'], 403);
                return;
            }
        }

        checkResourceExists($pdo, 'branches', $branch_id);


        $stmt = $pdo->prepare("SELECT 1 FROM room_types WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            logError("Loại phòng không tồn tại, ID: $id");
            responseJson(['status' => 'error', 'message' => 'Loại phòng không tồn tại'], 404);
            return;
        }

        $stmt = $pdo->prepare("
            UPDATE room_types
            SET name = ?, description = ?, branch_id = ?, updated_at = NOW()
            WHERE id = ? AND deleted_at IS NULL
        ");
        $stmt->execute([$name, $description, $branch_id, $id]);
        responseJson(['status' => 'success', 'message' => 'Cập nhật loại phòng thành công']);
    } catch (PDOException $e) {
        $errorMsg = "Lỗi cập nhật loại phòng: " . $e->getMessage();
        logError($errorMsg);
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu: ' . $e->getMessage()], 500);
    }
}

// Cập nhật một phần thông tin loại phòng
function patchRoomType($id) {
    try {
        $pdo = getDB();
        $user = verifyJWT();
        if (!$user) {
            responseJson(['status' => 'error', 'message' => 'Không xác thực được người dùng'], 401);
            return;
        }
        $user_id = $user['user_id'];
        $role = $user['role'];
 

        if ($role !== 'admin' && $role !== 'owner') {
            logError("Không có quyền cập nhật loại phòng");
            responseJson(['status' => 'error', 'message' => 'Không có quyền cập nhật loại phòng'], 403);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);

        if (empty($input) || !is_array($input)) {
            logError("Dữ liệu đầu vào không hợp lệ");
            responseJson(['status' => 'error', 'message' => 'Dữ liệu đầu vào không hợp lệ'], 400);
            return;
        }
        $data = sanitizeInput($input);

        $fields = [];
        $params = [];
        $allowedFields = ['name', 'description', 'branch_id'];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = ?";
                if ($field === 'branch_id') {
                    $params[] = (int)$data[$field];
                } else {
                    $params[] = $data[$field];
                }
            }
        }

        if (empty($fields)) {
            responseJson(['status' => 'error', 'message' => 'Không có trường nào để cập nhật'], 400);
            return;
        }


        if ($role === 'owner') {
            $branch_id = array_key_exists('branch_id', $data) ? (int)$data['branch_id'] : null;
            if ($branch_id) {
                $stmt = $pdo->prepare("SELECT 1 FROM branches WHERE id = ? AND owner_id = ? AND deleted_at IS NULL");
                $stmt->execute([$branch_id, $user_id]);
                if (!$stmt->fetch()) {
                    responseJson(['status' => 'error', 'message' => 'Không có quyền cập nhật loại phòng cho chi nhánh này'], 403);
                    return;
                }
            } else {
                // Kiểm tra quyền sở hữu loại phòng hiện tại
                $stmt = $pdo->prepare("
                    SELECT 1 FROM room_types rt
                    JOIN branches b ON rt.branch_id = b.id
                    WHERE rt.id = ? AND b.owner_id = ? AND rt.deleted_at IS NULL
                ");
                $stmt->execute([$id, $user_id]);
                if (!$stmt->fetch()) {
                 
                    responseJson(['status' => 'error', 'message' => 'Không có quyền cập nhật loại phòng này'], 403);
                    return;
                }
            }
        }

        if (array_key_exists('branch_id', $data)) {
            checkResourceExists($pdo, 'branches', (int)$data['branch_id']);
           
        }

        $stmt = $pdo->prepare("SELECT 1 FROM room_types WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            
            responseJson(['status' => 'error', 'message' => 'Loại phòng không tồn tại'], 404);
            return;
        }

        $params[] = $id;
        $query = "UPDATE room_types SET " . implode(", ", $fields) . ", updated_at = NOW() WHERE id = ? AND deleted_at IS NULL";
  
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
      

        responseJson(['status' => 'success', 'message' => 'Cập nhật loại phòng thành công']);
    } catch (PDOException $e) {
        $errorMsg = "Lỗi cập nhật loại phòng một phần: " . $e->getMessage();
        logError($errorMsg);
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu: ' . $e->getMessage()], 500);
    }
}

// Xóa loại phòng (soft delete)
function deleteRoomType($id) {
    try {
        $pdo = getDB();
        $user = verifyJWT();
        if (!$user) {
            responseJson(['status' => 'error', 'message' => 'Không xác thực được người dùng'], 401);
            return;
        }
        $user_id = $user['user_id'];
        $role = $user['role'];

        if ($role !== 'admin' && $role !== 'owner') {
            responseJson(['status' => 'error', 'message' => 'Không có quyền xóa loại phòng'], 403);
            return;
        }

        if ($role === 'owner') {
            $stmt = $pdo->prepare("
                SELECT 1 FROM room_types rt
                JOIN branches b ON rt.branch_id = b.id
                WHERE rt.id = ? AND b.owner_id = ? AND rt.deleted_at IS NULL
            ");
            $stmt->execute([$id, $user_id]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Không có quyền xóa loại phòng này'], 403);
                return;
            }
        }

        // Kiểm tra xem có phòng nào đang sử dụng loại phòng này không
        $stmt = $pdo->prepare("SELECT 1 FROM rooms WHERE type_id = ? AND deleted_at IS NULL");
        $stmt->execute([$id]);
        if ($stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Không thể xóa loại phòng này vì vẫn còn phòng đang sử dụng nó'], 400);
            return;
        }

        $stmt = $pdo->prepare("SELECT 1 FROM room_types WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Loại phòng không tồn tại'], 404);
            return;
        }

        $stmt = $pdo->prepare("UPDATE room_types SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$id]);

        responseJson(['status' => 'success', 'message' => 'Xóa loại phòng thành công']);
    } catch (PDOException $e) {
        logError("Lỗi xóa loại phòng: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}
?>
<?php
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/utils/common.php';

function getTickets() {
    $pdo = getDB();
    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $conditions = [];
    $params = [];

    if (!empty($_GET['user_id']) && filter_var($_GET['user_id'], FILTER_VALIDATE_INT)) {
        $conditions[] = "t.user_id = ?";
        $params[] = $_GET['user_id'];
    }
    if (!empty($_GET['status']) && in_array($_GET['status'], ['open', 'in_progress', 'closed'])) {
        $conditions[] = "t.status = ?";
        $params[] = $_GET['status'];
    }
    if (!empty($_GET['priority']) && in_array($_GET['priority'], ['low', 'medium', 'high'])) {
        $conditions[] = "t.priority = ?";
        $params[] = $_GET['priority'];
    }

    if (!empty($_GET['search'])) {
        $search = '%' . sanitizeInput($_GET['search']) . '%';
        $conditions[] = "(t.title LIKE ? OR t.description LIKE ?)";
        $params[] = $search;
        $params[] = $search;
    }

    $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
    $query = "
        SELECT t.*, u.name AS user_name
        FROM tickets t
        LEFT JOIN users u ON t.user_id = u.id
        $whereClause
    ";

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM tickets t $whereClause");
    $countStmt->execute($params);
    $totalRecords = $countStmt->fetchColumn();
    $totalPages = ceil($totalRecords / $limit);

    $query .= " LIMIT $limit OFFSET $offset"; 

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $tickets = $stmt->fetchAll();

    responseJson([
        'status' => 'success',
        'data' => $tickets,
        'pagination' => [
            'current_page' => $page,
            'limit' => $limit,
            'total_records' => $totalRecords,
            'total_pages' => $totalPages
        ]
    ]);
}


function createTicket() {
    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['subject', 'description', 'branch_id']);
    $user = verifyJWT();

    $subject = sanitizeInput($input['subject']);
    $description = sanitizeInput($input['description']);
    $branchId = filter_var($input['branch_id'], FILTER_VALIDATE_INT);
    $status = in_array($input['status'] ?? 'open', ['open', 'in_progress', 'closed']) ? $input['status'] : 'open';

    if (!$branchId) {
        responseJson(['status' => 'error', 'message' => 'Dữ liệu không hợp lệ'], 400);
    }

    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'branches', $branchId);
        if ($user['role'] === 'customer') {
            $stmt = $pdo->prepare("SELECT id FROM branch_customers WHERE branch_id = ? AND user_id = ?");
            $stmt->execute([$branchId, $user['user_id']]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Bạn không có quyền tạo yêu cầu hỗ trợ cho chi nhánh này'], 403);
            }
        } elseif ($user['role'] === 'employee') {
            $stmt = $pdo->prepare("SELECT branch_id FROM employee_assignments WHERE branch_id = ? AND employee_id = ?");
            $stmt->execute([$branchId, $user['user_id']]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Chi nhánh không hợp lệ hoặc bạn không có quyền'], 403);
            }
        } elseif ($user['role'] === 'owner') {
            $stmt = $pdo->prepare("SELECT id FROM branches WHERE id = ? AND owner_id = ?");
            $stmt->execute([$branchId, $user['user_id']]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Chi nhánh không hợp lệ hoặc bạn không có quyền'], 403);
            }
        } else {
            responseJson(['status' => 'error', 'message' => 'Không có quyền tạo yêu cầu hỗ trợ'], 403);
        }

        $stmt = $pdo->prepare("
            INSERT INTO tickets (user_id, branch_id, subject, description, status)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$user['user_id'], $branchId, $subject, $description, $status]);

        $ticketId = $pdo->lastInsertId();
        createNotification($pdo, $user['user_id'], "Yêu cầu hỗ trợ ID $ticketId đã được tạo.");
        responseJson(['status' => 'success', 'data' => ['ticket_id' => $ticketId]]);
    } catch (Exception $e) {
        logError('Lỗi tạo ticket: ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function getTicketById() {
    $ticketId = getResourceIdFromUri('#/tickets/([0-9]+)#');
    $user = verifyJWT();
    $pdo = getDB();
    try {
        $query = "
            SELECT t.id, t.user_id, t.branch_id, t.subject, t.description, t.status, t.created at, t.updated_at,
                   u.username AS user_name, b.name AS branch_name
            FROM tickets t
            JOIN users u ON t.user_id = u.id
            JOIN branches b ON t.branch_id = b.id
            WHERE t.id = ?
        ";
        $params = [$ticketId];
        if ($user['role'] === 'customer') {
            $query .= " AND t.user_id = ?";
            $params[] = $user['user_id'];
        } elseif ($user['role'] === 'employee') {
            $query .= " AND t.branch_id IN (SELECT branch_id FROM employee_assignments WHERE employee_id = ?)";
            $params[] = $user['user_id'];
        } elseif ($user['role'] === 'owner') {
            $query .= " AND t.branch_id IN (SELECT id FROM branches WHERE owner_id = ?)";
            $params[] = $user['user_id'];
        }

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $ticket = $stmt->fetch();

        if (!$ticket) {
            responseJson(['status' => 'error', 'message' => 'Không tìm thấy yêu cầu hỗ trợ'], 404);
        }
        responseJson(['status' => 'success', 'data' => $ticket]);
    } catch (Exception $e) {
        logError('Lỗi lấy ticket ID ' . $ticketId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi truy vấn'], 500);
    }
}

function updateTicket() {
    $ticketId = getResourceIdFromUri('#/tickets/([0-9]+)#');
    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['subject', 'description', 'branch_id', 'status']);
    $user = verifyJWT();

    $subject = sanitizeInput($input['subject']);
    $description = sanitizeInput($input['description']);
    $branchId = filter_var($input['branch_id'], FILTER_VALIDATE_INT);
    $status = in_array($input['status'], ['open', 'in_progress', 'closed']) ? $input['status'] : 'open';

    if (!$branchId) {
        responseJson(['status' => 'error', 'message' => 'Dữ liệu không hợp lệ'], 400);
    }

    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'tickets', $ticketId);
        checkResourceExists($pdo, 'branches', $branchId);

        if ($user['role'] === 'customer') {
            $stmt = $pdo->prepare("SELECT id FROM tickets WHERE id = ? AND user_id = ?");
            $stmt->execute([$ticketId, $user['user_id']]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Bạn không có quyền chỉnh sửa yêu cầu này'], 403);
            }
            $stmt = $pdo->prepare("SELECT id FROM branch_customers WHERE branch_id = ? AND user_id = ?");
            $stmt->execute([$branchId, $user['user_id']]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Chi nhánh không hợp lệ hoặc bạn không có quyền'], 403);
            }
            if ($status !== 'open') {
                responseJson(['status' => 'error', 'message' => 'Khách hàng chỉ có thể đặt trạng thái open'], 403);
            }
        } elseif ($user['role'] === 'employee') {
            $stmt = $pdo->prepare("SELECT branch_id FROM employee_assignments WHERE branch_id = ? AND employee_id = ?");
            $stmt->execute([$branchId, $user['user_id']]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Chi nhánh không hợp lệ hoặc bạn không có quyền'], 403);
            }
        } elseif ($user['role'] === 'owner') {
            $stmt = $pdo->prepare("SELECT id FROM branches WHERE id = ? AND owner_id = ?");
            $stmt->execute([$branchId, $user['user_id']]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Chi nhánh không hợp lệ hoặc bạn không có quyền'], 403);
            }
        } else {
            responseJson(['status' => 'error', 'message' => 'Không có quyền chỉnh sửa yêu cầu hỗ trợ'], 403);
        }

        $stmt = $pdo->prepare("
            UPDATE tickets SET subject = ?, description = ?, branch_id = ?, status = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$subject, $description, $branchId, $status, $ticketId]);

        createNotification($pdo, $user['user_id'], "Yêu cầu hỗ trợ ID $ticketId đã được cập nhật.");
        responseJson(['status' => 'success', 'message' => 'Cập nhật yêu cầu hỗ trợ thành công']);
    } catch (Exception $e) {
        logError('Lỗi cập nhật ticket ID ' . $ticketId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function patchTicket() {
    $ticketId = getResourceIdFromUri('#/tickets/([0-9]+)#');
    $input = json_decode(file_get_contents('php://input'), true);
    $user = verifyJWT();

    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'tickets', $ticketId);
        if ($user['role'] === 'customer') {
            $stmt = $pdo->prepare("SELECT id FROM tickets WHERE id = ? AND user_id = ?");
            $stmt->execute([$ticketId, $user['user_id']]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Bạn không có quyền chỉnh sửa yêu cầu này'], 403);
            }
        } elseif ($user['role'] === 'employee') {
            $stmt = $pdo->prepare("SELECT id FROM tickets WHERE id = ? AND branch_id IN (SELECT branch_id FROM employee_assignments WHERE employee_id = ?)");
            $stmt->execute([$ticketId, $user['user_id']]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Yêu cầu không hợp lệ hoặc bạn không có quyền'], 403);
            }
        } elseif ($user['role'] === 'owner') {
            $stmt = $pdo->prepare("SELECT id FROM tickets WHERE id = ? AND branch_id IN (SELECT id FROM branches WHERE owner_id = ?)");
            $stmt->execute([$ticketId, $user['user_id']]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Yêu cầu không hợp lệ hoặc bạn không có quyền'], 403);
            }
        } else {
            responseJson(['status' => 'error', 'message' => 'Không có quyền chỉnh sửa yêu cầu hỗ trợ'], 403);
        }

        $updates = [];
        $params = [];
        if (!empty($input['subject'])) {
            $updates[] = "subject = ?";
            $params[] = sanitizeInput($input['subject']);
        }
        if (!empty($input['description'])) {
            $updates[] = "description = ?";
            $params[] = sanitizeInput($input['description']);
        }
        if (!empty($input['branch_id'])) {
            $branchId = filter_var($input['branch_id'], FILTER_VALIDATE_INT);
            checkResourceExists($pdo, 'branches', $branchId);
            if ($user['role'] === 'customer') {
                $stmt = $pdo->prepare("SELECT id FROM branch_customers WHERE branch_id = ? AND user_id = ?");
                $stmt->execute([$branchId, $user['user_id']]);
                if (!$stmt->fetch()) {
                    responseJson(['status' => 'error', 'message' => 'Chi nhánh không hợp lệ hoặc bạn không có quyền'], 403);
                }
            } elseif ($user['role'] === 'employee') {
                $stmt = $pdo->prepare("SELECT branch_id FROM employee_assignments WHERE branch_id = ? AND employee_id = ?");
                $stmt->execute([$branchId, $user['user_id']]);
                if (!$stmt->fetch()) {
                    responseJson(['status' => 'error', 'message' => 'Chi nhánh không hợp lệ hoặc bạn không có quyền'], 403);
                }
            } elseif ($user['role'] === 'owner') {
                $stmt = $pdo->prepare("SELECT id FROM branches WHERE id = ? AND owner_id = ?");
                $stmt->execute([$branchId, $user['user_id']]);
                if (!$stmt->fetch()) {
                    responseJson(['status' => 'error', 'message' => 'Chi nhánh không hợp lệ hoặc bạn không có quyền'], 403);
                }
            }
            $updates[] = "branch_id = ?";
            $params[] = $branchId;
        }
        if (!empty($input['status'])) {
            $status = in_array($input['status'], ['open', 'in_progress', 'closed']) ? $input['status'] : 'open';
            if ($user['role'] === 'customer' && $status !== 'open') {
                responseJson(['status' => 'error', 'message' => 'Khách hàng chỉ có thể đặt trạng thái open'], 403);
            }
            $updates[] = "status = ?";
            $params[] = $status;
        }

        if (empty($updates)) {
            responseJson(['status' => 'error', 'message' => 'Không có dữ liệu để cập nhật'], 400);
        }

        $updates[] = "updated_at = NOW()";
        $query = "UPDATE tickets SET " . implode(', ', $updates) . " WHERE id = ?";
        $params[] = $ticketId;
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);

        createNotification($pdo, $user['user_id'], "Yêu cầu hỗ trợ ID $ticketId đã được cập nhật.");
        responseJson(['status' => 'success', 'message' => 'Cập nhật yêu cầu hỗ trợ thành công']);
    } catch (Exception $e) {
        logError('Lỗi patch ticket ID ' . $ticketId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}

function deleteTicket() {
    $ticketId = getResourceIdFromUri('#/tickets/([0-9]+)#');
    $user = verifyJWT();
    $pdo = getDB();
    try {
        checkResourceExists($pdo, 'tickets', $ticketId);
        if ($user['role'] === 'customer') {
            $stmt = $pdo->prepare("SELECT id FROM tickets WHERE id = ? AND user_id = ?");
            $stmt->execute([$ticketId, $user['user_id']]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Yêu cầu không hợp lệ hoặc bạn không có quyền'], 403);
            }
        } elseif ($user['role'] === 'employee') {
            $stmt = $pdo->prepare("SELECT id FROM tickets WHERE id = ? AND branch_id IN (SELECT branch_id FROM employee_assignments WHERE employee_id = ?)");
            $stmt->execute([$ticketId, $user['user_id']]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Yêu cầu không hợp lệ hoặc bạn không có quyền'], 403);
            }
        } elseif ($user['role'] === 'owner') {
            $stmt = $pdo->prepare("SELECT id FROM tickets WHERE id = ? AND branch_id IN (SELECT id FROM branches WHERE owner_id = ?)");
            $stmt->execute([$ticketId, $user['user_id']]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Yêu cầu không hợp lệ hoặc bạn không có quyền'], 403);
            }
        } else {
            responseJson(['status' => 'error', 'message' => 'Không có quyền xóa yêu cầu hỗ trợ'], 403);
        }

        $stmt = $pdo->prepare("DELETE FROM tickets WHERE id = ?");
        $stmt->execute([$ticketId]);
        responseJson(['status' => 'success', 'message' => 'Xóa yêu cầu hỗ trợ thành công']);
    } catch (Exception $e) {
        logError('Lỗi xóa ticket ID ' . $ticketId . ': ' . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi xử lý'], 500);
    }
}
?>
<?php
require_once __DIR__ . '/common.php';

function validateDate($date) {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) {
        responseJson(['status' => 'error', 'message' => 'Ngày không hợp lệ, định dạng phải là YYYY-MM-DD'], 400);
    }
    return $date;
}

function getInvoices() {
    $pdo = getDB();

    // Phân trang
    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    // Điều kiện lọc
    $conditions = [];
    $params = [];

    if (!empty($_GET['status']) && in_array($_GET['status'], ['pending', 'paid', 'overdue'])) {
        $conditions[] = "i.status = ?";
        $params[] = $_GET['status'];
    }
    if (!empty($_GET['contract_id']) && filter_var($_GET['contract_id'], FILTER_VALIDATE_INT)) {
        $conditions[] = "i.contract_id = ?";
        $params[] = $_GET['contract_id'];
    }
    if (!empty($_GET['branch_id']) && filter_var($_GET['branch_id'], FILTER_VALIDATE_INT)) {
        $conditions[] = "i.branch_id = ?";
        $params[] = $_GET['branch_id'];
    }
    if (!empty($_GET['min_amount']) && filter_var($_GET['min_amount'], FILTER_VALIDATE_FLOAT)) {
        $conditions[] = "i.amount >= ?";
        $params[] = $_GET['min_amount'];
    }
    if (!empty($_GET['max_amount']) && filter_var($_GET['max_amount'], FILTER_VALIDATE_FLOAT)) {
        $conditions[] = "i.amount <= ?";
        $params[] = $_GET['max_amount'];
    }

    // Tìm kiếm
    if (!empty($_GET['search'])) {
        $search = '%' . sanitizeInput($_GET['search']) . '%';
        $conditions[] = "u.name LIKE ?";
        $params[] = $search;
    }

    // Xây dựng truy vấn
    $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
    $query = "
        SELECT i.*, c.user_id, c.room_id, b.name AS branch_name, u.name AS customer_name
        FROM invoices i
        JOIN contracts c ON i.contract_id = c.id
        JOIN branches b ON i.branch_id = b.id
        JOIN users u ON c.user_id = u.id
        $whereClause
    ";

    // Đếm tổng số bản ghi
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM invoices i JOIN contracts c ON i.contract_id = c.id JOIN users u ON c.user_id = u.id $whereClause");
    $countStmt->execute($params);
    $totalRecords = $countStmt->fetchColumn();
    $totalPages = ceil($totalRecords / $limit);

    // Truy vấn dữ liệu với phân trang
    $query .= " LIMIT $limit OFFSET $offset"; 

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $invoices = $stmt->fetchAll();

    // Trả về phản hồi với thông tin phân trang
    responseJson([
        'status' => 'success',
        'data' => $invoices,
        'pagination' => [
            'current_page' => $page,
            'limit' => $limit,
            'total_records' => $totalRecords,
            'total_pages' => $totalPages
        ]
    ]);
}

function createInvoice() {
    $pdo = getDB();
    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['contract_id', 'branch_id', 'amount', 'due_date']);

    $contractId = filter_var($input['contract_id'], FILTER_VALIDATE_INT);
    $branchId = filter_var($input['branch_id'], FILTER_VALIDATE_INT);
    $amount = filter_var($input['amount'], FILTER_VALIDATE_FLOAT);
    $dueDate = validateDate($input['due_date']);

    if (!$contractId || !$branchId || $amount < 0) {
        responseJson(['status' => 'error', 'message' => 'Dữ liệu không hợp lệ'], 400);
    }

    checkResourceExists($pdo, 'contracts', $contractId);
    checkResourceExists($pdo, 'branches', $branchId);

    $stmt = $pdo->prepare("SELECT user_id FROM contracts WHERE id = ?");
    $stmt->execute([$contractId]);
    $userId = $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        INSERT INTO invoices (contract_id, branch_id, amount, due_date)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$contractId, $branchId, $amount, $dueDate]);

    createNotification($pdo, $userId, "Hóa đơn mới với số tiền $amount đã được tạo, hạn thanh toán: $dueDate");
    responseJson(['status' => 'success', 'message' => 'Tạo hóa đơn thành công']);
}

function getInvoiceById() {
    $pdo = getDB();
    $id = getResourceIdFromUri('#^/api/v1/invoices/([0-9]+)$#');
    checkResourceExists($pdo, 'invoices', $id);

    $stmt = $pdo->prepare("
        SELECT i.*, c.user_id, c.room_id, b.name AS branch_name, u.name AS customer_name
        FROM invoices i
        JOIN contracts c ON i.contract_id = c.id
        JOIN branches b ON i.branch_id = b.id
        JOIN users u ON c.user_id = u.id
        WHERE i.id = ?
    ");
    $stmt->execute([$id]);
    $invoice = $stmt->fetch();
    responseJson(['status' => 'success', 'data' => $invoice]);
}

function updateInvoice() {
    $pdo = getDB();
    $id = getResourceIdFromUri('#^/api/v1/invoices/([0-9]+)$#');
    checkResourceExists($pdo, 'invoices', $id);

    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['contract_id', 'branch_id', 'amount', 'due_date', 'status']);

    $contractId = filter_var($input['contract_id'], FILTER_VALIDATE_INT);
    $branchId = filter_var($input['branch_id'], FILTER_VALIDATE_INT);
    $amount = filter_var($input['amount'], FILTER_VALIDATE_FLOAT);
    $dueDate = validateDate($input['due_date']);
    $status = $input['status'];

    if (!$contractId || !$branchId || $amount < 0 || !in_array($status, ['pending', 'paid', 'overdue'])) {
        responseJson(['status' => 'error', 'message' => 'Dữ liệu không hợp lệ'], 400);
    }

    checkResourceExists($pdo, 'contracts', $contractId);
    checkResourceExists($pdo, 'branches', $branchId);

    $stmt = $pdo->prepare("SELECT user_id FROM contracts WHERE id = ?");
    $stmt->execute([$contractId]);
    $userId = $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        UPDATE invoices
        SET contract_id = ?, branch_id = ?, amount = ?, due_date = ?, status = ?
        WHERE id = ?
    ");
    $stmt->execute([$contractId, $branchId, $amount, $dueDate, $status, $id]);

    createNotification($pdo, $userId, "Hóa đơn ID $id đã được cập nhật, số tiền: $amount, hạn thanh toán: $dueDate");
    responseJson(['status' => 'success', 'message' => 'Cập nhật hóa đơn thành công']);
}

function patchInvoice() {
    $pdo = getDB();
    $id = getResourceIdFromUri('#^/api/v1/invoices/([0-9]+)$#');
    checkResourceExists($pdo, 'invoices', $id);

    $input = json_decode(file_get_contents('php://input'), true);
    $allowedFields = ['contract_id', 'branch_id', 'amount', 'due_date', 'status'];
    $updates = [];
    $params = [];

    foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
            $updates[] = "$field = ?";
            if ($field === 'due_date') {
                $params[] = validateDate($input[$field]);
            } elseif ($field === 'amount') {
                $params[] = filter_var($input[$field], FILTER_VALIDATE_FLOAT);
            } elseif ($field === 'contract_id' || $field === 'branch_id') {
                $params[] = filter_var($input[$field], FILTER_VALIDATE_INT);
            } else {
                $params[] = $input[$field];
            }
        }
    }

    if (empty($updates)) {
        responseJson(['status' => 'error', 'message' => 'Không có trường nào để cập nhật'], 400);
    }

    if (isset($input['contract_id'])) {
        checkResourceExists($pdo, 'contracts', $input['contract_id']);
    }

    if (isset($input['branch_id'])) {
        checkResourceExists($pdo, 'branches', $input['branch_id']);
    }

    if (isset($input['amount']) && $input['amount'] < 0) {
        responseJson(['status' => 'error', 'message' => 'Số tiền không hợp lệ'], 400);
    }

    if (isset($input['status']) && !in_array($input['status'], ['pending', 'paid', 'overdue'])) {
        responseJson(['status' => 'error', 'message' => 'Trạng thái không hợp lệ'], 400);
    }

    $params[] = $id;
    $query = "UPDATE invoices SET " . implode(', ', $updates) . " WHERE id = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);

    if ($stmt->rowCount() > 0) {
        $stmt = $pdo->prepare("SELECT user_id FROM contracts WHERE id = (SELECT contract_id FROM invoices WHERE id = ?)");
        $stmt->execute([$id]);
        $userId = $stmt->fetchColumn();
        createNotification($pdo, $userId, "Hóa đơn ID $id đã được cập nhật");
        responseJson(['status' => 'success', 'message' => 'Cập nhật hóa đơn thành công']);
    } else {
        responseJson(['status' => 'success', 'message' => 'Không có thay đổi']);
    }
}

function deleteInvoice() {
    $pdo = getDB();
    $id = getResourceIdFromUri('#^/api/v1/invoices/([0-9]+)$#');
    checkResourceExists($pdo, 'invoices', $id);

    $stmt = $pdo->prepare("SELECT contract_id FROM invoices WHERE id = ?");
    $stmt->execute([$id]);
    $contractId = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT user_id FROM contracts WHERE id = ?");
    $stmt->execute([$contractId]);
    $userId = $stmt->fetchColumn();

    $stmt = $pdo->prepare("DELETE FROM invoices WHERE id = ?");
    $stmt->execute([$id]);

    createNotification($pdo, $userId, "Hóa đơn ID $id đã bị xóa");
    responseJson(['status' => 'success', 'message' => 'Xóa hóa đơn thành công']);
}
?>
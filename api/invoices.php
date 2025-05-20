<?php
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/utils/common.php';

// Lấy danh sách hóa đơn (GET /invoices)
function getInvoices() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if (!in_array($role, ['admin', 'owner', 'employee', 'customer'])) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền xem hóa đơn'], 403);
        return;
    }

    $branch_id = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : null;
    $month = isset($_GET['month']) ? $_GET['month'] : null;
    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] > 0 ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $conditions = ['i.deleted_at IS NULL'];
    $params = [];

    if ($branch_id) {
        $conditions[] = 'i.branch_id = :branch_id';
        $params['branch_id'] = $branch_id;
    }

    if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
        $conditions[] = "DATE_FORMAT(i.due_date, '%Y-%m') = :month";
        $params['month'] = $month;
    }

    if ($role === 'owner' || $role === 'employee') {
        $conditions[] = 'i.branch_id IN (
            SELECT id FROM branches WHERE owner_id = :owner_id OR id IN (
                SELECT branch_id FROM employee_assignments WHERE employee_id = :employee_id
            )
        )';
        $params['owner_id'] = $user_id;
        $params['employee_id'] = $user_id;
    } elseif ($role === 'customer') {
        $conditions[] = 'i.contract_id IN (
            SELECT id FROM contracts WHERE user_id = :customer_id
        )';
        $params['customer_id'] = $user_id;
    }

    $where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $query = "
        SELECT i.id, i.contract_id, i.branch_id, i.amount, i.due_date, i.status, i.created_at,
               c.room_id, r.name AS room_name, b.name AS branch_name, p.payment_date,
               u.phone AS owner_phone, u.qr_code_url, u.bank_details
        FROM invoices i
        JOIN contracts c ON i.contract_id = c.id
        JOIN rooms r ON c.room_id = r.id
        JOIN branches b ON i.branch_id = b.id
        LEFT JOIN payments p ON i.contract_id = p.contract_id AND i.due_date = p.due_date
        JOIN users u ON b.owner_id = u.id
        $where_clause
        ORDER BY i.created_at DESC
        LIMIT :limit OFFSET :offset
    ";

    try {
        $count_query = "
            SELECT COUNT(*) 
            FROM invoices i
            JOIN contracts c ON i.contract_id = c.id
            JOIN rooms r ON c.room_id = r.id
            JOIN branches b ON i.branch_id = b.id
            JOIN users u ON b.owner_id = u.id
            $where_clause
        ";
        $count_stmt = $pdo->prepare($count_query);
        foreach ($params as $key => $value) {
            $count_stmt->bindValue(":$key", $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $count_stmt->execute();
        $total_records = $count_stmt->fetchColumn();
        $total_pages = ceil($total_records / $limit);

        $stmt = $pdo->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue(":$key", $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($invoices as &$invoice) {
            $invoice['bank_details'] = $invoice['bank_details'] ? json_decode($invoice['bank_details'], true) : null;
        }

        responseJson([
            'status' => 'success',
            'data' => $invoices,
            'pagination' => [
                'current_page' => $page,
                'limit' => $limit,
                'total_records' => $total_records,
                'total_pages' => $total_pages
            ]
        ]);
    } catch (PDOException $e) {
        error_log("Lỗi lấy danh sách hóa đơn: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Tạo hóa đơn (POST /invoices)
function createInvoice() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if (!in_array($role, ['owner', 'employee'])) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền tạo hóa đơn'], 403);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['contract_id', 'branch_id', 'amount', 'due_date', 'status']);
    $data = sanitizeInput($input);
    $contract_id = (int)$data['contract_id'];
    $branch_id = (int)$data['branch_id'];
    $amount = (float)$data['amount'];
    $due_date = $data['due_date'];
    $status = $data['status'];

    if ($amount < 0) {
        responseJson(['status' => 'error', 'message' => 'Tổng tiền không được âm'], 400);
        return;
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $due_date)) {
        responseJson(['status' => 'error', 'message' => 'Định dạng ngày đến hạn không hợp lệ (YYYY-MM-DD)'], 400);
        return;
    }

    if (!in_array($status, ['pending', 'paid', 'overdue'])) {
        responseJson(['status' => 'error', 'message' => 'Trạng thái không hợp lệ'], 400);
        return;
    }

    try {
        checkResourceExists($pdo, 'contracts', $contract_id);
        checkResourceExists($pdo, 'branches', $branch_id);

        $stmt = $pdo->prepare("
            SELECT 1 FROM branches b
            WHERE b.id = ? AND (b.owner_id = ? OR EXISTS (
                SELECT 1 FROM employee_assignments ea WHERE ea.branch_id = b.id AND ea.employee_id = ?
            ))
        ");
        $stmt->execute([$branch_id, $user_id, $user_id]);
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Không có quyền tạo hóa đơn cho chi nhánh này'], 403);
            return;
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO invoices (contract_id, branch_id, amount, due_date, status, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$contract_id, $branch_id, $amount, $due_date, $status]);
        $invoice_id = $pdo->lastInsertId();

        $stmt = $pdo->prepare("
            SELECT r.name AS room_name
            FROM contracts c
            JOIN rooms r ON c.room_id = r.id
            WHERE c.id = ?
        ");
        $stmt->execute([$contract_id]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);
        $room_name = $room['room_name'];

        $pdo->commit();

        createNotification(
            $pdo,
            $user_id,
            "Đã tạo hóa đơn (ID: $invoice_id, Tổng: $amount, Trạng thái: $status) cho phòng $room_name."
        );

        $stmt = $pdo->prepare("
            SELECT i.id, i.contract_id, i.branch_id, i.amount, i.due_date, i.status, i.created_at,
                   u.phone AS owner_phone, u.qr_code_url, u.bank_details
            FROM invoices i
            JOIN branches b ON i.branch_id = b.id
            JOIN users u ON b.owner_id = u.id
            WHERE i.id = ?
        ");
        $stmt->execute([$invoice_id]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        $invoice['bank_details'] = $invoice['bank_details'] ? json_decode($invoice['bank_details'], true) : null;

        responseJson([
            'status' => 'success',
            'message' => 'Tạo hóa đơn thành công',
            'data' => $invoice
        ]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Lỗi tạo hóa đơn: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Lấy hóa đơn theo ID (GET /invoices/{id})
function getInvoiceById($invoice_id) {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if (!in_array($role, ['admin', 'owner', 'employee', 'customer'])) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền xem hóa đơn'], 403);
        return;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT i.id, i.contract_id, i.branch_id, i.amount, i.due_date, i.status, i.created_at,
                   c.room_id, r.name AS room_name, b.name AS branch_name, p.payment_date,
                   u.phone AS owner_phone, u.qr_code_url, u.bank_details
            FROM invoices i
            JOIN contracts c ON i.contract_id = c.id
            JOIN rooms r ON c.room_id = r.id
            JOIN branches b ON i.branch_id = b.id
            LEFT JOIN payments p ON i.contract_id = p.contract_id AND i.due_date = p.due_date
            JOIN users u ON b.owner_id = u.id
            WHERE i.id = ? AND i.deleted_at IS NULL
        ");
        $stmt->execute([$invoice_id]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$invoice) {
            responseJson(['status' => 'error', 'message' => 'Hóa đơn không tồn tại'], 404);
            return;
        }

        if ($role === 'owner' || $role === 'employee') {
            $stmt = $pdo->prepare("
                SELECT 1 FROM branches b
                WHERE b.id = ? AND (b.owner_id = ? OR EXISTS (
                    SELECT 1 FROM employee_assignments ea WHERE ea.branch_id = b.id AND ea.employee_id = ?
                ))
            ");
            $stmt->execute([$invoice['branch_id'], $user_id, $user_id]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Không có quyền xem hóa đơn này'], 403);
                return;
            }
        } elseif ($role === 'customer') {
            $stmt = $pdo->prepare("
                SELECT 1 FROM contracts c
                WHERE c.id = ? AND c.user_id = ?
            ");
            $stmt->execute([$invoice['contract_id'], $user_id]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Không có quyền xem hóa đơn này'], 403);
                return;
            }
        }

        $invoice['bank_details'] = $invoice['bank_details'] ? json_decode($invoice['bank_details'], true) : null;

        responseJson([
            'status' => 'success',
            'data' => $invoice
        ]);
    } catch (PDOException $e) {
        error_log("Lỗi lấy hóa đơn: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Lấy chi tiết hóa đơn (GET /invoices/{id}/details)
function getInvoiceDetails($invoice_id) {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if (!in_array($role, ['admin', 'owner', 'employee', 'customer'])) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền xem chi tiết hóa đơn'], 403);
        return;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT i.id, i.contract_id, i.branch_id, i.amount, i.due_date, i.status, i.created_at,
                   c.room_id, r.name AS room_name, b.name AS branch_name,
                   u.phone AS owner_phone, u.qr_code_url, u.bank_details
            FROM invoices i
            JOIN contracts c ON i.contract_id = c.id
            JOIN rooms r ON c.room_id = r.id
            JOIN branches b ON i.branch_id = b.id
            JOIN users u ON b.owner_id = u.id
            WHERE i.id = ? AND i.deleted_at IS NULL
        ");
        $stmt->execute([$invoice_id]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$invoice) {
            responseJson(['status' => 'error', 'message' => 'Hóa đơn không tồn tại'], 404);
            return;
        }

        if ($role === 'owner' || $role === 'employee') {
            $stmt = $pdo->prepare("
                SELECT 1 FROM branches b
                WHERE b.id = ? AND (b.owner_id = ? OR EXISTS (
                    SELECT 1 FROM employee_assignments ea WHERE ea.branch_id = b.id AND ea.employee_id = ?
                ))
            ");
            $stmt->execute([$invoice['branch_id'], $user_id, $user_id]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Không có quyền xem hóa đơn này'], 403);
                return;
            }
        } elseif ($role === 'customer') {
            $stmt = $pdo->prepare("
                SELECT 1 FROM contracts c
                WHERE c.id = ? AND c.user_id = ?
            ");
            $stmt->execute([$invoice['contract_id'], $user_id]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Không có quyền xem hóa đơn này'], 403);
                return;
            }
        }

        $month = date('Y-m', strtotime($invoice['due_date']));
        $stmt = $pdo->prepare("
            SELECT s.id AS service_id, s.name AS service_name, s.price, u.usage_amount, s.unit
            FROM utility_usage u
            JOIN services s ON u.service_id = s.id
            WHERE u.room_id = ? AND u.month = ? AND u.deleted_at IS NULL
        ");
        $stmt->execute([$invoice['room_id'], $month]);
        $usages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $details = [
            [
                'service_id' => null,
                'amount' => (float)$invoice['amount'],
                'usage_amount' => null,
                'description' => "Tiền phòng ({$invoice['room_name']})",
                'service_name' => 'Room Price'
            ]
        ];

        foreach ($usages as $usage) {
            $details[] = [
                'service_id' => $usage['service_id'],
                'amount' => (float)($usage['usage_amount'] * $usage['price']),
                'usage_amount' => (float)$usage['usage_amount'],
                'description' => "Tiền {$usage['service_name']} ({$usage['usage_amount']} {$usage['unit']})",
                'service_name' => $usage['service_name']
            ];
        }

        $invoice['bank_details'] = $invoice['bank_details'] ? json_decode($invoice['bank_details'], true) : null;

        responseJson([
            'status' => 'success',
            'data' => [
                'invoice' => $invoice,
                'details' => $details
            ]
        ]);
    } catch (PDOException $e) {
        error_log("Lỗi lấy chi tiết hóa đơn: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Tạo/làm mới hóa đơn hàng loạt (POST /invoices/bulk)
function createBulkInvoices() {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if (!in_array($role, ['admin', 'owner', 'employee'])) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền tạo hóa đơn'], 403);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['branch_id', 'month', 'due_date']);
    $data = sanitizeInput($input);
    $branch_id = (int)$data['branch_id'];
    $month = $data['month'];
    $due_date = $data['due_date'];

    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        responseJson(['status' => 'error', 'message' => 'Định dạng tháng không hợp lệ (YYYY-MM)'], 400);
        return;
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $due_date)) {
        responseJson(['status' => 'error', 'message' => 'Định dạng ngày đến hạn không hợp lệ (YYYY-MM-DD)'], 400);
        return;
    }

    try {
        checkResourceExists($pdo, 'branches', $branch_id);

        if ($role === 'owner' || $role === 'employee') {
            $stmt = $pdo->prepare("
                SELECT 1 FROM branches b
                WHERE b.id = ? AND (b.owner_id = ? OR EXISTS (
                    SELECT 1 FROM employee_assignments ea WHERE ea.branch_id = b.id AND ea.employee_id = ?
                ))
            ");
            $stmt->execute([$branch_id, $user_id, $user_id]);
            if (!$stmt->fetch()) {
                responseJson(['status' => 'error', 'message' => 'Không có quyền tạo hóa đơn cho chi nhánh này'], 403);
                return;
            }
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT c.id AS contract_id, c.room_id, r.price AS room_price, r.name AS room_name
            FROM contracts c
            JOIN rooms r ON c.room_id = r.id
            WHERE r.branch_id = ? 
            AND r.status = 'occupied'
            AND ? BETWEEN DATE_FORMAT(c.start_date, '%Y-%m') AND DATE_FORMAT(c.end_date, '%Y-%m')
            AND c.status IN ('active', 'ended', 'cancelled')
            AND c.deleted_at IS NULL
            AND EXISTS (
                SELECT 1 
                FROM utility_usage u
                JOIN services s ON u.service_id = s.id
                WHERE u.room_id = c.room_id 
                AND u.month = ? 
                AND u.deleted_at IS NULL
                AND s.type = 'electricity'
            )
        ");
        $stmt->execute([$branch_id, $month, $month]);
        $contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $created = [];
        foreach ($contracts as $contract) {
            $contract_id = $contract['contract_id'];
            $room_id = $contract['room_id'];
            $room_price = $contract['room_price'];
            $room_name = $contract['room_name'];

            $stmt = $pdo->prepare("
                SELECT u.service_id, u.usage_amount, s.price, s.name AS service_name, s.unit
                FROM utility_usage u
                JOIN services s ON u.service_id = s.id
                WHERE u.room_id = ? AND u.month = ? AND u.deleted_at IS NULL
            ");
            $stmt->execute([$room_id, $month]);
            $usages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $total_amount = $room_price;
            foreach ($usages as $usage) {
                $service_amount = $usage['usage_amount'] * $usage['price'];
                $total_amount += $service_amount;
            }

            $stmt = $pdo->prepare("
                SELECT id FROM invoices
                WHERE contract_id = ? AND DATE_FORMAT(due_date, '%Y-%m') = ? AND deleted_at IS NULL
            ");
            $stmt->execute([$contract_id, $month]);
            $existing_invoice = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing_invoice) {
                $stmt = $pdo->prepare("
                    UPDATE invoices
                    SET amount = ?, due_date = ?, status = 'pending', created_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$total_amount, $due_date, $existing_invoice['id']]);
                $invoice_id = $existing_invoice['id'];
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO invoices (contract_id, branch_id, amount, due_date, status, created_at)
                    VALUES (?, ?, ?, ?, 'pending', NOW())
                ");
                $stmt->execute([$contract_id, $branch_id, $total_amount, $due_date]);
                $invoice_id = $pdo->lastInsertId();
            }

            $created[] = [
                'id' => $invoice_id,
                'contract_id' => $contract_id,
                'room_id' => $room_id,
                'branch_id' => $branch_id,
                'amount' => $total_amount,
                'due_date' => $due_date,
                'status' => 'pending'
            ];

            createNotification(
                $pdo,
                $user_id,
                "Đã tạo/cập nhật hóa đơn (ID: $invoice_id, Tổng: $total_amount) cho phòng $room_name, kỳ $month."
            );
        }

        $pdo->commit();

        responseJson([
            'status' => 'success',
            'message' => 'Tạo/làm mới hóa đơn thành công',
            'data' => [
                'count' => count($created),
                'invoices' => $created
            ]
        ]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Lỗi tạo hóa đơn: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Cập nhật hóa đơn (PUT /invoices/{id})
function updateInvoice($invoice_id) {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if (!in_array($role, ['owner', 'employee'])) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền cập nhật hóa đơn'], 403);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    validateRequiredFields($input, ['amount', 'due_date', 'status']);
    $data = sanitizeInput($input);
    $amount = (float)$data['amount'];
    $due_date = $data['due_date'];
    $status = $data['status'];

    if ($amount < 0) {
        responseJson(['status' => 'error', 'message' => 'Tổng tiền không được âm'], 400);
        return;
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $due_date)) {
        responseJson(['status' => 'error', 'message' => 'Định dạng ngày đến hạn không hợp lệ (YYYY-MM-DD)'], 400);
        return;
    }

    if (!in_array($status, ['pending', 'paid', 'overdue'])) {
        responseJson(['status' => 'error', 'message' => 'Trạng thái không hợp lệ'], 400);
        return;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT i.branch_id, i.contract_id, c.room_id, r.name AS room_name
            FROM invoices i
            JOIN contracts c ON i.contract_id = c.id
            JOIN rooms r ON c.room_id = r.id
            WHERE i.id = ? AND i.deleted_at IS NULL
        ");
        $stmt->execute([$invoice_id]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) {
            responseJson(['status' => 'error', 'message' => 'Hóa đơn không tồn tại'], 404);
            return;
        }

        $stmt = $pdo->prepare("
            SELECT 1 FROM branches b
            WHERE b.id = ? AND (b.owner_id = ? OR EXISTS (
                SELECT 1 FROM employee_assignments ea WHERE ea.branch_id = b.id AND ea.employee_id = ?
            ))
        ");
        $stmt->execute([$invoice['branch_id'], $user_id, $user_id]);
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Không có quyền cập nhật hóa đơn này'], 403);
            return;
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            UPDATE invoices
            SET amount = ?, due_date = ?, status = ?, created_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$amount, $due_date, $status, $invoice_id]);

        if ($status === 'paid') {
            $stmt = $pdo->prepare("
                SELECT id FROM payments
                WHERE contract_id = ? AND due_date = ?
            ");
            $stmt->execute([$invoice['contract_id'], $due_date]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($payment) {
                $stmt = $pdo->prepare("
                    UPDATE payments
                    SET amount = ?, payment_date = CURDATE(), status = 'paid'
                    WHERE id = ?
                ");
                $stmt->execute([$amount, $payment['id']]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO payments (contract_id, amount, due_date, payment_date, status, created_at)
                    VALUES (?, ?, ?, CURDATE(), 'paid', NOW())
                ");
                $stmt->execute([$invoice['contract_id'], $amount, $due_date]);
            }
        }

        $pdo->commit();

        createNotification(
            $pdo,
            $user_id,
            "Đã cập nhật hóa đơn (ID: $invoice_id, Tổng: $amount, Trạng thái: $status) cho phòng {$invoice['room_name']}."
        );

        $stmt = $pdo->prepare("
            SELECT i.id, i.contract_id, i.branch_id, i.amount, i.due_date, i.status, i.created_at,
                   u.phone AS owner_phone, u.qr_code_url, u.bank_details
            FROM invoices i
            JOIN branches b ON i.branch_id = b.id
            JOIN users u ON b.owner_id = u.id
            WHERE i.id = ?
        ");
        $stmt->execute([$invoice_id]);
        $updated_invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        $updated_invoice['bank_details'] = $updated_invoice['bank_details'] ? json_decode($updated_invoice['bank_details'], true) : null;

        responseJson([
            'status' => 'success',
            'message' => 'Cập nhật hóa đơn thành công',
            'data' => $updated_invoice
        ]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Lỗi cập nhật hóa đơn: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Cập nhật một phần hóa đơn (PATCH /invoices/{id})
function patchInvoice($invoice_id) {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if (!in_array($role, ['owner', 'employee'])) {
        responseJson(['status' => 'error', 'message' => 'Không có quyền cập nhật hóa đơn'], 403);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $data = sanitizeInput($input);

    $allowed_fields = ['amount', 'due_date', 'status'];
    $update_fields = [];
    $params = ['id' => $invoice_id];

    foreach ($allowed_fields as $field) {
        if (isset($data[$field])) {
            $update_fields[] = "$field = :$field";
            $params[$field] = $data[$field];
        }
    }

    if (empty($update_fields)) {
        responseJson(['status' => 'error', 'message' => 'Không có trường nào được cung cấp để cập nhật'], 400);
        return;
    }

    if (isset($data['amount']) && (float)$data['amount'] < 0) {
        responseJson(['status' => 'error', 'message' => 'Tổng tiền không được âm'], 400);
        return;
    }

    if (isset($data['due_date']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['due_date'])) {
        responseJson(['status' => 'error', 'message' => 'Định dạng ngày đến hạn không hợp lệ (YYYY-MM-DD)'], 400);
        return;
    }

    if (isset($data['status']) && !in_array($data['status'], ['pending', 'paid', 'overdue'])) {
        responseJson(['status' => 'error', 'message' => 'Trạng thái không hợp lệ'], 400);
        return;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT i.branch_id, i.contract_id, c.room_id, r.name AS room_name
            FROM invoices i
            JOIN contracts c ON i.contract_id = c.id
            JOIN rooms r ON c.room_id = r.id
            WHERE i.id = ? AND i.deleted_at IS NULL
        ");
        $stmt->execute([$invoice_id]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) {
            responseJson(['status' => 'error', 'message' => 'Hóa đơn không tồn tại'], 404);
            return;
        }

        $stmt = $pdo->prepare("
            SELECT 1 FROM branches b
            WHERE b.id = ? AND (b.owner_id = ? OR EXISTS (
                SELECT 1 FROM employee_assignments ea WHERE ea.branch_id = b.id AND ea.employee_id = ?
            ))
        ");
        $stmt->execute([$invoice['branch_id'], $user_id, $user_id]);
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Không có quyền cập nhật hóa đơn này'], 403);
            return;
        }

        $pdo->beginTransaction();

        $query = "UPDATE invoices SET " . implode(', ', $update_fields) . ", created_at = NOW() WHERE id = :id";
        $stmt = $pdo->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue(":$key", $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        $pdo->commit();

        createNotification(
            $pdo,
            $user_id,
            "Đã cập nhật một phần hóa đơn (ID: $invoice_id) cho phòng {$invoice['room_name']}."
        );

        $stmt = $pdo->prepare("
            SELECT i.id, i.contract_id, i.branch_id, i.amount, i.due_date, i.status, i.created_at,
                   u.phone AS owner_phone, u.qr_code_url, u.bank_details
            FROM invoices i
            JOIN branches b ON i.branch_id = b.id
            JOIN users u ON b.owner_id = u.id
            WHERE i.id = ?
        ");
        $stmt->execute([$invoice_id]);
        $updated_invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        $updated_invoice['bank_details'] = $updated_invoice['bank_details'] ? json_decode($updated_invoice['bank_details'], true) : null;

        responseJson([
            'status' => 'success',
            'message' => 'Cập nhật hóa đơn thành công',
            'data' => $updated_invoice
        ]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Lỗi cập nhật hóa đơn: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}

// Xóa mềm hóa đơn (DELETE /invoices/{id})
function deleteInvoice($invoice_id) {
    $pdo = getDB();
    $user = verifyJWT();
    $user_id = $user['user_id'];
    $role = $user['role'];

    if ($role !== 'owner') {
        responseJson(['status' => 'error', 'message' => 'Không có quyền xóa hóa đơn'], 403);
        return;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT i.branch_id, i.contract_id, c.room_id, r.name AS room_name
            FROM invoices i
            JOIN contracts c ON i.contract_id = c.id
            JOIN rooms r ON c.room_id = r.id
            WHERE i.id = ? AND i.deleted_at IS NULL
        ");
        $stmt->execute([$invoice_id]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) {
            responseJson(['status' => 'error', 'message' => 'Hóa đơn không tồn tại'], 404);
            return;
        }

        $stmt = $pdo->prepare("
            SELECT 1 FROM branches b
            WHERE b.id = ? AND b.owner_id = ?
        ");
        $stmt->execute([$invoice['branch_id'], $user_id]);
        if (!$stmt->fetch()) {
            responseJson(['status' => 'error', 'message' => 'Không có quyền xóa hóa đơn này'], 403);
            return;
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("UPDATE invoices SET deleted_at = NOW() WHERE id = ?");
        $stmt->execute([$invoice_id]);

        $pdo->commit();

        createNotification(
            $pdo,
            $user_id,
            "Đã xóa hóa đơn (ID: $invoice_id) cho phòng {$invoice['room_name']}."
        );

        responseJson([
            'status' => 'success',
            'message' => 'Xóa hóa đơn thành công',
            'data' => ['id' => $invoice_id]
        ]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Lỗi xóa hóa đơn: " . $e->getMessage());
        responseJson(['status' => 'error', 'message' => 'Lỗi cơ sở dữ liệu'], 500);
    }
}
?>
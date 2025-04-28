<?php
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/utils/common.php';
function getRevenueStatistics() {
    $pdo = getDB();

    // Filter conditions
    $conditions = [];
    $params = [];

    if (!empty($_GET['start_date'])) {
        $conditions[] = "p.payment_date >= ?";
        $params[] = validateDate($_GET['start_date']);
    }
    if (!empty($_GET['end_date'])) {
        $conditions[] = "p.payment_date <= ?";
        $params[] = validateDate($_GET['end_date']);
    }
    if (!empty($_GET['branch_id']) && filter_var($_GET['branch_id'], FILTER_VALIDATE_INT)) {
        $conditions[] = "c.branch_id = ?";
        $params[] = $_GET['branch_id'];
    }

    // Search
    if (!empty($_GET['search'])) {
        $search = '%' . sanitizeInput($_GET['search']) . '%';
        $conditions[] = "b.name LIKE ?";
        $params[] = $search;
    }

    // Build query
    $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
    $query = "
        SELECT 
            b.id AS branch_id, 
            b.name AS branch_name, 
            SUM(p.amount) AS total_revenue,
            COUNT(p.id) AS payment_count,
            DATE(p.payment_date) AS payment_date
        FROM payments p
        JOIN contracts c ON p.contract_id = c.id
        JOIN branches b ON c.branch_id = b.id
        $whereClause
        GROUP BY b.id, DATE(p.payment_date)
        ORDER BY payment_date DESC
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $statistics = $stmt->fetchAll();

    responseJson([
        'status' => 'success',
        'data' => $statistics
    ]);
}

// Hàm hỗ trợ validate ngày
function validateDate($date) {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) {
        responseJson(['status' => 'error', 'message' => 'Định dạng ngày không hợp lệ (Y-m-d)'], 400);
    }
    return $date;
}
?>
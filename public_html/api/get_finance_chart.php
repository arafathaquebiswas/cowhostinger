<?php
require_once dirname(__DIR__) . '/includes/role_guard.php';
require_once dirname(__DIR__) . '/includes/farm_guard.php';
startSecureSession();
requireRole(['admin', 'manager', 'accountant']);

$db  = getDB();
$raw = [];

$stmt = $db->prepare(
    "SELECT DATE_FORMAT(transaction_date, '%Y-%m') AS m,
            SUM(CASE WHEN type = 'income'  THEN amount ELSE 0 END) AS income,
            SUM(CASE WHEN type = 'expense' AND category != 'Equipment Purchase' THEN amount ELSE 0 END) AS expense
     FROM finance_transactions
     WHERE transaction_date >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
       AND " . farmFilter() . "
     GROUP BY m
     ORDER BY m ASC"
);
$stmt->execute();
foreach ($stmt->fetchAll() as $row) {
    $raw[$row['m']] = [
        'income'  => round((float)$row['income'],  2),
        'expense' => round((float)$row['expense'], 2),
    ];
}

$labels  = [];
$income  = [];
$expense = [];
for ($i = 5; $i >= 0; $i--) {
    $m       = date('Y-m', strtotime("-{$i} months"));
    $labels[] = date('M Y', strtotime($m . '-01'));
    $income[]  = $raw[$m]['income']  ?? 0;
    $expense[] = $raw[$m]['expense'] ?? 0;
}

jsonResponse(['labels' => $labels, 'income' => $income, 'expense' => $expense]);

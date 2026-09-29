<?php
/** JSON: leave balances for ?employee=ID&year=YYYY[&exclude=LEAVE_ID], keyed by leave type code. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/hr.php';
require_login();

header('Content-Type: application/json');
$employeeId = (int)input('employee');
$year = (int)input('year') ?: (int)date('Y');
if (!$employeeId) {
    http_response_code(400);
    echo json_encode(['error' => 'employee is required']);
    exit;
}
$out = [];
foreach (hr_leave_types() as $code => $t) {
    $out[$code] = hr_leave_balance($employeeId, $code, $year, (int)input('exclude'));
}
echo json_encode($out);

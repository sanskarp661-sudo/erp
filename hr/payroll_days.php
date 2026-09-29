<?php
/** JSON: payroll day breakdown for ?employee=ID&month=YYYY-MM (used by Generate Salary Slip). */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/hr.php';
require_module_manage('hrms');

header('Content-Type: application/json');
$employeeId = (int)input('employee');
$month = (string)input('month');
if (!$employeeId || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
    http_response_code(400);
    echo json_encode(['error' => 'employee and month (YYYY-MM) are required']);
    exit;
}
echo json_encode(hr_payroll_days($employeeId, $month));

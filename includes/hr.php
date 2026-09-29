<?php
/**
 * HRM helpers shared by Attendance, Leave Application and Salary Slip:
 * shift timings, working-day counting, leave balances and the payroll
 * day breakdown (working / present / paid leave / loss-of-pay days).
 *
 * Sundays are the weekly off everywhere in HRM.
 */

/** Shift name => [start, end] (24h). Matches the Work Shift options on the employee form. */
const HR_SHIFTS = [
    'General' => ['09:30', '18:30'],
    'Morning' => ['06:00', '14:00'],
    'Evening' => ['14:00', '22:00'],
    'Night' => ['22:00', '06:00'],
    'Rotational' => ['09:30', '18:30'],
];

/** Minutes after shift start before an entry counts as late (and before shift end for early exit). */
const HR_GRACE_MINUTES = 15;

function hr_is_weekly_off(string $date): bool
{
    return date('w', strtotime($date)) === '0';
}

/** Every date from $start to $end inclusive, as Y-m-d strings. */
function hr_date_range(string $start, string $end): array
{
    $dates = [];
    for ($t = strtotime($start), $last = strtotime($end); $t <= $last; $t = strtotime('+1 day', $t)) {
        $dates[] = date('Y-m-d', $t);
    }
    return $dates;
}

/** Number of non-Sunday days from $start to $end inclusive. */
function hr_working_days(string $start, string $end): int
{
    return count(array_filter(hr_date_range($start, $end), fn($d) => !hr_is_weekly_off($d)));
}

/** Days a leave request consumes: working days in range, minus half a day if it is a half-day leave. */
function hr_leave_days(string $start, string $end, bool $halfDay): float
{
    $days = (float)hr_working_days($start, $end);
    return $halfDay && $days > 0 ? $days - 0.5 : $days;
}

/**
 * Late entry / early exit / hours worked for a check-in and check-out
 * against a shift. Night shifts that cross midnight are handled.
 */
function hr_attendance_flags(?string $shift, ?string $checkIn, ?string $checkOut): array
{
    [$start, $end] = HR_SHIFTS[$shift ?: 'General'] ?? HR_SHIFTS['General'];
    $toMin = fn(string $t) => (int)substr($t, 0, 2) * 60 + (int)substr($t, 3, 2);
    $s = $toMin($start);
    $e = $toMin($end);
    $overnight = $e <= $s;
    $flags = ['late_entry' => 0, 'early_exit' => 0, 'working_hours' => null];
    if ($checkIn) {
        $in = $toMin($checkIn);
        if ($overnight && $in < $s - 240) {
            $in += 1440; // checked in after midnight on a night shift
        }
        $flags['late_entry'] = $in > $s + HR_GRACE_MINUTES ? 1 : 0;
    }
    if ($checkOut) {
        $out = $toMin($checkOut);
        $endMin = $overnight ? $e + 1440 : $e;
        if ($overnight && $out < $s) {
            $out += 1440;
        }
        $flags['early_exit'] = $out < $endMin - HR_GRACE_MINUTES ? 1 : 0;
    }
    if ($checkIn && $checkOut) {
        $in = $toMin($checkIn);
        $out = $toMin($checkOut);
        if ($out < $in) {
            $out += 1440;
        }
        $flags['working_hours'] = round(($out - $in) / 60, 2);
    }
    return $flags;
}

/** Active leave types keyed by code. */
function hr_leave_types(bool $activeOnly = true): array
{
    $rows = db()->query('SELECT * FROM leave_types' . ($activeOnly ? " WHERE status = 'active'" : '') . ' ORDER BY sort_order, name')->fetchAll();
    return array_column($rows, null, 'code');
}

/**
 * Leave balance for one employee, type and calendar year: yearly
 * allocation minus days already approved or pending (excluding
 * $excludeLeaveId, the request being edited). Unpaid types return null
 * (no limit).
 */
function hr_leave_balance(int $employeeId, string $typeCode, int $year, int $excludeLeaveId = 0): ?array
{
    $types = hr_leave_types(false);
    $type = $types[$typeCode] ?? null;
    if (!$type || !$type['is_paid']) {
        return null;
    }
    $stmt = db()->prepare("SELECT status, COALESCE(SUM(total_days), 0) days FROM leaves
        WHERE employee_id = ? AND leave_type = ? AND YEAR(start_date) = ? AND status IN ('approved','pending') AND id <> ?
        GROUP BY status");
    $stmt->execute([$employeeId, $typeCode, $year, $excludeLeaveId]);
    $used = ['approved' => 0.0, 'pending' => 0.0];
    foreach ($stmt as $r) {
        $used[$r['status']] = (float)$r['days'];
    }
    $allocated = (float)$type['annual_allocation'];
    return [
        'allocated' => $allocated,
        'taken' => $used['approved'],
        'pending' => $used['pending'],
        'available' => round($allocated - $used['approved'] - $used['pending'], 1),
    ];
}

/**
 * Payroll day breakdown for an employee and a pay month (Y-m):
 *   working_days  non-Sunday days in the month
 *   present_days  attendance marked present (half day = 0.5)
 *   paid_leave    approved paid leave on working days
 *   lop_days      loss of pay: absences, unpaid leave, the unpaid half of
 *                 a half day, days before joining / after relieving, and
 *                 unmarked days
 *   unmarked      working days with no attendance and no leave; these
 *                 are unpaid (included in lop_days)
 *   payment_days  working_days - lop_days
 */
function hr_payroll_days(int $employeeId, string $month): array
{
    $start = $month . '-01';
    $end = date('Y-m-t', strtotime($start));
    $emp = db()->prepare('SELECT hire_date, relieving_date FROM employees WHERE id = ?');
    $emp->execute([$employeeId]);
    $emp = $emp->fetch() ?: ['hire_date' => null, 'relieving_date' => null];

    $attendance = [];
    $stmt = db()->prepare('SELECT attendance_date, status FROM attendance WHERE employee_id = ? AND attendance_date BETWEEN ? AND ?');
    $stmt->execute([$employeeId, $start, $end]);
    foreach ($stmt as $r) {
        $attendance[$r['attendance_date']] = $r['status'];
    }

    // Approved leave per date: 'paid' / 'unpaid', and whether it is a half day.
    $leaveOn = [];
    $types = hr_leave_types(false);
    $stmt = db()->prepare("SELECT leave_type, start_date, end_date, half_day, half_day_date FROM leaves
        WHERE employee_id = ? AND status = 'approved' AND start_date <= ? AND end_date >= ?");
    $stmt->execute([$employeeId, $end, $start]);
    foreach ($stmt as $l) {
        $paid = (bool)($types[$l['leave_type']]['is_paid'] ?? true);
        foreach (hr_date_range(max($l['start_date'], $start), min($l['end_date'], $end)) as $d) {
            $half = $l['half_day'] && ($l['half_day_date'] ?: $l['start_date']) === $d;
            $leaveOn[$d] = ['paid' => $paid, 'fraction' => $half ? 0.5 : 1.0];
        }
    }

    $out = ['working_days' => 0.0, 'present_days' => 0.0, 'paid_leave' => 0.0, 'lop_days' => 0.0, 'unmarked' => 0.0, 'not_employed' => 0.0];
    foreach (hr_date_range($start, $end) as $d) {
        if (hr_is_weekly_off($d)) {
            continue;
        }
        $out['working_days']++;
        if (($emp['hire_date'] && $d < $emp['hire_date']) || ($emp['relieving_date'] && $d > $emp['relieving_date'])) {
            $out['lop_days']++;
            $out['not_employed']++;
            continue;
        }
        $leave = $leaveOn[$d] ?? null;
        $leaveFraction = $leave['fraction'] ?? 0.0;
        if ($leave) {
            $leave['paid'] ? $out['paid_leave'] += $leaveFraction : $out['lop_days'] += $leaveFraction;
        }
        $remaining = 1.0 - $leaveFraction; // part of the day not covered by leave
        if ($remaining <= 0) {
            continue;
        }
        $status = $attendance[$d] ?? null;
        if ($status === 'present') {
            $out['present_days'] += $remaining;
        } elseif ($status === 'half_day') {
            $worked = min(0.5, $remaining);
            $out['present_days'] += $worked;
            $out['lop_days'] += $remaining - $worked;
        } elseif ($status === 'absent' || ($status === 'leave' && !$leave)) {
            // Marked absent, or marked "leave" with no approved application.
            $out['lop_days'] += $remaining;
        } elseif ($status === null) {
            $out['unmarked'] += $remaining;
            $out['lop_days'] += $remaining;
        }
    }
    $out['payment_days'] = max(0, $out['working_days'] - $out['lop_days']);
    return $out;
}

/** Whether the current user may approve or reject this leave application. */
function hr_can_decide_leave(array $leave): bool
{
    $me = current_user();
    return can_manage_module('hrms') || ($leave['leave_approver_id'] && (int)$leave['leave_approver_id'] === (int)($me['id'] ?? 0));
}

/**
 * Marks attendance for an approved leave: 'leave' on each working day in
 * its range ('half_day' on its half-day date), linked by leave_id so a
 * later cancellation can remove exactly those rows.
 */
function hr_mark_leave_attendance(array $leave): void
{
    $stmt = db()->prepare("INSERT INTO attendance (employee_id, attendance_date, status, check_in, check_out, late_entry, early_exit, working_hours, leave_id, marked_by)
        VALUES (?, ?, ?, NULL, NULL, 0, 0, NULL, ?, ?)
        ON DUPLICATE KEY UPDATE status = VALUES(status), check_in = NULL, check_out = NULL, late_entry = 0, early_exit = 0, working_hours = NULL, leave_id = VALUES(leave_id), marked_by = VALUES(marked_by)");
    foreach (hr_date_range($leave['start_date'], $leave['end_date']) as $d) {
        if (hr_is_weekly_off($d)) {
            continue;
        }
        $half = $leave['half_day'] && ($leave['half_day_date'] ?: $leave['start_date']) === $d;
        $stmt->execute([$leave['employee_id'], $d, $half ? 'half_day' : 'leave', $leave['id'], current_user()['id'] ?? null]);
    }
}

/** Removes the attendance rows an approved leave created. */
function hr_clear_leave_attendance(int $leaveId): void
{
    db()->prepare('DELETE FROM attendance WHERE leave_id = ?')->execute([$leaveId]);
}

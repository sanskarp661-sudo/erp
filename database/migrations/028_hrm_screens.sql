-- Migration 028 (part 2): Salary Slip, Leave Application, Attendance and
-- Department redesign. Run after 028_hrm_employee_tabs.sql.
--
--   salary_slips       attendance-based pay days, loss-of-pay (LOP)
--                      proration, bank snapshot, remarks
--   salary_slip_items  full (unprorated) amount per component
--   leave_types        new master: yearly allocation, paid / unpaid
--   leaves             half-days, day count, approver, rejection reason
--   attendance         shift, late entry / early exit, working hours
--   departments        code, parent, head, cost center, status
--
-- Existing rows keep working: new columns default to values that reproduce
-- the old behaviour (e.g. a slip with no working days recorded is shown
-- exactly as before).
--
-- Safe to re-run: every CREATE TABLE / ADD COLUMN / INSERT is idempotent.

SET NAMES utf8mb4;

-- Salary Slip ---------------------------------------------------------
ALTER TABLE salary_slips ADD COLUMN IF NOT EXISTS posting_date DATE DEFAULT NULL AFTER pay_period_end;
ALTER TABLE salary_slips ADD COLUMN IF NOT EXISTS working_days DECIMAL(5,1) NOT NULL DEFAULT 0 AFTER posting_date;
ALTER TABLE salary_slips ADD COLUMN IF NOT EXISTS present_days DECIMAL(5,1) NOT NULL DEFAULT 0 AFTER working_days;
ALTER TABLE salary_slips ADD COLUMN IF NOT EXISTS paid_leave_days DECIMAL(5,1) NOT NULL DEFAULT 0 AFTER present_days;
ALTER TABLE salary_slips ADD COLUMN IF NOT EXISTS lop_days DECIMAL(5,1) NOT NULL DEFAULT 0 AFTER paid_leave_days;
ALTER TABLE salary_slips ADD COLUMN IF NOT EXISTS payment_days DECIMAL(5,1) NOT NULL DEFAULT 0 AFTER lop_days;
ALTER TABLE salary_slips ADD COLUMN IF NOT EXISTS prorate_lop TINYINT(1) NOT NULL DEFAULT 1 AFTER payment_days;
ALTER TABLE salary_slips ADD COLUMN IF NOT EXISTS full_basic_salary DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER basic_salary;
ALTER TABLE salary_slips ADD COLUMN IF NOT EXISTS salary_mode ENUM('bank_transfer','cash','cheque') DEFAULT NULL AFTER net_pay;
ALTER TABLE salary_slips ADD COLUMN IF NOT EXISTS bank_name VARCHAR(120) DEFAULT NULL AFTER salary_mode;
ALTER TABLE salary_slips ADD COLUMN IF NOT EXISTS bank_account_no VARCHAR(40) DEFAULT NULL AFTER bank_name;
ALTER TABLE salary_slips ADD COLUMN IF NOT EXISTS bank_ifsc VARCHAR(11) DEFAULT NULL AFTER bank_account_no;
ALTER TABLE salary_slips ADD COLUMN IF NOT EXISTS remarks TEXT DEFAULT NULL AFTER notes;
ALTER TABLE salary_slip_items ADD COLUMN IF NOT EXISTS full_amount DECIMAL(14,2) DEFAULT NULL AFTER amount;
UPDATE salary_slips SET full_basic_salary = basic_salary WHERE full_basic_salary = 0 AND basic_salary > 0;

-- Leave types (master) ------------------------------------------------
CREATE TABLE IF NOT EXISTS leave_types (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(30) NOT NULL UNIQUE,
  name VARCHAR(80) NOT NULL,
  annual_allocation DECIMAL(5,1) NOT NULL DEFAULT 0,
  is_paid TINYINT(1) NOT NULL DEFAULT 1,
  allow_half_day TINYINT(1) NOT NULL DEFAULT 1,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- `code` matches the values the old form stored in leaves.leave_type.
INSERT IGNORE INTO leave_types (code, name, annual_allocation, is_paid, sort_order) VALUES
  ('casual', 'Casual Leave', 12, 1, 1),
  ('sick', 'Sick Leave', 12, 1, 2),
  ('annual', 'Earned / Annual Leave', 18, 1, 3),
  ('unpaid', 'Leave Without Pay', 0, 0, 4);

-- Leave Application ---------------------------------------------------
ALTER TABLE leaves ADD COLUMN IF NOT EXISTS application_no VARCHAR(30) DEFAULT NULL AFTER id;
ALTER TABLE leaves ADD COLUMN IF NOT EXISTS half_day TINYINT(1) NOT NULL DEFAULT 0 AFTER end_date;
ALTER TABLE leaves ADD COLUMN IF NOT EXISTS half_day_date DATE DEFAULT NULL AFTER half_day;
ALTER TABLE leaves ADD COLUMN IF NOT EXISTS total_days DECIMAL(5,1) NOT NULL DEFAULT 0 AFTER half_day_date;
ALTER TABLE leaves ADD COLUMN IF NOT EXISTS contact_during_leave VARCHAR(120) DEFAULT NULL AFTER reason;
ALTER TABLE leaves ADD COLUMN IF NOT EXISTS handover_to_id INT UNSIGNED DEFAULT NULL AFTER contact_during_leave;
ALTER TABLE leaves ADD COLUMN IF NOT EXISTS leave_approver_id INT UNSIGNED DEFAULT NULL AFTER handover_to_id;
ALTER TABLE leaves ADD COLUMN IF NOT EXISTS decided_by INT UNSIGNED DEFAULT NULL AFTER status;
ALTER TABLE leaves ADD COLUMN IF NOT EXISTS decided_at DATETIME DEFAULT NULL AFTER decided_by;
ALTER TABLE leaves ADD COLUMN IF NOT EXISTS decision_remarks VARCHAR(255) DEFAULT NULL AFTER decided_at;
ALTER TABLE leaves ADD COLUMN IF NOT EXISTS created_by INT UNSIGNED DEFAULT NULL AFTER decision_remarks;
ALTER TABLE leaves MODIFY COLUMN status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending';
-- Backfill day counts and numbers for existing requests.
UPDATE leaves SET total_days = DATEDIFF(end_date, start_date) + 1 WHERE total_days = 0;
UPDATE leaves SET application_no = CONCAT('LV-', LPAD(id, 6, '0')) WHERE application_no IS NULL;

-- Attendance ----------------------------------------------------------
ALTER TABLE attendance ADD COLUMN IF NOT EXISTS shift VARCHAR(40) DEFAULT NULL AFTER status;
ALTER TABLE attendance ADD COLUMN IF NOT EXISTS late_entry TINYINT(1) NOT NULL DEFAULT 0 AFTER check_out;
ALTER TABLE attendance ADD COLUMN IF NOT EXISTS early_exit TINYINT(1) NOT NULL DEFAULT 0 AFTER late_entry;
ALTER TABLE attendance ADD COLUMN IF NOT EXISTS working_hours DECIMAL(5,2) DEFAULT NULL AFTER early_exit;
ALTER TABLE attendance ADD COLUMN IF NOT EXISTS leave_id INT UNSIGNED DEFAULT NULL AFTER working_hours;
ALTER TABLE attendance ADD COLUMN IF NOT EXISTS marked_by INT UNSIGNED DEFAULT NULL AFTER notes;
ALTER TABLE attendance ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER marked_by;

-- Department ----------------------------------------------------------
ALTER TABLE departments ADD COLUMN IF NOT EXISTS code VARCHAR(20) DEFAULT NULL AFTER name;
ALTER TABLE departments ADD COLUMN IF NOT EXISTS parent_id INT UNSIGNED DEFAULT NULL AFTER code;
ALTER TABLE departments ADD COLUMN IF NOT EXISTS head_employee_id INT UNSIGNED DEFAULT NULL AFTER parent_id;
ALTER TABLE departments ADD COLUMN IF NOT EXISTS cost_center VARCHAR(60) DEFAULT NULL AFTER head_employee_id;
ALTER TABLE departments ADD COLUMN IF NOT EXISTS email VARCHAR(150) DEFAULT NULL AFTER cost_center;
ALTER TABLE departments ADD COLUMN IF NOT EXISTS location VARCHAR(120) DEFAULT NULL AFTER email;
ALTER TABLE departments ADD COLUMN IF NOT EXISTS default_leave_approver_id INT UNSIGNED DEFAULT NULL AFTER location;
ALTER TABLE departments ADD COLUMN IF NOT EXISTS status ENUM('active','inactive') NOT NULL DEFAULT 'active' AFTER description;
ALTER TABLE departments ADD COLUMN IF NOT EXISTS created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER status;

-- Foreign keys (named so a re-run can skip them).
SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'leaves' AND CONSTRAINT_NAME = 'fk_leaves_handover');
SET @sql := IF(@fk = 0, 'ALTER TABLE leaves ADD CONSTRAINT fk_leaves_handover FOREIGN KEY (handover_to_id) REFERENCES employees(id) ON DELETE SET NULL', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'leaves' AND CONSTRAINT_NAME = 'fk_leaves_approver');
SET @sql := IF(@fk = 0, 'ALTER TABLE leaves ADD CONSTRAINT fk_leaves_approver FOREIGN KEY (leave_approver_id) REFERENCES users(id) ON DELETE SET NULL', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'leaves' AND CONSTRAINT_NAME = 'fk_leaves_decided_by');
SET @sql := IF(@fk = 0, 'ALTER TABLE leaves ADD CONSTRAINT fk_leaves_decided_by FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND CONSTRAINT_NAME = 'fk_attendance_leave');
SET @sql := IF(@fk = 0, 'ALTER TABLE attendance ADD CONSTRAINT fk_attendance_leave FOREIGN KEY (leave_id) REFERENCES leaves(id) ON DELETE SET NULL', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'departments' AND CONSTRAINT_NAME = 'fk_departments_parent');
SET @sql := IF(@fk = 0, 'ALTER TABLE departments ADD CONSTRAINT fk_departments_parent FOREIGN KEY (parent_id) REFERENCES departments(id) ON DELETE SET NULL', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'departments' AND CONSTRAINT_NAME = 'fk_departments_head');
SET @sql := IF(@fk = 0, 'ALTER TABLE departments ADD CONSTRAINT fk_departments_head FOREIGN KEY (head_employee_id) REFERENCES employees(id) ON DELETE SET NULL', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'departments' AND CONSTRAINT_NAME = 'fk_departments_leave_approver');
SET @sql := IF(@fk = 0, 'ALTER TABLE departments ADD CONSTRAINT fk_departments_leave_approver FOREIGN KEY (default_leave_approver_id) REFERENCES users(id) ON DELETE SET NULL', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

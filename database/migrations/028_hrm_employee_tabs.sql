-- Migration 028: Employee redesign (all six tabs).
--
-- Brings the Employee form up to the tabbed, four-column layout used by the
-- Sales Order and Purchase Order forms: Details, Employment, Contact &
-- Address, Salary & Bank, Qualifications & Experience, and Exit & More
-- Info. Adds the header fields for each tab on `employees`, plus three
-- child tables:
--   employee_salary_components  default earnings / deductions per employee,
--                               prefilled into Generate Salary Slip
--   employee_education          qualifications
--   employee_experience         previous work history
--
-- `employees.salary` keeps meaning "monthly basic salary", so salary slips
-- and the Employees list keep working unchanged. `status` gains a 'left'
-- value for employees who have been relieved.
--
-- Safe to re-run: every CREATE TABLE / ADD COLUMN is idempotent.

SET NAMES utf8mb4;

-- Details tab
ALTER TABLE employees ADD COLUMN IF NOT EXISTS salutation VARCHAR(10) DEFAULT NULL AFTER employee_code;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS gender ENUM('male','female','other') DEFAULT NULL AFTER name;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS date_of_birth DATE DEFAULT NULL AFTER gender;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS marital_status ENUM('single','married','divorced','widowed') DEFAULT NULL AFTER date_of_birth;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS blood_group VARCHAR(5) DEFAULT NULL AFTER marital_status;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS nationality VARCHAR(60) DEFAULT NULL AFTER blood_group;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS father_or_spouse_name VARCHAR(150) DEFAULT NULL AFTER nationality;
ALTER TABLE employees MODIFY COLUMN status ENUM('active','inactive','left') NOT NULL DEFAULT 'active';

-- Employment tab
ALTER TABLE employees ADD COLUMN IF NOT EXISTS reports_to_id INT UNSIGNED DEFAULT NULL AFTER designation;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS employment_type ENUM('full_time','part_time','contract','intern','apprentice') NOT NULL DEFAULT 'full_time' AFTER reports_to_id;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS grade VARCHAR(40) DEFAULT NULL AFTER employment_type;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS work_location VARCHAR(120) DEFAULT NULL AFTER grade;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS work_shift VARCHAR(40) DEFAULT NULL AFTER work_location;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS probation_end_date DATE DEFAULT NULL AFTER hire_date;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS confirmation_date DATE DEFAULT NULL AFTER probation_end_date;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS notice_period_days SMALLINT UNSIGNED NOT NULL DEFAULT 30 AFTER confirmation_date;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS leave_approver_id INT UNSIGNED DEFAULT NULL AFTER notice_period_days;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS user_id INT UNSIGNED DEFAULT NULL AFTER leave_approver_id;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS biometric_id VARCHAR(40) DEFAULT NULL AFTER user_id;

-- Contact & Address tab (existing `email` = work email, `phone` = mobile)
ALTER TABLE employees ADD COLUMN IF NOT EXISTS personal_email VARCHAR(150) DEFAULT NULL AFTER email;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS alternate_phone VARCHAR(40) DEFAULT NULL AFTER phone;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS current_address VARCHAR(255) DEFAULT NULL AFTER alternate_phone;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS permanent_address VARCHAR(255) DEFAULT NULL AFTER current_address;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS city VARCHAR(80) DEFAULT NULL AFTER permanent_address;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS state VARCHAR(80) DEFAULT NULL AFTER city;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS pincode VARCHAR(12) DEFAULT NULL AFTER state;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS country VARCHAR(60) DEFAULT NULL AFTER pincode;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS emergency_contact_name VARCHAR(150) DEFAULT NULL AFTER country;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS emergency_contact_relation VARCHAR(60) DEFAULT NULL AFTER emergency_contact_name;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS emergency_contact_phone VARCHAR(40) DEFAULT NULL AFTER emergency_contact_relation;

-- Salary & Bank tab (existing `salary` = monthly basic)
ALTER TABLE employees ADD COLUMN IF NOT EXISTS salary_mode ENUM('bank_transfer','cash','cheque') NOT NULL DEFAULT 'bank_transfer' AFTER salary;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS bank_name VARCHAR(120) DEFAULT NULL AFTER salary_mode;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS bank_account_holder VARCHAR(150) DEFAULT NULL AFTER bank_name;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS bank_account_no VARCHAR(40) DEFAULT NULL AFTER bank_account_holder;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS bank_ifsc VARCHAR(11) DEFAULT NULL AFTER bank_account_no;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS pan_no VARCHAR(10) DEFAULT NULL AFTER bank_ifsc;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS aadhaar_no VARCHAR(12) DEFAULT NULL AFTER pan_no;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS uan_no VARCHAR(12) DEFAULT NULL AFTER aadhaar_no;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS pf_no VARCHAR(30) DEFAULT NULL AFTER uan_no;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS esi_no VARCHAR(20) DEFAULT NULL AFTER pf_no;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS monthly_gross DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER esi_no;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS monthly_deductions DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER monthly_gross;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS annual_ctc DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER monthly_deductions;

-- Exit & More Info tab
ALTER TABLE employees ADD COLUMN IF NOT EXISTS resignation_date DATE DEFAULT NULL AFTER annual_ctc;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS relieving_date DATE DEFAULT NULL AFTER resignation_date;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS exit_reason VARCHAR(60) DEFAULT NULL AFTER relieving_date;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS exit_notes TEXT DEFAULT NULL AFTER exit_reason;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS remarks TEXT DEFAULT NULL AFTER exit_notes;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS tags VARCHAR(255) DEFAULT NULL AFTER remarks;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

-- Foreign keys for the new link fields (named so a re-run can skip them).
SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND CONSTRAINT_NAME = 'fk_employees_reports_to');
SET @sql := IF(@fk = 0, 'ALTER TABLE employees ADD CONSTRAINT fk_employees_reports_to FOREIGN KEY (reports_to_id) REFERENCES employees(id) ON DELETE SET NULL', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND CONSTRAINT_NAME = 'fk_employees_leave_approver');
SET @sql := IF(@fk = 0, 'ALTER TABLE employees ADD CONSTRAINT fk_employees_leave_approver FOREIGN KEY (leave_approver_id) REFERENCES users(id) ON DELETE SET NULL', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND CONSTRAINT_NAME = 'fk_employees_user');
SET @sql := IF(@fk = 0, 'ALTER TABLE employees ADD CONSTRAINT fk_employees_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Salary structure: default monthly earnings / deductions on top of basic.
CREATE TABLE IF NOT EXISTS employee_salary_components (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id INT UNSIGNED NOT NULL,
  component_type ENUM('earning','deduction') NOT NULL,
  label VARCHAR(100) NOT NULL,
  amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS employee_education (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id INT UNSIGNED NOT NULL,
  qualification VARCHAR(120) NOT NULL,
  institute VARCHAR(150) DEFAULT NULL,
  year_of_passing SMALLINT UNSIGNED DEFAULT NULL,
  grade VARCHAR(20) DEFAULT NULL,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS employee_experience (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id INT UNSIGNED NOT NULL,
  company VARCHAR(150) NOT NULL,
  designation VARCHAR(120) DEFAULT NULL,
  from_date DATE DEFAULT NULL,
  to_date DATE DEFAULT NULL,
  last_salary DECIMAL(14,2) DEFAULT NULL,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Backfill: existing employees get gross = basic, CTC = basic x 12.
UPDATE employees SET monthly_gross = salary, annual_ctc = salary * 12 WHERE monthly_gross = 0 AND salary > 0;

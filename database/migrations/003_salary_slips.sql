-- Migration 003: Salary Slip feature for HRMS.
-- Safe to run on an already-deployed database (idempotent). Run this once
-- via phpMyAdmin against your live database, then re-upload the
-- application files.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS salary_slips (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slip_no VARCHAR(30) NOT NULL UNIQUE,
  employee_id INT UNSIGNED NOT NULL,
  pay_period_start DATE NOT NULL,
  pay_period_end DATE NOT NULL,
  basic_salary DECIMAL(14,2) NOT NULL DEFAULT 0,
  total_earnings DECIMAL(14,2) NOT NULL DEFAULT 0,
  total_deductions DECIMAL(14,2) NOT NULL DEFAULT 0,
  net_pay DECIMAL(14,2) NOT NULL DEFAULT 0,
  status ENUM('draft','paid') NOT NULL DEFAULT 'draft',
  payment_date DATE DEFAULT NULL,
  payment_method ENUM('cash','bank_transfer','cheque','other') DEFAULT NULL,
  expense_id INT UNSIGNED DEFAULT NULL,
  notes VARCHAR(255) DEFAULT NULL,
  created_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_emp_period (employee_id, pay_period_start, pay_period_end),
  FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
  FOREIGN KEY (expense_id) REFERENCES expenses(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS salary_slip_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  salary_slip_id INT UNSIGNED NOT NULL,
  component_type ENUM('earning','deduction') NOT NULL,
  label VARCHAR(100) NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  FOREIGN KEY (salary_slip_id) REFERENCES salary_slips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

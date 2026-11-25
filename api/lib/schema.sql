-- Horizon HRMS schema (MySQL 8). Executed by the installer; safe to re-run.

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(60) NOT NULL UNIQUE,
  display_name VARCHAR(120) NOT NULL,
  role_title VARCHAR(120) NOT NULL DEFAULT 'HR administrator',
  password_hash VARCHAR(255) NOT NULL,
  profile_image MEDIUMBLOB NULL,
  profile_image_type VARCHAR(30) NULL,
  profile_image_updated_at DATETIME NULL,
  -- 'admin' (HR, full access) or 'employee' (own records only, linked to employee_id).
  -- The employee foreign key is added by ensure_account_columns() once employees exists.
  account_role VARCHAR(20) NOT NULL DEFAULT 'admin',
  employee_id INT NULL,
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_employee (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(60) NOT NULL PRIMARY KEY,
  v TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employees (
  id INT AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(80) NOT NULL,
  last_name VARCHAR(80) NOT NULL,
  email VARCHAR(160) NOT NULL UNIQUE,
  phone VARCHAR(40) NOT NULL DEFAULT '',
  title VARCHAR(120) NOT NULL,
  department VARCHAR(60) NOT NULL,
  location VARCHAR(80) NOT NULL DEFAULT '',
  emp_type VARCHAR(20) NOT NULL DEFAULT 'Full-time',
  start_date DATE NOT NULL,
  end_date DATE NULL,
  salary DECIMAL(12,2) NOT NULL DEFAULT 0,
  manager_id INT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'Active',
  biometric_id VARCHAR(30) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_employees_biometric (biometric_id),
  INDEX idx_emp_dept (department),
  CONSTRAINT fk_emp_manager FOREIGN KEY (manager_id) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS leave_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  emp_id INT NOT NULL,
  leave_type VARCHAR(40) NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  days INT NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  reason VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATE NOT NULL,
  decided_at DATETIME NULL,
  INDEX idx_leave_status (status),
  INDEX idx_leave_dates (start_date, end_date),
  CONSTRAINT fk_leave_emp FOREIGN KEY (emp_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employee_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  emp_id INT NOT NULL,
  request_type VARCHAR(24) NOT NULL,
  purpose VARCHAR(160) NOT NULL,
  destination VARCHAR(160) NOT NULL DEFAULT '',
  start_date DATE NOT NULL,
  end_date DATE NULL,
  details VARCHAR(1000) NOT NULL DEFAULT '',
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  decision_role VARCHAR(24) NULL,
  decided_by_user_id INT NULL,
  decided_by_employee_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_at DATETIME NULL,
  INDEX idx_employee_request_status (status),
  INDEX idx_employee_request_emp (emp_id),
  CONSTRAINT fk_employee_request_emp FOREIGN KEY (emp_id) REFERENCES employees(id) ON DELETE CASCADE,
  CONSTRAINT fk_employee_request_user FOREIGN KEY (decided_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_employee_request_reviewer FOREIGN KEY (decided_by_employee_id) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance (
  work_date DATE NOT NULL,
  emp_id INT NOT NULL,
  status VARCHAR(10) NOT NULL,
  time_in TIME NULL,
  time_out TIME NULL,
  PRIMARY KEY (work_date, emp_id),
  CONSTRAINT fk_att_emp FOREIGN KEY (emp_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every raw time punch: imported from the biometric device or recorded by web clock-in.
-- The Daily Time Record (DTR) is built from these.
CREATE TABLE IF NOT EXISTS attendance_punches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  emp_id INT NOT NULL,
  punched_at DATETIME NOT NULL,
  source VARCHAR(20) NOT NULL DEFAULT 'biometric',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_punch (emp_id, punched_at),
  INDEX idx_punch_time (punched_at),
  CONSTRAINT fk_punch_emp FOREIGN KEY (emp_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_date DATE NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  title VARCHAR(160) NOT NULL,
  detail VARCHAR(255) NOT NULL DEFAULT '',
  for_employees TINYINT(1) NOT NULL DEFAULT 0, -- 1 = also shown on employees' Today's schedule
  INDEX idx_event_date (event_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS positions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(160) NOT NULL,
  department VARCHAR(60) NOT NULL,
  emp_type VARCHAR(20) NOT NULL DEFAULT 'Full-time',
  location VARCHAR(80) NOT NULL DEFAULT '',
  status VARCHAR(10) NOT NULL DEFAULT 'open',
  created_at DATE NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS candidates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  position_id INT NOT NULL,
  name VARCHAR(160) NOT NULL,
  email VARCHAR(160) NOT NULL DEFAULT '',
  source VARCHAR(60) NOT NULL DEFAULT '',
  stage VARCHAR(20) NOT NULL DEFAULT 'Applied',
  applied_at DATE NOT NULL,
  employee_id INT NULL,
  CONSTRAINT fk_cand_pos FOREIGN KEY (position_id) REFERENCES positions(id) ON DELETE CASCADE,
  CONSTRAINT fk_cand_emp FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pay_runs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  period CHAR(7) NOT NULL UNIQUE,
  status VARCHAR(10) NOT NULL DEFAULT 'draft',
  paid_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pay_bonuses (
  run_id INT NOT NULL,
  emp_id INT NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  PRIMARY KEY (run_id, emp_id),
  CONSTRAINT fk_bonus_run FOREIGN KEY (run_id) REFERENCES pay_runs(id) ON DELETE CASCADE,
  CONSTRAINT fk_bonus_emp FOREIGN KEY (emp_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Payslips are a snapshot taken when a pay run is approved, so history
-- survives later salary changes or deleted employees.
CREATE TABLE IF NOT EXISTS payslips (
  id INT AUTO_INCREMENT PRIMARY KEY,
  run_id INT NOT NULL,
  emp_id INT NULL,
  emp_name VARCHAR(170) NOT NULL,
  department VARCHAR(60) NOT NULL,
  base DECIMAL(12,2) NOT NULL,
  bonus DECIMAL(12,2) NOT NULL,
  gross DECIMAL(12,2) NOT NULL,
  tax DECIMAL(12,2) NOT NULL,
  benefits DECIMAL(12,2) NOT NULL,
  net DECIMAL(12,2) NOT NULL,
  CONSTRAINT fk_slip_run FOREIGN KEY (run_id) REFERENCES pay_runs(id) ON DELETE CASCADE,
  CONSTRAINT fk_slip_emp FOREIGN KEY (emp_id) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Company updates posted by HR; shown on every employee's Overview.
CREATE TABLE IF NOT EXISTS announcements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(120) NOT NULL,
  body VARCHAR(500) NOT NULL DEFAULT '',
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_announcement_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

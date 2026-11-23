-- Horizon HRMS schema (MySQL 8). Executed by the installer; safe to re-run.

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(60) NOT NULL UNIQUE,
  display_name VARCHAR(120) NOT NULL,
  role_title VARCHAR(120) NOT NULL DEFAULT 'HR administrator',
  password_hash VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
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
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
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

CREATE TABLE IF NOT EXISTS attendance (
  work_date DATE NOT NULL,
  emp_id INT NOT NULL,
  status VARCHAR(10) NOT NULL,
  time_in TIME NULL,
  time_out TIME NULL,
  PRIMARY KEY (work_date, emp_id),
  CONSTRAINT fk_att_emp FOREIGN KEY (emp_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_date DATE NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  title VARCHAR(160) NOT NULL,
  detail VARCHAR(255) NOT NULL DEFAULT '',
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

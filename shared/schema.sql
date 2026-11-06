-- InterviewLens database schema (MySQL / MariaDB). All times are UTC.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(191) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL DEFAULT '',
  phone VARCHAR(30) NOT NULL DEFAULT '',
  country VARCHAR(60) NOT NULL DEFAULT 'India',
  password_hash VARCHAR(255) NULL,
  google_sub VARCHAR(64) NULL UNIQUE,
  avatar_url VARCHAR(500) NOT NULL DEFAULT '',
  signup_method VARCHAR(20) NOT NULL DEFAULT 'password',
  email_verified TINYINT(1) NOT NULL DEFAULT 0,
  email_verified_at DATETIME NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  status_reason VARCHAR(255) NOT NULL DEFAULT '',
  referral_code VARCHAR(16) NOT NULL UNIQUE,
  referred_by INT UNSIGNED NULL,
  tags VARCHAR(255) NOT NULL DEFAULT '',
  admin_notes TEXT NULL,
  totp_secret VARCHAR(255) NULL,
  totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
  failed_logins INT NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  reminder_emails TINYINT(1) NOT NULL DEFAULT 1,
  marketing_emails TINYINT(1) NOT NULL DEFAULT 1,
  login_alerts TINYINT(1) NOT NULL DEFAULT 1,
  theme VARCHAR(10) NOT NULL DEFAULT 'dark',
  signup_ip VARCHAR(64) NOT NULL DEFAULT '',
  last_login_at DATETIME NULL,
  last_login_ip VARCHAR(64) NOT NULL DEFAULT '',
  last_seen_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  KEY ix_users_status (status), KEY ix_users_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admins (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(191) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL DEFAULT '',
  password_hash VARCHAR(255) NOT NULL,
  role VARCHAR(20) NOT NULL DEFAULT 'admin',
  active TINYINT(1) NOT NULL DEFAULT 1,
  totp_secret VARCHAR(255) NULL,
  totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
  failed_logins INT NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  last_login_at DATETIME NULL,
  last_login_ip VARCHAR(64) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sessions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(10) NOT NULL,                 -- user | admin
  token_hash CHAR(64) NOT NULL UNIQUE,
  owner_id INT UNSIGNED NOT NULL,
  csrf CHAR(43) NOT NULL,
  ip VARCHAR(64) NOT NULL DEFAULT '',
  user_agent VARCHAR(400) NOT NULL DEFAULT '',
  twofa_pending TINYINT(1) NOT NULL DEFAULT 0,
  impersonated_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  revoked TINYINT(1) NOT NULL DEFAULT 0,
  KEY ix_sessions_owner (kind, owner_id), KEY ix_sessions_seen (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS one_time_tokens (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  purpose VARCHAR(20) NOT NULL,              -- verify | reset | impersonate
  owner_id INT UNSIGNED NOT NULL,
  admin_id INT UNSIGNED NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_ott_owner (purpose, owner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS plans (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(30) NOT NULL UNIQUE,
  name VARCHAR(80) NOT NULL,
  days INT NOT NULL,
  price_paise INT NOT NULL,
  mrp_paise INT NOT NULL DEFAULT 0,
  description VARCHAR(255) NOT NULL DEFAULT '',
  features TEXT NULL,
  badge VARCHAR(30) NOT NULL DEFAULT '',
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coupons (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(30) NOT NULL UNIQUE,
  kind VARCHAR(10) NOT NULL DEFAULT 'percent',  -- percent | flat (paise)
  value INT NOT NULL DEFAULT 0,
  max_uses INT NOT NULL DEFAULT 0,
  per_user_limit INT NOT NULL DEFAULT 1,
  used_count INT NOT NULL DEFAULT 0,
  plan_codes VARCHAR(200) NOT NULL DEFAULT '',
  valid_from DATETIME NULL,
  valid_to DATETIME NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  note VARCHAR(200) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS devices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id VARCHAR(40) NOT NULL UNIQUE,
  user_id INT UNSIGNED NOT NULL,
  name VARCHAR(80) NOT NULL DEFAULT 'My PC',
  hostname VARCHAR(120) NOT NULL DEFAULT '',
  os VARCHAR(120) NOT NULL DEFAULT '',
  app_version VARCHAR(30) NOT NULL DEFAULT '',
  status VARCHAR(20) NOT NULL DEFAULT 'active',  -- active | blocked | removed
  last_ip VARCHAR(64) NOT NULL DEFAULT '',
  last_seen_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_devices_user (user_id),
  CONSTRAINT fk_devices_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS licenses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  license_key VARCHAR(40) NOT NULL UNIQUE,
  user_id INT UNSIGNED NOT NULL,
  device_pk INT UNSIGNED NULL,
  plan_id INT UNSIGNED NULL,
  days INT NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'issued',  -- issued | active | expired | revoked | suspended
  source VARCHAR(20) NOT NULL DEFAULT 'purchase',
  issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  activated_at DATETIME NULL,
  expires_at DATETIME NULL,
  revoked_reason VARCHAR(255) NOT NULL DEFAULT '',
  last_check_at DATETIME NULL,
  notes VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_lic_user (user_id), KEY ix_lic_status (status), KEY ix_lic_exp (expires_at),
  CONSTRAINT fk_lic_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_lic_device FOREIGN KEY (device_pk) REFERENCES devices(id) ON DELETE SET NULL,
  CONSTRAINT fk_lic_plan FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS license_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  license_id INT UNSIGNED NOT NULL,
  kind VARCHAR(30) NOT NULL,
  detail VARCHAR(400) NOT NULL DEFAULT '',
  actor VARCHAR(60) NOT NULL DEFAULT 'system',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_le_lic (license_id),
  CONSTRAINT fk_le_lic FOREIGN KEY (license_id) REFERENCES licenses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_no VARCHAR(30) NOT NULL UNIQUE,
  invoice_no VARCHAR(30) NULL UNIQUE,
  user_id INT UNSIGNED NOT NULL,
  plan_id INT UNSIGNED NULL,
  plan_name VARCHAR(80) NOT NULL DEFAULT '',
  plan_days INT NOT NULL,
  base_paise INT NOT NULL,
  discount_paise INT NOT NULL DEFAULT 0,
  tax_paise INT NOT NULL DEFAULT 0,
  amount_paise INT NOT NULL,
  coupon_code VARCHAR(30) NOT NULL DEFAULT '',
  method VARCHAR(20) NOT NULL DEFAULT '',        -- razorpay | upi | admin | free
  status VARCHAR(20) NOT NULL DEFAULT 'created',
  gateway_order_id VARCHAR(64) NULL,
  gateway_payment_id VARCHAR(64) NULL,
  utr VARCHAR(40) NOT NULL DEFAULT '',
  proof_file VARCHAR(200) NOT NULL DEFAULT '',
  payer_note VARCHAR(300) NOT NULL DEFAULT '',
  admin_note VARCHAR(300) NOT NULL DEFAULT '',
  reviewed_by INT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  paid_at DATETIME NULL,
  license_id INT UNSIGNED NULL,
  device_pk INT UNSIGNED NULL,
  ip VARCHAR(64) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_orders_user (user_id), KEY ix_orders_status (status), KEY ix_orders_created (created_at),
  KEY ix_orders_gw (gateway_order_id), KEY ix_orders_utr (utr),
  CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activity_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  admin_id INT UNSIGNED NULL,
  session_id INT UNSIGNED NULL,
  device_id VARCHAR(40) NOT NULL DEFAULT '',
  source VARCHAR(15) NOT NULL DEFAULT 'web',      -- web | desktop | admin | system | api
  kind VARCHAR(40) NOT NULL,
  label VARCHAR(255) NOT NULL DEFAULT '',
  path VARCHAR(400) NOT NULL DEFAULT '',
  method VARCHAR(8) NOT NULL DEFAULT '',
  status SMALLINT NOT NULL DEFAULT 0,
  duration_ms INT NOT NULL DEFAULT 0,
  ip VARCHAR(64) NOT NULL DEFAULT '',
  user_agent VARCHAR(400) NOT NULL DEFAULT '',
  referrer VARCHAR(400) NOT NULL DEFAULT '',
  meta TEXT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY ix_ev_user_time (user_id, created_at), KEY ix_ev_time (created_at), KEY ix_ev_kind (kind),
  KEY ix_ev_ip (ip), KEY ix_ev_device (device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(150) NOT NULL,
  body TEXT NULL,
  level VARCHAR(10) NOT NULL DEFAULT 'info',
  link VARCHAR(300) NOT NULL DEFAULT '',
  read_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_notif_user (user_id, read_at),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS announcements (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(150) NOT NULL,
  body TEXT NULL,
  level VARCHAR(10) NOT NULL DEFAULT 'info',
  target VARCHAR(10) NOT NULL DEFAULT 'both',     -- portal | desktop | both
  active TINYINT(1) NOT NULL DEFAULT 1,
  starts_at DATETIME NULL,
  ends_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tickets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  subject VARCHAR(200) NOT NULL,
  category VARCHAR(40) NOT NULL DEFAULT 'General',
  priority VARCHAR(10) NOT NULL DEFAULT 'normal',
  status VARCHAR(15) NOT NULL DEFAULT 'open',      -- open | answered | closed
  assigned_to INT UNSIGNED NULL,
  rating TINYINT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_tickets_status (status),
  CONSTRAINT fk_tickets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_messages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT UNSIGNED NOT NULL,
  author VARCHAR(10) NOT NULL,                     -- user | admin
  admin_id INT UNSIGNED NULL,
  body TEXT NOT NULL,
  internal TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_tm_ticket (ticket_id),
  CONSTRAINT fk_tm_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS canned_replies (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(100) NOT NULL,
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  admin_id INT UNSIGNED NULL,
  action VARCHAR(60) NOT NULL,
  target_type VARCHAR(30) NOT NULL DEFAULT '',
  target_id VARCHAR(60) NOT NULL DEFAULT '',
  detail TEXT NULL,
  ip VARCHAR(64) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_audit_time (created_at), KEY ix_audit_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  `key` VARCHAR(60) PRIMARY KEY,
  value TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  to_email VARCHAR(191) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  template VARCHAR(40) NOT NULL DEFAULT '',
  body_html MEDIUMTEXT NULL,
  status VARCHAR(15) NOT NULL DEFAULT 'queued',   -- queued | sent | failed | logged
  error VARCHAR(500) NOT NULL DEFAULT '',
  attempts INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL,
  KEY ix_email_status (status), KEY ix_email_to (to_email), KEY ix_email_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_templates (
  `key` VARCHAR(40) PRIMARY KEY,
  subject VARCHAR(255) NOT NULL,
  body_html TEXT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ip_blocks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(64) NOT NULL UNIQUE,
  reason VARCHAR(200) NOT NULL DEFAULT '',
  expires_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(10) NOT NULL DEFAULT 'user',
  email VARCHAR(191) NOT NULL DEFAULT '',
  ip VARCHAR(64) NOT NULL DEFAULT '',
  success TINYINT(1) NOT NULL DEFAULT 0,
  reason VARCHAR(100) NOT NULL DEFAULT '',
  user_agent VARCHAR(400) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_la_time (created_at), KEY ix_la_ip (ip), KEY ix_la_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
  bucket VARCHAR(191) PRIMARY KEY,
  hits INT NOT NULL DEFAULT 0,
  window_start INT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reminder_logs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  license_id INT UNSIGNED NOT NULL,
  threshold INT NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_reminder (license_id, threshold, expires_at),
  CONSTRAINT fk_rem_lic FOREIGN KEY (license_id) REFERENCES licenses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_releases (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  version VARCHAR(30) NOT NULL,
  filename VARCHAR(200) NOT NULL,
  size_bytes BIGINT NOT NULL DEFAULT 0,
  sha256 CHAR(64) NOT NULL DEFAULT '',
  notes TEXT NULL,
  is_latest TINYINT(1) NOT NULL DEFAULT 0,
  downloads INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

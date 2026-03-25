-- Simple key-value settings storage (used for global report defaults)
CREATE TABLE IF NOT EXISTS app_settings (
  skey VARCHAR(64) NOT NULL,
  svalue TEXT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (skey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Example keys:
-- report_director_name
-- report_director_date

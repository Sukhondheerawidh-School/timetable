-- Persist report approval preferences per user (cross-device)
CREATE TABLE IF NOT EXISTS user_report_prefs (
  user_id INT UNSIGNED NOT NULL,
  director_name VARCHAR(190) NOT NULL DEFAULT '',
  director_date VARCHAR(32) NOT NULL DEFAULT '',
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_user_report_prefs_user_id
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

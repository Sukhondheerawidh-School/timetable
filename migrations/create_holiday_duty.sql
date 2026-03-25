-- Holiday Duty (เวรวันหยุด) schema
-- Created: 2026-03-18

CREATE TABLE IF NOT EXISTS holiday_duty_dates (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  academic_year_id INT UNSIGNED NOT NULL,
  duty_date DATE NOT NULL,
  note VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_year_date (academic_year_id, duty_date),
  KEY idx_year_date (academic_year_id, duty_date),
  CONSTRAINT fk_hdd_year FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS holiday_duty_posts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  academic_year_id INT UNSIGNED NOT NULL,
  post_name VARCHAR(150) NOT NULL,
  required_count TINYINT UNSIGNED NOT NULL DEFAULT 1,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_year_post (academic_year_id, post_name),
  KEY idx_year_active (academic_year_id, is_active, sort_order),
  CONSTRAINT fk_hdp_year FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS holiday_duty_teams (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  academic_year_id INT UNSIGNED NOT NULL,
  team_name VARCHAR(80) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_year_team (academic_year_id, team_name),
  KEY idx_year_active (academic_year_id, is_active, sort_order),
  CONSTRAINT fk_hdt_year FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS holiday_duty_team_members (
  team_id INT UNSIGNED NOT NULL,
  teacher_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (team_id, teacher_id),
  KEY idx_teacher (teacher_id),
  CONSTRAINT fk_hdtm_team FOREIGN KEY (team_id) REFERENCES holiday_duty_teams(id) ON DELETE CASCADE,
  CONSTRAINT fk_hdtm_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS holiday_duty_assignments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  holiday_duty_date_id INT UNSIGNED NOT NULL,
  holiday_duty_post_id INT UNSIGNED NOT NULL,
  teacher_id INT UNSIGNED NOT NULL,
  team_id INT UNSIGNED NULL,
  created_by_user_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_date_post_teacher (holiday_duty_date_id, holiday_duty_post_id, teacher_id),
  KEY idx_date_post (holiday_duty_date_id, holiday_duty_post_id),
  KEY idx_teacher (teacher_id),
  KEY idx_team (team_id),
  CONSTRAINT fk_hda_date FOREIGN KEY (holiday_duty_date_id) REFERENCES holiday_duty_dates(id) ON DELETE CASCADE,
  CONSTRAINT fk_hda_post FOREIGN KEY (holiday_duty_post_id) REFERENCES holiday_duty_posts(id) ON DELETE CASCADE,
  CONSTRAINT fk_hda_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
  CONSTRAINT fk_hda_team FOREIGN KEY (team_id) REFERENCES holiday_duty_teams(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS holiday_duty_substitutions (
  assignment_id INT UNSIGNED NOT NULL,
  from_teacher_id INT UNSIGNED NOT NULL,
  to_teacher_id INT UNSIGNED NOT NULL,
  reason VARCHAR(255) NULL,
  created_by_user_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (assignment_id),
  KEY idx_from (from_teacher_id),
  KEY idx_to (to_teacher_id),
  CONSTRAINT fk_hds_as FOREIGN KEY (assignment_id) REFERENCES holiday_duty_assignments(id) ON DELETE CASCADE,
  CONSTRAINT fk_hds_from FOREIGN KEY (from_teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
  CONSTRAINT fk_hds_to FOREIGN KEY (to_teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

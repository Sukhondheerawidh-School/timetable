

ALTER TABLE `activity_groups` 
ADD COLUMN `is_all_day` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'If 1, activity spans entire day skipping breaks' AFTER `period_no`;

-- Modify period_no to allow NULL for all-day activities
ALTER TABLE `activity_groups`
MODIFY COLUMN `period_no` TINYINT(3) UNSIGNED NULL COMMENT 'NULL for all-day activities';

-- Drop old unique key
ALTER TABLE `activity_groups` DROP KEY `uniq_activity_time`;

-- Add new unique key that accounts for is_all_day and allows NULL period_no for all-day activities
ALTER TABLE `activity_groups`
ADD UNIQUE KEY `uniq_activity_time` (`academic_year_id`, `term_no`, `day_of_week`, `period_no`, `activity_name`);

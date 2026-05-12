-- Migration: เพิ่ม 'superuser' เข้า ENUM ของ column role ใน users table
-- รัน 1 ครั้งเดียวบน server จริงแล้วลบทิ้ง

ALTER TABLE `users`
  MODIFY COLUMN `role` ENUM('admin','user','superuser') NOT NULL DEFAULT 'user';

-- fix user ที่ role เป็น '' (เกิดจาก insert 'superuser' ก่อน ALTER)
UPDATE `users` SET `role` = 'superuser' WHERE `role` = '' OR `role` IS NULL;

-- Migration: เพิ่มคอลัมน์ ชื่อ/นามสกุลภาษาอังกฤษ และ password_hash ในตาราง teachers
-- ใช้สำหรับสร้าง API เชื่อมต่อระบบอื่นในอนาคต (รหัสผ่านเก็บเป็น bcrypt hash)
-- รัน 1 ครั้งเดียวบน server จริง (แอปมี tt_teachers_init() เพิ่มคอลัมน์ให้อัตโนมัติด้วย)

ALTER TABLE `teachers`
  ADD COLUMN `first_name_en` VARCHAR(100) NULL AFTER `last_name`,
  ADD COLUMN `last_name_en`  VARCHAR(100) NULL AFTER `first_name_en`,
  ADD COLUMN `password_hash` VARCHAR(255) NULL AFTER `last_name_en`;

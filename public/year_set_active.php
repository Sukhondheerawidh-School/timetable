<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';

requireLogin();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf($_POST['csrf'] ?? '')) {
  flash_set('error','คำขอไม่ถูกต้อง');
  redirect('years.php');
}

$id = (int)($_POST['id'] ?? 0);
try {
  // Ensure settings table exists BEFORE any transactional work.
  // (CREATE TABLE is DDL and can implicitly commit, causing "There is no active transaction".)
  tt_app_settings_init($pdo);

  $pdo->exec('UPDATE academic_years SET is_active = 0');
  $stmt = $pdo->prepare('UPDATE academic_years SET is_active = 1 WHERE id = ?');
  $stmt->execute([$id]);

  // Sync to global active year/term defaults
  tt_app_setting_set($pdo, 'active_year_id', (string)$id);
  $existingTerm = (int)(tt_app_setting_get($pdo, 'active_term_no', '0') ?? '0');
  if ($existingTerm <= 0) {
    $terms = tt_terms_list($pdo, $id);
    $firstTerm = !empty($terms) ? (int)$terms[0]['term_no'] : 1;
    tt_app_setting_set($pdo, 'active_term_no', (string)$firstTerm);
  }
  flash_set('success', 'ตั้งปีการศึกษานี้เป็น Active แล้ว');
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  flash_set('error','ตั้ง Active ไม่สำเร็จ: '.$e->getMessage());
}
redirect('years.php');

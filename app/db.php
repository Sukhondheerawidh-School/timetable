<?php
require_once __DIR__ . '/../config/config.php';

function tt_db_create_pdo(): PDO {
  $dsn = sprintf('%s:host=%s;dbname=%s;charset=utf8mb4', DB_DRIVER, DB_HOST, DB_NAME);
  $pdo = new PDO($dsn, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);

  // If helpers are loaded, ensure app_settings exists early.
  if (function_exists('tt_app_settings_init')) {
    tt_app_settings_init($pdo);
  }
  return $pdo;
}

/**
 * Reconnect global PDO (best-effort). Returns true on success.
 */
function tt_db_reconnect(): bool {
  global $pdo;
  try {
    $pdo = tt_db_create_pdo();
    return true;
  } catch (PDOException $e) {
    return false;
  }
}

try {
  $pdo = tt_db_create_pdo();
} catch (PDOException $e) {
  die('DB Connection failed: ' . $e->getMessage());
}

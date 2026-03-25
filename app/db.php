<?php
require_once __DIR__ . '/../config/config.php';

try {
  $dsn = sprintf('%s:host=%s;dbname=%s;charset=utf8mb4', DB_DRIVER, DB_HOST, DB_NAME);
  $pdo = new PDO($dsn, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);

  // If helpers are loaded, ensure app_settings exists early.
  if (function_exists('tt_app_settings_init')) {
    tt_app_settings_init($pdo);
  }
} catch (PDOException $e) {
  die('DB Connection failed: ' . $e->getMessage());
}

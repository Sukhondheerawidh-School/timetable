<?php
require_once __DIR__ . '/../app/db.php';

$rows = $pdo->query('SELECT id, username, name, role FROM users ORDER BY username ASC')->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo $r['id'] . "\t" . $r['username'] . "\t" . $r['name'] . "\t" . $r['role'] . "\n";
}

<?php
require_once __DIR__ . '/../app/db.php';

$id = (int)($argv[1] ?? 0);
if ($id <= 0) {
    fwrite(STDERR, "Usage: php scripts/check_user_by_id.php <id>\n");
    exit(2);
}

$db = $pdo->query('SELECT DATABASE()')->fetchColumn();
echo "DB=", $db, "\n";

$schema = $pdo->query('SHOW CREATE TABLE users')->fetch(PDO::FETCH_ASSOC);
if ($schema && isset($schema['Create Table'])) {
    echo "\n=== SHOW CREATE TABLE users ===\n";
    echo $schema['Create Table'], "\n";
}

$direct = $pdo->query('SELECT id, username, name, role FROM users WHERE id = ' . $id . ' LIMIT 1')->fetch(PDO::FETCH_ASSOC);
echo "\n=== Direct query WHERE id=$id ===\n";
var_export($direct);
echo "\n";

$stmt = $pdo->prepare('SELECT id, username, name, role FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$prep = $stmt->fetch(PDO::FETCH_ASSOC);
echo "\n=== Prepared query WHERE id=? (id=$id) ===\n";
var_export($prep);
echo "\n";

$all = $pdo->query('SELECT id, username FROM users ORDER BY id ASC LIMIT 20')->fetchAll(PDO::FETCH_ASSOC);
echo "\n=== First 20 users by id ===\n";
foreach ($all as $r) {
    echo $r['id'], "\t", $r['username'], "\n";
}

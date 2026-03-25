<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

/**
 * บันทึก Activity Log
 * 
 * @param string $action - ประเภทการกระทำ (create, update, delete, login, logout, etc.)
 * @param string|null $tableName - ชื่อตาราง (ถ้ามี)
 * @param int|null $recordId - ID ของข้อมูล (ถ้ามี)
 * @param array|null $oldValues - ค่าเก่า (สำหรับ update/delete)
 * @param array|null $newValues - ค่าใหม่ (สำหรับ create/update)
 */
function logActivity(
    string $action,
    ?string $tableName = null,
    ?int $recordId = null,
    ?array $oldValues = null,
    ?array $newValues = null
): void {
    global $pdo;
    
    try {
        $user = currentUser();
        $userId = $user ? (int)$user['id'] : null;
        $username = $user ? ($user['username'] ?? 'unknown') : 'guest';
        
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        
        // กรองข้อมูลที่ไม่ต้องการบันทึก (เช่น password)
        if ($oldValues) {
            unset($oldValues['password']);
        }
        if ($newValues) {
            unset($newValues['password']);
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO activity_logs 
            (user_id, username, action, table_name, record_id, old_values, new_values, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $userId,
            $username,
            $action,
            $tableName,
            $recordId,
            $oldValues ? json_encode($oldValues, JSON_UNESCAPED_UNICODE) : null,
            $newValues ? json_encode($newValues, JSON_UNESCAPED_UNICODE) : null,
            $ipAddress,
            $userAgent
        ]);
    } catch (Exception $e) {
        // Silent fail - ไม่ให้ log error ขัดขวางการทำงานหลัก
        error_log("Activity log error: " . $e->getMessage());
    }
}

/**
 * บันทึก log การ login
 */
function logLogin(string $username, bool $success = true): void {
    logActivity(
        $success ? 'login_success' : 'login_failed',
        'users',
        null,
        null,
        ['username' => $username]
    );
}

/**
 * บันทึก log การ logout
 */
function logLogout(): void {
    logActivity('logout', 'users');
}

/**
 * บันทึก log การสร้างข้อมูล
 */
function logCreate(string $tableName, int $recordId, array $data): void {
    logActivity('create', $tableName, $recordId, null, $data);
}

/**
 * บันทึก log การแก้ไขข้อมูล
 */
function logUpdate(string $tableName, int $recordId, array $oldData, array $newData): void {
    logActivity('update', $tableName, $recordId, $oldData, $newData);
}

/**
 * บันทึก log การลบข้อมูล
 */
function logDelete(string $tableName, int $recordId, array $data): void {
    logActivity('delete', $tableName, $recordId, $data, null);
}
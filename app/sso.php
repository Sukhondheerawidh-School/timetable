<?php
/**
 * SchoolOS SSO bridge
 *
 * Portal (schoolos-portal) ออก JWT (HS256) เก็บใน cookie ชื่อ `schoolos_token`
 * ซึ่งไหลมาถึงแอปนี้เองผ่าน nginx gateway (โดเมนเดียวกัน)
 *
 * ถ้า token ตรวจลายเซ็นผ่าน และ username (claim `sub`) มีอยู่ในตาราง users
 * จะสร้าง $_SESSION['user'] ให้อัตโนมัติ — สิทธิ์ (role) ยึดตามตาราง users
 * ของแอปนี้เสมอ ไม่ใช่ role จาก portal
 *
 * - ไม่กระทบการ login แบบเดิม (ใช้ได้ทั้งสองทาง / รันแอปเดี่ยวๆ ก็ยังได้)
 * - ไม่สร้าง user ใหม่อัตโนมัติ — user ที่ไม่มีในระบบนี้จะเจอหน้า login ตามปกติ
 * - เปิดใช้โดยตั้ง env `JWT_SECRET` (ค่าเดียวกับ portal) ในไฟล์ .env
 *   ไม่ตั้ง = ปิด SSO เงียบๆ ทุกอย่างทำงานแบบเดิม
 *
 * spec ของ token: ดู docs/jwt-integration.md ใน repo schoolos-portal
 */

const SSO_COOKIE = 'schoolos_token';
// เพดานอายุ session สูงสุดนับจาก login ที่ portal (ต้องตรงกับฝั่ง portal)
const SSO_ABSOLUTE_TIMEOUT_MS = 8 * 60 * 60 * 1000;
// เผื่อเวลานาฬิกาเพี้ยนระหว่างเครื่อง (วินาที)
const SSO_CLOCK_LEEWAY = 30;

/**
 * ตรวจ JWT แบบ HS256 ด้วยฟังก์ชันมาตรฐานของ PHP (hash_hmac + hash_equals)
 * ไม่ต้องพึ่ง composer — repo นี้ commit vendor/ ตรงๆ การเพิ่ม dependency ใหม่
 * ต้อง regenerate autoloader ทั้งชุด จึงเลือกทางที่ diff เล็กและตรวจสอบง่ายกว่า
 *
 * @return array<string,mixed>|null claims เมื่อผ่านทุกเงื่อนไข, null เมื่อไม่ผ่าน
 */
function sso_jwt_verify(string $token, string $secret): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$h64, $p64, $s64] = $parts;

    $b64url_decode = static function (string $s) {
        $s = strtr($s, '-_', '+/');
        $pad = strlen($s) % 4;
        if ($pad > 0) $s .= str_repeat('=', 4 - $pad);
        return base64_decode($s, true);
    };

    $headerJson  = $b64url_decode($h64);
    $payloadJson = $b64url_decode($p64);
    $signature   = $b64url_decode($s64);
    if ($headerJson === false || $payloadJson === false || $signature === false) return null;

    // บังคับ alg เป็น HS256 เท่านั้น (กัน algorithm-confusion เช่น alg=none)
    $header = json_decode($headerJson, true);
    if (!is_array($header) || ($header['alg'] ?? '') !== 'HS256') return null;

    // ตรวจลายเซ็นก่อนเชื่อ payload ใดๆ — เทียบแบบ constant-time
    $expected = hash_hmac('sha256', $h64 . '.' . $p64, $secret, true);
    if (!hash_equals($expected, $signature)) return null;

    $claims = json_decode($payloadJson, true);
    if (!is_array($claims)) return null;

    $now = time();
    // exp = idle window 15 นาที (portal เซ็นใหม่ให้เรื่อยๆ ระหว่างใช้งาน)
    if (!isset($claims['exp']) || $now > (int)$claims['exp'] + SSO_CLOCK_LEEWAY) return null;
    // เพดาน 8 ชม. นับจาก login ครั้งแรก (claim login_at หน่วย ms)
    if (!isset($claims['login_at'])) return null;
    if ((microtime(true) * 1000) - (float)$claims['login_at'] > SSO_ABSOLUTE_TIMEOUT_MS) return null;

    return $claims;
}

/**
 * พยายาม auto-login จาก token ของ portal — เรียกก่อนเช็ค isLoggedIn()
 * เงียบเสมอ: ไม่ผ่านเงื่อนไขใดก็ตาม = ปล่อยไปตาม flow login ปกติ
 */
function sso_attempt_login(): void
{
    if (!empty($_SESSION['user'])) return;
    // หมายเหตุ: ไม่มี flag บล็อก SSO — การกัน auto-login วนหลัง logout
    // ทำที่ logout.php ด้วยการลบ schoolos_token ทิ้งแทน (token ใหม่ = ตั้งใจ login ใหม่)

    $secret = (string) (getenv('JWT_SECRET') ?: '');
    if ($secret === '') return; // ไม่ได้เปิดใช้ SSO

    $token = (string) ($_COOKIE[SSO_COOKIE] ?? '');
    if ($token === '') return;

    $claims = sso_jwt_verify($token, $secret);
    if ($claims === null || empty($claims['sub'])) return;

    // จับคู่กับผู้ใช้ของแอปนี้ด้วย username — role/สิทธิ์ใช้ของแอปนี้
    global $pdo;
    if (!isset($pdo)) return;
    try {
        $st = $pdo->prepare('SELECT id, username, role FROM users WHERE username = ? LIMIT 1');
        $st->execute([(string) $claims['sub']]);
        $user = $st->fetch();
    } catch (Throwable $e) {
        return; // DB มีปัญหา → ไปหน้า login ปกติ
    }
    if (!$user) return; // ไม่มี user นี้ในระบบตารางสอน

    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id'       => $user['id'],
        'username' => $user['username'],
        'role'     => $user['role'],
    ];
    $_SESSION['LAST_ACTIVITY'] = time();
    // จำไว้ว่า session นี้มาจาก SSO — ตอน logout จะได้ลบ token ของ portal ด้วย
    $_SESSION['sso_login'] = 1;

    if (function_exists('logLogin')) {
        try {
            logLogin((string) $user['username'] . ' (SchoolOS SSO)', true);
        } catch (Throwable $e) {
            // ไม่ให้ log พังการ login
        }
    }
}

<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';

requireAdmin();

$pageTitle = 'Activity Logs';

// ตัวกรอง
$filter_user = $_GET['filter_user'] ?? '';
$filter_action = $_GET['filter_action'] ?? '';
$filter_table = $_GET['filter_table'] ?? '';
$filter_date_from = $_GET['filter_date_from'] ?? '';
$filter_date_to = $_GET['filter_date_to'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 50;

// ✅ แก้ไข: สร้าง WHERE clause แยกต่างหาก
$whereClauses = [];
$params = [];

if ($filter_user !== '') {
    $whereClauses[] = "username LIKE ?";
    $params[] = '%' . $filter_user . '%';
}

if ($filter_action !== '') {
    $whereClauses[] = "action = ?";
    $params[] = $filter_action;
}

if ($filter_table !== '') {
    $whereClauses[] = "table_name = ?";
    $params[] = $filter_table;
}

if ($filter_date_from !== '') {
    $whereClauses[] = "DATE(created_at) >= ?";
    $params[] = $filter_date_from;
}

if ($filter_date_to !== '') {
    $whereClauses[] = "DATE(created_at) <= ?";
    $params[] = $filter_date_to;
}

// ✅ สร้าง WHERE string
$whereSQL = '';
if (!empty($whereClauses)) {
    $whereSQL = ' WHERE ' . implode(' AND ', $whereClauses);
}

// ✅ นับทั้งหมด
$countSql = "SELECT COUNT(*) FROM activity_logs" . $whereSQL;
$stmt = $pdo->prepare($countSql);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

// ✅ ดึงข้อมูล
$sql = "SELECT * FROM activity_logs" . $whereSQL . " ORDER BY created_at DESC LIMIT " . $per_page . " OFFSET " . (($page - 1) * $per_page);
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

$total_pages = ceil($total / $per_page);

// ดึงรายการ action และ table ที่มี
$actions = $pdo->query("SELECT DISTINCT action FROM activity_logs WHERE action IS NOT NULL ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
$tables = $pdo->query("SELECT DISTINCT table_name FROM activity_logs WHERE table_name IS NOT NULL ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);

include __DIR__ . '/../partials/head.php';
include __DIR__ . '/../partials/navbar.php';
?>

<div class="max-w-7xl mx-auto px-4 mt-8">
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-xl font-semibold">📋 Activity Logs</h1>
        <div class="text-sm text-slate-500">
            ทั้งหมด <?= number_format($total) ?> รายการ
        </div>
    </div>

    <!-- ฟอร์มกรอง -->
    <form method="get" class="bg-white rounded-2xl shadow p-4 mb-6">
        <div class="grid grid-cols-1 md:grid-cols-5 gap-3">
            <div>
                <label class="block text-xs mb-1">ผู้ใช้</label>
                <input type="text" name="filter_user" value="<?= htmlspecialchars($filter_user) ?>" 
                       class="w-full border rounded px-3 py-2 text-sm" placeholder="ชื่อผู้ใช้...">
            </div>
            
            <div>
                <label class="block text-xs mb-1">การกระทำ</label>
                <select name="filter_action" class="w-full border rounded px-3 py-2 text-sm">
                    <option value="">ทั้งหมด</option>
                    <?php foreach ($actions as $action): ?>
                        <option value="<?= htmlspecialchars($action) ?>" <?= $action === $filter_action ? 'selected' : '' ?>>
                            <?= htmlspecialchars($action) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label class="block text-xs mb-1">ตาราง</label>
                <select name="filter_table" class="w-full border rounded px-3 py-2 text-sm">
                    <option value="">ทั้งหมด</option>
                    <?php foreach ($tables as $table): ?>
                        <option value="<?= htmlspecialchars($table) ?>" <?= $table === $filter_table ? 'selected' : '' ?>>
                            <?= htmlspecialchars($table) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label class="block text-xs mb-1">วันที่เริ่มต้น</label>
                <input type="date" name="filter_date_from" value="<?= htmlspecialchars($filter_date_from) ?>" 
                       class="w-full border rounded px-3 py-2 text-sm">
            </div>
            
            <div>
                <label class="block text-xs mb-1">วันที่สิ้นสุด</label>
                <input type="date" name="filter_date_to" value="<?= htmlspecialchars($filter_date_to) ?>" 
                       class="w-full border rounded px-3 py-2 text-sm">
            </div>
        </div>
        
        <div class="flex gap-2 mt-3">
            <button type="submit" class="px-4 py-2 rounded-xl bg-slate-900 text-white text-sm hover:opacity-90">
                🔍 ค้นหา
            </button>
            <a href="?" class="px-4 py-2 rounded-xl border text-sm hover:bg-slate-50">
                ✕ ล้างตัวกรอง
            </a>
        </div>
    </form>

    <!-- ตาราง Logs -->
    <div class="bg-white rounded-2xl shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="text-left px-4 py-3 font-semibold">เวลา</th>
                        <th class="text-left px-4 py-3 font-semibold">ผู้ใช้</th>
                        <th class="text-left px-4 py-3 font-semibold">การกระทำ</th>
                        <th class="text-left px-4 py-3 font-semibold">ตาราง</th>
                        <th class="text-left px-4 py-3 font-semibold">Record ID</th>
                        <th class="text-left px-4 py-3 font-semibold">IP</th>
                        <th class="text-center px-4 py-3 font-semibold">รายละเอียด</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$logs): ?>
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-slate-500">
                                ไม่พบข้อมูล
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <tr class="border-t hover:bg-slate-50">
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <?= date('d/m/Y H:i:s', strtotime($log['created_at'])) ?>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="font-medium"><?= htmlspecialchars($log['username']) ?></span>
                                </td>
                                <td class="px-4 py-3">
                                    <?php
                                    $actionColors = [
                                        'login_success' => 'bg-green-100 text-green-700',
                                        'login_failed' => 'bg-red-100 text-red-700',
                                        'logout' => 'bg-slate-100 text-slate-700',
                                        'create' => 'bg-blue-100 text-blue-700',
                                        'create_loads' => 'bg-blue-100 text-blue-700',
                                        'update' => 'bg-yellow-100 text-yellow-700',
                                        'update_loads' => 'bg-yellow-100 text-yellow-700',
                                        'delete' => 'bg-rose-100 text-rose-700',
                                        'delete_all_loads' => 'bg-rose-100 text-rose-700',
                                        'copy_loads' => 'bg-purple-100 text-purple-700',
                                        'add_timetable_slot' => 'bg-blue-100 text-blue-700',
                                    ];
                                    $colorClass = $actionColors[$log['action']] ?? 'bg-slate-100 text-slate-700';
                                    ?>
                                    <span class="px-2 py-1 rounded-full text-xs font-medium <?= $colorClass ?>">
                                        <?= htmlspecialchars($log['action']) ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <?= $log['table_name'] ? htmlspecialchars($log['table_name']) : '-' ?>
                                </td>
                                <td class="px-4 py-3">
                                    <?= $log['record_id'] ? '#' . $log['record_id'] : '-' ?>
                                </td>
                                <td class="px-4 py-3 text-xs text-slate-500">
                                    <?= htmlspecialchars($log['ip_address'] ?? '-') ?>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <button onclick='showLogDetail(<?= json_encode($log, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' 
                                            class="text-blue-600 hover:underline text-xs">
                                        ดูรายละเอียด
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="border-t px-4 py-3 flex items-center justify-between">
                <div class="text-sm text-slate-500">
                    หน้า <?= $page ?> จาก <?= $total_pages ?>
                </div>
                <div class="flex gap-2">
                    <?php if ($page > 1): ?>
                        <?php
                        $prevParams = $_GET;
                        $prevParams['page'] = $page - 1;
                        ?>
                        <a href="?<?= http_build_query($prevParams) ?>" 
                           class="px-3 py-1 rounded border hover:bg-slate-50 text-sm">
                            ← ก่อนหน้า
                        </a>
                    <?php endif; ?>
                    
                    <?php if ($page < $total_pages): ?>
                        <?php
                        $nextParams = $_GET;
                        $nextParams['page'] = $page + 1;
                        ?>
                        <a href="?<?= http_build_query($nextParams) ?>" 
                           class="px-3 py-1 rounded border hover:bg-slate-50 text-sm">
                            ถัดไป →
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal รายละเอียด -->
<div id="logDetailModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl max-w-4xl w-full max-h-[90vh] overflow-y-auto">
        <div class="sticky top-0 bg-white border-b px-6 py-4 flex items-center justify-between">
            <h3 class="text-lg font-semibold">รายละเอียด Log</h3>
            <button onclick="closeLogDetail()" class="text-slate-500 hover:text-slate-700">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <div id="logDetailContent" class="p-6"></div>
    </div>
</div>

<script>
function showLogDetail(log) {
    const modal = document.getElementById('logDetailModal');
    const content = document.getElementById('logDetailContent');
    
    let html = '<div class="space-y-4">';
    
    html += `<div><span class="font-semibold">เวลา:</span> ${log.created_at}</div>`;
    html += `<div><span class="font-semibold">ผู้ใช้:</span> ${log.username} (ID: ${log.user_id || 'N/A'})</div>`;
    html += `<div><span class="font-semibold">การกระทำ:</span> ${log.action}</div>`;
    html += `<div><span class="font-semibold">ตาราง:</span> ${log.table_name || '-'}</div>`;
    html += `<div><span class="font-semibold">Record ID:</span> ${log.record_id || '-'}</div>`;
    html += `<div><span class="font-semibold">IP Address:</span> ${log.ip_address || '-'}</div>`;
    html += `<div><span class="font-semibold">User Agent:</span> <div class="text-xs text-slate-600 mt-1">${log.user_agent || '-'}</div></div>`;
    
    if (log.old_values) {
        try {
            html += '<div class="border-t pt-4 mt-4">';
            html += '<div class="font-semibold mb-2">ค่าเก่า:</div>';
            html += `<pre class="bg-slate-50 p-3 rounded text-xs overflow-x-auto">${JSON.stringify(JSON.parse(log.old_values), null, 2)}</pre>`;
            html += '</div>';
        } catch (e) {
            html += '<div class="border-t pt-4 mt-4">';
            html += '<div class="font-semibold mb-2 text-red-600">ค่าเก่า (Error parsing JSON):</div>';
            html += `<pre class="bg-slate-50 p-3 rounded text-xs overflow-x-auto">${log.old_values}</pre>`;
            html += '</div>';
        }
    }
    
    if (log.new_values) {
        try {
            html += '<div class="border-t pt-4 mt-4">';
            html += '<div class="font-semibold mb-2">ค่าใหม่:</div>';
            html += `<pre class="bg-slate-50 p-3 rounded text-xs overflow-x-auto">${JSON.stringify(JSON.parse(log.new_values), null, 2)}</pre>`;
            html += '</div>';
        } catch (e) {
            html += '<div class="border-t pt-4 mt-4">';
            html += '<div class="font-semibold mb-2 text-red-600">ค่าใหม่ (Error parsing JSON):</div>';
            html += `<pre class="bg-slate-50 p-3 rounded text-xs overflow-x-auto">${log.new_values}</pre>`;
            html += '</div>';
        }
    }
    
    html += '</div>';
    
    content.innerHTML = html;
    modal.classList.remove('hidden');
}

function closeLogDetail() {
    document.getElementById('logDetailModal').classList.add('hidden');
}

// ปิด modal เมื่อคลิกนอก content
document.getElementById('logDetailModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeLogDetail();
    }
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
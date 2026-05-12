<?php
// ลบทิ้งหลังตรวจแล้ว
if (!function_exists('opcache_get_status')) {
    die('OPcache extension ไม่ได้โหลด');
}
$s = opcache_get_status(false);
echo '<pre style="font:13px monospace;padding:16px">';
echo "OPcache enabled:        " . ($s['opcache_enabled'] ? '✅ YES' : '❌ NO') . "\n";
echo "Memory used:            " . round($s['memory_usage']['used_memory']/1024/1024, 2) . " MB\n";
echo "Memory free:            " . round($s['memory_usage']['free_memory']/1024/1024, 2) . " MB\n";
echo "Cached scripts:         " . $s['opcache_statistics']['num_cached_scripts'] . "\n";
echo "Cache hits:             " . $s['opcache_statistics']['hits'] . "\n";
echo "Cache misses:           " . $s['opcache_statistics']['misses'] . "\n";
echo '</pre>';

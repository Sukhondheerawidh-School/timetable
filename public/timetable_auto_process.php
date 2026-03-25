<?php
require_once __DIR__.'/../app/auth.php';
require_once __DIR__.'/../app/helpers.php';
require_once __DIR__.'/../app/db.php';
requireLogin(); requireAdmin();

$year_id = (int)($_GET['year_id'] ?? 0);
$term_no = (int)($_GET['term_no'] ?? 1);

if (!$year_id) die('ไม่ระบุปีการศึกษา');
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>กำลังจัดตารางอัตโนมัติ...</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    body { font-family: 'Sarabun', sans-serif; }
    @keyframes spin { to { transform: rotate(360deg); } }
    .spinner { animation: spin 1s linear infinite; }
  </style>
</head>
<body class="bg-slate-50">
  <div class="max-w-4xl mx-auto px-4 py-8">
    <div class="bg-white rounded-2xl shadow-lg p-8">
      <h1 class="text-2xl font-bold mb-6 text-center">🚀 จัดตารางสอนอัตโนมัติ</h1>
      
      <!-- Status -->
      <div id="status" class="mb-6">
        <div class="flex items-center justify-center gap-3 p-4 bg-indigo-50 rounded-lg">
          <svg class="w-8 h-8 spinner text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
          </svg>
          <span class="text-lg font-medium text-indigo-900">กำลังเริ่มต้นระบบจัดตาราง...</span>
        </div>
      </div>

      <!-- Console Log -->
      <div class="mb-6">
        <div class="flex items-center justify-between mb-2">
          <h2 class="font-semibold text-slate-700">📋 รายละเอียดการทำงาน</h2>
          <button onclick="toggleConsole()" class="text-sm text-indigo-600 hover:underline">แสดง/ซ่อน</button>
        </div>
        <div id="console" class="bg-slate-900 text-green-400 p-4 rounded-lg font-mono text-xs h-96 overflow-y-auto">
          <div class="text-yellow-400">[เริ่มต้น] เชื่อมต่อระบบ...</div>
        </div>
      </div>

      <!-- Summary -->
      <div id="summary" class="hidden p-4 rounded-lg mb-4"></div>

      <!-- Actions -->
      <div id="actions" class="hidden flex gap-3 justify-center">
        <button onclick="location.href='timetable_auto_dashboard.php?year_id=<?= $year_id ?>&term_no=<?= $term_no ?>'" 
                class="px-6 py-2 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700">
          กลับไปหน้าแดชบอร์ด
        </button>
        <button onclick="location.href='timetable_auto_missing.php?year_id=<?= $year_id ?>&term_no=<?= $term_no ?>'"
                class="px-6 py-2 rounded-lg border border-slate-300 bg-white hover:bg-slate-50">
          ดูรายงานวิชาที่ยังลงไม่ได้
        </button>
      </div>
    </div>
  </div>

  <script>
  const consoleEl = document.getElementById('console');
  const statusEl = document.getElementById('status');
  const summaryEl = document.getElementById('summary');
  const actionsEl = document.getElementById('actions');

  function log(msg, color = 'text-green-400') {
    const time = new Date().toLocaleTimeString('th-TH');
    const line = document.createElement('div');
    line.className = color;
    line.textContent = `[${time}] ${msg}`;
    consoleEl.appendChild(line);
    consoleEl.scrollTop = consoleEl.scrollHeight;
  }

  function toggleConsole() {
    consoleEl.style.display = consoleEl.style.display === 'none' ? 'block' : 'none';
  }

  async function runAutoSchedule() {
    const startTime = Date.now();
    
    try {
      log('เริ่มการประมวลผล...', 'text-yellow-400');
      log('ปีการศึกษา: <?= $year_id ?> | เทอม: <?= $term_no ?>', 'text-cyan-400');
      
      const response = await fetch('timetable_auto_run.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ 
          year_id: <?= $year_id ?>, 
          term_no: <?= $term_no ?> 
        })
      });

      const data = await response.json();
      const elapsed = ((Date.now() - startTime) / 1000).toFixed(2);

      if (data.error) {
        log('❌ เกิดข้อผิดพลาด: ' + data.error, 'text-red-400');
        statusEl.innerHTML = `
          <div class="p-4 bg-rose-50 rounded-lg text-rose-700">
            <div class="font-semibold mb-2">❌ เกิดข้อผิดพลาด</div>
            <div>${data.error}</div>
          </div>
        `;
        actionsEl.classList.remove('hidden');
        return;
      }

      // แสดง logs
      if (data.logs && data.logs.length > 0) {
        log('─── เริ่มการจัดคาบ ───', 'text-cyan-400');
        data.logs.forEach(logLine => {
          const color = logLine.includes('✓') ? 'text-green-400' : 
                       logLine.includes('✗') ? 'text-red-400' : 
                       'text-yellow-400';
          log(logLine, color);
        });
      }

      log('─── สรุปผลการทำงาน ───', 'text-cyan-400');
      log(`✓ จัดสำเร็จ: ${data.placed} คาบ`, 'text-green-400');
      log(`⏱️ ใช้เวลา: ${elapsed} วินาที`, 'text-blue-400');
      
      if (data.fails && data.fails.length > 0) {
        log(`⚠️ ล้มเหลว: ${data.fails.length} รายการ`, 'text-red-400');
        data.fails.forEach(fail => log(`  - ${fail}`, 'text-red-300'));
      }

      // แสดง summary
      statusEl.innerHTML = '';
      summaryEl.className = 'p-4 rounded-lg bg-emerald-50 text-emerald-700';
      summaryEl.innerHTML = `
        <div class="text-xl font-bold mb-3">✅ จัดตารางเสร็จสิ้น!</div>
        <div class="grid grid-cols-2 gap-4 text-sm">
          <div>
            <div class="text-emerald-600">จัดสำเร็จ</div>
            <div class="text-2xl font-bold">${data.placed} คาบ</div>
          </div>
          <div>
            <div class="text-emerald-600">ใช้เวลา</div>
            <div class="text-2xl font-bold">${elapsed} วินาที</div>
          </div>
          ${data.fails && data.fails.length > 0 ? `
          <div class="col-span-2">
            <div class="text-rose-600">ล้มเหลว ${data.fails.length} รายการ</div>
            <details class="mt-2">
              <summary class="cursor-pointer text-sm">ดูรายละเอียด</summary>
              <ul class="list-disc pl-5 mt-2 text-xs">
                ${data.fails.map(f => `<li>${f}</li>`).join('')}
              </ul>
            </details>
          </div>
          ` : ''}
        </div>
      `;
      summaryEl.classList.remove('hidden');
      actionsEl.classList.remove('hidden');

    } catch (error) {
      log('❌ เกิดข้อผิดพลาดร้ายแรง: ' + error.message, 'text-red-400');
      statusEl.innerHTML = `
        <div class="p-4 bg-rose-50 rounded-lg text-rose-700">
          <div class="font-semibold mb-2">❌ เกิดข้อผิดพลาดในการเชื่อมต่อ</div>
          <div>${error.message}</div>
        </div>
      `;
      actionsEl.classList.remove('hidden');
    }
  }

  // เริ่มทำงานทันทีที่โหลดหน้า
  window.onload = () => {
    setTimeout(runAutoSchedule, 500);
  };
  </script>
</body>
</html>
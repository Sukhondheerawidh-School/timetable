<?php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
requireLogin();
requireAdmin();

// ดึงข้อมูลกลุ่มสาระทั้งหมด เรียงตาม display_order
$stmt = $pdo->query('SELECT id, name, display_order, is_active, created_at 
                     FROM subject_groups 
                     ORDER BY display_order ASC, name ASC');
$groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<?php include __DIR__ . '/../partials/head.php'; ?>
<?php include __DIR__ . '/../partials/navbar.php'; ?>

<div class="max-w-5xl mx-auto px-4">
  <div class="flex items-center justify-between mt-8 mb-6">
    <div>
      <h1 class="text-2xl font-bold text-slate-800">📚 กลุ่มสาระการเรียนรู้</h1>
      <p class="text-sm text-slate-500 mt-1">จัดการกลุ่มสาระที่ใช้ในระบบ</p>
    </div>
    <a href="<?= url('subject_group_create.php'); ?>" 
       class="px-5 py-2.5 rounded-xl bg-gradient-to-r from-indigo-600 to-indigo-500 text-white hover:from-indigo-700 hover:to-indigo-600 shadow-lg shadow-indigo-500/30 transition-all duration-200 text-sm font-medium">
      <span class="text-lg">➕</span> เพิ่มกลุ่มสาระ
    </a>
  </div>

  <?php if ($f = flash_get()): ?>
    <div class="mb-6 p-4 rounded-xl <?= $f['type']==='success' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-rose-50 text-rose-700 border border-rose-200'; ?> text-sm font-medium shadow-sm">
      <?= $f['type']==='success' ? '✅' : '❌'; ?> <?= htmlspecialchars($f['msg']); ?>
    </div>
  <?php endif; ?>

  <div class="bg-white rounded-2xl shadow-xl border border-slate-200 overflow-hidden">
    <?php if (empty($groups)): ?>
      <div class="p-12 text-center">
        <div class="text-6xl mb-4">📚</div>
        <p class="text-slate-500 text-lg">ยังไม่มีกลุ่มสาระ</p>
      </div>
    <?php else: ?>
      <table class="w-full">
        <thead class="bg-gradient-to-r from-slate-50 to-slate-100 border-b-2 border-slate-200">
          <tr>
            <th class="text-left px-4 py-3 text-sm font-medium text-slate-600">ลำดับ</th>
            <th class="text-left px-4 py-3 text-sm font-medium text-slate-600">ชื่อกลุ่มสาระ</th>
            <th class="text-center px-4 py-3 text-sm font-medium text-slate-600">สถานะ</th>
            <th class="text-center px-4 py-3 text-sm font-medium text-slate-600">จำนวนครู</th>
            <th class="text-right px-4 py-3 text-sm font-medium text-slate-600">จัดการ</th>
          </tr>
        </thead>
        <tbody class="divide-y">
          <?php 
          // นับจำนวนครูในแต่ละกลุ่มสาระ
          $teacher_counts = [];
          $count_stmt = $pdo->query('SELECT subject_group, COUNT(*) as cnt FROM teachers WHERE subject_group IS NOT NULL GROUP BY subject_group');
          while ($row = $count_stmt->fetch(PDO::FETCH_ASSOC)) {
            $teacher_counts[(int)$row['subject_group']] = (int)$row['cnt'];
          }
          
          foreach ($groups as $g): 
            $teacher_count = $teacher_counts[(int)$g['id']] ?? 0;
          ?>
            <tr class="hover:bg-indigo-50/50 transition-colors duration-150 border-b border-slate-100 last:border-0">
              <td class="px-4 py-4 text-sm">
                <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-indigo-100 text-indigo-700 font-semibold text-xs">
                  <?= (int)$g['display_order']; ?>
                </span>
              </td>
              <td class="px-4 py-4">
                <span class="font-semibold text-slate-800"><?= htmlspecialchars($g['name']); ?></span>
              </td>
              <td class="px-4 py-4 text-center">
                <?php if ($g['is_active']): ?>
                  <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-emerald-100 text-emerald-700 border border-emerald-200">
                    ✅ ใช้งาน
                  </span>
                <?php else: ?>
                  <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-slate-100 text-slate-600 border border-slate-200">
                    ⏸️ ปิดใช้งาน
                  </span>
                <?php endif; ?>
              </td>
              <td class="px-4 py-4 text-center text-sm">
                <?php if ($teacher_count > 0): ?>
                  <a href="<?= url('teachers.php?group=' . (int)$g['id']); ?>" 
                     class="inline-flex items-center gap-1 text-indigo-600 hover:text-indigo-700 font-medium hover:underline">
                    👨‍🏫 <?= $teacher_count; ?> คน
                  </a>
                <?php else: ?>
                  <span class="text-slate-400">👨‍🏫 0 คน</span>
                <?php endif; ?>
              </td>
              <td class="px-4 py-4 text-right">
                <div class="flex items-center justify-end gap-2">
                  <a href="<?= url('subject_group_edit.php?id=' . (int)$g['id']); ?>" 
                     class="px-3 py-1.5 rounded-lg text-xs font-medium bg-blue-50 text-blue-700 hover:bg-blue-100 border border-blue-200 transition-colors">
                    ✏️ แก้ไข
                  </a>
                  <a href="<?= url('subject_group_delete.php?id=' . (int)$g['id']); ?>" 
                     class="px-3 py-1.5 rounded-lg text-xs font-medium bg-rose-50 text-rose-700 hover:bg-rose-100 border border-rose-200 transition-colors"
                     onclick="return ttConfirmLink(this,{text: <?= json_encode('ต้องการลบกลุ่มสาระ "'.$g['name'].'" หรือไม่?', JSON_UNESCAPED_UNICODE); ?>});">
                    🗑️ ลบ
                  </a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="mt-4 text-sm text-slate-500">
    <p>💡 <strong>คำแนะนำ:</strong> กลุ่มสาระที่มีครูใช้งานอยู่ไม่สามารถลบได้ ต้องย้ายครูออกก่อน</p>
    <p>💡 ลำดับการแสดงผลจะใช้สำหรับการเรียงลำดับในรายการต่างๆ ทั่วระบบ</p>
  </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>

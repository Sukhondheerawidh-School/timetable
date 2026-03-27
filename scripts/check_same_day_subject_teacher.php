<?php
// CLI script: detect same-day duplicate subject+teacher for the same class.
// Usage: php scripts/check_same_day_subject_teacher.php <year_id> <term_no>

require_once __DIR__ . '/../app/db.php';

$yearId = isset($argv[1]) ? (int)$argv[1] : 0;
$termNo = isset($argv[2]) ? (int)$argv[2] : 0;

if ($yearId <= 0 || !in_array($termNo, [1, 2], true)) {
  fwrite(STDERR, "Usage: php scripts/check_same_day_subject_teacher.php <year_id> <term_no>\n");
  fwrite(STDERR, "Example: php scripts/check_same_day_subject_teacher.php 3 1\n");
  exit(2);
}

$sql = "
  SELECT
    ts.day_of_week,
    ts.class_id,
    c.class_name,
    ts.subject_name,
    COALESCE(st.teacher_id, ts.teacher_id) AS teacher_id,
    CONCAT(IFNULL(t.first_name,''),' ',IFNULL(t.last_name,'')) AS teacher_name,
    COUNT(*) AS cnt,
    GROUP_CONCAT(ts.period_no ORDER BY ts.period_no SEPARATOR ',') AS periods,
    GROUP_CONCAT(ts.id ORDER BY ts.period_no SEPARATOR ',') AS slot_ids,
    GROUP_CONCAT(ts.source ORDER BY ts.period_no SEPARATOR ',') AS sources
  FROM timetable_slots ts
  LEFT JOIN timetable_slot_teachers st ON st.slot_id = ts.id
  LEFT JOIN classes c ON c.id = ts.class_id
  LEFT JOIN teachers t ON t.id = COALESCE(st.teacher_id, ts.teacher_id)
  WHERE ts.academic_year_id = ? AND ts.term_no = ?
    AND ts.subject_name IS NOT NULL AND ts.subject_name <> ''
    AND COALESCE(st.teacher_id, ts.teacher_id) IS NOT NULL
  GROUP BY ts.day_of_week, ts.class_id, ts.subject_name, COALESCE(st.teacher_id, ts.teacher_id)
  HAVING cnt > 1
  ORDER BY cnt DESC, ts.day_of_week, c.class_name, ts.subject_name, teacher_name
";

$stmt = $pdo->prepare($sql);
$stmt->execute([$yearId, $termNo]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
  echo "OK: No same-day duplicate subject+teacher found.\n";
  exit(0);
}

echo "FOUND: " . count($rows) . " violation group(s)\n\n";
foreach ($rows as $r) {
  echo sprintf(
    "Day %d | Class %s (#%d) | %s | Teacher %s (#%d) | Count %d | Periods [%s] | SlotIds [%s] | Sources [%s]\n",
    (int)$r['day_of_week'],
    (string)($r['class_name'] ?? ''),
    (int)$r['class_id'],
    (string)$r['subject_name'],
    trim((string)($r['teacher_name'] ?? '')),
    (int)$r['teacher_id'],
    (int)$r['cnt'],
    (string)($r['periods'] ?? ''),
    (string)($r['slot_ids'] ?? ''),
    (string)($r['sources'] ?? '')
  );
}

exit(1);

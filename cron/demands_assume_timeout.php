<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$hours = (int)admin_setting_get('demands.assume_timeout_hours', '4');
if ($hours <= 0) {
    $hours = 4;
}

$cut = (new DateTime('now'));
$cut->modify('-' . $hours . ' hours');
$cutAt = $cut->format('Y-m-d H:i:s');

$db = db();

$stmt = $db->prepare(
    "SELECT id, assumed_by_user_id, assumed_at
     FROM demands
     WHERE status IN ('aguardando_captacao','tratamento_manual','em_captacao')
       AND assumed_by_user_id IS NOT NULL
       AND assumed_at IS NOT NULL
       AND assumed_at <= :cut"
);
$stmt->execute(['cut' => $cutAt]);
$rows = $stmt->fetchAll();

if (count($rows) === 0) {
    echo "OK: no demands to release\n";
    exit;
}

$upd = $db->prepare('UPDATE demands SET assumed_by_user_id = NULL, assumed_at = NULL WHERE id = :id');

$released = 0;
foreach ($rows as $r) {
    $id = (int)$r['id'];
    $upd->execute(['id' => $id]);

    // log de status como evento operacional
    $ins = $db->prepare('INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (:id, :old, :new, NULL, :note)');
    $ins->execute([
        'id' => $id,
        'old' => null,
        'new' => 'released',
        'note' => 'Auto-release: timeout de ' . $hours . 'h sem andamento. Retornou ao pool.',
    ]);

    $released++;
}

echo 'OK: released=' . $released . "\n";

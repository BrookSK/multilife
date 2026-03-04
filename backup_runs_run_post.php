<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('backups.manage');

$kind = isset($_POST['kind']) ? (string)$_POST['kind'] : 'db';
if (!in_array($kind, ['db', 'files'], true)) {
    $kind = 'db';
}

$db = db();

// Stub: registra como success imediatamente.
$stmt = $db->prepare("INSERT INTO backup_runs (kind, status, started_by_user_id, finished_at, output_path, meta_json) VALUES (:k, 'success', :uid, NOW(), :out, :meta)");

$day = (new DateTime())->format('Y-m-d');
$out = 'storage/backups/' . $kind . '_' . $day . '_stub.txt';
$meta = ['stub' => true];

$stmt->execute([
    'k' => $kind,
    'uid' => auth_user_id(),
    'out' => $out,
    'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
]);

audit_log('create', 'backup_runs', (string)$db->lastInsertId(), null, ['kind' => $kind, 'stub' => true]);

flash_set('success', 'Execução registrada (stub).');
header('Location: /backup_runs_list.php');
exit;

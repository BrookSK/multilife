<?php

declare(strict_types=1);

function audit_log(string $action, string $module, ?string $recordId, $oldData, $newData): void
{
    $uid = auth_user_id();
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

    $oldJson = $oldData === null ? null : json_encode($oldData, JSON_UNESCAPED_UNICODE);
    $newJson = $newData === null ? null : json_encode($newData, JSON_UNESCAPED_UNICODE);

    $stmt = db()->prepare(
        'INSERT INTO audit_logs (user_id, action, module, record_id, old_data, new_data, ip) VALUES (:uid, :action, :module, :record_id, :old_data, :new_data, :ip)'
    );
    $stmt->execute([
        'uid' => $uid,
        'action' => $action,
        'module' => $module,
        'record_id' => $recordId,
        'old_data' => $oldJson,
        'new_data' => $newJson,
        'ip' => $ip,
    ]);
}

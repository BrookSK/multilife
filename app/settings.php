<?php

declare(strict_types=1);

function admin_setting_get(string $key, ?string $default = null): ?string
{
    $stmt = db()->prepare('SELECT setting_value FROM admin_settings WHERE setting_key = :k LIMIT 1');
    $stmt->execute(['k' => $key]);
    $row = $stmt->fetch();
    if (!$row) {
        return $default;
    }
    $val = $row['setting_value'];
    if ($val === null) {
        return $default;
    }
    return (string)$val;
}

function admin_settings_get_prefix(string $prefix): array
{
    $stmt = db()->prepare('SELECT setting_key, setting_value FROM admin_settings WHERE setting_key LIKE :p ORDER BY setting_key ASC');
    $stmt->execute(['p' => $prefix . '%']);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[(string)$r['setting_key']] = (string)($r['setting_value'] ?? '');
    }
    return $out;
}

function admin_settings_set_many(array $settings, int $updatedByUserId): void
{
    $db = db();
    $stmt = $db->prepare('INSERT INTO admin_settings (setting_key, setting_value, updated_by_user_id) VALUES (:k, :v, :uid) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by_user_id = VALUES(updated_by_user_id)');
    foreach ($settings as $k => $v) {
        $key = trim((string)$k);
        if ($key === '') {
            continue;
        }
        $val = is_string($v) ? trim($v) : trim((string)$v);
        $stmt->execute(['k' => $key, 'v' => $val, 'uid' => $updatedByUserId]);
    }
}

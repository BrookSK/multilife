<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

$specialty = trim((string)($_POST['specialty'] ?? ''));
$minRaw = (string)($_POST['minimum_value'] ?? '0');
$status = (string)($_POST['status'] ?? 'active');

if ($specialty === '') {
    flash_set('error', 'Informe a especialidade.');
    header('Location: /specialty_minimums_create.php');
    exit;
}

if (!is_numeric($minRaw)) {
    $minRaw = '0';
}

if (!in_array($status, ['active','inactive'], true)) {
    $status = 'active';
}

$db = db();
try {
    $stmt = $db->prepare('INSERT INTO specialty_minimums (specialty, minimum_value, status) VALUES (:sp, :mv, :st)');
    $stmt->execute(['sp' => $specialty, 'mv' => $minRaw, 'st' => $status]);
    $id = (string)$db->lastInsertId();
    audit_log('create', 'specialty_minimums', $id, null, ['specialty' => $specialty, 'minimum_value' => $minRaw]);
} catch (Throwable $e) {
    flash_set('error', 'Falha ao salvar (talvez já exista essa especialidade).');
    header('Location: /specialty_minimums_create.php');
    exit;
}

flash_set('success', 'Mínimo criado.');
header('Location: /specialty_minimums_list.php');
exit;

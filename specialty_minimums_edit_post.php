<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

$id = (int)($_POST['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM specialty_minimums WHERE id = :id');
$stmt->execute(['id' => $id]);
$old = $stmt->fetch();

if (!$old) {
    flash_set('error', 'Registro não encontrado.');
    header('Location: /specialty_minimums_list.php');
    exit;
}

$specialty = trim((string)($_POST['specialty'] ?? ''));
$minRaw = (string)($_POST['minimum_value'] ?? '0');
$status = (string)($_POST['status'] ?? 'active');

if ($specialty === '') {
    flash_set('error', 'Informe a especialidade.');
    header('Location: /specialty_minimums_edit.php?id=' . $id);
    exit;
}

if (!is_numeric($minRaw)) {
    $minRaw = '0';
}

if (!in_array($status, ['active','inactive'], true)) {
    $status = 'active';
}

try {
    $stmt = db()->prepare('UPDATE specialty_minimums SET specialty = :sp, minimum_value = :mv, status = :st WHERE id = :id');
    $stmt->execute(['sp' => $specialty, 'mv' => $minRaw, 'st' => $status, 'id' => $id]);
    audit_log('update', 'specialty_minimums', (string)$id, $old, ['specialty' => $specialty, 'minimum_value' => $minRaw, 'status' => $status]);
} catch (Throwable $e) {
    flash_set('error', 'Falha ao salvar (talvez já exista essa especialidade).');
    header('Location: /specialty_minimums_edit.php?id=' . $id);
    exit;
}

flash_set('success', 'Mínimo atualizado.');
header('Location: /specialty_minimums_list.php');
exit;

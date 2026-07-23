<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

$id = (int)($_POST['id'] ?? 0);
$note = trim((string)($_POST['captation_note'] ?? ''));

if ($id <= 0) {
    flash_set('error', 'ID inválido.');
    header('Location: /demands_list.php');
    exit;
}

// Verificar se a demanda existe
$stmt = db()->prepare('SELECT id FROM demands WHERE id = :id');
$stmt->execute(['id' => $id]);
if (!$stmt->fetch()) {
    flash_set('error', 'Demanda não encontrada.');
    header('Location: /demands_list.php');
    exit;
}

// Salvar observação
$upd = db()->prepare('UPDATE demands SET captation_note = :note WHERE id = :id');
$upd->execute(['note' => $note !== '' ? $note : null, 'id' => $id]);

flash_set('success', 'Observação da captação salva.');
header('Location: /demands_view.php?id=' . $id);
exit;

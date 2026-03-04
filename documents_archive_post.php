<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('documents.manage');

$id = (int)($_POST['id'] ?? 0);

$stmt = db()->prepare('SELECT id, status FROM documents WHERE id = :id');
$stmt->execute(['id' => $id]);
$old = $stmt->fetch();
if (!$old) {
    flash_set('error', 'Documento não encontrado.');
    header('Location: /documents_list.php');
    exit;
}

$stmt = db()->prepare("UPDATE documents SET status = 'archived' WHERE id = :id");
$stmt->execute(['id' => $id]);

audit_log('update', 'documents', (string)$id, $old, ['status' => 'archived']);

flash_set('success', 'Documento arquivado.');
header('Location: /documents_list.php');
exit;

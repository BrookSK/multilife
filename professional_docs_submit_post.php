<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('professional_docs.submit');

$uid = (int)auth_user_id();
$id = (int)($_POST['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM professional_documentations WHERE id = :id AND professional_user_id = :uid');
$stmt->execute(['id' => $id, 'uid' => $uid]);
$d = $stmt->fetch();

if (!$d) {
    flash_set('error', 'Formulário não encontrado.');
    header('Location: /professional_docs_list.php');
    exit;
}

if ((string)$d['status'] !== 'draft') {
    flash_set('error', 'Apenas rascunhos podem ser enviados.');
    header('Location: /professional_docs_edit.php?id=' . $id);
    exit;
}

$stmt = db()->prepare('UPDATE professional_documentations SET status = \'submitted\', submitted_at = NOW() WHERE id = :id');
$stmt->execute(['id' => $id]);

audit_log('update', 'professional_documentations_submit', (string)$id, ['status' => (string)$d['status']], ['status' => 'submitted']);

flash_set('success', 'Formulário enviado para revisão.');
header('Location: /professional_docs_list.php?status=submitted');
exit;

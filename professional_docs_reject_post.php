<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('professional_docs.review');

$id = (int)($_POST['id'] ?? 0);
$note = trim((string)($_POST['review_note'] ?? ''));

$stmt = db()->prepare('SELECT id, status FROM professional_documentations WHERE id = :id');
$stmt->execute(['id' => $id]);
$d = $stmt->fetch();

if (!$d) {
    flash_set('error', 'Registro não encontrado.');
    header('Location: /professional_docs_review_list.php');
    exit;
}

if (!in_array((string)$d['status'], ['submitted'], true)) {
    flash_set('error', 'Apenas registros enviados podem ser reprovados.');
    header('Location: /professional_docs_review_view.php?id=' . $id);
    exit;
}

$stmt = db()->prepare('UPDATE professional_documentations SET status = \'rejected\', reviewed_by_user_id = :uid, reviewed_at = NOW(), review_note = :note WHERE id = :id');
$stmt->execute([
    'uid' => auth_user_id(),
    'note' => $note !== '' ? $note : null,
    'id' => $id,
]);

audit_log('update', 'professional_documentations_review', (string)$id, ['status' => (string)$d['status']], ['status' => 'rejected']);

flash_set('success', 'Documentação reprovada.');
header('Location: /professional_docs_review_list.php?status=rejected');
exit;

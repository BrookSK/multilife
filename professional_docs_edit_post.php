<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('professional_docs.submit');

$uid = (int)auth_user_id();
$id = (int)($_POST['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM professional_documentations WHERE id = :id AND professional_user_id = :uid');
$stmt->execute(['id' => $id, 'uid' => $uid]);
$old = $stmt->fetch();

if (!$old) {
    flash_set('error', 'Formulário não encontrado.');
    header('Location: /professional_docs_list.php');
    exit;
}

if ((string)$old['status'] !== 'draft') {
    flash_set('error', 'Apenas rascunhos podem ser editados.');
    header('Location: /professional_docs_edit.php?id=' . $id);
    exit;
}

$patientRef = trim((string)($_POST['patient_ref'] ?? ''));
$sessions = (int)($_POST['sessions_count'] ?? 1);
$billing = trim((string)($_POST['billing_docs'] ?? ''));
$productivity = trim((string)($_POST['productivity_docs'] ?? ''));
$notes = trim((string)($_POST['notes'] ?? ''));

if ($patientRef === '') {
    flash_set('error', 'Informe o paciente.');
    header('Location: /professional_docs_edit.php?id=' . $id);
    exit;
}

if ($sessions < 1) {
    $sessions = 1;
}

$stmt = db()->prepare('UPDATE professional_documentations SET patient_ref = :p, sessions_count = :s, billing_docs = :b, productivity_docs = :pr, notes = :n WHERE id = :id');
$stmt->execute([
    'p' => $patientRef,
    's' => $sessions,
    'b' => $billing !== '' ? $billing : null,
    'pr' => $productivity !== '' ? $productivity : null,
    'n' => $notes !== '' ? $notes : null,
    'id' => $id,
]);

audit_log('update', 'professional_documentations', (string)$id, $old, ['patient_ref' => $patientRef, 'sessions_count' => $sessions]);

flash_set('success', 'Rascunho atualizado.');
header('Location: /professional_docs_edit.php?id=' . $id);
exit;

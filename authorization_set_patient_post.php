<?php
/**
 * Vincula o paciente a uma autorização (aba Autorização).
 * O paciente não é mais escolhido no chat ao selecionar o profissional: ele é
 * cadastrado/selecionado aqui. Só enquanto a autorização aguarda a aprovação
 * (depois de aprovada, o atendimento já foi criado com o paciente).
 */

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

$authId = (int)($_POST['auth_id'] ?? 0);
$patientId = (int)($_POST['patient_id'] ?? 0);
$back = '/authorization_view.php?id=' . $authId;

if ($authId <= 0) {
    flash_set('error', 'Autorização inválida.');
    header('Location: /authorization_list.php');
    exit;
}
if ($patientId <= 0) {
    flash_set('error', 'Selecione um paciente.');
    header('Location: ' . $back);
    exit;
}

$db = db();

$st = $db->prepare('SELECT id, status, patient_id FROM authorization_requests WHERE id = :id');
$st->execute(['id' => $authId]);
$auth = $st->fetch(PDO::FETCH_ASSOC);
if (!$auth) {
    flash_set('error', 'Autorização não encontrada.');
    header('Location: /authorization_list.php');
    exit;
}
if ((string)$auth['status'] !== 'aguardando_autorizacao') {
    flash_set('error', 'O paciente só pode ser alterado enquanto a autorização aguarda aprovação.');
    header('Location: ' . $back);
    exit;
}

$sp = $db->prepare('SELECT id, full_name FROM patients WHERE id = :id AND deleted_at IS NULL');
$sp->execute(['id' => $patientId]);
$patient = $sp->fetch(PDO::FETCH_ASSOC);
if (!$patient) {
    flash_set('error', 'Paciente não encontrado.');
    header('Location: ' . $back);
    exit;
}

$db->prepare("UPDATE authorization_requests SET patient_id = :pid WHERE id = :id AND status = 'aguardando_autorizacao'")
    ->execute(['pid' => $patientId, 'id' => $authId]);

audit_log('update', 'authorization_requests', (string)$authId,
    ['patient_id' => (int)$auth['patient_id']], ['patient_id' => $patientId]);

flash_set('success', 'Paciente vinculado: ' . (string)$patient['full_name'] . '.');
header('Location: ' . $back);
exit;

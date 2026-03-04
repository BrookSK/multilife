<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('professional_applications.manage');

$id = (int)($_POST['id'] ?? 0);

$stmt = db()->prepare('SELECT id, status FROM professional_applications WHERE id = :id');
$stmt->execute(['id' => $id]);
$pa = $stmt->fetch();

if (!$pa) {
    flash_set('error', 'Candidatura não encontrada.');
    header('Location: /professional_applications_list.php');
    exit;
}

$stmt = db()->prepare('UPDATE professional_applications SET status = \'need_more_info\', reviewed_by_user_id = :rid, reviewed_at = NOW() WHERE id = :id');
$stmt->execute(['rid' => auth_user_id(), 'id' => $id]);

audit_log('update', 'professional_applications', (string)$id, ['status' => (string)$pa['status']], ['status' => 'need_more_info']);

flash_set('success', 'Status atualizado para need_more_info. (Envio de notificação será integrado depois)');
header('Location: /professional_applications_view.php?id=' . $id);
exit;

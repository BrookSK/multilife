<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('finance.manage');

$id = (int)($_POST['id'] ?? 0);
$status = (string)($_POST['status'] ?? '');

if (!in_array($status, ['pendente','recebido','inadimplente'], true)) {
    flash_set('error', 'Status inválido.');
    header('Location: /finance_receivable_list.php');
    exit;
}

$stmt = db()->prepare('SELECT id, status FROM finance_accounts_receivable WHERE id = :id');
$stmt->execute(['id' => $id]);
$old = $stmt->fetch();
if (!$old) {
    flash_set('error', 'Registro não encontrado.');
    header('Location: /finance_receivable_list.php');
    exit;
}

$receivedAt = null;
if ($status === 'recebido') {
    $receivedAt = (new DateTime())->format('Y-m-d H:i:s');
}

$stmt = db()->prepare('UPDATE finance_accounts_receivable SET status = :st, received_at = :ra WHERE id = :id');
$stmt->execute(['st' => $status, 'ra' => $receivedAt, 'id' => $id]);

audit_log('update', 'finance_accounts_receivable', (string)$id, $old, ['status' => $status]);

flash_set('success', 'Conta a receber atualizada.');
header('Location: /finance_receivable_list.php');
exit;

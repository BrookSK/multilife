<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('finance.manage');

$id = (int)($_POST['id'] ?? 0);

$stmt = db()->prepare('SELECT id, status FROM finance_accounts_payable WHERE id = :id');
$stmt->execute(['id' => $id]);
$old = $stmt->fetch();
if (!$old) {
    flash_set('error', 'Registro não encontrado.');
    header('Location: /finance_payable_list.php');
    exit;
}

if ((string)$old['status'] === 'pago') {
    flash_set('success', 'Já estava marcado como pago.');
    header('Location: /finance_payable_list.php');
    exit;
}

$stmt = db()->prepare("UPDATE finance_accounts_payable SET status = 'pago', paid_at = NOW() WHERE id = :id");
$stmt->execute(['id' => $id]);

audit_log('update', 'finance_accounts_payable', (string)$id, $old, ['status' => 'pago']);

flash_set('success', 'Conta a pagar marcada como paga.');
header('Location: /finance_payable_list.php');
exit;

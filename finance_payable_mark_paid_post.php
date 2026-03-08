<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('finance.manage');

$id = (int)($_POST['id'] ?? 0);

if ($id === 0) {
    flash_set('error', 'ID inválido.');
    header('Location: /finance_payable_list.php');
    exit;
}

$stmt = db()->prepare('SELECT id, status, entry_type FROM financial_entries WHERE id = :id');
$stmt->execute(['id' => $id]);
$old = $stmt->fetch();

if (!$old) {
    flash_set('error', 'Lançamento não encontrado.');
    header('Location: /finance_payable_list.php');
    exit;
}

if ((string)$old['status'] === 'paid') {
    flash_set('success', 'Já estava marcado como pago.');
    header('Location: /finance_payable_list.php');
    exit;
}

$stmt = db()->prepare("UPDATE financial_entries SET status = 'paid', paid_date = CURDATE() WHERE id = :id");
$stmt->execute(['id' => $id]);

audit_log('update', 'financial_entries', (string)$id, $old, ['status' => 'paid']);

flash_set('success', 'Conta a pagar marcada como paga.');
header('Location: /finance_payable_list.php');
exit;

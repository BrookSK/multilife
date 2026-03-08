<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('finance.manage');

$id = (int)($_POST['id'] ?? 0);
$status = (string)($_POST['status'] ?? '');

if ($id === 0) {
    flash_set('error', 'ID inválido.');
    header('Location: /finance_receivable_list.php');
    exit;
}

// Converter status do formulário para o padrão do banco
$statusMap = [
    'pendente' => 'pending',
    'recebido' => 'paid',
    'inadimplente' => 'cancelled',
    'pending' => 'pending',
    'paid' => 'paid',
    'cancelled' => 'cancelled',
];

$dbStatus = $statusMap[$status] ?? null;

if ($dbStatus === null) {
    flash_set('error', 'Status inválido.');
    header('Location: /finance_receivable_list.php');
    exit;
}

$stmt = db()->prepare('SELECT id, status, entry_type FROM financial_entries WHERE id = :id');
$stmt->execute(['id' => $id]);
$old = $stmt->fetch();

if (!$old) {
    flash_set('error', 'Lançamento não encontrado.');
    header('Location: /finance_receivable_list.php');
    exit;
}

$paidDate = null;
if ($dbStatus === 'paid') {
    $paidDate = (new DateTime())->format('Y-m-d');
}

$stmt = db()->prepare('UPDATE financial_entries SET status = :st, paid_date = :pd WHERE id = :id');
$stmt->execute(['st' => $dbStatus, 'pd' => $paidDate, 'id' => $id]);

audit_log('update', 'financial_entries', (string)$id, $old, ['status' => $dbStatus]);

flash_set('success', 'Conta a receber atualizada.');
header('Location: /finance_receivable_list.php');
exit;

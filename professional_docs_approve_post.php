<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('professional_docs.review');

$id = (int)($_POST['id'] ?? 0);

$stmt = db()->prepare('SELECT id, status, appointment_id, professional_user_id, sessions_count FROM professional_documentations WHERE id = :id');
$stmt->execute(['id' => $id]);
$d = $stmt->fetch();

if (!$d) {
    flash_set('error', 'Registro não encontrado.');
    header('Location: /professional_docs_review_list.php');
    exit;
}

if ((string)$d['status'] !== 'submitted') {
    flash_set('error', 'Apenas registros enviados podem ser aprovados.');
    header('Location: /professional_docs_review_view.php?id=' . $id);
    exit;
}

$db = db();
$db->beginTransaction();
try {
    $stmt = $db->prepare('UPDATE professional_documentations SET status = \'approved\', reviewed_by_user_id = :uid, reviewed_at = NOW(), review_note = NULL WHERE id = :id');
    $stmt->execute(['uid' => auth_user_id(), 'id' => $id]);

    // Financeiro: cria Conta a Pagar (repasse) quando houver agendamento vinculado
    if ($d['appointment_id'] !== null) {
        $stmt = $db->prepare('SELECT id, value_per_session FROM appointments WHERE id = :id');
        $stmt->execute(['id' => (int)$d['appointment_id']]);
        $a = $stmt->fetch();
        if ($a) {
            $sessions = (int)($d['sessions_count'] ?? 1);
            if ($sessions < 1) {
                $sessions = 1;
            }

            $amount = (float)$a['value_per_session'] * $sessions;

            $stmt = $db->prepare(
                "INSERT INTO finance_accounts_payable (appointment_id, professional_user_id, amount, due_at, status)
                 VALUES (:aid, :uid, :amount, DATE_ADD(NOW(), INTERVAL 15 DAY), 'pendente')"
            );
            $stmt->execute([
                'aid' => (int)$a['id'],
                'uid' => (int)$d['professional_user_id'],
                'amount' => number_format($amount, 2, '.', ''),
            ]);
        }
    }

    audit_log('update', 'professional_documentations_review', (string)$id, ['status' => (string)$d['status']], ['status' => 'approved']);
    if ($d['appointment_id'] !== null) {
        audit_log('create', 'finance_accounts_payable', (string)$id, null, ['appointment_id' => (int)$d['appointment_id']]);
    }

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

flash_set('success', 'Documentação aprovada.');
header('Location: /professional_docs_review_list.php?status=approved');
exit;

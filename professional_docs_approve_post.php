<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('professional_docs.review');

$id = (int)($_POST['id'] ?? 0);

$stmt = db()->prepare('SELECT id, status, appointment_id, patient_id, patient_ref, professional_user_id, sessions_count, notes FROM professional_documentations WHERE id = :id');
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

    // Conclui pendência de revisão (se existir)
    $stmt = $db->prepare(
        "UPDATE pending_items\n"
        . "SET status = 'done', resolved_at = NOW()\n"
        . "WHERE status = 'open' AND type = 'professional_docs_review'\n"
        . "  AND related_table = 'professional_documentations' AND related_id = :rid"
    );
    $stmt->execute(['rid' => $id]);

    // Prontuário (se houver patient_id)
    if ($d['patient_id'] !== null) {
        $att = [];
        $stmt = $db->prepare(
            "SELECT pdd.doc_kind, doc.id AS document_id\n"
            . "FROM professional_documentation_documents pdd\n"
            . "INNER JOIN documents doc ON doc.id = pdd.document_id\n"
            . "WHERE pdd.documentation_id = :id\n"
            . "ORDER BY doc.id ASC"
        );
        $stmt->execute(['id' => $id]);
        $rows = $stmt->fetchAll();
        foreach ($rows as $r) {
            $att[] = [
                'kind' => (string)$r['doc_kind'],
                'document_id' => (int)$r['document_id'],
            ];
        }

        $occurredAt = (new DateTime('now'))->format('Y-m-d H:i:s');
        if ($d['appointment_id'] !== null) {
            $stmt = $db->prepare('SELECT first_at FROM appointments WHERE id = :id');
            $stmt->execute(['id' => (int)$d['appointment_id']]);
            $a = $stmt->fetch();
            if ($a && isset($a['first_at'])) {
                $occurredAt = (string)$a['first_at'];
            }
        }

        $stmt = $db->prepare(
            'INSERT INTO patient_prontuario_entries (patient_id, professional_user_id, origin, occurred_at, sessions_count, notes, attachments_json) '
            . 'VALUES (:pid, :puid, :origin, :occ, :sc, :notes, :att)'
        );
        $stmt->execute([
            'pid' => (int)$d['patient_id'],
            'puid' => (int)$d['professional_user_id'],
            'origin' => 'professional_documentations',
            'occ' => $occurredAt,
            'sc' => (int)($d['sessions_count'] ?? 1),
            'notes' => $d['notes'] !== null ? (string)$d['notes'] : null,
            'att' => json_encode($att, JSON_UNESCAPED_UNICODE),
        ]);

        $prontId = (string)$db->lastInsertId();
        audit_log('create', 'patient_prontuario_entries', $prontId, null, ['patient_id' => (int)$d['patient_id'], 'origin' => 'professional_documentations']);
    }

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

// Confirmação ao profissional (WhatsApp + e-mail) via jobs
$payload = [
    'documentation_id' => $id,
];
integration_job_enqueue('evolution', 'professional_docs_approved_notify', $payload, null);
integration_job_enqueue('smtp', 'professional_docs_approved_email', $payload, null);

flash_set('success', 'Documentação aprovada.');
header('Location: /professional_docs_review_list.php?status=approved');
exit;

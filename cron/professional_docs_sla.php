<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

// MD 6.1: Prazo 48h já é gerado no due_at.
// Regras:
// - lembrete (dias antes) configurável
// - se não enviado: reenvio diário por até 7 dias consecutivos após o prazo
// - após 7 dias: pendência de revisão admin

$db = db();

$daysBefore = (int)admin_setting_get('professional.docs_reminder_days_before_due', '1');
if ($daysBefore < 0) {
    $daysBefore = 1;
}

$now = new DateTime('now');
$today = $now->format('Y-m-d');

// 1) Lembretes antes do prazo
if ($daysBefore > 0) {
    $stmt = $db->prepare(
        "SELECT pd.id, pd.professional_user_id, pd.patient_ref, pd.due_at
         FROM professional_documentations pd
         WHERE pd.status = 'draft'
           AND pd.due_at IS NOT NULL
           AND DATE(pd.due_at) = DATE_ADD(CURDATE(), INTERVAL :d DAY)"
    );
    $stmt->execute(['d' => $daysBefore]);
    $rows = $stmt->fetchAll();

    foreach ($rows as $r) {
        $docId = (int)$r['id'];
        $payload = [
            'doc_id' => $docId,
            'professional_user_id' => (int)$r['professional_user_id'],
            'patient_ref' => (string)$r['patient_ref'],
            'due_at' => (string)$r['due_at'],
            'kind' => 'before_due',
        ];
        integration_job_enqueue('evolution', 'professional_doc_reminder', $payload, null);
    }
}

// 2) Cobrança diária após o prazo (até 7 dias)
$stmt = $db->prepare(
    "SELECT pd.id, pd.professional_user_id, pd.patient_ref, pd.due_at, pd.reminders_sent, pd.last_reminder_at
     FROM professional_documentations pd
     WHERE pd.status = 'draft'
       AND pd.due_at IS NOT NULL
       AND pd.due_at < NOW()"
);
$stmt->execute();
$overdue = $stmt->fetchAll();

$createdJobs = 0;
$createdPendings = 0;

$upd = $db->prepare('UPDATE professional_documentations SET reminders_sent = :rs, last_reminder_at = NOW() WHERE id = :id');

$pendingExists = $db->prepare(
    "SELECT id FROM pending_items
     WHERE status = 'open' AND type = 'professional_docs_review'
       AND related_table = 'professional_documentations' AND related_id = :rid
     LIMIT 1"
);
$pendingIns = $db->prepare(
    "INSERT INTO pending_items (type, status, title, detail, related_table, related_id, assigned_user_id)
     VALUES ('professional_docs_review','open',:title,:detail,'professional_documentations',:rid,NULL)"
);

foreach ($overdue as $r) {
    $docId = (int)$r['id'];
    $dueAt = new DateTime((string)$r['due_at']);
    $daysOverdue = (int)$dueAt->diff($now)->format('%a');

    if ($daysOverdue <= 0) {
        continue;
    }

    // após 7 dias: pendência revisão admin
    if ($daysOverdue >= 7) {
        $pendingExists->execute(['rid' => $docId]);
        if (!$pendingExists->fetch()) {
            $pendingIns->execute([
                'title' => 'Revisão Admin: formulário não enviado (Doc #' . $docId . ')',
                'detail' => 'Atraso de ' . $daysOverdue . ' dias. Profissional sem ação após cobranças.',
                'rid' => $docId,
            ]);
            $createdPendings++;
        }
        continue;
    }

    // 1 cobrança por dia
    $last = $r['last_reminder_at'] ? (new DateTime((string)$r['last_reminder_at']))->format('Y-m-d') : null;
    if ($last === $today) {
        continue;
    }

    $sent = (int)$r['reminders_sent'];
    if ($sent >= 7) {
        continue;
    }

    $payload = [
        'doc_id' => $docId,
        'professional_user_id' => (int)$r['professional_user_id'],
        'patient_ref' => (string)$r['patient_ref'],
        'due_at' => (string)$r['due_at'],
        'days_overdue' => $daysOverdue,
        'kind' => 'overdue',
    ];

    integration_job_enqueue('evolution', 'professional_doc_reminder', $payload, null);
    $createdJobs++;

    $upd->execute(['rs' => $sent + 1, 'id' => $docId]);
}

echo 'OK: jobs=' . $createdJobs . ' pendings=' . $createdPendings . "\n";

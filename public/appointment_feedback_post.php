<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$token = trim((string)($_POST['token'] ?? ''));
$status = trim((string)($_POST['status'] ?? ''));
$note = trim((string)($_POST['note'] ?? ''));

if ($token === '' || !in_array($status, ['confirmed','professional_absent','cancelled'], true)) {
    view_header('Confirmação');
    echo '<div class="card"><div style="font-weight:900">Dados inválidos.</div></div>';
    view_footer();
    exit;
}

$db = db();

$stmt = $db->prepare(
    'SELECT f.id AS feedback_id, f.status AS feedback_status, f.appointment_id, a.created_by_user_id, a.demand_id, p.full_name AS patient_name '
    . 'FROM appointment_patient_feedback f '
    . 'INNER JOIN appointments a ON a.id = f.appointment_id '
    . 'INNER JOIN patients p ON p.id = a.patient_id '
    . 'WHERE f.token = :t '
    . 'LIMIT 1'
);
$stmt->execute(['t' => $token]);
$row = $stmt->fetch();

if (!$row) {
    view_header('Confirmação');
    echo '<div class="card"><div style="font-weight:900">Link inválido ou expirado.</div></div>';
    view_footer();
    exit;
}

$db->beginTransaction();
try {
    $stmt = $db->prepare('UPDATE appointment_patient_feedback SET status = :st, note = :note WHERE id = :id');
    $stmt->execute([
        'st' => $status,
        'note' => $note !== '' ? mb_strimwidth($note, 0, 255, '') : null,
        'id' => (int)$row['feedback_id'],
    ]);

    // Se houver problema, cria pendência para o captador responsável
    if (in_array($status, ['professional_absent','cancelled'], true)) {
        $assigned = $row['created_by_user_id'] !== null ? (int)$row['created_by_user_id'] : null;
        $title = 'Atenção: paciente reportou problema no atendimento (#' . (int)$row['appointment_id'] . ')';
        $detail = 'Paciente: ' . (string)$row['patient_name'] . ' | Status: ' . $status;
        if ($note !== '') {
            $detail .= ' | Obs: ' . mb_strimwidth($note, 0, 120, '...');
        }
        $stmt = $db->prepare(
            "INSERT INTO pending_items (type, status, title, detail, related_table, related_id, assigned_user_id)"
            . " VALUES ('patient_feedback','open',:title,:detail,'appointments',:rid,:uid)"
        );
        $stmt->execute([
            'title' => $title,
            'detail' => mb_strimwidth($detail, 0, 240, '...'),
            'rid' => (int)$row['appointment_id'],
            'uid' => $assigned,
        ]);
    }

    integration_log('patient_feedback', 'appointment_feedback_submit', 'success', null, ['token' => $token, 'status' => $status], null, null, 1);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    integration_log('patient_feedback', 'appointment_feedback_submit', 'error', null, ['token' => $token, 'status' => $status], null, mb_strimwidth($e->getMessage(), 0, 255, ''), 1);
    throw $e;
}

view_header('Confirmação');
echo '<div class="card">';
echo '<div style="font-size:22px;font-weight:900">Obrigado!</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Sua resposta foi registrada.</div>';
echo '</div>';
view_footer();

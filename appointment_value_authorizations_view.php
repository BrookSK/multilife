<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('appointments.manage');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = db()->prepare('SELECT a.*, u.name AS requested_by_name, ur.name AS reviewed_by_name FROM appointment_value_authorizations a LEFT JOIN users u ON u.id = a.requested_by_user_id LEFT JOIN users ur ON ur.id = a.reviewed_by_user_id WHERE a.id = :id');
$stmt->execute(['id' => $id]);
$row = $stmt->fetch();

if (!$row) {
    flash_set('error', 'Solicitação não encontrada.');
    header('Location: /appointment_value_authorizations_list.php');
    exit;
}

view_header('Autorização #' . (string)$row['id']);

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:12px;color:hsl(var(--muted-foreground));margin-bottom:6px">Autorização</div>';
echo '<div style="font-size:22px;font-weight:900">#' . (int)$row['id'] . '</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">';
echo '<strong>Status:</strong> ' . h((string)$row['status']) . ' &nbsp; <strong>Especialidade:</strong> ' . h((string)$row['specialty']);
echo '</div>';
echo '</div>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/appointment_value_authorizations_list.php">Voltar</a>';
if ((string)$row['status'] === 'pending') {
    echo '<form method="post" action="/appointment_value_authorizations_approve_post.php" style="display:inline">';
    echo '<input type="hidden" name="id" value="' . (int)$row['id'] . '">';
    echo '<button class="btn btnPrimary" type="submit" onclick="return confirm(\'Autorizar e criar agendamento?\')">Autorizar</button>';
    echo '</form>';

    echo '<form method="post" action="/appointment_value_authorizations_reject_post.php" style="display:inline">';
    echo '<input type="hidden" name="id" value="' . (int)$row['id'] . '">';
    echo '<input type="hidden" name="note" value="Rejeitado pelo Admin">';
    echo '<button class="btn" type="submit" onclick="return confirm(\'Rejeitar?\')">Rejeitar</button>';
    echo '</form>';
}

echo '</div>';
echo '</div>';

echo '</section>';

echo '<section class="card col12">';
echo '<div style="display:grid;gap:8px">';
$fields = [
    'Paciente ID' => (string)$row['patient_id'],
    'Profissional ID' => (string)$row['professional_user_id'],
    'Demanda ID' => $row['demand_id'] !== null ? (string)$row['demand_id'] : '-',
    '1º atendimento' => (string)$row['first_at'],
    'Recorrência' => (string)$row['recurrence_type'],
    'Regra' => (string)($row['recurrence_rule'] ?? ''),
    'Solicitado' => (string)$row['requested_value'],
    'Mínimo' => (string)$row['minimum_value'],
    'Solicitado por' => (string)($row['requested_by_name'] ?? '-'),
    'Solicitado em' => (string)$row['requested_at'],
    'Revisado por' => (string)($row['reviewed_by_name'] ?? '-'),
    'Revisado em' => (string)($row['reviewed_at'] ?? '-'),
    'Nota revisão' => (string)($row['review_note'] ?? ''),
];
foreach ($fields as $k => $v) {
    $v = trim((string)$v);
    echo '<div class="pill" style="display:block"><strong>' . h($k) . ':</strong> ' . h($v !== '' ? $v : '-') . '</div>';
}

echo '</div>';
echo '</section>';

echo '</div>';

view_footer();

<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('appointments.manage');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = db()->prepare(
    'SELECT a.*, p.full_name AS patient_name, u.name AS professional_name
     FROM appointments a
     INNER JOIN patients p ON p.id = a.patient_id
     INNER JOIN users u ON u.id = a.professional_user_id
     WHERE a.id = :id AND p.deleted_at IS NULL'
);
$stmt->execute(['id' => $id]);
$a = $stmt->fetch();

if (!$a) {
    flash_set('error', 'Agendamento não encontrado.');
    header('Location: /appointments_list.php');
    exit;
}

$stmt = db()->prepare(
    'SELECT l.created_at, l.old_status, l.new_status, l.note, u.name AS user_name
     FROM appointment_status_logs l
     LEFT JOIN users u ON u.id = l.user_id
     WHERE l.appointment_id = :id
     ORDER BY l.id DESC'
);
$stmt->execute(['id' => $id]);
$logs = $stmt->fetchAll();

view_header('Agendamento #' . (string)$a['id']);

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:12px;color:hsl(var(--muted-foreground));margin-bottom:6px">Agendamento</div>';
echo '<div style="font-size:22px;font-weight:900">#' . (int)$a['id'] . '</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">';
echo '<strong>Paciente:</strong> ' . h((string)$a['patient_name']) . ' &nbsp; <strong>Profissional:</strong> ' . h((string)$a['professional_name']) . '<br>';
echo '<strong>1º atendimento:</strong> ' . h((string)$a['first_at']) . ' &nbsp; <strong>Recorrência:</strong> ' . h((string)$a['recurrence_type']) . ' &nbsp; <strong>Status:</strong> ' . h((string)$a['status']);
echo '</div>';
echo '</div>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/appointments_list.php">Voltar</a>';
echo '<a class="btn" href="/appointments_edit.php?id=' . (int)$a['id'] . '">Editar</a>';
if (in_array((string)$a['recurrence_type'], ['weekly','monthly','custom'], true)) {
    echo '<a class="btn btnPrimary" href="/appointments_renew_cycle.php?appointment_id=' . (int)$a['id'] . '">Renovar Ciclo</a>';
    echo '<form method="post" action="/appointments_close_cycle_post.php" style="display:inline">';
    echo '<input type="hidden" name="appointment_id" value="' . (int)$a['id'] . '">';
    echo '<button class="btn" type="submit" onclick="return confirm(\'Encerrar este ciclo de atendimento?\')">Encerrar Ciclo</button>';
    echo '</form>';
}
echo '<form method="post" action="/appointments_cancel_post.php" style="display:inline">';
echo '<input type="hidden" name="id" value="' . (int)$a['id'] . '">';
echo '<button class="btn" type="submit" onclick="return confirm(\'Cancelar este agendamento?\')">Cancelar</button>';
echo '</form>';
echo '</div>';

echo '</div>';

echo '<form method="post" action="/appointments_set_status_post.php" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">';
echo '<input type="hidden" name="id" value="' . (int)$a['id'] . '">';
echo '<select name="status" style="min-width:240px">';
$allowed = ['agendado','pendente_formulario','realizado','atrasado','cancelado','revisao_admin'];
foreach ($allowed as $st) {
    $sel = ((string)$a['status'] === $st) ? ' selected' : '';
    echo '<option value="' . h($st) . '"' . $sel . '>' . h($st) . '</option>';
}
echo '</select>';
echo '<input name="note" placeholder="Observação (opcional)" style="flex:1;min-width:240px">';
echo '<button class="btn" type="submit">Atualizar status</button>';
echo '</form>';

echo '</section>';

echo '<section class="card col12">';
echo '<div style="font-weight:900;margin-bottom:8px">Histórico de status</div>';
echo '<div style="overflow:auto">';
echo '<table>';
echo '<thead><tr>';
echo '<th>Quando</th><th>Usuário</th><th>De</th><th>Para</th><th>Obs.</th>';
echo '</tr></thead><tbody>';
foreach ($logs as $l) {
    echo '<tr>';
    echo '<td>' . h((string)$l['created_at']) . '</td>';
    echo '<td>' . h((string)($l['user_name'] ?? '-')) . '</td>';
    echo '<td>' . h((string)($l['old_status'] ?? '-')) . '</td>';
    echo '<td>' . h((string)$l['new_status']) . '</td>';
    echo '<td>' . h((string)($l['note'] ?? '')) . '</td>';
    echo '</tr>';
}

echo '</tbody></table>';
echo '</div>';
echo '</section>';

echo '</div>';

view_footer();

<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('appointments.manage');

$appointmentId = isset($_GET['appointment_id']) ? (int)$_GET['appointment_id'] : 0;

$stmt = db()->prepare(
    'SELECT a.*, p.full_name AS patient_name, u.name AS professional_name
     FROM appointments a
     INNER JOIN patients p ON p.id = a.patient_id
     INNER JOIN users u ON u.id = a.professional_user_id
     WHERE a.id = :id AND p.deleted_at IS NULL'
);
$stmt->execute(['id' => $appointmentId]);
$appt = $stmt->fetch();

if (!$appt) {
    flash_set('error', 'Agendamento não encontrado.');
    header('Location: /appointments_list.php');
    exit;
}

view_header('Renovar Ciclo de Agendamento');

echo '<div class="card">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900;margin-bottom:6px">Renovar Ciclo de Agendamento</div>';
echo '<div style="color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Agendamento #' . (int)$appt['id'] . ' | ' . h((string)$appt['patient_name']) . ' / ' . h((string)$appt['professional_name']) . '</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/appointments_view.php?id=' . (int)$appt['id'] . '">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<div style="height:14px"></div>';

echo '<form method="post" action="/appointments_renew_cycle_post.php" style="display:grid;gap:12px;max-width:900px">';
echo '<input type="hidden" name="appointment_id" value="' . (int)$appt['id'] . '">';

echo '<div class="grid">';

echo '<div class="col12">';
echo '<div class="pill" style="display:block;background:hsl(var(--muted));padding:12px">';
echo '<strong>Dados atuais:</strong><br>';
echo 'Especialidade: ' . h((string)$appt['specialty']) . '<br>';
echo 'Primeira data: ' . h((string)$appt['first_at']) . '<br>';
echo 'Recorrência: ' . h((string)$appt['recurrence_type']) . '<br>';
echo 'Valor por sessão: R$ ' . h((string)$appt['value_per_session']);
echo '</div>';
echo '</div>';

echo '<div class="col6">';
echo '<label>Nova data/hora do 1º atendimento<input type="datetime-local" name="first_at" required></label>';
echo '</div>';

echo '<div class="col6">';
echo '<label>Frequência<select name="recurrence_type">';
echo '<option value="single">single</option>';
echo '<option value="weekly" ' . ((string)$appt['recurrence_type'] === 'weekly' ? 'selected' : '') . '>weekly</option>';
echo '<option value="monthly" ' . ((string)$appt['recurrence_type'] === 'monthly' ? 'selected' : '') . '>monthly</option>';
echo '<option value="custom" ' . ((string)$appt['recurrence_type'] === 'custom' ? 'selected' : '') . '>custom</option>';
echo '</select></label>';
echo '</div>';

echo '<div class="col12">';
echo '<label>Regra de recorrência (opcional)<textarea name="recurrence_rule" rows="2" placeholder="Ex: 3x por semana por 30 dias">' . h((string)($appt['recurrence_rule'] ?? '')) . '</textarea></label>';
echo '</div>';

echo '<div class="col6">';
echo '<label>Valor por sessão<input type="number" step="0.01" min="0" name="value_per_session" required value="' . h((string)$appt['value_per_session']) . '"></label>';
echo '</div>';

echo '</div>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end;margin-top:6px">';
echo '<a class="btn" href="/appointments_view.php?id=' . (int)$appt['id'] . '">Cancelar</a>';
echo '<button class="btn btnPrimary" type="submit">Renovar Ciclo</button>';
echo '</div>';

echo '</form>';

echo '</div>';

view_footer();

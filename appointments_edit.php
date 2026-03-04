<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('appointments.manage');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = db()->prepare('SELECT * FROM appointments WHERE id = :id');
$stmt->execute(['id' => $id]);
$a = $stmt->fetch();

if (!$a) {
    flash_set('error', 'Agendamento não encontrado.');
    header('Location: /appointments_list.php');
    exit;
}

$patients = db()->query('SELECT id, full_name FROM patients WHERE deleted_at IS NULL ORDER BY full_name ASC')->fetchAll();
$professionals = db()->query(
    "SELECT u.id, u.name, u.email FROM users u INNER JOIN user_roles ur ON ur.user_id = u.id INNER JOIN roles r ON r.id = ur.role_id WHERE u.status = 'active' AND r.slug = 'profissional' ORDER BY u.name ASC"
)->fetchAll();

view_header('Editar agendamento');

echo '<div class="card">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900;margin-bottom:6px">Editar agendamento</div>';
echo '<div style="color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Atualize dados do agendamento.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/appointments_list.php">Voltar</a>';
echo '<a class="btn" href="/appointments_view.php?id=' . (int)$a['id'] . '">Ver detalhes</a>';
echo '</div>';
echo '</div>';

echo '<div style="height:14px"></div>';

echo '<form method="post" action="/appointments_edit_post.php" style="display:grid;gap:12px;max-width:900px">';
echo '<input type="hidden" name="id" value="' . (int)$a['id'] . '">';

echo '<div class="grid">';

echo '<div class="col6">';
echo '<label>Paciente<select name="patient_id" required>';
foreach ($patients as $p) {
    $sel = ((int)$a['patient_id'] === (int)$p['id']) ? ' selected' : '';
    echo '<option value="' . (int)$p['id'] . '"' . $sel . '>' . h((string)$p['full_name']) . ' (#' . (int)$p['id'] . ')</option>';
}
echo '</select></label>';
echo '</div>';

echo '<div class="col6">';
echo '<label>Profissional<select name="professional_user_id" required>';
foreach ($professionals as $u) {
    $sel = ((int)$a['professional_user_id'] === (int)$u['id']) ? ' selected' : '';
    echo '<option value="' . (int)$u['id'] . '"' . $sel . '>' . h((string)$u['name']) . ' — ' . h((string)$u['email']) . '</option>';
}
echo '</select></label>';
echo '</div>';

echo '<div class="col6">';
$dt = DateTime::createFromFormat('Y-m-d H:i:s', (string)$a['first_at']);
$val = $dt ? $dt->format('Y-m-d\TH:i') : '';
echo '<label>Data/hora do 1º atendimento<input type="datetime-local" name="first_at" required value="' . h($val) . '"></label>';
echo '</div>';

echo '<div class="col6">';
echo '<label>Frequência<select name="recurrence_type">';
$types = ['single','weekly','monthly','custom'];
foreach ($types as $t) {
    $sel = ((string)$a['recurrence_type'] === $t) ? ' selected' : '';
    echo '<option value="' . h($t) . '"' . $sel . '>' . h($t) . '</option>';
}
echo '</select></label>';
echo '</div>';

echo '<div class="col12">';
echo '<label>Regra de recorrência<textarea name="recurrence_rule" rows="2">' . h((string)($a['recurrence_rule'] ?? '')) . '</textarea></label>';
echo '</div>';

echo '<div class="col6">';
echo '<label>Valor por sessão<input type="number" step="0.01" min="0" name="value_per_session" required value="' . h((string)$a['value_per_session']) . '"></label>';
echo '</div>';

echo '</div>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end;margin-top:6px">';
echo '<a class="btn" href="/appointments_view.php?id=' . (int)$a['id'] . '">Cancelar</a>';
echo '<button class="btn btnPrimary" type="submit">Salvar</button>';
echo '</div>';

echo '</form>';

echo '</div>';

view_footer();

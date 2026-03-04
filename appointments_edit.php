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
echo '<div style="font-size:22px;font-weight:800;margin-bottom:6px">Editar agendamento</div>';

echo '<form method="post" action="/appointments_edit_post.php" style="display:grid;gap:12px;max-width:900px">';
echo '<input type="hidden" name="id" value="' . (int)$a['id'] . '">';

echo '<div class="grid">';

echo '<div class="col6">';
echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Paciente<select name="patient_id" required style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px">';
foreach ($patients as $p) {
    $sel = ((int)$a['patient_id'] === (int)$p['id']) ? ' selected' : '';
    echo '<option value="' . (int)$p['id'] . '"' . $sel . '>' . h((string)$p['full_name']) . ' (#' . (int)$p['id'] . ')</option>';
}
echo '</select></label>';
echo '</div>';

echo '<div class="col6">';
echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Profissional<select name="professional_user_id" required style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px">';
foreach ($professionals as $u) {
    $sel = ((int)$a['professional_user_id'] === (int)$u['id']) ? ' selected' : '';
    echo '<option value="' . (int)$u['id'] . '"' . $sel . '>' . h((string)$u['name']) . ' — ' . h((string)$u['email']) . '</option>';
}
echo '</select></label>';
echo '</div>';

echo '<div class="col6">';
$dt = DateTime::createFromFormat('Y-m-d H:i:s', (string)$a['first_at']);
$val = $dt ? $dt->format('Y-m-d\TH:i') : '';
echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Data/hora do 1º atendimento<input type="datetime-local" name="first_at" required value="' . h($val) . '" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:10px 12px;outline:none;font-size:14px"></label>';
echo '</div>';

echo '<div class="col6">';
echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Frequência<select name="recurrence_type" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px">';
$types = ['single','weekly','monthly','custom'];
foreach ($types as $t) {
    $sel = ((string)$a['recurrence_type'] === $t) ? ' selected' : '';
    echo '<option value="' . h($t) . '"' . $sel . '>' . h($t) . '</option>';
}
echo '</select></label>';
echo '</div>';

echo '<div class="col12">';
echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Regra de recorrência<textarea name="recurrence_rule" rows="2" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px">' . h((string)($a['recurrence_rule'] ?? '')) . '</textarea></label>';
echo '</div>';

echo '<div class="col6">';
echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Valor por sessão<input type="number" step="0.01" min="0" name="value_per_session" required value="' . h((string)$a['value_per_session']) . '" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label>';
echo '</div>';

echo '</div>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:6px">';
echo '<button class="btn btnPrimary" type="submit">Salvar</button>';
echo '<a class="btn" href="/appointments_view.php?id=' . (int)$a['id'] . '">Cancelar</a>';
echo '</div>';

echo '</form>';

echo '</div>';

view_footer();

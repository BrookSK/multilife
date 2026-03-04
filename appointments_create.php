<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('appointments.manage');

$patients = db()->query('SELECT id, full_name FROM patients WHERE deleted_at IS NULL ORDER BY full_name ASC')->fetchAll();
$professionals = db()->query(
    "SELECT u.id, u.name, u.email FROM users u INNER JOIN user_roles ur ON ur.user_id = u.id INNER JOIN roles r ON r.id = ur.role_id WHERE u.status = 'active' AND r.slug = 'profissional' ORDER BY u.name ASC"
)->fetchAll();

view_header('Novo agendamento');

echo '<div class="card">';
echo '<div style="font-size:22px;font-weight:800;margin-bottom:6px">Novo agendamento</div>';
echo '<div style="color:rgba(234,240,255,.72);font-size:14px;line-height:1.6;margin-bottom:14px">Criado pelo Captador após confirmação via chat.</div>';

echo '<form method="post" action="/appointments_create_post.php" style="display:grid;gap:12px;max-width:900px">';

echo '<div class="grid">';

echo '<div class="col6">';
echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Paciente<select name="patient_id" required style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px">';
echo '<option value="">Selecione</option>';
foreach ($patients as $p) {
    echo '<option value="' . (int)$p['id'] . '">' . h((string)$p['full_name']) . ' (#' . (int)$p['id'] . ')</option>';
}
echo '</select></label>';
echo '</div>';

echo '<div class="col6">';
echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Profissional<select name="professional_user_id" required style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px">';
echo '<option value="">Selecione</option>';
foreach ($professionals as $u) {
    echo '<option value="' . (int)$u['id'] . '">' . h((string)$u['name']) . ' — ' . h((string)$u['email']) . '</option>';
}
echo '</select></label>';
echo '</div>';

echo '<div class="col6">';
echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Data/hora do 1º atendimento<input type="datetime-local" name="first_at" required style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:10px 12px;outline:none;font-size:14px"></label>';
echo '</div>';

echo '<div class="col6">';
echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Frequência<select name="recurrence_type" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px">';
echo '<option value="single">single</option>';
echo '<option value="weekly">weekly</option>';
echo '<option value="monthly">monthly</option>';
echo '<option value="custom">custom</option>';
echo '</select></label>';
echo '</div>';

echo '<div class="col12">';
echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Regra de recorrência (opcional)<textarea name="recurrence_rule" rows="2" placeholder="Ex: 3x por semana por 30 dias" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></textarea></label>';
echo '</div>';

echo '<div class="col6">';
echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Valor por sessão<input type="number" step="0.01" min="0" name="value_per_session" required value="0" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label>';
echo '</div>';

echo '<div class="col6">';
echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Vincular a card (demanda) - ID (opcional)<input type="number" name="demand_id" min="1" placeholder="Ex: 123" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label>';
echo '</div>';

echo '</div>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:6px">';
echo '<button class="btn btnPrimary" type="submit">Salvar</button>';
echo '<a class="btn" href="/appointments_list.php">Cancelar</a>';
echo '</div>';

echo '</form>';

echo '</div>';

view_footer();

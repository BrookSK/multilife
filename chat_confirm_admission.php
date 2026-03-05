<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('appointments.manage');

$chatId = isset($_GET['chat_id']) ? (int)$_GET['chat_id'] : 0;
$prefDemandId = isset($_GET['demand_id']) ? (int)$_GET['demand_id'] : 0;

$stmt = db()->prepare('SELECT * FROM chat_conversations WHERE id = :id');
$stmt->execute(['id' => $chatId]);
$chat = $stmt->fetch();

if (!$chat) {
    flash_set('error', 'Conversa não encontrada.');
    header('Location: /chat_web.php');
    exit;
}

$patients = db()->query("SELECT id, full_name FROM patients WHERE deleted_at IS NULL ORDER BY full_name ASC")->fetchAll();
$professionals = db()->query(
    "SELECT u.id, u.name, u.email FROM users u INNER JOIN user_roles ur ON ur.user_id = u.id INNER JOIN roles r ON r.id = ur.role_id WHERE u.status = 'active' AND r.slug = 'profissional' ORDER BY u.name ASC"
)->fetchAll();

$demands = db()->query(
    "SELECT id, title, status FROM demands WHERE status IN ('aguardando_captacao','tratamento_manual','em_captacao') ORDER BY id DESC LIMIT 200"
)->fetchAll();

$prefPatientId = 0;
$prefProfessionalUserId = 0;

if ((string)$chat['contact_kind'] === 'patient' && $chat['contact_ref_id'] !== null) {
    $prefPatientId = (int)$chat['contact_ref_id'];
}

if ((string)$chat['contact_kind'] === 'professional' && $chat['contact_ref_id'] !== null) {
    // linked to professional_application id; if approved, use created_user_id
    $stmt = db()->prepare('SELECT created_user_id FROM professional_applications WHERE id = :id');
    $stmt->execute(['id' => (int)$chat['contact_ref_id']]);
    $pa = $stmt->fetch();
    if ($pa && $pa['created_user_id'] !== null) {
        $prefProfessionalUserId = (int)$pa['created_user_id'];
    }
}

view_header('Confirmar Admissão');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:12px;color:hsl(var(--muted-foreground));margin-bottom:6px">Chat</div>';
echo '<div style="font-size:22px;font-weight:900">Confirmar Admissão</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Gera agendamento + pendência do formulário + financeiro e marca a demanda como admitida.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/chat_web.php?id=' . (int)$chat['id'] . '">Voltar ao chat</a>';
echo '</div>';
echo '</div>';
echo '</section>';

echo '<section class="card col12">';
echo '<form method="post" action="/chat_confirm_admission_post.php" style="display:grid;gap:12px;max-width:980px">';
echo '<input type="hidden" name="chat_id" value="' . (int)$chat['id'] . '">';

echo '<div class="grid">';

echo '<div class="col6">';
echo '<label>Demanda (card)<select name="demand_id">';
echo '<option value="">(opcional)</option>';
foreach ($demands as $d) {
    $sel = ((int)$d['id'] === $prefDemandId) ? ' selected' : '';
    echo '<option value="' . (int)$d['id'] . '"' . $sel . '>#' . (int)$d['id'] . ' — ' . h((string)$d['title']) . ' (' . h((string)$d['status']) . ')</option>';
}

echo '</select></label>';
echo '</div>';

echo '<div class="col6">';
echo '<label>Telefone do contato<input value="' . h((string)$chat['external_phone']) . '" readonly></label>';
echo '</div>';

echo '<div class="col6">';
echo '<label>Paciente<select name="patient_id" required>';
echo '<option value="">Selecione</option>';
foreach ($patients as $p) {
    $sel = ((int)$p['id'] === $prefPatientId) ? ' selected' : '';
    echo '<option value="' . (int)$p['id'] . '"' . $sel . '>' . h((string)$p['full_name']) . ' (#' . (int)$p['id'] . ')</option>';
}

echo '</select></label>';
echo '</div>';

echo '<div class="col6">';
echo '<label>Profissional<select name="professional_user_id" required>';
echo '<option value="">Selecione</option>';
foreach ($professionals as $u) {
    $sel = ((int)$u['id'] === $prefProfessionalUserId) ? ' selected' : '';
    echo '<option value="' . (int)$u['id'] . '"' . $sel . '>' . h((string)$u['name']) . ' — ' . h((string)$u['email']) . '</option>';
}

echo '</select></label>';
echo '</div>';

echo '<div class="col6">';
echo '<label>Especialidade<input name="specialty" required maxlength="120" placeholder="Ex: Fisioterapia"></label>';
echo '</div>';

echo '<div class="col6">';
echo '<label>Data/hora do 1º atendimento<input type="datetime-local" name="first_at" required></label>';
echo '</div>';

echo '<div class="col6">';
echo '<label>Frequência<select name="recurrence_type">';
echo '<option value="single">single</option>';
echo '<option value="weekly">weekly</option>';
echo '<option value="monthly">monthly</option>';
echo '<option value="custom">custom</option>';
echo '</select></label>';
echo '</div>';

echo '<div class="col12">';
echo '<label>Regra de recorrência (opcional)<textarea name="recurrence_rule" rows="2" placeholder="Ex: 3x por semana por 30 dias"></textarea></label>';
echo '</div>';

echo '<div class="col6">';
echo '<label>Valor por sessão<input type="number" step="0.01" min="0" name="value_per_session" required value="0"></label>';
echo '</div>';

echo '<div class="col6">';
echo '<label>Observação (opcional)<input name="note" placeholder="Ex: Profissional confirmado via chat"></label>';
echo '</div>';

echo '</div>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end;margin-top:6px">';
echo '<a class="btn" href="/chat_web.php?id=' . (int)$chat['id'] . '">Cancelar</a>';
echo '<button class="btn btnPrimary" type="submit">Confirmar e gerar agendamento</button>';
echo '</div>';

echo '</form>';
echo '</section>';

echo '</div>';

view_footer();

<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('patient_links.manage');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = db()->prepare('SELECT id, full_name FROM patients WHERE id = :id AND deleted_at IS NULL');
$stmt->execute(['id' => $id]);
$p = $stmt->fetch();

if (!$p) {
    flash_set('error', 'Paciente não encontrado.');
    header('Location: /patients_list.php');
    exit;
}

$professionals = db()->query(
    "SELECT u.id, u.name, u.email FROM users u INNER JOIN user_roles ur ON ur.user_id = u.id INNER JOIN roles r ON r.id = ur.role_id WHERE u.status = 'active' AND r.slug = 'profissional' ORDER BY u.name ASC"
)->fetchAll();

$stmt = db()->prepare('SELECT professional_user_id, specialty, is_active FROM patient_professionals WHERE patient_id = :pid');
$stmt->execute(['pid' => $id]);
$current = [];
foreach ($stmt->fetchAll() as $r) {
    $current[(int)$r['professional_user_id']] = [
        'specialty' => (string)($r['specialty'] ?? ''),
        'is_active' => (int)$r['is_active'],
    ];
}

view_header('Vínculos');

echo '<div class="card">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:800">Vínculo Paciente ↔ Profissional</div>';
echo '<div style="margin-top:6px;color:rgba(234,240,255,.72);font-size:14px;line-height:1.6">' . h((string)$p['full_name']) . '</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/patients_view.php?id=' . (int)$p['id'] . '">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<form method="post" action="/patients_links_edit_post.php" style="margin-top:14px;display:grid;gap:10px">';
echo '<input type="hidden" name="patient_id" value="' . (int)$p['id'] . '">';

foreach ($professionals as $pro) {
    $pid = (int)$pro['id'];
    $checked = isset($current[$pid]) ? ' checked' : '';
    $spec = isset($current[$pid]) ? $current[$pid]['specialty'] : '';
    $active = isset($current[$pid]) ? (int)$current[$pid]['is_active'] : 1;

    echo '<div class="pill" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:12px">';
    echo '<label style="display:flex;align-items:center;gap:10px">';
    echo '<input type="checkbox" name="professional_ids[]" value="' . $pid . '"' . $checked . '> ';
    echo '<strong>' . h((string)$pro['name']) . '</strong> <span style="color:rgba(234,240,255,.72)">(' . h((string)$pro['email']) . ')</span>';
    echo '</label>';

    echo '<input name="specialty_' . $pid . '" placeholder="Especialidade (opcional)" value="' . h($spec) . '" style="flex:1;min-width:200px;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:10px 12px;outline:none;font-size:14px">';

    echo '<select name="is_active_' . $pid . '" style="border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:10px 12px;outline:none;font-size:14px">';
    echo '<option value="1"' . ($active === 1 ? ' selected' : '') . '>ativo</option>';
    echo '<option value="0"' . ($active !== 1 ? ' selected' : '') . '>inativo</option>';
    echo '</select>';

    echo '</div>';
}

if (count($professionals) === 0) {
    echo '<div class="pill" style="display:block">Nenhum usuário com role profissional encontrado.</div>';
}

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:6px">';
echo '<button class="btn btnPrimary" type="submit">Salvar vínculos</button>';
echo '<a class="btn" href="/patients_view.php?id=' . (int)$p['id'] . '">Cancelar</a>';
echo '</div>';

echo '</form>';

echo '</div>';

view_footer();

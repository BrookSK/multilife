<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('hr.manage');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = db()->prepare('SELECT * FROM hr_employees WHERE id = :id');
$stmt->execute(['id' => $id]);
$e = $stmt->fetch();

if (!$e) {
    flash_set('error', 'Funcionário não encontrado.');
    header('Location: /hr_employees_list.php');
    exit;
}

view_header('Editar funcionário');

echo '<div class="card">';
echo '<div style="font-size:22px;font-weight:800;margin-bottom:6px">Editar funcionário</div>';

echo '<form method="post" action="/hr_employees_edit_post.php" style="display:grid;gap:12px;max-width:720px">';
echo '<input type="hidden" name="id" value="' . (int)$e['id'] . '">';

echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Nome completo<input name="full_name" required maxlength="160" value="' . h((string)$e['full_name']) . '" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label>';

echo '<div class="grid">';
echo '<div class="col6"><label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">E-mail<input type="email" name="email" maxlength="190" value="' . h((string)($e['email'] ?? '')) . '" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label></div>';
echo '<div class="col6"><label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Telefone<input name="phone" maxlength="30" value="' . h((string)($e['phone'] ?? '')) . '" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label></div>';
echo '</div>';

echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Cargo/Função<input name="role_title" maxlength="120" value="' . h((string)($e['role_title'] ?? '')) . '" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label>';

echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Status<select name="status" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px">';
$st = (string)$e['status'];
echo '<option value="active"' . ($st === 'active' ? ' selected' : '') . '>active</option>';
echo '<option value="inactive"' . ($st === 'inactive' ? ' selected' : '') . '>inactive</option>';
echo '</select></label>';

echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Observações<textarea name="notes" rows="3" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px">' . h((string)($e['notes'] ?? '')) . '</textarea></label>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<button class="btn btnPrimary" type="submit">Salvar</button>';
echo '<a class="btn" href="/hr_employees_list.php">Cancelar</a>';
echo '</div>';
echo '</form>';

echo '</div>';

view_footer();

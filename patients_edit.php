<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('patients.manage');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = db()->prepare('SELECT * FROM patients WHERE id = :id AND deleted_at IS NULL');
$stmt->execute(['id' => $id]);
$p = $stmt->fetch();

if (!$p) {
    flash_set('error', 'Paciente não encontrado.');
    header('Location: /patients_list.php');
    exit;
}

view_header('Editar paciente');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:800">Editar paciente</div>';
echo '<div style="margin-top:6px;color:rgba(234,240,255,.72);font-size:14px;line-height:1.6">' . h((string)$p['full_name']) . '</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/patients_view.php?id=' . (int)$p['id'] . '">Voltar</a>';
echo '<a class="btn" href="/patients_links_edit.php?id=' . (int)$p['id'] . '">Vínculos</a>';
echo '</div>';
echo '</div>';
echo '</section>';

echo '<section class="card col12">';
echo '<form method="post" action="/patients_edit_post.php" style="display:grid;gap:12px;max-width:980px">';
echo '<input type="hidden" name="id" value="' . (int)$p['id'] . '">';

echo '<div style="font-weight:800;margin-top:6px">Identificação</div>';
echo '<div class="grid">';
echo '<div class="col6"><label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Nome completo<input name="full_name" required maxlength="160" value="' . h((string)$p['full_name']) . '" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label></div>';
echo '<div class="col6"><label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">CPF<input name="cpf" maxlength="20" value="' . h((string)($p['cpf'] ?? '')) . '" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label></div>';
echo '<div class="col6"><label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">RG<input name="rg" maxlength="30" value="' . h((string)($p['rg'] ?? '')) . '" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label></div>';
echo '<div class="col6"><label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Data de nascimento<input type="date" name="birth_date" value="' . h((string)($p['birth_date'] ?? '')) . '" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:10px 12px;outline:none;font-size:14px"></label></div>';
echo '</div>';

echo '<div style="font-weight:800;margin-top:6px">Contato</div>';
echo '<div class="grid">';
echo '<div class="col6"><label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">WhatsApp<input name="whatsapp" maxlength="30" value="' . h((string)($p['whatsapp'] ?? '')) . '" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label></div>';
echo '<div class="col6"><label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">E-mail<input type="email" name="email" maxlength="190" value="' . h((string)($p['email'] ?? '')) . '" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label></div>';
echo '</div>';

echo '<div style="font-weight:800;margin-top:6px">Endereço</div>';
echo '<div class="grid">';
echo '<div class="col6"><label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">CEP<input name="address_zip" maxlength="12" value="' . h((string)($p['address_zip'] ?? '')) . '" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label></div>';
echo '<div class="col6"><label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Logradouro<input name="address_street" maxlength="160" value="' . h((string)($p['address_street'] ?? '')) . '" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label></div>';
echo '<div class="col6"><label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Número<input name="address_number" maxlength="20" value="' . h((string)($p['address_number'] ?? '')) . '" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label></div>';
echo '<div class="col6"><label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Complemento<input name="address_complement" maxlength="80" value="' . h((string)($p['address_complement'] ?? '')) . '" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label></div>';
echo '<div class="col6"><label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Bairro<input name="address_neighborhood" maxlength="80" value="' . h((string)($p['address_neighborhood'] ?? '')) . '" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label></div>';
echo '<div class="col6"><label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Cidade<input name="address_city" maxlength="120" value="' . h((string)($p['address_city'] ?? '')) . '" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label></div>';
echo '<div class="col6"><label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">UF<input name="address_state" maxlength="2" value="' . h((string)($p['address_state'] ?? '')) . '" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px;text-transform:uppercase"></label></div>';
echo '</div>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:6px">';
echo '<button class="btn btnPrimary" type="submit">Salvar</button>';
echo '<a class="btn" href="/patients_view.php?id=' . (int)$p['id'] . '">Cancelar</a>';
echo '</div>';

echo '</form>';
echo '</section>';

echo '</div>';

view_footer();

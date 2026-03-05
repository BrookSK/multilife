<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('whatsapp_groups.manage');

$stmt = db()->prepare(
    "SELECT u.id, u.name, u.email, u.phone\n"
    . "FROM users u\n"
    . "INNER JOIN user_roles ur ON ur.user_id = u.id\n"
    . "INNER JOIN roles r ON r.id = ur.role_id\n"
    . "WHERE u.status = 'active' AND r.slug = 'profissional'\n"
    . "ORDER BY u.name ASC"
);
$stmt->execute();
$professionals = $stmt->fetchAll();

view_header('Criar grupo via Evolution');

echo '<div class="card">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900;margin-bottom:6px">Criar grupo via Evolution</div>';
echo '<div style="color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Cria o grupo na Evolution e salva no sistema.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/whatsapp_groups_list.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<div style="height:14px"></div>';

echo '<form method="post" action="/whatsapp_groups_create_evolution_post.php" style="display:grid;gap:12px;max-width:860px">';
echo '<label>Nome do grupo (subject)<input name="subject" required maxlength="160" placeholder="Ex: Fisio - SP"></label>';
echo '<label>Profissionais participantes (cadastro interno)<select name="professional_user_ids[]" multiple required size="10">';
foreach ($professionals as $p) {
    $label = (string)$p['name'] . ' — ' . (string)$p['email'];
    $phone = trim((string)($p['phone'] ?? ''));
    if ($phone !== '') {
        $label .= ' — ' . $phone;
    } else {
        $label .= ' — (sem telefone)';
    }
    echo '<option value="' . (int)$p['id'] . '">' . h($label) . '</option>';
}
echo '</select></label>';
echo '<label>Descrição (opcional)<textarea name="description" rows="3"></textarea></label>';

echo '<div class="grid" style="gap:12px">';
echo '<div class="col6"><label>Especialidade (opcional)<input name="specialty" maxlength="120" placeholder="Ex: Fisioterapia"></label></div>';
echo '<div class="col6"><label>Status<select name="status"><option value="active">active</option><option value="inactive">inactive</option></select></label></div>';
echo '<div class="col6"><label>Cidade (opcional)<input name="city" maxlength="120" placeholder="Ex: São Paulo"></label></div>';
echo '<div class="col6"><label>UF (opcional)<input name="state" maxlength="2" placeholder="SP" style="text-transform:uppercase"></label></div>';
echo '</div>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end">';
echo '<a class="btn" href="/whatsapp_groups_list.php">Cancelar</a>';
echo '<button class="btn btnPrimary" type="submit">Criar e salvar</button>';
echo '</div>';

echo '</form>';
echo '</div>';

echo '</div>';

view_footer();

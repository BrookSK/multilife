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

// Buscar especialidades
$specStmt = db()->query('SELECT id, name FROM specialties WHERE status = "active" ORDER BY name ASC');
$specialties = $specStmt->fetchAll();

// Buscar estados brasileiros
$states = [
    'AC' => 'Acre', 'AL' => 'Alagoas', 'AP' => 'Amapá', 'AM' => 'Amazonas',
    'BA' => 'Bahia', 'CE' => 'Ceará', 'DF' => 'Distrito Federal', 'ES' => 'Espírito Santo',
    'GO' => 'Goiás', 'MA' => 'Maranhão', 'MT' => 'Mato Grosso', 'MS' => 'Mato Grosso do Sul',
    'MG' => 'Minas Gerais', 'PA' => 'Pará', 'PB' => 'Paraíba', 'PR' => 'Paraná',
    'PE' => 'Pernambuco', 'PI' => 'Piauí', 'RJ' => 'Rio de Janeiro', 'RN' => 'Rio Grande do Norte',
    'RS' => 'Rio Grande do Sul', 'RO' => 'Rondônia', 'RR' => 'Roraima', 'SC' => 'Santa Catarina',
    'SP' => 'São Paulo', 'SE' => 'Sergipe', 'TO' => 'Tocantins'
];

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

echo '<form method="post" action="/whatsapp_groups_create_evolution_post.php" style="display:grid;gap:12px">';

echo '<label>Especialidade<select name="specialty_id" required id="specialtySelect">';
echo '<option value="">Selecione...</option>';
foreach ($specialties as $spec) {
    echo '<option value="' . (int)$spec['id'] . '">' . h((string)$spec['name']) . '</option>';
}
echo '</select></label>';

echo '<label>Localização (Estado)<select name="location" required id="locationSelect">';
echo '<option value="">Selecione...</option>';
foreach ($states as $uf => $name) {
    echo '<option value="' . h($uf) . '">' . h($uf) . ' - ' . h($name) . '</option>';
}
echo '</select></label>';

echo '<div style="padding:12px;background:hsla(var(--primary)/.05);border:1px solid hsl(var(--border));border-radius:8px">';
echo '<div style="font-size:13px;color:hsl(var(--muted-foreground))">';
echo '<strong>Nome do grupo será gerado automaticamente:</strong><br>';
echo '<span id="groupNamePreview" style="font-weight:600;color:hsl(var(--foreground))">Selecione especialidade e localização</span>';
echo '</div>';
echo '</div>';

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

echo '<label>Status<select name="status"><option value="active">Ativo</option><option value="inactive">Inativo</option></select></label>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end">';
echo '<a class="btn" href="/whatsapp_groups_list.php">Cancelar</a>';
echo '<button class="btn btnPrimary" type="submit">Criar e salvar</button>';
echo '</div>';

echo '</form>';

echo '<script>';
echo 'const specialtySelect = document.getElementById("specialtySelect");';
echo 'const locationSelect = document.getElementById("locationSelect");';
echo 'const preview = document.getElementById("groupNamePreview");';
echo 'const specialtiesData = ' . json_encode(array_column($specialties, 'name', 'id')) . ';';
echo 'function updatePreview(){';
echo '  const specId = specialtySelect.value;';
echo '  const location = locationSelect.value;';
echo '  if(specId && location){';
echo '    const specName = specialtiesData[specId];';
echo '    preview.textContent = specName + " - " + location + " - 1";';
echo '    preview.style.color = "hsl(var(--foreground))";';
echo '  }else{';
echo '    preview.textContent = "Selecione especialidade e localização";';
echo '    preview.style.color = "hsl(var(--muted-foreground))";';
echo '  }';
echo '}';
echo 'specialtySelect.addEventListener("change", updatePreview);';
echo 'locationSelect.addEventListener("change", updatePreview);';
echo '</script>';

echo '</div>';

echo '</div>';

view_footer();

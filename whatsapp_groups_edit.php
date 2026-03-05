<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('whatsapp_groups.manage');

// Buscar especialidades cadastradas
$specialtiesStmt = db()->query("SELECT id, name FROM specialties WHERE status = 'active' ORDER BY name ASC");
$specialties = $specialtiesStmt->fetchAll();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = db()->prepare('SELECT * FROM whatsapp_groups WHERE id = :id');
$stmt->execute(['id' => $id]);
$g = $stmt->fetch();

if (!$g) {
    flash_set('error', 'Grupo não encontrado.');
    header('Location: /whatsapp_groups_list.php');
    exit;
}

view_header('Editar grupo');

echo '<div class="card">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900;margin-bottom:6px">Editar grupo WhatsApp</div>';
echo '<div style="color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Atualize filtros: especialidade + cidade/UF.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/whatsapp_groups_list.php">Voltar</a>';
echo '<a class="btn" href="/whatsapp_groups_members_evolution.php?id=' . (int)$g['id'] . '">Membros (Evolution)</a>';
echo '</div>';
echo '</div>';

echo '<div style="height:14px"></div>';

echo '<form method="post" action="/whatsapp_groups_edit_post.php" style="display:grid;gap:12px;max-width:820px">';
echo '<input type="hidden" name="id" value="' . (int)$g['id'] . '">';
echo '<label>Nome<input name="name" required maxlength="160" value="' . h((string)$g['name']) . '" placeholder="Nome do grupo"></label>';

echo '<div class="grid" style="gap:12px">';
echo '<div class="col6">';
echo '<label>Evolution Group JID (obrigatório)<input name="evolution_group_jid" required maxlength="120" value="' . h((string)($g['evolution_group_jid'] ?? '')) . '" placeholder="Ex: 1203xxxxxx@g.us"></label>';
echo '</div>';
echo '<div class="col6">';
echo '<label>Número de contatos (opcional)<input type="number" name="contacts_count" min="0" value="' . h((string)($g['contacts_count'] ?? '')) . '" placeholder="Ex: 256"></label>';
echo '</div>';
echo '</div>';

echo '<div class="grid" style="gap:12px">';
echo '<div class="col6">';
echo '<label>Especialidade (opcional)<select name="specialty"><option value="">Selecione...</option>';
$currentSpecialty = (string)($g['specialty'] ?? '');
foreach ($specialties as $spec) {
    $selected = ($currentSpecialty === (string)$spec['name']) ? ' selected' : '';
    echo '<option value="' . h((string)$spec['name']) . '"' . $selected . '>' . h((string)$spec['name']) . '</option>';
}
echo '</select></label>';
echo '</div>';
echo '<div class="col6">';
echo '<label>Status<select name="status">';
$st = (string)$g['status'];
echo '<option value="active"' . ($st === 'active' ? ' selected' : '') . '>active</option>';
echo '<option value="inactive"' . ($st === 'inactive' ? ' selected' : '') . '>inactive</option>';
echo '</select></label>';
echo '</div>';
echo '</div>';

$currentState = (string)($g['state'] ?? '');
$currentCity = (string)($g['city'] ?? '');

echo '<div class="grid" style="gap:12px">';
echo '<div class="col6">';
echo '<label>UF (opcional)<select name="state" id="edit_group_state"><option value="">Selecione...</option>';
$states = ['AC'=>'Acre','AL'=>'Alagoas','AP'=>'Amapá','AM'=>'Amazonas','BA'=>'Bahia','CE'=>'Ceará','DF'=>'Distrito Federal','ES'=>'Espírito Santo','GO'=>'Goiás','MA'=>'Maranhão','MT'=>'Mato Grosso','MS'=>'Mato Grosso do Sul','MG'=>'Minas Gerais','PA'=>'Pará','PB'=>'Paraíba','PR'=>'Paraná','PE'=>'Pernambuco','PI'=>'Piauí','RJ'=>'Rio de Janeiro','RN'=>'Rio Grande do Norte','RS'=>'Rio Grande do Sul','RO'=>'Rondônia','RR'=>'Roraima','SC'=>'Santa Catarina','SP'=>'São Paulo','SE'=>'Sergipe','TO'=>'Tocantins'];
foreach ($states as $uf => $nome) {
    $selected = ($currentState === $uf) ? ' selected' : '';
    echo '<option value="' . $uf . '"' . $selected . '>' . $uf . ' - ' . $nome . '</option>';
}
echo '</select></label>';
echo '</div>';
echo '<div class="col6">';
echo '<label>Cidade (opcional)<select name="city" id="edit_group_city"><option value="">Carregando...</option></select></label>';
echo '</div>';
echo '</div>';

echo '<script>';
echo 'const editGroupUfSelect = document.getElementById("edit_group_state");';
echo 'const editGroupCitySelect = document.getElementById("edit_group_city");';
echo 'const currentCity = ' . json_encode($currentCity) . ';';
echo 'const currentState = ' . json_encode($currentState) . ';';
echo 'async function loadEditCities(uf, preselect = "") {';
echo '  editGroupCitySelect.innerHTML = "<option value=\\"\\">Carregando...</option>";';
echo '  editGroupCitySelect.disabled = true;';
echo '  if(!uf){';
echo '    editGroupCitySelect.innerHTML = "<option value=\\"\\">Selecione o estado primeiro...</option>";';
echo '    editGroupCitySelect.disabled = false;';
echo '    return;';
echo '  }';
echo '  try{';
echo '    const response = await fetch(`https://servicodados.ibge.gov.br/api/v1/localidades/estados/${uf}/municipios?orderBy=nome`);';
echo '    const cidades = await response.json();';
echo '    editGroupCitySelect.innerHTML = "<option value=\\"\\">Selecione...</option>";';
echo '    cidades.forEach(function(cidade){';
echo '      const opt = document.createElement("option");';
echo '      opt.value = cidade.nome;';
echo '      opt.textContent = cidade.nome;';
echo '      if(cidade.nome === preselect) opt.selected = true;';
echo '      editGroupCitySelect.appendChild(opt);';
echo '    });';
echo '    editGroupCitySelect.disabled = false;';
echo '  }catch(err){';
echo '    console.error("Erro ao buscar cidades:", err);';
echo '    editGroupCitySelect.innerHTML = "<option value=\\"\\">Erro ao carregar cidades</option>";';
echo '    editGroupCitySelect.disabled = false;';
echo '  }';
echo '}';
echo 'if(currentState) loadEditCities(currentState, currentCity);';
echo 'else editGroupCitySelect.innerHTML = "<option value=\\"\\">Selecione o estado primeiro...</option>";';
echo 'editGroupUfSelect.addEventListener("change", function(){ loadEditCities(this.value); });';
echo '</script>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end">';
echo '<a class="btn" href="/whatsapp_groups_list.php">Cancelar</a>';
echo '<button class="btn btnPrimary" type="submit">Salvar</button>';
echo '</div>';
echo '</form>';

echo '</div>';

view_footer();

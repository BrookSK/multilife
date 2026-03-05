<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('whatsapp_groups.manage');

// Buscar especialidades cadastradas
$specialtiesStmt = db()->query("SELECT id, name FROM specialties WHERE status = 'active' ORDER BY name ASC");
$specialties = $specialtiesStmt->fetchAll();

view_header('Novo grupo WhatsApp');

echo '<div class="card">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900;margin-bottom:6px">Novo grupo WhatsApp</div>';
echo '<div style="color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Cadastre filtros: especialidade + cidade/UF.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/whatsapp_groups_list.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<div style="height:14px"></div>';

echo '<form method="post" action="/whatsapp_groups_create_post.php" style="display:grid;gap:12px;max-width:820px">';
echo '<label>Nome<input name="name" required maxlength="160" placeholder="Nome do grupo"></label>';

echo '<div class="grid" style="gap:12px">';
echo '<div class="col6">';
echo '<label>Evolution Group JID (obrigatório)<input name="evolution_group_jid" required maxlength="120" placeholder="Ex: 1203xxxxxx@g.us"></label>';
echo '</div>';
echo '<div class="col6">';
echo '<label>Número de contatos (opcional)<input type="number" name="contacts_count" min="0" placeholder="Ex: 256"></label>';
echo '</div>';
echo '</div>';

echo '<div class="grid" style="gap:12px">';
echo '<div class="col6">';
echo '<label>Especialidade (opcional)<select name="specialty"><option value="">Selecione...</option>';
foreach ($specialties as $spec) {
    echo '<option value="' . h((string)$spec['name']) . '">' . h((string)$spec['name']) . '</option>';
}
echo '</select></label>';
echo '</div>';
echo '<div class="col6">';
echo '<label>Status<select name="status">';
echo '<option value="active">active</option>';
echo '<option value="inactive">inactive</option>';
echo '</select></label>';
echo '</div>';
echo '</div>';

echo '<div class="grid" style="gap:12px">';
echo '<div class="col6">';
echo '<label>UF (opcional)<select name="state" id="group_state"><option value="">Selecione...</option><option value="AC">AC - Acre</option><option value="AL">AL - Alagoas</option><option value="AP">AP - Amapá</option><option value="AM">AM - Amazonas</option><option value="BA">BA - Bahia</option><option value="CE">CE - Ceará</option><option value="DF">DF - Distrito Federal</option><option value="ES">ES - Espírito Santo</option><option value="GO">GO - Goiás</option><option value="MA">MA - Maranhão</option><option value="MT">MT - Mato Grosso</option><option value="MS">MS - Mato Grosso do Sul</option><option value="MG">MG - Minas Gerais</option><option value="PA">PA - Pará</option><option value="PB">PB - Paraíba</option><option value="PR">PR - Paraná</option><option value="PE">PE - Pernambuco</option><option value="PI">PI - Piauí</option><option value="RJ">RJ - Rio de Janeiro</option><option value="RN">RN - Rio Grande do Norte</option><option value="RS">RS - Rio Grande do Sul</option><option value="RO">RO - Rondônia</option><option value="RR">RR - Roraima</option><option value="SC">SC - Santa Catarina</option><option value="SP">SP - São Paulo</option><option value="SE">SE - Sergipe</option><option value="TO">TO - Tocantins</option></select></label>';
echo '</div>';
echo '<div class="col6">';
echo '<label>Cidade (opcional)<select name="city" id="group_city"><option value="">Selecione o estado primeiro...</option></select></label>';
echo '</div>';
echo '</div>';

echo '<script>';
echo 'const groupUfSelect = document.getElementById("group_state");';
echo 'const groupCitySelect = document.getElementById("group_city");';
echo 'if(groupUfSelect && groupCitySelect){';
echo '  groupUfSelect.addEventListener("change", async function(){';
echo '    const uf = this.value;';
echo '    groupCitySelect.innerHTML = "<option value=\\"\\">Carregando...</option>";';
echo '    groupCitySelect.disabled = true;';
echo '    if(!uf){';
echo '      groupCitySelect.innerHTML = "<option value=\\"\\">Selecione o estado primeiro...</option>";';
echo '      groupCitySelect.disabled = false;';
echo '      return;';
echo '    }';
echo '    try{';
echo '      const response = await fetch(`https://servicodados.ibge.gov.br/api/v1/localidades/estados/${uf}/municipios?orderBy=nome`);';
echo '      const cidades = await response.json();';
echo '      groupCitySelect.innerHTML = "<option value=\\"\\">Selecione...</option>";';
echo '      cidades.forEach(function(cidade){';
echo '        const opt = document.createElement("option");';
echo '        opt.value = cidade.nome;';
echo '        opt.textContent = cidade.nome;';
echo '        groupCitySelect.appendChild(opt);';
echo '      });';
echo '      groupCitySelect.disabled = false;';
echo '    }catch(err){';
echo '      console.error("Erro ao buscar cidades:", err);';
echo '      groupCitySelect.innerHTML = "<option value=\\"\\">Erro ao carregar cidades</option>";';
echo '      groupCitySelect.disabled = false;';
echo '    }';
echo '  });';
echo '}';
echo '</script>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end">';
echo '<a class="btn" href="/whatsapp_groups_list.php">Cancelar</a>';
echo '<button class="btn btnPrimary" type="submit">Salvar</button>';
echo '</div>';
echo '</form>';

echo '</div>';

view_footer();

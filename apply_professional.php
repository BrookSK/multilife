<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

// Página pública - não requer autenticação
$user = auth_user();
$isPublic = ($user === null);

// Buscar especialidades cadastradas no sistema
$specialtiesStmt = db()->query("SELECT id, name FROM specialties WHERE status = 'active' ORDER BY name ASC");
$specialties = $specialtiesStmt->fetchAll();

if ($isPublic) {
    // Renderizar header público sem menu
    echo '<!doctype html>';
    echo '<html lang="pt-BR">';
    echo '<head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>Candidatura de Profissional - MultiLife Care</title>';
    echo '<style>';
    echo "@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');";
    echo ':root{--background:216 33% 97%;--foreground:210 36% 17%;--card:0 0% 100%;--card-foreground:210 36% 17%;--primary:180 65% 46%;--primary-foreground:0 0% 100%;--primary-dark:180 71% 36%;--muted:216 33% 97%;--muted-foreground:216 18% 61%;--border:216 20% 90%;--input:216 20% 90%;--ring:180 65% 46%;--radius:0.625rem;--shadow-card:0 1px 3px 0 rgba(0,0,0,.06),0 1px 2px -1px rgba(0,0,0,.06);--shadow-elevated:0 10px 25px -5px rgba(0,0,0,.08),0 8px 10px -6px rgba(0,0,0,.04);}';
    echo '*{box-sizing:border-box;border-color:hsl(var(--border))}';
    echo 'html,body{height:100%}';
    echo 'body{margin:0;font-family:Inter,ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial;min-height:100vh;color:hsl(var(--foreground));background:hsl(var(--background));}';
    echo 'input,select,textarea{font-family:inherit}';
    echo 'input:not([type="checkbox"]):not([type="radio"]):not([type="file"]),select,textarea{width:100%;border-radius:10px;border:1px solid hsl(var(--input));background:hsla(var(--muted)/.50);color:hsl(var(--foreground));padding:10px 12px;outline:none;font-size:14px;transition:background .15s ease,box-shadow .15s ease,border-color .15s ease}';
    echo 'textarea{min-height:96px;resize:vertical}';
    echo 'input:not([type="checkbox"]):not([type="radio"]):not([type="file"]):focus,select:focus,textarea:focus{background:hsl(var(--card));border-color:hsla(var(--ring)/.55);box-shadow:0 0 0 4px hsla(var(--ring)/.15)}';
    echo '::placeholder{color:hsl(var(--muted-foreground))}';
    echo 'label{display:grid;gap:7px;font-size:13px;font-weight:600;color:hsl(var(--foreground))}';
    
    // Checkbox moderno
    echo 'input[type="checkbox"]{appearance:none;-webkit-appearance:none;width:18px;height:18px;border:2px solid hsl(var(--input));border-radius:4px;background:hsl(var(--card));cursor:pointer;position:relative;transition:all .15s ease;margin:0}';
    echo 'input[type="checkbox"]:checked{background:hsl(var(--primary));border-color:hsl(var(--primary))}';
    echo 'input[type="checkbox"]:checked::after{content:"";position:absolute;left:5px;top:2px;width:4px;height:8px;border:solid hsl(var(--primary-foreground));border-width:0 2px 2px 0;transform:rotate(45deg)}';
    echo 'input[type="checkbox"]:focus{outline:none;box-shadow:0 0 0 3px hsla(var(--ring)/.15)}';
    echo 'input[type="checkbox"]:hover:not(:disabled){border-color:hsl(var(--primary))}';
    
    // Radio moderno
    echo 'input[type="radio"]{appearance:none;-webkit-appearance:none;width:18px;height:18px;border:2px solid hsl(var(--input));border-radius:50%;background:hsl(var(--card));cursor:pointer;position:relative;transition:all .15s ease;margin:0}';
    echo 'input[type="radio"]:checked{border-color:hsl(var(--primary));border-width:5px}';
    echo 'input[type="radio"]:focus{outline:none;box-shadow:0 0 0 3px hsla(var(--ring)/.15)}';
    echo 'input[type="radio"]:hover:not(:disabled){border-color:hsl(var(--primary))}';
    
    // File input moderno
    echo 'input[type="file"]{padding:8px 12px;border:1px solid hsl(var(--input));border-radius:10px;background:hsl(var(--card));color:hsl(var(--foreground));font-size:14px;cursor:pointer;transition:all .15s ease}';
    echo 'input[type="file"]:hover{border-color:hsl(var(--primary));background:hsla(var(--primary)/.05)}';
    echo 'input[type="file"]:focus{outline:none;border-color:hsla(var(--ring)/.55);box-shadow:0 0 0 4px hsla(var(--ring)/.15)}';
    echo 'input[type="file"]::file-selector-button{padding:6px 12px;margin-right:12px;border:none;border-radius:6px;background:hsl(var(--primary));color:hsl(var(--primary-foreground));font-weight:600;font-size:13px;cursor:pointer;transition:background .15s ease}';
    echo 'input[type="file"]::file-selector-button:hover{background:hsl(var(--primary-dark))}';
    
    echo 'form{display:grid;gap:14px}';
    echo '.formSection{padding:18px;border-radius:12px;background:hsla(var(--muted)/.25);border:1px solid hsl(var(--border));margin-bottom:14px}';
    echo '.formSectionTitle{font-size:15px;font-weight:800;color:hsl(var(--foreground));margin-bottom:14px;padding-bottom:10px;border-bottom:1px solid hsl(var(--border))}';
    echo '.card{background:hsl(var(--card));border:1px solid hsl(var(--border));box-shadow:var(--shadow-elevated);border-radius:calc(var(--radius) + 6px);padding:18px;color:hsl(var(--card-foreground))}';
    echo '.grid{display:grid;grid-template-columns:repeat(12,1fr);gap:14px}';
    echo '.col6{grid-column:span 6}';
echo '.col12{grid-column:span 12}';
    echo '.btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 12px;border-radius:10px;border:1px solid hsl(var(--border));background:hsl(var(--card));color:hsl(var(--foreground));font-weight:600;font-size:13px;box-shadow:var(--shadow-card);transition:box-shadow .15s ease,transform .06s ease,background .15s ease;text-decoration:none}';
    echo '.btn:hover{box-shadow:0 4px 12px 0 rgba(0,0,0,.08),0 2px 4px -1px rgba(0,0,0,.06);text-decoration:none}';
    echo '.btn:active{transform:translateY(1px)}';
    echo '.btnPrimary{border-color:transparent;background:hsl(var(--primary));color:hsl(var(--primary-foreground))}';
    echo '.btnPrimary:hover{background:hsl(var(--primary-dark))}';
    echo '@media(max-width:860px){.col6{grid-column:span 12}}';
    echo '</style>';
    echo '</head>';
    echo '<body>';
    echo '<div style="max-width:1100px;margin:0 auto;padding:24px">';
} else {
    view_header('Candidatura de Profissional');
}

echo '<div class="card">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900;margin-bottom:6px">Candidatura de Profissional - MultiLife Care</div>';
echo '<div style="color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Preencha seus dados para avaliação. Após aprovação, você receberá acesso ao sistema.</div>';
echo '</div>';
if (!$isPublic) {
    echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
    echo '<a class="btn" href="/professional_applications_list.php">Voltar</a>';
    echo '</div>';
}
echo '</div>';

echo '<div style="height:14px"></div>';

// Exibir mensagens flash
$successMsg = flash_get('success');
$errorMsg = flash_get('error');

if ($successMsg !== '') {
    echo '<div style="padding:12px 16px;border-radius:10px;background:hsl(142,76%,36%);color:#fff;font-weight:600;margin-bottom:14px">';
    echo h($successMsg);
    echo '</div>';
}

if ($errorMsg !== '') {
    echo '<div style="padding:12px 16px;border-radius:10px;background:hsl(0,84%,60%);color:#fff;font-weight:600;margin-bottom:14px">';
    echo h($errorMsg);
    echo '</div>';
}

echo '<!-- FORMULARIO INICIADO - PHP EXECUTANDO CORRETAMENTE -->';
echo '<form method="post" action="/apply_professional_post.php" style="display:grid;gap:12px">';

echo '<div class="grid">';

echo '<div class="col6">';
echo '<label>Nome completo<input name="full_name" required maxlength="160" placeholder="Nome completo"></label>';
echo '</div>';

echo '<div class="col6">';
echo '<label>E-mail<input type="email" name="email" required maxlength="190" placeholder="email@empresa.com"></label>';
echo '</div>';

echo '<div class="col6">';
echo '<label>Telefone/WhatsApp<input name="phone" required maxlength="30" placeholder="5511999999999"></label>';
echo '</div>';

echo '<div class="col6">';
echo '<label>UF de atuação<select name="operation_state" id="operation_state"><option value="">Selecione...</option><option value="AC">AC - Acre</option><option value="AL">AL - Alagoas</option><option value="AP">AP - Amapá</option><option value="AM">AM - Amazonas</option><option value="BA">BA - Bahia</option><option value="CE">CE - Ceará</option><option value="DF">DF - Distrito Federal</option><option value="ES">ES - Espírito Santo</option><option value="GO">GO - Goiás</option><option value="MA">MA - Maranhão</option><option value="MT">MT - Mato Grosso</option><option value="MS">MS - Mato Grosso do Sul</option><option value="MG">MG - Minas Gerais</option><option value="PA">PA - Pará</option><option value="PB">PB - Paraíba</option><option value="PR">PR - Paraná</option><option value="PE">PE - Pernambuco</option><option value="PI">PI - Piauí</option><option value="RJ">RJ - Rio de Janeiro</option><option value="RN">RN - Rio Grande do Norte</option><option value="RS">RS - Rio Grande do Sul</option><option value="RO">RO - Rondônia</option><option value="RR">RR - Roraima</option><option value="SC">SC - Santa Catarina</option><option value="SP">SP - São Paulo</option><option value="SE">SE - Sergipe</option><option value="TO">TO - Tocantins</option></select></label>';
echo '</div>';

echo '<div class="col12">';
echo '<label style="display:block;margin-bottom:8px">Cidades de atuação</label>';
echo '<div id="cities_checkboxes" style="max-height:300px;overflow-y:auto;border:1px solid hsl(var(--border));border-radius:8px;padding:12px;background:hsl(var(--background))"><p style="color:hsl(var(--muted-foreground));font-size:14px">Selecione o estado de atuação primeiro...</p></div>';
echo '</div>';

echo '</div>';

echo '<div style="font-weight:900;margin-top:6px">Identificação</div>';

echo '<div class="grid">';
echo '<div class="col6"><label>Estado civil<select name="marital_status"><option value="">Selecione...</option><option value="Solteiro(a)">Solteiro(a)</option><option value="Casado(a)">Casado(a)</option><option value="Divorciado(a)">Divorciado(a)</option><option value="Viúvo(a)">Viúvo(a)</option><option value="União Estável">União Estável</option></select></label></div>';
echo '<div class="col6"><label>Sexo<select name="sex"><option value="">Selecione...</option><option value="Masculino">Masculino</option><option value="Feminino">Feminino</option><option value="Outro">Outro</option></select></label></div>';
echo '<div class="col6"><label>Religião<input name="religion" maxlength="60"></label></div>';
echo '<div class="col6"><label>Naturalidade<input name="birthplace" maxlength="120"></label></div>';
echo '<div class="col6"><label>Nacionalidade<input name="nationality" maxlength="80"></label></div>';
echo '<div class="col6"><label>Escolaridade<select name="education_level"><option value="">Selecione...</option><option value="Ensino Fundamental">Ensino Fundamental</option><option value="Ensino Médio">Ensino Médio</option><option value="Ensino Superior">Ensino Superior</option><option value="Pós-graduação">Pós-graduação</option><option value="Mestrado">Mestrado</option><option value="Doutorado">Doutorado</option></select></label></div>';
echo '</div>';

echo '<div style="font-weight:900;margin-top:6px">Endereço</div>';

echo '<div class="grid">';
echo '<div class="col6"><label>Logradouro<input name="address_street" maxlength="160"></label></div>';
echo '<div class="col6"><label>Número<input name="address_number" maxlength="20"></label></div>';
echo '<div class="col6"><label>Complemento<input name="address_complement" maxlength="80"></label></div>';
echo '<div class="col6"><label>Bairro<input name="address_neighborhood" maxlength="80"></label></div>';
echo '<div class="col6"><label>UF<select name="address_state" id="address_state"><option value="">Selecione...</option><option value="AC">AC - Acre</option><option value="AL">AL - Alagoas</option><option value="AP">AP - Amapá</option><option value="AM">AM - Amazonas</option><option value="BA">BA - Bahia</option><option value="CE">CE - Ceará</option><option value="DF">DF - Distrito Federal</option><option value="ES">ES - Espírito Santo</option><option value="GO">GO - Goiás</option><option value="MA">MA - Maranhão</option><option value="MT">MT - Mato Grosso</option><option value="MS">MS - Mato Grosso do Sul</option><option value="MG">MG - Minas Gerais</option><option value="PA">PA - Pará</option><option value="PB">PB - Paraíba</option><option value="PR">PR - Paraná</option><option value="PE">PE - Pernambuco</option><option value="PI">PI - Piauí</option><option value="RJ">RJ - Rio de Janeiro</option><option value="RN">RN - Rio Grande do Norte</option><option value="RS">RS - Rio Grande do Sul</option><option value="RO">RO - Rondônia</option><option value="RR">RR - Roraima</option><option value="SC">SC - Santa Catarina</option><option value="SP">SP - São Paulo</option><option value="SE">SE - Sergipe</option><option value="TO">TO - Tocantins</option></select></label></div>';
echo '<div class="col6"><label>Cidade<select name="address_city" id="address_city"><option value="">Selecione o estado primeiro...</option></select></label></div>';
echo '<div class="col6"><label>CEP<input name="address_zip" maxlength="12" placeholder="00000-000"></label></div>';
echo '</div>';

echo '<div style="font-weight:900;margin-top:6px">Documentos</div>';

echo '<div class="grid">';
echo '<div class="col6"><label>RG<input name="rg" maxlength="30"></label></div>';
echo '<div class="col6"><label>Sigla do Conselho<select name="council_abbr"><option value="">Selecione...</option><option value="COREN">COREN - Enfermagem</option><option value="CRM">CRM - Medicina</option><option value="CRF">CRF - Farmácia</option><option value="CREFITO">CREFITO - Fisioterapia/Terapia Ocupacional</option><option value="CRN">CRN - Nutrição</option><option value="CREFONO">CREFONO - Fonoaudiologia</option><option value="CRP">CRP - Psicologia</option><option value="CRBM">CRBM - Biomedicina</option><option value="Outro">Outro</option></select></label></div>';
echo '<div class="col6"><label>Número do Conselho<input name="council_number" maxlength="30"></label></div>';
echo '<div class="col6"><label>UF do Conselho<select name="council_state"><option value="">Selecione...</option><option value="AC">AC - Acre</option><option value="AL">AL - Alagoas</option><option value="AP">AP - Amapá</option><option value="AM">AM - Amazonas</option><option value="BA">BA - Bahia</option><option value="CE">CE - Ceará</option><option value="DF">DF - Distrito Federal</option><option value="ES">ES - Espírito Santo</option><option value="GO">GO - Goiás</option><option value="MA">MA - Maranhão</option><option value="MT">MT - Mato Grosso</option><option value="MS">MS - Mato Grosso do Sul</option><option value="MG">MG - Minas Gerais</option><option value="PA">PA - Pará</option><option value="PB">PB - Paraíba</option><option value="PR">PR - Paraná</option><option value="PE">PE - Pernambuco</option><option value="PI">PI - Piauí</option><option value="RJ">RJ - Rio de Janeiro</option><option value="RN">RN - Rio Grande do Norte</option><option value="RS">RS - Rio Grande do Sul</option><option value="RO">RO - Rondônia</option><option value="RR">RR - Roraima</option><option value="SC">SC - Santa Catarina</option><option value="SP">SP - São Paulo</option><option value="SE">SE - Sergipe</option><option value="TO">TO - Tocantins</option></select></label></div>';
echo '</div>';

echo '<div style="font-weight:900;margin-top:6px">Dados bancários</div>';

echo '<div class="grid">';
echo '<div class="col6"><label>Banco<input name="bank_name" maxlength="80"></label></div>';
echo '<div class="col6"><label>Agência<input name="bank_agency" maxlength="20"></label></div>';
echo '<div class="col6"><label>Conta<input name="bank_account" maxlength="30"></label></div>';
echo '<div class="col6"><label>Tipo de conta<select name="bank_account_type"><option value="">Selecione...</option><option value="Corrente">Corrente</option><option value="Poupança">Poupança</option><option value="Salário">Salário</option></select></label></div>';
echo '<div class="col6"><label>Titular<input name="bank_account_holder" maxlength="160"></label></div>';
echo '<div class="col6"><label>CPF do titular<input name="bank_account_holder_cpf" maxlength="20"></label></div>';
echo '<div class="col6"><label>PIX<input name="pix_key" maxlength="120"></label></div>';
echo '<div class="col6"><label>Titular do PIX<input name="pix_holder" maxlength="160"></label></div>';
echo '</div>';

echo '<div style="font-weight:900;margin-top:6px">Informações técnicas</div>';

echo '<label>Experiência em home care<textarea name="home_care_experience" rows="3"></textarea></label>';

echo '<div class="grid">';
echo '<div class="col12"><label>Tempo de atuação<input name="years_of_experience" maxlength="40" placeholder="Ex: 5 anos"></label></div>';
echo '</div>';

echo '<div style="margin-top:14px">';
echo '<label style="display:block;margin-bottom:8px;font-size:13px;font-weight:600">Especializações</label>';
echo '<div id="specializations_container" style="display:flex;flex-direction:column;gap:8px"></div>';
echo '<button type="button" id="add_specialization" class="btn" style="margin-top:8px;font-size:13px;padding:6px 12px">+ Adicionar Especialização</button>';
echo '</div>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:center;margin-top:24px">';
if ($isPublic) {
    echo '<button class="btn btnPrimary" type="submit" style="font-size:15px;padding:12px 32px">Enviar Candidatura</button>';
} else {
    echo '<a class="btn" href="/professional_applications_list.php">Cancelar</a>';
    echo '<button class="btn btnPrimary" type="submit">Enviar candidatura</button>';
}
echo '</div>';

echo '</form>';

echo '</div>';

// JavaScript para buscar cidades da API do IBGE
echo '<script>';

echo 'async function loadCities(uf, selectElement, placeholder = "Selecione...") {';
echo '  selectElement.innerHTML = "<option value=\\"\\">Carregando...</option>";';
echo '  selectElement.disabled = true;';
echo '  if(!uf){';
echo '    selectElement.innerHTML = `<option value=\\"\\">${placeholder}</option>`;';
echo '    selectElement.disabled = false;';
echo '    return;';
echo '  }';
echo '  try{';
echo '    const response = await fetch(`https://servicodados.ibge.gov.br/api/v1/localidades/estados/${uf}/municipios?orderBy=nome`);';
echo '    const cidades = await response.json();';
echo '    selectElement.innerHTML = `<option value=\\"\\">${placeholder}</option>`;';
echo '    cidades.forEach(function(cidade){';
echo '      const opt = document.createElement("option");';
echo '      opt.value = cidade.nome;';
echo '      opt.textContent = cidade.nome;';
echo '      selectElement.appendChild(opt);';
echo '    });';
echo '    selectElement.disabled = false;';
echo '  }catch(err){';
echo '    console.error("Erro ao buscar cidades:", err);';
echo '    selectElement.innerHTML = "<option value=\\"\\">Erro ao carregar cidades</option>";';
echo '    selectElement.disabled = false;';
echo '  }';
echo '}';

echo 'const ufSelect = document.getElementById("address_state");';
echo 'const cidadeSelect = document.getElementById("address_city");';
echo 'if(ufSelect && cidadeSelect){';
echo '  ufSelect.addEventListener("change", function(){ loadCities(this.value, cidadeSelect, "Selecione..."); });';
echo '}';

echo 'const operationUfSelect = document.getElementById("operation_state");';
echo 'const citiesCheckboxesContainer = document.getElementById("cities_checkboxes");';
echo 'if(operationUfSelect && citiesCheckboxesContainer){';
echo '  operationUfSelect.addEventListener("change", async function(){';
echo '    const uf = this.value;';
echo '    citiesCheckboxesContainer.innerHTML = "<p style=\\"color:hsl(var(--muted-foreground));font-size:14px\\">Carregando...</p>";';
echo '    if(!uf){';
echo '      citiesCheckboxesContainer.innerHTML = "<p style=\\"color:hsl(var(--muted-foreground));font-size:14px\\">Selecione o estado de atuação primeiro...</p>";';
echo '      return;';
echo '    }';
echo '    try{';
echo '      const response = await fetch(`https://servicodados.ibge.gov.br/api/v1/localidades/estados/${uf}/municipios?orderBy=nome`);';
echo '      const cidades = await response.json();';
echo '      let html = "<div style=\\"display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:8px\\">";';
echo '      cidades.forEach(function(cidade){';
echo '        html += `<label style="display:flex;align-items:center;gap:6px;cursor:pointer;padding:4px"><input type="checkbox" name="cities_of_operation[]" value="${cidade.nome}" style="cursor:pointer"><span style="font-size:14px">${cidade.nome}</span></label>`;';
echo '      });';
echo '      html += "</div>";';
echo '      citiesCheckboxesContainer.innerHTML = html;';
echo '    }catch(err){';
echo '      console.error("Erro ao buscar cidades:", err);';
echo '      citiesCheckboxesContainer.innerHTML = "<p style=\\"color:hsl(0,84%,60%);font-size:14px\\">Erro ao carregar cidades</p>";';
echo '    }';
echo '  });';
echo '}';
echo '';
echo '// Especializações dinâmicas';
echo 'const specializationsContainer = document.getElementById("specializations_container");';
echo 'const addSpecializationBtn = document.getElementById("add_specialization");';
echo '';
echo 'if(specializationsContainer && addSpecializationBtn) {';
echo '  function addSpecializationField(value = "") {';
echo '    const div = document.createElement("div");';
echo '    div.style.display = "flex";';
echo '    div.style.gap = "8px";';
echo '    div.style.alignItems = "center";';
echo '    div.innerHTML = `<input type="text" name="specializations[]" value="${value}" maxlength="120" placeholder="Ex: Fisioterapia" style="flex:1"><button type="button" class="remove-spec" style="padding:6px 12px;background:hsl(0,84%,60%);color:white;border:none;border-radius:6px;cursor:pointer;font-size:13px">×</button>`;';
echo '    specializationsContainer.appendChild(div);';
echo '    const removeBtn = div.querySelector(".remove-spec");';
echo '    if(removeBtn) {';
echo '      removeBtn.addEventListener("click", function(){ div.remove(); });';
echo '    }';
echo '  }';
echo '  ';
echo '  // Adicionar primeira linha';
echo '  addSpecializationField();';
echo '  ';
echo '  // Event listener para botão adicionar';
echo '  addSpecializationBtn.addEventListener("click", function(e){ ';
echo '    e.preventDefault();';
echo '    addSpecializationField(); ';
echo '  });';
echo '}';
echo '</script>';

if ($isPublic) {
    echo '</div>';
    echo '</body>';
    echo '</html>';
} else {
    view_footer();
}

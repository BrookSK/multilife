<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('patients.manage');

// Buscar especialidades cadastradas
$specialtiesStmt = db()->query("SELECT id, name FROM specialties WHERE status = 'active' ORDER BY name ASC");
$specialties = $specialtiesStmt->fetchAll();

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

$healthArr = [];
if (!empty($p['health_json'])) {
    $decoded = json_decode((string)$p['health_json'], true);
    if (is_array($decoded)) {
        $healthArr = $decoded;
    }
}

$healthAllergies = isset($healthArr['allergies']) ? (string)$healthArr['allergies'] : '';
$healthMedications = isset($healthArr['medications']) ? (string)$healthArr['medications'] : '';
$healthConditions = isset($healthArr['conditions']) ? (string)$healthArr['conditions'] : '';
$healthRestrictions = isset($healthArr['restrictions']) ? (string)$healthArr['restrictions'] : '';
$healthBloodType = isset($healthArr['blood_type']) ? (string)$healthArr['blood_type'] : '';
$healthNotes = isset($healthArr['notes']) ? (string)$healthArr['notes'] : '';

$respArr = [];
if (!empty($p['responsible_json'])) {
    $decoded = json_decode((string)$p['responsible_json'], true);
    if (is_array($decoded)) {
        $respArr = $decoded;
    }
}

$respName = isset($respArr['name']) ? (string)$respArr['name'] : '';
$respRelationship = isset($respArr['relationship']) ? (string)$respArr['relationship'] : '';
$respCpf = isset($respArr['cpf']) ? (string)$respArr['cpf'] : '';
$respPhone = isset($respArr['phone']) ? (string)$respArr['phone'] : '';
$respEmail = isset($respArr['email']) ? (string)$respArr['email'] : '';
$respNotes = isset($respArr['notes']) ? (string)$respArr['notes'] : '';

$mhArr = [];
if (!empty($p['medical_history_json'])) {
    $decoded = json_decode((string)$p['medical_history_json'], true);
    if (is_array($decoded)) {
        $mhArr = $decoded;
    }
}

$mhMainComplaints = isset($mhArr['main_complaints']) ? (string)$mhArr['main_complaints'] : '';
$mhPastDiseases = isset($mhArr['past_diseases']) ? (string)$mhArr['past_diseases'] : '';
$mhSurgeries = isset($mhArr['surgeries']) ? (string)$mhArr['surgeries'] : '';
$mhHospitalizations = isset($mhArr['hospitalizations']) ? (string)$mhArr['hospitalizations'] : '';
$mhFamilyHistory = isset($mhArr['family_history']) ? (string)$mhArr['family_history'] : '';
$mhHabits = isset($mhArr['habits']) ? (string)$mhArr['habits'] : '';
$mhNotes = isset($mhArr['notes']) ? (string)$mhArr['notes'] : '';

$lgpdArr = [];
if (!empty($p['lgpd_json'])) {
    $decoded = json_decode((string)$p['lgpd_json'], true);
    if (is_array($decoded)) {
        $lgpdArr = $decoded;
    }
}

$lgpdConsentStatus = isset($lgpdArr['consent_status']) ? (string)$lgpdArr['consent_status'] : '';
$lgpdConsentAt = isset($lgpdArr['consent_at']) ? (string)$lgpdArr['consent_at'] : '';
$lgpdConsentVersion = isset($lgpdArr['consent_version']) ? (string)$lgpdArr['consent_version'] : '';
$lgpdConsentChannel = isset($lgpdArr['consent_channel']) ? (string)$lgpdArr['consent_channel'] : '';
$lgpdNotes = isset($lgpdArr['notes']) ? (string)$lgpdArr['notes'] : '';

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Editar paciente</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">' . h((string)$p['full_name']) . '</div>';
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

echo '<style>';
echo '.ptTabs{display:flex;gap:10px;flex-wrap:wrap}';
echo '.ptTab{border:1px solid hsl(var(--border));background:hsla(var(--secondary)/.50);border-radius:10px;padding:8px 10px;font-size:12px;font-weight:900;color:hsl(var(--muted-foreground));cursor:pointer}';
echo '.ptTab.isActive{background:hsl(var(--primary));border-color:hsl(var(--primary));color:hsl(var(--primary-foreground))}';
echo '.ptPanel{display:none;margin-top:12px}';
echo '.ptPanel.isActive{display:block}';
echo '</style>';

echo '<div class="ptTabs" id="ptTabs">';
$tabs = [
    ['k' => 'ident', 'l' => 'Identificação'],
    ['k' => 'contato', 'l' => 'Contato'],
    ['k' => 'end', 'l' => 'Endereço'],
    ['k' => 'emerg', 'l' => 'Emergência'],
    ['k' => 'conv', 'l' => 'Convênio'],
    ['k' => 'saude', 'l' => 'Saúde'],
    ['k' => 'hist', 'l' => 'Histórico Médico'],
    ['k' => 'fin', 'l' => 'Financeiro'],
    ['k' => 'lgpd', 'l' => 'LGPD'],
    ['k' => 'resp', 'l' => 'Responsável'],
    ['k' => 'adm', 'l' => 'Administrativo'],
];
foreach ($tabs as $i => $t) {
    $isActive = $i === 0;
    echo '<button type="button" class="ptTab' . ($isActive ? ' isActive' : '') . '" data-tab="' . h($t['k']) . '">' . h($t['l']) . '</button>';
}
echo '</div>';

echo '<div class="ptPanel isActive" data-panel="ident">';
echo '<div class="grid">';
echo '<div class="col6"><label>Nome completo<input name="full_name" required maxlength="160" value="' . h((string)$p['full_name']) . '"></label></div>';
echo '<div class="col6"><label>CPF<input name="cpf" maxlength="20" value="' . h((string)($p['cpf'] ?? '')) . '" placeholder="000.000.000-00"></label></div>';
echo '<div class="col6"><label>RG<input name="rg" maxlength="30" value="' . h((string)($p['rg'] ?? '')) . '"></label></div>';
echo '<div class="col6"><label>Data de nascimento<input type="date" name="birth_date" value="' . h((string)($p['birth_date'] ?? '')) . '"></label></div>';
echo '<div class="col6"><label>Sexo<input name="sex" maxlength="20" value="' . h((string)($p['sex'] ?? '')) . '"></label></div>';
echo '<div class="col6"><label>Estado civil<input name="marital_status" maxlength="40" value="' . h((string)($p['marital_status'] ?? '')) . '"></label></div>';
echo '<div class="col6"><label>Profissão<input name="profession" maxlength="80" value="' . h((string)($p['profession'] ?? '')) . '"></label></div>';
echo '<div class="col6"><label>Escolaridade<input name="education_level" maxlength="80" value="' . h((string)($p['education_level'] ?? '')) . '"></label></div>';
echo '</div>';
echo '</div>';

echo '<div class="ptPanel" data-panel="contato">';
echo '<div class="grid">';
echo '<div class="col6"><label>WhatsApp<input name="whatsapp" maxlength="30" value="' . h((string)($p['whatsapp'] ?? '')) . '" placeholder="5511999999999"></label></div>';
echo '<div class="col6"><label>Telefone principal<input name="phone_primary" maxlength="30" value="' . h((string)($p['phone_primary'] ?? '')) . '" placeholder="5511999999999"></label></div>';
echo '<div class="col6"><label>Telefone secundário<input name="phone_secondary" maxlength="30" value="' . h((string)($p['phone_secondary'] ?? '')) . '" placeholder="5511999999999"></label></div>';
echo '<div class="col6"><label>Preferência de contato<input name="preferred_contact" maxlength="30" value="' . h((string)($p['preferred_contact'] ?? '')) . '" placeholder="WhatsApp / Telefone / E-mail"></label></div>';
echo '<div class="col6"><label>E-mail<input type="email" name="email" maxlength="190" value="' . h((string)($p['email'] ?? '')) . '" placeholder="email@exemplo.com"></label></div>';
echo '</div>';
echo '</div>';

echo '<div class="ptPanel" data-panel="end">';
echo '<div class="grid">';
echo '<div class="col6"><label>CEP<input name="address_zip" maxlength="12" value="' . h((string)($p['address_zip'] ?? '')) . '" placeholder="00000-000"></label></div>';
echo '<div class="col6"><label>Logradouro<input name="address_street" maxlength="160" value="' . h((string)($p['address_street'] ?? '')) . '"></label></div>';
echo '<div class="col6"><label>Número<input name="address_number" maxlength="20" value="' . h((string)($p['address_number'] ?? '')) . '"></label></div>';
echo '<div class="col6"><label>Complemento<input name="address_complement" maxlength="80" value="' . h((string)($p['address_complement'] ?? '')) . '"></label></div>';
echo '<div class="col6"><label>Bairro<input name="address_neighborhood" maxlength="80" value="' . h((string)($p['address_neighborhood'] ?? '')) . '"></label></div>';
$currentAddressState = (string)($p['address_state'] ?? '');
$currentAddressCity = (string)($p['address_city'] ?? '');
$states = ['AC'=>'Acre','AL'=>'Alagoas','AP'=>'Amapá','AM'=>'Amazonas','BA'=>'Bahia','CE'=>'Ceará','DF'=>'Distrito Federal','ES'=>'Espírito Santo','GO'=>'Goiás','MA'=>'Maranhão','MT'=>'Mato Grosso','MS'=>'Mato Grosso do Sul','MG'=>'Minas Gerais','PA'=>'Pará','PB'=>'Paraíba','PR'=>'Paraná','PE'=>'Pernambuco','PI'=>'Piauí','RJ'=>'Rio de Janeiro','RN'=>'Rio Grande do Norte','RS'=>'Rio Grande do Sul','RO'=>'Rondônia','RR'=>'Roraima','SC'=>'Santa Catarina','SP'=>'São Paulo','SE'=>'Sergipe','TO'=>'Tocantins'];

echo '<div class="col6"><label>UF<select name="address_state" id="edit_address_state"><option value="">Selecione...</option>';
foreach ($states as $uf => $nome) {
    $selected = ($currentAddressState === $uf) ? ' selected' : '';
    echo '<option value="' . $uf . '"' . $selected . '>' . $uf . ' - ' . $nome . '</option>';
}
echo '</select></label></div>';
echo '<div class="col6"><label>Cidade<select name="address_city" id="edit_address_city"><option value="">Carregando...</option></select></label></div>';
echo '<div class="col6"><label>País<input name="address_country" maxlength="60" value="' . h((string)($p['address_country'] ?? '')) . '"></label></div>';
echo '</div>';
echo '</div>';

echo '<div class="ptPanel" data-panel="emerg">';
echo '<div class="grid">';
echo '<div class="col6"><label>Nome<input name="emergency_name" maxlength="160" value="' . h((string)($p['emergency_name'] ?? '')) . '"></label></div>';
echo '<div class="col6"><label>Parentesco<input name="emergency_relationship" maxlength="60" value="' . h((string)($p['emergency_relationship'] ?? '')) . '"></label></div>';
echo '<div class="col6"><label>Telefone<input name="emergency_phone" maxlength="30" value="' . h((string)($p['emergency_phone'] ?? '')) . '"></label></div>';
echo '</div>';
echo '</div>';

echo '<div class="ptPanel" data-panel="conv">';
echo '<div class="grid">';
echo '<div class="col6"><label>Convênio<input name="insurance_name" maxlength="120" value="' . h((string)($p['insurance_name'] ?? '')) . '"></label></div>';
echo '<div class="col6"><label>Nº carteirinha<input name="insurance_card_number" maxlength="60" value="' . h((string)($p['insurance_card_number'] ?? '')) . '"></label></div>';
echo '<div class="col6"><label>Validade<input type="date" name="insurance_valid_until" value="' . h((string)($p['insurance_valid_until'] ?? '')) . '"></label></div>';
echo '<div class="col12"><label>Observações<textarea name="insurance_notes" rows="3">' . h((string)($p['insurance_notes'] ?? '')) . '</textarea></label></div>';
echo '</div>';
echo '</div>';

echo '<div class="ptPanel" data-panel="saude">';
echo '<div class="grid">';
echo '<div class="col12"><label>Alergias<textarea name="health_allergies" rows="2">' . h($healthAllergies) . '</textarea></label></div>';
echo '<div class="col12"><label>Medicamentos em uso<textarea name="health_medications" rows="2">' . h($healthMedications) . '</textarea></label></div>';
echo '<div class="col12"><label>Condições / Diagnósticos<textarea name="health_conditions" rows="2">' . h($healthConditions) . '</textarea></label></div>';
echo '<div class="col12"><label>Restrições / Observações clínicas<textarea name="health_restrictions" rows="2">' . h($healthRestrictions) . '</textarea></label></div>';
echo '<div class="col6"><label>Tipo sanguíneo<input name="health_blood_type" maxlength="10" value="' . h($healthBloodType) . '"></label></div>';
echo '<div class="col12"><label>Observações gerais<textarea name="health_notes" rows="3">' . h($healthNotes) . '</textarea></label></div>';
echo '<div class="col12"><label>Saúde (JSON)<textarea name="health_json" rows="6" placeholder="{}">' . h((string)($p['health_json'] ?? '')) . '</textarea></label></div>';
echo '</div>';
echo '</div>';

echo '<div class="ptPanel" data-panel="hist">';
echo '<div class="grid">';
echo '<div class="col12"><label>Queixas principais<textarea name="mh_main_complaints" rows="2">' . h($mhMainComplaints) . '</textarea></label></div>';
echo '<div class="col12"><label>Doenças prévias<textarea name="mh_past_diseases" rows="2">' . h($mhPastDiseases) . '</textarea></label></div>';
echo '<div class="col12"><label>Cirurgias<textarea name="mh_surgeries" rows="2">' . h($mhSurgeries) . '</textarea></label></div>';
echo '<div class="col12"><label>Internações<textarea name="mh_hospitalizations" rows="2">' . h($mhHospitalizations) . '</textarea></label></div>';
echo '<div class="col12"><label>Histórico familiar<textarea name="mh_family_history" rows="2">' . h($mhFamilyHistory) . '</textarea></label></div>';
echo '<div class="col12"><label>Hábitos (sono, álcool, tabaco, etc.)<textarea name="mh_habits" rows="2">' . h($mhHabits) . '</textarea></label></div>';
echo '<div class="col12"><label>Observações<textarea name="mh_notes" rows="3">' . h($mhNotes) . '</textarea></label></div>';
echo '<div class="col12"><label>Histórico médico (JSON)<textarea name="medical_history_json" rows="6" placeholder="{}">' . h((string)($p['medical_history_json'] ?? '')) . '</textarea></label></div>';
echo '</div>';
echo '</div>';

echo '<div class="ptPanel" data-panel="fin">';
echo '<label>Financeiro (JSON)<textarea name="finance_json" rows="6" placeholder="{}">' . h((string)($p['finance_json'] ?? '')) . '</textarea></label>';
echo '</div>';

echo '<div class="ptPanel" data-panel="lgpd">';
echo '<div class="grid">';
echo '<div class="col6"><label>Consentimento<select name="lgpd_consent_status">';
$lgpdOptions = [
    '' => '-',
    'consented' => 'consented',
    'denied' => 'denied',
    'pending' => 'pending',
];
foreach ($lgpdOptions as $k => $label) {
    $sel = ($lgpdConsentStatus === $k) ? ' selected' : '';
    echo '<option value="' . h($k) . '"' . $sel . '>' . h($label) . '</option>';
}
echo '</select></label></div>';
echo '<div class="col6"><label>Data do consentimento<input type="date" name="lgpd_consent_at" value="' . h($lgpdConsentAt) . '"></label></div>';
echo '<div class="col6"><label>Versão do termo<input name="lgpd_consent_version" maxlength="40" value="' . h($lgpdConsentVersion) . '" placeholder="Ex: v1"></label></div>';
echo '<div class="col6"><label>Canal<input name="lgpd_consent_channel" maxlength="60" value="' . h($lgpdConsentChannel) . '" placeholder="Ex: WhatsApp / Presencial"></label></div>';
echo '<div class="col12"><label>Observações<textarea name="lgpd_notes" rows="3">' . h($lgpdNotes) . '</textarea></label></div>';
echo '<div class="col12"><label>LGPD (JSON)<textarea name="lgpd_json" rows="6" placeholder="{}">' . h((string)($p['lgpd_json'] ?? '')) . '</textarea></label></div>';
echo '</div>';
echo '</div>';

echo '<div class="ptPanel" data-panel="resp">';
echo '<div class="grid">';
echo '<div class="col6"><label>Nome<input name="responsible_name" maxlength="160" value="' . h($respName) . '"></label></div>';
echo '<div class="col6"><label>Parentesco<input name="responsible_relationship" maxlength="60" value="' . h($respRelationship) . '"></label></div>';
echo '<div class="col6"><label>CPF<input name="responsible_cpf" maxlength="20" value="' . h($respCpf) . '" placeholder="000.000.000-00"></label></div>';
echo '<div class="col6"><label>Telefone<input name="responsible_phone" maxlength="30" value="' . h($respPhone) . '" placeholder="5511999999999"></label></div>';
echo '<div class="col6"><label>E-mail<input type="email" name="responsible_email" maxlength="190" value="' . h($respEmail) . '"></label></div>';
echo '<div class="col12"><label>Observações<textarea name="responsible_notes" rows="3">' . h($respNotes) . '</textarea></label></div>';
echo '<div class="col12"><label>Responsável (JSON)<textarea name="responsible_json" rows="6" placeholder="{}">' . h((string)($p['responsible_json'] ?? '')) . '</textarea></label></div>';
echo '</div>';
echo '</div>';

echo '<div class="ptPanel" data-panel="adm">';
echo '<div class="grid">';
echo '<div class="col6"><label>Status<input name="admin_status" maxlength="40" value="' . h((string)($p['admin_status'] ?? '')) . '"></label></div>';
echo '<div class="col6"><label>Unidade<input name="unit" maxlength="80" value="' . h((string)($p['unit'] ?? '')) . '"></label></div>';
echo '<div class="col12"><label>Médico responsável<input name="doctor_responsible" maxlength="160" value="' . h((string)($p['doctor_responsible'] ?? '')) . '"></label></div>';
echo '<div class="col12">';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end">';
echo '<a class="btn" href="/documents_list.php?entity_type=patient&entity_id=' . (int)$p['id'] . '">Documentos do paciente</a>';
echo '<a class="btn" href="/documents_upload.php?entity_type=patient&entity_id=' . (int)$p['id'] . '&return_to=' . urlencode('/patients_edit.php?id=' . (int)$p['id']) . '">Novo documento</a>';
echo '</div>';
echo '</div>';
echo '<div class="col12"><label>Documentos (JSON)<textarea name="documents_json" rows="4" placeholder="{}">' . h((string)($p['documents_json'] ?? '')) . '</textarea></label></div>';
echo '</div>';
echo '</div>';

echo '<script>';
echo '(function(){var tabs=document.querySelectorAll(".ptTab");var panels=document.querySelectorAll(".ptPanel");';
echo 'var act=function(k){tabs.forEach(function(b){b.classList.toggle("isActive", b.getAttribute("data-tab")===k);}); panels.forEach(function(p){p.classList.toggle("isActive", p.getAttribute("data-panel")===k);});};';
echo 'tabs.forEach(function(b){b.addEventListener("click", function(){act(b.getAttribute("data-tab"));});});})();';
echo '</script>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end;margin-top:6px">';
echo '<a class="btn" href="/patients_view.php?id=' . (int)$p['id'] . '">Cancelar</a>';
echo '<button class="btn btnPrimary" type="submit">Salvar</button>';
echo '</div>';

echo '</form>';
echo '</section>';

echo '<script>';
echo 'const editAddressState = ' . json_encode($currentAddressState) . ';';
echo 'const editAddressCity = ' . json_encode($currentAddressCity) . ';';
echo 'const editAddressStateSelect = document.getElementById("edit_address_state");';
echo 'const editAddressCitySelect = document.getElementById("edit_address_city");';
echo 'async function loadEditPatientCities(uf, preselect = "") {';
echo '  if(!editAddressCitySelect) return;';
echo '  editAddressCitySelect.innerHTML = "<option value=\\"\\">Carregando...</option>";';
echo '  editAddressCitySelect.disabled = true;';
echo '  if(!uf){';
echo '    editAddressCitySelect.innerHTML = "<option value=\\"\\">Selecione o estado primeiro...</option>";';
echo '    editAddressCitySelect.disabled = false;';
echo '    return;';
echo '  }';
echo '  try{';
echo '    const response = await fetch(`https://servicodados.ibge.gov.br/api/v1/localidades/estados/${uf}/municipios?orderBy=nome`);';
echo '    const cidades = await response.json();';
echo '    editAddressCitySelect.innerHTML = "<option value=\\"\\">Selecione...</option>";';
echo '    cidades.forEach(function(cidade){';
echo '      const opt = document.createElement("option");';
echo '      opt.value = cidade.nome;';
echo '      opt.textContent = cidade.nome;';
echo '      if(cidade.nome === preselect) opt.selected = true;';
echo '      editAddressCitySelect.appendChild(opt);';
echo '    });';
echo '    editAddressCitySelect.disabled = false;';
echo '  }catch(err){';
echo '    console.error("Erro ao buscar cidades:", err);';
echo '    editAddressCitySelect.innerHTML = "<option value=\\"\\">Erro ao carregar cidades</option>";';
echo '    editAddressCitySelect.disabled = false;';
echo '  }';
echo '}';
echo 'if(editAddressState) loadEditPatientCities(editAddressState, editAddressCity);';
echo 'else if(editAddressCitySelect) editAddressCitySelect.innerHTML = "<option value=\\"\\">Selecione o estado primeiro...</option>";';
echo 'if(editAddressStateSelect) editAddressStateSelect.addEventListener("change", function(){ loadEditPatientCities(this.value); });';
echo '</script>';

view_footer();

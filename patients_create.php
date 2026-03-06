<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('patients.manage');

// Capturar telefone e chat_id se vier do chat
$phoneFromChat = isset($_GET['phone']) ? trim((string)$_GET['phone']) : '';
$fromChat = isset($_GET['from_chat']) && $_GET['from_chat'] === '1';
$fromAssignmentModal = isset($_GET['from_assignment_modal']) && $_GET['from_assignment_modal'] === '1';
$chatId = isset($_GET['chat_id']) ? trim((string)$_GET['chat_id']) : '';

// Buscar especialidades cadastradas
$specialtiesStmt = db()->query("SELECT id, name FROM specialties WHERE status = 'active' ORDER BY name ASC");
$specialties = $specialtiesStmt->fetchAll();

view_header('Novo paciente');

echo '<div class="card">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900;margin-bottom:6px">Novo paciente</div>';
echo '<div style="color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Cadastro inicial do paciente.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
if ($fromChat && $chatId !== '') {
    echo '<a class="btn" href="/chat_web.php?chat=' . urlencode($chatId) . '&type=all">Voltar ao Chat</a>';
} else {
    echo '<a class="btn" href="/patients_list.php">Voltar</a>';
}
echo '</div>';
echo '</div>';

echo '<div style="height:14px"></div>';

echo '<form method="post" action="/patients_create_post.php" enctype="multipart/form-data">';

// Campos hidden para retornar ao chat
if ($fromChat) {
    echo '<input type="hidden" name="from_chat" value="1">';
    if ($fromAssignmentModal) {
        echo '<input type="hidden" name="from_assignment_modal" value="1">';
    }
    if ($chatId !== '') {
        echo '<input type="hidden" name="chat_id" value="' . h($chatId) . '">';
    }
}

// 1. Identificação
echo '<div class="formSection">';
echo '<div class="formSectionTitle">1. Identificação do Paciente</div>';
echo '<div class="grid">';
echo '<div class="col6"><label>Nome completo *<input name="full_name" required maxlength="160" placeholder="Nome completo"></label></div>';
echo '<div class="col6"><label>Nome social<input name="social_name" maxlength="160" placeholder="Nome social (opcional)"></label></div>';
echo '<div class="col6"><label>Sexo<select name="sex"><option value="">Selecione</option><option value="Masculino">Masculino</option><option value="Feminino">Feminino</option></select></label></div>';
echo '<div class="col6"><label>Gênero<input name="gender" maxlength="40" placeholder="Identidade de gênero"></label></div>';
echo '<div class="col6"><label>Data de nascimento<input type="date" name="birth_date"></label></div>';
echo '<div class="col6"><label>CPF<input name="cpf" maxlength="20" placeholder="000.000.000-00"></label></div>';
echo '<div class="col6"><label>RG<input name="rg" maxlength="30" placeholder="Número do RG"></label></div>';
echo '<div class="col6"><label>Órgão emissor<input name="rg_issuer" maxlength="60" placeholder="SSP/SP"></label></div>';
echo '<div class="col6"><label>Nacionalidade<input name="nationality" maxlength="80" placeholder="Brasileiro(a)"></label></div>';
echo '<div class="col6"><label>Naturalidade - Estado<select name="birth_state" id="birth_state"><option value="">Selecione...</option><option value="AC">AC - Acre</option><option value="AL">AL - Alagoas</option><option value="AP">AP - Amapá</option><option value="AM">AM - Amazonas</option><option value="BA">BA - Bahia</option><option value="CE">CE - Ceará</option><option value="DF">DF - Distrito Federal</option><option value="ES">ES - Espírito Santo</option><option value="GO">GO - Goiás</option><option value="MA">MA - Maranhão</option><option value="MT">MT - Mato Grosso</option><option value="MS">MS - Mato Grosso do Sul</option><option value="MG">MG - Minas Gerais</option><option value="PA">PA - Pará</option><option value="PB">PB - Paraíba</option><option value="PR">PR - Paraná</option><option value="PE">PE - Pernambuco</option><option value="PI">PI - Piauí</option><option value="RJ">RJ - Rio de Janeiro</option><option value="RN">RN - Rio Grande do Norte</option><option value="RS">RS - Rio Grande do Sul</option><option value="RO">RO - Rondônia</option><option value="RR">RR - Roraima</option><option value="SC">SC - Santa Catarina</option><option value="SP">SP - São Paulo</option><option value="SE">SE - Sergipe</option><option value="TO">TO - Tocantins</option></select></label></div>';
echo '<div class="col6"><label>Naturalidade - Cidade<select name="birth_city" id="birth_city"><option value="">Selecione o estado primeiro...</option></select></label></div>';
echo '<div class="col6"><label>Estado civil<select name="marital_status"><option value="">Selecione</option><option value="Solteiro(a)">Solteiro(a)</option><option value="Casado(a)">Casado(a)</option><option value="Divorciado(a)">Divorciado(a)</option><option value="Viúvo(a)">Viúvo(a)</option><option value="União estável">União estável</option></select></label></div>';
echo '<div class="col6"><label>Profissão<input name="profession" maxlength="80" placeholder="Profissão"></label></div>';
echo '<div class="col6"><label>Escolaridade<select name="education_level"><option value="">Selecione</option><option value="Fundamental incompleto">Fundamental incompleto</option><option value="Fundamental completo">Fundamental completo</option><option value="Médio incompleto">Médio incompleto</option><option value="Médio completo">Médio completo</option><option value="Superior incompleto">Superior incompleto</option><option value="Superior completo">Superior completo</option><option value="Pós-graduação">Pós-graduação</option></select></label></div>';
echo '<div class="col6"><label>Foto do paciente<input type="file" name="photo" accept="image/*"></label></div>';
echo '</div></div>';

// 2. Contato
echo '<div class="formSection">';
echo '<div class="formSectionTitle">2. Informações de Contato</div>';
echo '<div class="grid">';
echo '<div class="col6"><label>Telefone principal<input name="phone_primary" maxlength="30" placeholder="5511999999999" value="' . h($phoneFromChat) . '"></label></div>';
echo '<div class="col6"><label>Telefone secundário<input name="phone_secondary" maxlength="30" placeholder="5511999999999"></label></div>';
echo '<div class="col6"><label>WhatsApp<input name="whatsapp" maxlength="30" placeholder="5511999999999"></label></div>';
echo '<div class="col6"><label>E-mail<input type="email" name="email" maxlength="190" placeholder="email@exemplo.com"></label></div>';
echo '<div class="col6"><label>Preferência de contato<select name="preferred_contact"><option value="">Selecione</option><option value="Telefone">Telefone</option><option value="WhatsApp">WhatsApp</option><option value="E-mail">E-mail</option></select></label></div>';
echo '</div></div>';

// 3. Endereço
echo '<div class="formSection">';
echo '<div class="formSectionTitle">3. Endereço</div>';
echo '<div class="grid">';
echo '<div class="col6"><label>CEP<input name="address_zip" maxlength="12" placeholder="00000-000"></label></div>';
echo '<div class="col6"><label>Logradouro<input name="address_street" maxlength="160" placeholder="Rua, Avenida, etc."></label></div>';
echo '<div class="col6"><label>Número<input name="address_number" maxlength="20" placeholder="Número"></label></div>';
echo '<div class="col6"><label>Complemento<input name="address_complement" maxlength="80" placeholder="Apto, Bloco, etc."></label></div>';
echo '<div class="col6"><label>Bairro<input name="address_neighborhood" maxlength="80" placeholder="Bairro"></label></div>';
echo '<div class="col6"><label>Estado<select name="address_state" id="address_state"><option value="">Selecione...</option><option value="AC">AC - Acre</option><option value="AL">AL - Alagoas</option><option value="AP">AP - Amapá</option><option value="AM">AM - Amazonas</option><option value="BA">BA - Bahia</option><option value="CE">CE - Ceará</option><option value="DF">DF - Distrito Federal</option><option value="ES">ES - Espírito Santo</option><option value="GO">GO - Goiás</option><option value="MA">MA - Maranhão</option><option value="MT">MT - Mato Grosso</option><option value="MS">MS - Mato Grosso do Sul</option><option value="MG">MG - Minas Gerais</option><option value="PA">PA - Pará</option><option value="PB">PB - Paraíba</option><option value="PR">PR - Paraná</option><option value="PE">PE - Pernambuco</option><option value="PI">PI - Piauí</option><option value="RJ">RJ - Rio de Janeiro</option><option value="RN">RN - Rio Grande do Norte</option><option value="RS">RS - Rio Grande do Sul</option><option value="RO">RO - Rondônia</option><option value="RR">RR - Roraima</option><option value="SC">SC - Santa Catarina</option><option value="SP">SP - São Paulo</option><option value="SE">SE - Sergipe</option><option value="TO">TO - Tocantins</option></select></label></div>';
echo '<div class="col6"><label>Cidade<select name="address_city" id="address_city"><option value="">Selecione o estado primeiro...</option></select></label></div>';
echo '<div class="col6"><label>País<input name="address_country" maxlength="60" placeholder="Brasil" value="Brasil"></label></div>';
echo '</div></div>';

// 4. Emergência
echo '<div class="formSection">';
echo '<div class="formSectionTitle">4. Informações de Emergência</div>';
echo '<div class="grid">';
echo '<div class="col6"><label>Nome do contato<input name="emergency_name" maxlength="160" placeholder="Nome completo"></label></div>';
echo '<div class="col6"><label>Grau de parentesco<input name="emergency_relationship" maxlength="60" placeholder="Mãe, Pai, Cônjuge, etc."></label></div>';
echo '<div class="col6"><label>Telefone principal<input name="emergency_phone" maxlength="30" placeholder="5511999999999"></label></div>';
echo '<div class="col6"><label>Telefone secundário<input name="emergency_phone_secondary" maxlength="30" placeholder="5511999999999"></label></div>';
echo '<div class="col6"><label>E-mail<input type="email" name="emergency_email" maxlength="190" placeholder="email@exemplo.com"></label></div>';
echo '</div></div>';

// 5. Convênio
echo '<div class="formSection">';
echo '<div class="formSectionTitle">5. Convênio / Seguro Saúde</div>';
echo '<div class="grid">';
echo '<div class="col6"><label>Possui convênio?<select name="has_insurance"><option value="0">Não</option><option value="1">Sim</option></select></label></div>';
echo '<div class="col6"><label>Nome do convênio<input name="insurance_name" maxlength="120" placeholder="Nome da operadora"></label></div>';
echo '<div class="col6"><label>Número da carteirinha<input name="insurance_card_number" maxlength="60" placeholder="Número"></label></div>';
echo '<div class="col6"><label>Plano<input name="insurance_plan" maxlength="120" placeholder="Nome do plano"></label></div>';
echo '<div class="col6"><label>Validade<input type="date" name="insurance_valid_until"></label></div>';
echo '<div class="col6"><label>Titular do plano<input name="insurance_holder_name" maxlength="160" placeholder="Nome do titular"></label></div>';
echo '<div class="col6"><label>Grau de dependência<select name="insurance_dependency_level"><option value="">Selecione</option><option value="Titular">Titular</option><option value="Cônjuge">Cônjuge</option><option value="Filho(a)">Filho(a)</option><option value="Dependente">Dependente</option></select></label></div>';
echo '<div class="col6"><label>Empresa do convênio<input name="insurance_company" maxlength="160" placeholder="Empresa"></label></div>';
echo '<div class="col6"><label>Foto carteirinha (frente)<input type="file" name="insurance_card_front" accept="image/*"></label></div>';
echo '<div class="col6"><label>Foto carteirinha (verso)<input type="file" name="insurance_card_back" accept="image/*"></label></div>';
echo '<div class="col12"><label>Observações<textarea name="insurance_notes" rows="2" placeholder="Observações sobre o convênio"></textarea></label></div>';
echo '</div></div>';

// 6. Informações Médicas Básicas
echo '<div class="formSection">';
echo '<div class="formSectionTitle">6. Informações Médicas Básicas</div>';
echo '<div class="grid">';
echo '<div class="col6"><label>Tipo sanguíneo<select name="blood_type"><option value="">Selecione</option><option value="A">A</option><option value="B">B</option><option value="AB">AB</option><option value="O">O</option></select></label></div>';
echo '<div class="col6"><label>Fator RH<select name="rh_factor"><option value="">Selecione</option><option value="+">Positivo (+)</option><option value="-">Negativo (-)</option></select></label></div>';
echo '<div class="col6"><label>Altura (cm)<input type="number" step="0.01" name="height_cm" placeholder="170.5"></label></div>';
echo '<div class="col6"><label>Peso (kg)<input type="number" step="0.01" name="weight_kg" placeholder="70.5"></label></div>';
echo '<div class="col6"><label>Pressão arterial padrão<input name="blood_pressure" maxlength="20" placeholder="120/80"></label></div>';
echo '<div class="col6"><label>Frequência cardíaca (bpm)<input type="number" name="heart_rate" placeholder="75"></label></div>';
echo '<div class="col6"><label>Temperatura corporal padrão (°C)<input type="number" step="0.01" name="body_temperature" placeholder="36.5"></label></div>';
echo '</div></div>';

// 12. Hábitos de Vida
echo '<div class="formSection">';
echo '<div class="formSectionTitle">12. Hábitos de Vida</div>';
echo '<div class="grid">';
echo '<div class="col6"><label>Fumante<select name="smoker"><option value="">Selecione</option><option value="Não">Não</option><option value="Sim">Sim</option><option value="Ex-fumante">Ex-fumante</option></select></label></div>';
echo '<div class="col6"><label>Consumo de álcool<select name="alcohol_consumption"><option value="">Selecione</option><option value="Não consome">Não consome</option><option value="Ocasional">Ocasional</option><option value="Moderado">Moderado</option><option value="Frequente">Frequente</option></select></label></div>';
echo '<div class="col6"><label>Uso de drogas<select name="drug_use"><option value="">Selecione</option><option value="Não">Não</option><option value="Ocasional">Ocasional</option><option value="Frequente">Frequente</option></select></label></div>';
echo '<div class="col6"><label>Atividade física<select name="physical_activity"><option value="">Selecione</option><option value="Sedentário">Sedentário</option><option value="Leve">Leve</option><option value="Moderada">Moderada</option><option value="Intensa">Intensa</option></select></label></div>';
echo '<div class="col6"><label>Frequência de exercícios<input name="exercise_frequency" maxlength="60" placeholder="Ex: 3x por semana"></label></div>';
echo '<div class="col6"><label>Dieta alimentar<input name="diet_type" maxlength="80" placeholder="Ex: Vegetariana, Vegana, etc."></label></div>';
echo '</div></div>';

// 13. Dados Biométricos
echo '<div class="formSection">';
echo '<div class="formSectionTitle">13. Dados Biométricos / Físicos</div>';
echo '<div class="grid">';
echo '<div class="col6"><label>Circunferência abdominal (cm)<input type="number" step="0.01" name="waist_circumference_cm" placeholder="85.5"></label></div>';
echo '<div class="col6"><label>Gordura corporal (%)<input type="number" step="0.01" name="body_fat_percentage" placeholder="20.5"></label></div>';
echo '<div class="col6"><label>Massa muscular (kg)<input type="number" step="0.01" name="muscle_mass_kg" placeholder="50.5"></label></div>';
echo '<div class="col6"><label>Saturação de oxigênio (%)<input type="number" step="0.01" name="oxygen_saturation" placeholder="98.5"></label></div>';
echo '</div></div>';

// 15. Administrativo
echo '<div class="formSection">';
echo '<div class="formSectionTitle">15. Informações Administrativas</div>';
echo '<div class="grid">';
echo '<div class="col6"><label>Status<select name="admin_status"><option value="Ativo">Ativo</option><option value="Inativo">Inativo</option></select></label></div>';
echo '<div class="col6"><label>Unidade/Clínica<input name="unit" maxlength="80" placeholder="Nome da unidade"></label></div>';
echo '<div class="col6"><label>Médico responsável<input name="doctor_responsible" maxlength="160" placeholder="Nome do médico"></label></div>';
echo '</div></div>';

// 16. LGPD
echo '<div class="formSection">';
echo '<div class="formSectionTitle">16. Consentimentos e LGPD</div>';
echo '<div class="grid">';
echo '<div class="col6"><label><input type="checkbox" name="consent_data_usage" value="1"> Consentimento de uso de dados</label></div>';
echo '<div class="col6"><label><input type="checkbox" name="consent_privacy_terms" value="1"> Termo de privacidade</label></div>';
echo '<div class="col6"><label><input type="checkbox" name="consent_contact" value="1"> Autorização para contato</label></div>';
echo '<div class="col6"><label><input type="checkbox" name="consent_data_sharing" value="1"> Autorização para compartilhamento de dados médicos</label></div>';
echo '</div></div>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end;margin-top:14px">';
echo '<a class="btn" href="/patients_list.php">Cancelar</a>';
echo '<button class="btn btnPrimary" type="submit">Salvar Paciente</button>';
echo '</div>';

echo '</form>';
echo '</div>';

echo '<script>';
echo 'async function loadPatientCities(uf, selectElement) {';
echo '  selectElement.innerHTML = "<option value=\\"\\">Carregando...</option>";';
echo '  selectElement.disabled = true;';
echo '  if(!uf){';
echo '    selectElement.innerHTML = "<option value=\\"\\">Selecione o estado primeiro...</option>";';
echo '    selectElement.disabled = false;';
echo '    return;';
echo '  }';
echo '  try{';
echo '    const response = await fetch(`https://servicodados.ibge.gov.br/api/v1/localidades/estados/${uf}/municipios?orderBy=nome`);';
echo '    const cidades = await response.json();';
echo '    selectElement.innerHTML = "<option value=\\"\\">Selecione...</option>";';
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
echo 'const birthStateSelect = document.getElementById("birth_state");';
echo 'const birthCitySelect = document.getElementById("birth_city");';
echo 'if(birthStateSelect && birthCitySelect){';
echo '  birthStateSelect.addEventListener("change", function(){ loadPatientCities(this.value, birthCitySelect); });';
echo '}';
echo 'const addressStateSelect = document.getElementById("address_state");';
echo 'const addressCitySelect = document.getElementById("address_city");';
echo 'if(addressStateSelect && addressCitySelect){';
echo '  addressStateSelect.addEventListener("change", function(){ loadPatientCities(this.value, addressCitySelect); });';
echo '}';
echo '</script>';

view_footer();

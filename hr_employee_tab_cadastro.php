<?php
// Aba de Cadastro - Dados Pessoais e Documentação

$employeeId = $isNew ? 'new' : (int)$employee['id'];

echo '<form method="post" action="/hr_employee_save_cadastro_post.php" enctype="multipart/form-data" style="display:grid;gap:24px">';
echo '<input type="hidden" name="employee_id" value="' . h((string)$employeeId) . '">';

// Foto
echo '<div>';
echo '<h3 style="font-size:18px;font-weight:700;margin-bottom:12px">Foto do Funcionário</h3>';
echo '<div style="display:flex;gap:16px;align-items:center">';
echo '<div style="width:100px;height:100px;border-radius:50%;overflow:hidden;border:2px solid hsl(var(--border))">';
// Usar placeholder SVG inline quando não houver foto para evitar loop de requisições 404
if (!$isNew && !empty($employee['photo_url'])) {
    $currentPhoto = h((string)$employee['photo_url']);
    echo '<img id="photoPreview" src="' . $currentPhoto . '" alt="Foto" style="width:100%;height:100%;object-fit:cover" onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'flex\'">';
    echo '<div style="display:none;width:100%;height:100%;background:#e5e7eb;align-items:center;justify-content:center;font-size:40px;color:#9ca3af">👤</div>';
} else {
    echo '<div style="display:flex;width:100%;height:100%;background:#e5e7eb;align-items:center;justify-content:center;font-size:40px;color:#9ca3af">👤</div>';
}
echo '</div>';
echo '<div>';
echo '<label class="btn" style="cursor:pointer">Escolher Foto<input type="file" name="photo" accept="image/*" style="display:none" onchange="previewPhoto(this)"></label>';
echo '<div style="margin-top:8px;font-size:12px;color:hsl(var(--muted-foreground))">JPG, PNG ou GIF (máx. 2MB)</div>';
echo '</div>';
echo '</div>';
echo '</div>';

echo '<script>
function previewPhoto(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const container = input.closest("form").querySelector("div[style*=\'border-radius:50%\']");
            if (container) {
                // Substituir conteúdo do container por uma imagem
                container.innerHTML = \'<img id="photoPreview" src="\' + e.target.result + \'" alt="Foto" style="width:100%;height:100%;object-fit:cover">\';
            }
        };
        reader.readAsDataURL(input.files[0]);
    }
}
</script>';

// 1. Dados Pessoais
echo '<div>';
echo '<h3 style="font-size:18px;font-weight:700;margin-bottom:12px;padding-bottom:8px;border-bottom:2px solid hsl(var(--border))">1. Dados Pessoais</h3>';
echo '<div class="grid" style="gap:12px">';

echo '<div class="col12"><label>Nome Completo *<input name="full_name" required maxlength="160" value="' . h((string)($employee['full_name'] ?? '')) . '" placeholder="Nome completo do funcionário"></label></div>';

echo '<div class="col6"><label>CPF<input name="cpf" maxlength="20" value="' . h((string)($employee['cpf'] ?? '')) . '" placeholder="000.000.000-00"></label></div>';
echo '<div class="col6"><label>RG<input name="rg" maxlength="20" value="' . h((string)($employee['rg'] ?? '')) . '" placeholder="00.000.000-0"></label></div>';

echo '<div class="col6"><label>Órgão Emissor RG<input name="rg_issuer" maxlength="50" value="' . h((string)($employee['rg_issuer'] ?? '')) . '" placeholder="SSP/SP"></label></div>';
echo '<div class="col6"><label>Data Emissão RG<input type="date" name="rg_issue_date" value="' . h((string)($employee['rg_issue_date'] ?? '')) . '"></label></div>';

echo '<div class="col4"><label>Data de Nascimento<input type="date" name="birth_date" value="' . h((string)($employee['birth_date'] ?? '')) . '"></label></div>';
echo '<div class="col4"><label>Sexo<select name="gender">';
echo '<option value="">Selecione...</option>';
$genders = ['masculino' => 'Masculino', 'feminino' => 'Feminino', 'outro' => 'Outro', 'prefiro_nao_informar' => 'Prefiro não informar'];
foreach ($genders as $val => $label) {
    $sel = (!$isNew && $employee['gender'] === $val) ? ' selected' : '';
    echo '<option value="' . $val . '"' . $sel . '>' . $label . '</option>';
}
echo '</select></label></div>';
echo '<div class="col4"><label>Estado Civil<select name="marital_status">';
echo '<option value="">Selecione...</option>';
$maritalStatuses = ['solteiro' => 'Solteiro(a)', 'casado' => 'Casado(a)', 'divorciado' => 'Divorciado(a)', 'viuvo' => 'Viúvo(a)', 'uniao_estavel' => 'União Estável'];
foreach ($maritalStatuses as $val => $label) {
    $sel = (!$isNew && $employee['marital_status'] === $val) ? ' selected' : '';
    echo '<option value="' . $val . '"' . $sel . '>' . $label . '</option>';
}
echo '</select></label></div>';

echo '<div class="col4"><label>Nacionalidade<input name="nationality" maxlength="50" value="' . h((string)($employee['nationality'] ?? '')) . '" placeholder="Brasileiro"></label></div>';
echo '<div class="col4"><label>Cidade de Nascimento<input name="birth_city" maxlength="100" value="' . h((string)($employee['birth_city'] ?? '')) . '" placeholder="São Paulo"></label></div>';
echo '<div class="col4"><label>Estado de Nascimento<input name="birth_state" maxlength="2" value="' . h((string)($employee['birth_state'] ?? '')) . '" placeholder="SP"></label></div>';

echo '<div class="col6"><label>Nome da Mãe<input name="mother_name" maxlength="160" value="' . h((string)($employee['mother_name'] ?? '')) . '" placeholder="Nome completo da mãe"></label></div>';
echo '<div class="col6"><label>Nome do Pai<input name="father_name" maxlength="160" value="' . h((string)($employee['father_name'] ?? '')) . '" placeholder="Nome completo do pai"></label></div>';

echo '</div>';
echo '</div>';

// 2. Função no Sistema
echo '<div>';
echo '<h3 style="font-size:18px;font-weight:700;margin-bottom:12px;padding-bottom:8px;border-bottom:2px solid hsl(var(--border))">2. Função no Sistema</h3>';
echo '<div class="grid" style="gap:12px">';

// Buscar funções disponíveis no sistema
$rolesStmt = db()->query('SELECT id, name, slug FROM roles ORDER BY name ASC');
$availableRoles = $rolesStmt->fetchAll();

echo '<div class="col12"><label>Função *<select name="role_id" required>';
echo '<option value="">Selecione a função...</option>';
foreach ($availableRoles as $role) {
    $sel = (!$isNew && isset($employee['role_id']) && (int)$employee['role_id'] === (int)$role['id']) ? ' selected' : '';
    echo '<option value="' . (int)$role['id'] . '"' . $sel . '>' . h((string)$role['name']) . ' (' . h((string)$role['slug']) . ')</option>';
}
echo '</select>';
echo '<span style="font-size:12px;color:hsl(var(--muted-foreground));display:block;margin-top:4px">Define o cargo, departamento e permissões de acesso do funcionário no sistema. <a href="/admin_settings.php" style="color:hsl(var(--primary))" target="_blank">Gerenciar funções</a></span>';
echo '</label></div>';

echo '</div>';
echo '</div>';

// 3. Documentação Trabalhista
echo '<div>';
echo '<h3 style="font-size:18px;font-weight:700;margin-bottom:12px;padding-bottom:8px;border-bottom:2px solid hsl(var(--border))">2. Documentação Trabalhista</h3>';
echo '<div class="grid" style="gap:12px">';

echo '<div class="col3"><label>Número CTPS<input name="ctps_number" maxlength="20" value="' . h((string)($employee['ctps_number'] ?? '')) . '" placeholder="0000000"></label></div>';
echo '<div class="col3"><label>Série CTPS<input name="ctps_series" maxlength="10" value="' . h((string)($employee['ctps_series'] ?? '')) . '" placeholder="0000"></label></div>';
echo '<div class="col3"><label>Estado CTPS<input name="ctps_state" maxlength="2" value="' . h((string)($employee['ctps_state'] ?? '')) . '" placeholder="SP"></label></div>';
echo '<div class="col3"><label>Data Emissão CTPS<input type="date" name="ctps_issue_date" value="' . h((string)($employee['ctps_issue_date'] ?? '')) . '"></label></div>';

echo '<div class="col4"><label>PIS/PASEP/NIT<input name="pis_pasep" maxlength="20" value="' . h((string)($employee['pis_pasep'] ?? '')) . '" placeholder="000.00000.00-0"></label></div>';
echo '<div class="col4"><label>Título de Eleitor<input name="voter_title" maxlength="20" value="' . h((string)($employee['voter_title'] ?? '')) . '" placeholder="0000 0000 0000"></label></div>';
echo '<div class="col2"><label>Zona<input name="voter_zone" maxlength="10" value="' . h((string)($employee['voter_zone'] ?? '')) . '" placeholder="000"></label></div>';
echo '<div class="col2"><label>Seção<input name="voter_section" maxlength="10" value="' . h((string)($employee['voter_section'] ?? '')) . '" placeholder="0000"></label></div>';

echo '<div class="col4"><label>Certificado Reservista<input name="military_certificate" maxlength="20" value="' . h((string)($employee['military_certificate'] ?? '')) . '" placeholder="000000000"></label></div>';

echo '</div>';
echo '</div>';

// 3. Contato
echo '<div>';
echo '<h3 style="font-size:18px;font-weight:700;margin-bottom:12px;padding-bottom:8px;border-bottom:2px solid hsl(var(--border))">3. Contato</h3>';
echo '<div class="grid" style="gap:12px">';

echo '<div class="col6"><label>Telefone Principal<input name="phone" maxlength="30" value="' . h((string)($employee['phone'] ?? '')) . '" placeholder="(00) 00000-0000"></label></div>';
echo '<div class="col6"><label>Telefone Secundário<input name="phone_secondary" maxlength="30" value="' . h((string)($employee['phone_secondary'] ?? '')) . '" placeholder="(00) 00000-0000"></label></div>';

echo '<div class="col12"><label>E-mail<input type="email" name="email" maxlength="190" value="' . h((string)($employee['email'] ?? '')) . '" placeholder="funcionario@empresa.com"></label></div>';

echo '<div class="col6"><label>Contato de Emergência<input name="emergency_contact_name" maxlength="160" value="' . h((string)($employee['emergency_contact_name'] ?? '')) . '" placeholder="Nome do contato"></label></div>';
echo '<div class="col6"><label>Telefone Emergência<input name="emergency_contact_phone" maxlength="30" value="' . h((string)($employee['emergency_contact_phone'] ?? '')) . '" placeholder="(00) 00000-0000"></label></div>';

echo '</div>';
echo '</div>';

// 4. Endereço
echo '<div>';
echo '<h3 style="font-size:18px;font-weight:700;margin-bottom:12px;padding-bottom:8px;border-bottom:2px solid hsl(var(--border))">4. Endereço</h3>';
echo '<div class="grid" style="gap:12px">';

echo '<div class="col3"><label>CEP<input name="address_cep" maxlength="10" value="' . h((string)($employee['address_cep'] ?? '')) . '" placeholder="00000-000"></label></div>';
echo '<div class="col7"><label>Logradouro<input name="address_street" maxlength="255" value="' . h((string)($employee['address_street'] ?? '')) . '" placeholder="Rua, Avenida..."></label></div>';
echo '<div class="col2"><label>Número<input name="address_number" maxlength="20" value="' . h((string)($employee['address_number'] ?? '')) . '" placeholder="123"></label></div>';

echo '<div class="col4"><label>Complemento<input name="address_complement" maxlength="100" value="' . h((string)($employee['address_complement'] ?? '')) . '" placeholder="Apto, Bloco..."></label></div>';
echo '<div class="col4"><label>Bairro<input name="address_neighborhood" maxlength="100" value="' . h((string)($employee['address_neighborhood'] ?? '')) . '" placeholder="Centro"></label></div>';
echo '<div class="col4"><label>Cidade<input name="address_city" maxlength="100" value="' . h((string)($employee['address_city'] ?? '')) . '" placeholder="São Paulo"></label></div>';

echo '<div class="col6"><label>Estado<input name="address_state" maxlength="2" value="' . h((string)($employee['address_state'] ?? '')) . '" placeholder="SP"></label></div>';
echo '<div class="col6"><label>País<input name="address_country" maxlength="50" value="' . h((string)($employee['address_country'] ?? 'Brasil')) . '" placeholder="Brasil"></label></div>';

echo '</div>';
echo '</div>';

// 5. Dados Bancários
echo '<div>';
echo '<h3 style="font-size:18px;font-weight:700;margin-bottom:12px;padding-bottom:8px;border-bottom:2px solid hsl(var(--border))">5. Dados Bancários</h3>';
echo '<div class="grid" style="gap:12px">';

echo '<div class="col6"><label>Banco<input name="bank_name" maxlength="100" value="' . h((string)($employee['bank_name'] ?? '')) . '" placeholder="Banco do Brasil"></label></div>';
echo '<div class="col3"><label>Agência<input name="bank_agency" maxlength="20" value="' . h((string)($employee['bank_agency'] ?? '')) . '" placeholder="0000"></label></div>';
echo '<div class="col3"><label>Conta<input name="bank_account" maxlength="30" value="' . h((string)($employee['bank_account'] ?? '')) . '" placeholder="00000-0"></label></div>';

echo '<div class="col6"><label>Tipo de Conta<select name="bank_account_type">';
echo '<option value="">Selecione...</option>';
$accountTypes = ['corrente' => 'Corrente', 'poupanca' => 'Poupança'];
foreach ($accountTypes as $val => $label) {
    $sel = (!$isNew && $employee['bank_account_type'] === $val) ? ' selected' : '';
    echo '<option value="' . $val . '"' . $sel . '>' . $label . '</option>';
}
echo '</select></label></div>';
echo '<div class="col6"><label>Chave PIX<input name="bank_pix_key" maxlength="100" value="' . h((string)($employee['bank_pix_key'] ?? '')) . '" placeholder="CPF, e-mail, telefone ou chave aleatória"></label></div>';

echo '</div>';
echo '</div>';

// 6. Escolaridade
echo '<div>';
echo '<h3 style="font-size:18px;font-weight:700;margin-bottom:12px;padding-bottom:8px;border-bottom:2px solid hsl(var(--border))">6. Escolaridade</h3>';
echo '<div class="grid" style="gap:12px">';

echo '<div class="col6"><label>Nível de Escolaridade<select name="education_level">';
echo '<option value="">Selecione...</option>';
$educationLevels = [
    'fundamental_incompleto' => 'Fundamental Incompleto',
    'fundamental_completo' => 'Fundamental Completo',
    'medio_incompleto' => 'Médio Incompleto',
    'medio_completo' => 'Médio Completo',
    'superior_incompleto' => 'Superior Incompleto',
    'superior_completo' => 'Superior Completo',
    'pos_graduacao' => 'Pós-Graduação',
    'mestrado' => 'Mestrado',
    'doutorado' => 'Doutorado'
];
foreach ($educationLevels as $val => $label) {
    $sel = (!$isNew && $employee['education_level'] === $val) ? ' selected' : '';
    echo '<option value="' . $val . '"' . $sel . '>' . $label . '</option>';
}
echo '</select></label></div>';
echo '<div class="col6"><label>Curso<input name="education_course" maxlength="160" value="' . h((string)($employee['education_course'] ?? '')) . '" placeholder="Nome do curso"></label></div>';

echo '<div class="col6"><label>Instituição<input name="education_institution" maxlength="160" value="' . h((string)($employee['education_institution'] ?? '')) . '" placeholder="Nome da instituição"></label></div>';
echo '<div class="col6"><label>Ano de Conclusão<input type="number" name="education_year" min="1900" max="2100" value="' . h((string)($employee['education_year'] ?? '')) . '" placeholder="2020"></label></div>';

echo '</div>';
echo '</div>';

// 7. Saúde
echo '<div>';
echo '<h3 style="font-size:18px;font-weight:700;margin-bottom:12px;padding-bottom:8px;border-bottom:2px solid hsl(var(--border))">7. Saúde e Segurança</h3>';
echo '<div class="grid" style="gap:12px">';

echo '<div class="col4"><label>Tipo Sanguíneo<select name="blood_type">';
echo '<option value="">Selecione...</option>';
$bloodTypes = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
foreach ($bloodTypes as $type) {
    $sel = (!$isNew && $employee['blood_type'] === $type) ? ' selected' : '';
    echo '<option value="' . $type . '"' . $sel . '>' . $type . '</option>';
}
echo '</select></label></div>';
echo '<div class="col8"><label>Alergias<input name="allergies" maxlength="255" value="' . h((string)($employee['allergies'] ?? '')) . '" placeholder="Descreva alergias conhecidas"></label></div>';

echo '<div class="col12"><label>Restrições Médicas<textarea name="medical_restrictions" rows="2" placeholder="Descreva restrições médicas ou condições relevantes">' . h((string)($employee['medical_restrictions'] ?? '')) . '</textarea></label></div>';

echo '<div class="col6"><label>Data Exame Admissional<input type="date" name="admission_exam_date" value="' . h((string)($employee['admission_exam_date'] ?? '')) . '"></label></div>';
echo '<div class="col6"><label>Status Exame Admissional<select name="admission_exam_status">';
echo '<option value="">Selecione...</option>';
$examStatuses = ['pendente' => 'Pendente', 'aprovado' => 'Aprovado', 'reprovado' => 'Reprovado'];
foreach ($examStatuses as $val => $label) {
    $sel = (!$isNew && $employee['admission_exam_status'] === $val) ? ' selected' : '';
    echo '<option value="' . $val . '"' . $sel . '>' . $label . '</option>';
}
echo '</select></label></div>';

echo '</div>';
echo '</div>';

// Botões de ação
echo '<div style="display:flex;gap:10px;justify-content:flex-end;padding-top:12px;border-top:2px solid hsl(var(--border))">';
echo '<a class="btn" href="/hr_dashboard.php">Cancelar</a>';
echo '<button class="btn btnPrimary" type="submit">' . ($isNew ? 'Criar Funcionário' : 'Salvar Alterações') . '</button>';
echo '</div>';

echo '</form>';

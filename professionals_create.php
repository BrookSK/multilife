<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('professionals.manage');

// Capturar telefone se vier do chat
$phoneFromChat = isset($_GET['phone']) ? trim((string)$_GET['phone']) : '';
$fromChat = isset($_GET['from_chat']) && $_GET['from_chat'] === '1';

// Buscar especialidades cadastradas
$specialtiesStmt = db()->query("SELECT id, name FROM specialties WHERE status = 'active' ORDER BY name ASC");
$specialties = $specialtiesStmt->fetchAll();

view_header('Novo Profissional');

echo '<div class="card">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900;margin-bottom:6px">Novo Profissional</div>';
echo '<div style="color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Cadastro de profissional de saúde.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/professionals_list.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<div style="height:14px"></div>';

echo '<form method="post" action="/professionals_create_post.php" enctype="multipart/form-data">';

// 1. Identificação
echo '<div class="formSection">';
echo '<div class="formSectionTitle">1. Identificação do Profissional</div>';
echo '<div class="grid">';
echo '<div class="col6"><label>Nome completo *<input name="full_name" required maxlength="160" placeholder="Nome completo"></label></div>';
echo '<div class="col6"><label>CPF *<input name="cpf" required maxlength="20" placeholder="000.000.000-00"></label></div>';
echo '<div class="col6"><label>RG<input name="rg" maxlength="30" placeholder="Número do RG"></label></div>';
echo '<div class="col6"><label>Data de nascimento<input type="date" name="birth_date"></label></div>';
echo '<div class="col6"><label>Sexo<select name="sex"><option value="">Selecione</option><option value="Masculino">Masculino</option><option value="Feminino">Feminino</option></select></label></div>';
echo '<div class="col6"><label>Foto do profissional<input type="file" name="photo" accept="image/*"></label></div>';
echo '</div></div>';

// 2. Contato
echo '<div class="formSection">';
echo '<div class="formSectionTitle">2. Informações de Contato</div>';
echo '<div class="grid">';
echo '<div class="col6"><label>Telefone principal<input name="phone" maxlength="30" placeholder="5511999999999" value="' . h($phoneFromChat) . '"></label></div>';
echo '<div class="col6"><label>WhatsApp<input name="whatsapp" maxlength="30" placeholder="5511999999999" value="' . h($phoneFromChat) . '"></label></div>';
echo '<div class="col6"><label>E-mail *<input type="email" name="email" required maxlength="190" placeholder="email@exemplo.com"></label></div>';
echo '</div></div>';

// 3. Dados Profissionais
echo '<div class="formSection">';
echo '<div class="formSectionTitle">3. Dados Profissionais</div>';
echo '<div class="grid">';
echo '<div class="col6"><label>Especialidade *<select name="specialty_id" required><option value="">Selecione...</option>';
foreach ($specialties as $spec) {
    echo '<option value="' . (int)$spec['id'] . '">' . h($spec['name']) . '</option>';
}
echo '</select></label></div>';
echo '<div class="col6"><label>Registro profissional (CRM, CREFITO, etc.)<input name="professional_registration" maxlength="60" placeholder="Ex: CRM 123456"></label></div>';
echo '<div class="col6"><label>Estado do registro<select name="registration_state"><option value="">Selecione...</option><option value="SP">SP</option><option value="RJ">RJ</option><option value="MG">MG</option><option value="RS">RS</option><option value="PR">PR</option><option value="SC">SC</option><option value="BA">BA</option><option value="PE">PE</option><option value="CE">CE</option><option value="DF">DF</option></select></label></div>';
echo '<div class="col6"><label>Formação acadêmica<input name="education" maxlength="160" placeholder="Ex: Graduação em Fisioterapia - USP"></label></div>';
echo '</div></div>';

// 4. Endereço
echo '<div class="formSection">';
echo '<div class="formSectionTitle">4. Endereço</div>';
echo '<div class="grid">';
echo '<div class="col6"><label>CEP<input name="address_zip" maxlength="12" placeholder="00000-000"></label></div>';
echo '<div class="col6"><label>Logradouro<input name="address_street" maxlength="160" placeholder="Rua, Avenida, etc."></label></div>';
echo '<div class="col6"><label>Número<input name="address_number" maxlength="20" placeholder="Número"></label></div>';
echo '<div class="col6"><label>Complemento<input name="address_complement" maxlength="80" placeholder="Apto, Sala, etc."></label></div>';
echo '<div class="col6"><label>Bairro<input name="address_neighborhood" maxlength="80" placeholder="Bairro"></label></div>';
echo '<div class="col6"><label>Cidade<input name="address_city" maxlength="80" placeholder="Cidade"></label></div>';
echo '<div class="col6"><label>Estado<select name="address_state"><option value="">Selecione...</option><option value="SP">SP</option><option value="RJ">RJ</option><option value="MG">MG</option><option value="RS">RS</option><option value="PR">PR</option><option value="SC">SC</option></select></label></div>';
echo '</div></div>';

// Botões
echo '<div style="margin-top:24px;display:flex;gap:12px;justify-content:flex-end">';
echo '<a href="/professionals_list.php" class="btn">Cancelar</a>';
echo '<button type="submit" class="btn btnPrimary">Cadastrar Profissional</button>';
echo '</div>';

echo '</form>';
echo '</div>';

view_footer();

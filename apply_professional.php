<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

view_header('Candidatura de Profissional');

echo '<div class="card">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900;margin-bottom:6px">Candidatura de Profissional</div>';
echo '<div style="color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Preencha seus dados para avaliação. Após aprovação, você receberá acesso ao sistema.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/login.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<div style="height:14px"></div>';

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
echo '<label>Cidades de atuação<textarea name="cities_of_operation" rows="2" placeholder="Ex: São Paulo, Guarulhos"></textarea></label>';
echo '</div>';

echo '</div>';

echo '<div style="font-weight:900;margin-top:6px">Identificação</div>';

echo '<div class="grid">';
echo '<div class="col6"><label>Estado civil<input name="marital_status" maxlength="40"></label></div>';
echo '<div class="col6"><label>Sexo<input name="sex" maxlength="20"></label></div>';
echo '<div class="col6"><label>Religião<input name="religion" maxlength="60"></label></div>';
echo '<div class="col6"><label>Naturalidade<input name="birthplace" maxlength="120"></label></div>';
echo '<div class="col6"><label>Nacionalidade<input name="nationality" maxlength="80"></label></div>';
echo '<div class="col6"><label>Escolaridade<input name="education_level" maxlength="80"></label></div>';
echo '</div>';

echo '<div style="font-weight:900;margin-top:6px">Endereço</div>';

echo '<div class="grid">';
echo '<div class="col6"><label>Logradouro<input name="address_street" maxlength="160"></label></div>';
echo '<div class="col6"><label>Número<input name="address_number" maxlength="20"></label></div>';
echo '<div class="col6"><label>Complemento<input name="address_complement" maxlength="80"></label></div>';
echo '<div class="col6"><label>Bairro<input name="address_neighborhood" maxlength="80"></label></div>';
echo '<div class="col6"><label>Cidade<input name="address_city" maxlength="120"></label></div>';
echo '<div class="col6"><label>UF<input name="address_state" maxlength="2" placeholder="SP" style="text-transform:uppercase"></label></div>';
echo '<div class="col6"><label>CEP<input name="address_zip" maxlength="12" placeholder="00000-000"></label></div>';
echo '</div>';

echo '<div style="font-weight:900;margin-top:6px">Documentos</div>';

echo '<div class="grid">';
echo '<div class="col6"><label>RG<input name="rg" maxlength="30"></label></div>';
echo '<div class="col6"><label>Sigla do Conselho<input name="council_abbr" maxlength="20" placeholder="COREN, CRM"></label></div>';
echo '<div class="col6"><label>Número do Conselho<input name="council_number" maxlength="30"></label></div>';
echo '<div class="col6"><label>UF do Conselho<input name="council_state" maxlength="2" placeholder="SP" style="text-transform:uppercase"></label></div>';
echo '</div>';

echo '<div style="font-weight:900;margin-top:6px">Dados bancários</div>';

echo '<div class="grid">';
echo '<div class="col6"><label>Banco<input name="bank_name" maxlength="80"></label></div>';
echo '<div class="col6"><label>Agência<input name="bank_agency" maxlength="20"></label></div>';
echo '<div class="col6"><label>Conta<input name="bank_account" maxlength="30"></label></div>';
echo '<div class="col6"><label>Tipo de conta<input name="bank_account_type" maxlength="20" placeholder="corrente/poupança"></label></div>';
echo '<div class="col6"><label>Titular<input name="bank_account_holder" maxlength="160"></label></div>';
echo '<div class="col6"><label>CPF do titular<input name="bank_account_holder_cpf" maxlength="20"></label></div>';
echo '<div class="col6"><label>PIX<input name="pix_key" maxlength="120"></label></div>';
echo '<div class="col6"><label>Titular do PIX<input name="pix_holder" maxlength="160"></label></div>';
echo '</div>';

echo '<div style="font-weight:900;margin-top:6px">Informações técnicas</div>';

echo '<label>Experiência em home care<textarea name="home_care_experience" rows="3"></textarea></label>';

echo '<div class="grid">';
echo '<div class="col6"><label>Tempo de atuação<input name="years_of_experience" maxlength="40" placeholder="Ex: 5 anos"></label></div>';
echo '<div class="col6"><label>Especializações/Pós<textarea name="specializations" rows="2"></textarea></label></div>';
echo '</div>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end;margin-top:6px">';
echo '<a class="btn" href="/login.php">Cancelar</a>';
echo '<button class="btn btnPrimary" type="submit">Enviar candidatura</button>';
echo '</div>';

echo '</form>';

echo '</div>';

view_footer();

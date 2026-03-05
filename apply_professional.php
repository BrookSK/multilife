<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

// Página pública - não requer autenticação
$user = auth_user();
$isPublic = ($user === null);

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
    echo 'input[type="text"],input[type="email"],input[type="password"],input[type="number"],select,textarea{width:100%;border-radius:10px;border:1px solid hsl(var(--input));background:hsla(var(--muted)/.50);color:hsl(var(--foreground));padding:10px 12px;outline:none;font-size:14px;transition:background .15s ease,box-shadow .15s ease,border-color .15s ease}';
    echo 'textarea{min-height:96px;resize:vertical}';
    echo 'input:focus,select:focus,textarea:focus{background:hsl(var(--card));border-color:hsla(var(--ring)/.55);box-shadow:0 0 0 4px hsla(var(--ring)/.15)}';
    echo '::placeholder{color:hsl(var(--muted-foreground))}';
    echo 'label{display:grid;gap:7px;font-size:13px;font-weight:600;color:hsl(var(--foreground))}';
    echo 'form{display:grid;gap:14px}';
    echo '.formSection{padding:18px;border-radius:12px;background:hsla(var(--muted)/.25);border:1px solid hsl(var(--border));margin-bottom:14px}';
    echo '.formSectionTitle{font-size:15px;font-weight:800;color:hsl(var(--foreground));margin-bottom:14px;padding-bottom:10px;border-bottom:1px solid hsl(var(--border))}';
    echo '.card{background:hsl(var(--card));border:1px solid hsl(var(--border));box-shadow:var(--shadow-elevated);border-radius:calc(var(--radius) + 6px);padding:18px;color:hsl(var(--card-foreground))}';
    echo '.grid{display:grid;grid-template-columns:repeat(12,1fr);gap:14px}';
    echo '.col6{grid-column:span 6}';
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
$flash = flash_get();
if ($flash) {
    $bgColor = $flash['type'] === 'success' ? 'hsl(142,76%,36%)' : 'hsl(0,84%,60%)';
    $textColor = '#fff';
    echo '<div style="padding:12px 16px;border-radius:10px;background:' . $bgColor . ';color:' . $textColor . ';font-weight:600;margin-bottom:14px">';
    echo h($flash['message']);
    echo '</div>';
}

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
echo '<div class="col6"><label>UF (2 letras)<input name="address_state" maxlength="2" placeholder="SP" style="text-transform:uppercase"></label></div>';
echo '<div class="col6"><label>CEP<input name="address_zip" maxlength="12" placeholder="00000-000"></label></div>';
echo '</div>';

echo '<div style="font-weight:900;margin-top:6px">Documentos</div>';

echo '<div class="grid">';
echo '<div class="col6"><label>RG<input name="rg" maxlength="30"></label></div>';
echo '<div class="col6"><label>Sigla do Conselho<input name="council_abbr" maxlength="20" placeholder="COREN, CRM"></label></div>';
echo '<div class="col6"><label>Número do Conselho<input name="council_number" maxlength="30"></label></div>';
echo '<div class="col6"><label>UF do Conselho (2 letras)<input name="council_state" maxlength="2" placeholder="SP" style="text-transform:uppercase"></label></div>';
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
if ($isPublic) {
    echo '<button class="btn btnPrimary" type="submit" style="font-size:15px;padding:12px 24px">Enviar Candidatura</button>';
} else {
    echo '<a class="btn" href="/professional_applications_list.php">Cancelar</a>';
    echo '<button class="btn btnPrimary" type="submit">Enviar candidatura</button>';
}
echo '</div>';

echo '</form>';

echo '</div>';

if ($isPublic) {
    echo '</div>';
    echo '</body>';
    echo '</html>';
} else {
    view_footer();
}

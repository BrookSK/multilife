<?php
/**
 * Página PÚBLICA (sem login) de atualização/complemento cadastral do profissional.
 * Enviada por link após a aprovação na pré-admissão. Acesso via token único.
 *
 * O profissional completa os dados no padrão da candidatura (professional_applications),
 * incluindo dados bancários e PIX.
 */

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$token = trim((string)($_GET['token'] ?? ''));

function cr_invalid_link(): void
{
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><title>Link inválido</title></head>'
        . '<body style="font-family:sans-serif;text-align:center;padding:60px;color:#1a1a2e">'
        . '<h2>Link inválido ou expirado</h2>'
        . '<p>Verifique o link recebido por WhatsApp/e-mail ou entre em contato com a equipe MultiLife Care.</p>'
        . '</body></html>';
    exit;
}

if ($token === '' || strlen($token) < 32) {
    cr_invalid_link();
}

// Garantir colunas de apoio (idempotente)
try { db()->exec("ALTER TABLE users ADD COLUMN registration_token VARCHAR(64) NULL"); } catch (Throwable $e) {}
try { db()->exec("ALTER TABLE users ADD COLUMN registration_token_created_at DATETIME NULL"); } catch (Throwable $e) {}
try { db()->exec("ALTER TABLE users ADD COLUMN city VARCHAR(120) NULL"); } catch (Throwable $e) {}
try { db()->exec("ALTER TABLE users ADD COLUMN is_pre_registration TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}

// Localizar o profissional pelo token
$uStmt = db()->prepare('SELECT id, name, email, phone, specialty, city FROM users WHERE registration_token = :t LIMIT 1');
$uStmt->execute(['t' => $token]);
$user = $uStmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    cr_invalid_link();
}

$userId = (int)$user['id'];

// Buscar candidatura vinculada (se já existir) para pré-preencher
$app = null;
try {
    $aStmt = db()->prepare('SELECT * FROM professional_applications WHERE created_user_id = :uid ORDER BY id DESC LIMIT 1');
    $aStmt->execute(['uid' => $userId]);
    $app = $aStmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) { $app = null; }

// Especialidades ativas
$specialties = [];
try {
    $specialties = db()->query("SELECT name FROM specialties WHERE status = 'active' ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) { $specialties = []; }

// Helper para valor pré-preenchido: prioridade candidatura -> user
$val = function (string $appField, ?string $userField = null) use ($app, $user): string {
    if ($app !== null && isset($app[$appField]) && trim((string)$app[$appField]) !== '') {
        return (string)$app[$appField];
    }
    if ($userField !== null && isset($user[$userField]) && trim((string)$user[$userField]) !== '') {
        return (string)$user[$userField];
    }
    return '';
};

// Lista de UFs
$ufs = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];
$ufNames = [
    'AC'=>'Acre','AL'=>'Alagoas','AP'=>'Amapá','AM'=>'Amazonas','BA'=>'Bahia','CE'=>'Ceará','DF'=>'Distrito Federal',
    'ES'=>'Espírito Santo','GO'=>'Goiás','MA'=>'Maranhão','MT'=>'Mato Grosso','MS'=>'Mato Grosso do Sul','MG'=>'Minas Gerais',
    'PA'=>'Pará','PB'=>'Paraíba','PR'=>'Paraná','PE'=>'Pernambuco','PI'=>'Piauí','RJ'=>'Rio de Janeiro','RN'=>'Rio Grande do Norte',
    'RS'=>'Rio Grande do Sul','RO'=>'Rondônia','RR'=>'Roraima','SC'=>'Santa Catarina','SP'=>'São Paulo','SE'=>'Sergipe','TO'=>'Tocantins',
];
function cr_uf_options(array $ufs, array $ufNames, string $selected): string {
    $out = '<option value="">Selecione...</option>';
    foreach ($ufs as $uf) {
        $sel = ($selected === $uf) ? ' selected' : '';
        $out .= '<option value="' . htmlspecialchars($uf, ENT_QUOTES) . '"' . $sel . '>' . htmlspecialchars($uf . ' - ' . ($ufNames[$uf] ?? $uf), ENT_QUOTES) . '</option>';
    }
    return $out;
}
function cr_select(string $name, array $options, string $selected, string $placeholder = 'Selecione...'): string {
    $out = '<select name="' . htmlspecialchars($name, ENT_QUOTES) . '"><option value="">' . htmlspecialchars($placeholder, ENT_QUOTES) . '</option>';
    foreach ($options as $opt) {
        $sel = ($selected === $opt) ? ' selected' : '';
        $out .= '<option value="' . htmlspecialchars($opt, ENT_QUOTES) . '"' . $sel . '>' . htmlspecialchars($opt, ENT_QUOTES) . '</option>';
    }
    $out .= '</select>';
    return $out;
}

$logoUrl = (string)admin_setting_get('app.logo_url', '');
$saved = isset($_GET['ok']);

$addrState = $val('address_state');
$councilState = $val('council_state');
$addrCity = $val('address_city', 'city');

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES); }
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Atualização Cadastral - MultiLife Care</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f7fa; color: #1a1a2e; line-height: 1.6; }
        .container { max-width: 820px; margin: 0 auto; padding: 24px 16px 60px; }
        .card { background: #fff; border-radius: 12px; padding: 24px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,.06); }
        .logo { text-align: center; margin-bottom: 20px; }
        .logo img { max-height: 54px; }
        h1 { font-size: 22px; font-weight: 700; margin-bottom: 8px; color: #00a884; }
        .section-title { font-size: 16px; font-weight: 700; margin: 22px 0 10px; color: #1a1a2e; border-bottom: 2px solid #e2e8f0; padding-bottom: 6px; }
        label { display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #334155; }
        input, select, textarea { width: 100%; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px; font-family: inherit; }
        textarea { min-height: 80px; resize: vertical; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .col12 { grid-column: span 2; }
        .field { margin-bottom: 2px; }
        .success { background: #d1fae5; border-left: 4px solid #10b981; padding: 12px 16px; border-radius: 0 8px 8px 0; margin-bottom: 20px; color: #065f46; font-weight: 600; }
        .intro { color: #64748b; font-size: 14px; margin-top: 4px; }
        .btn { display: inline-block; padding: 13px 28px; background: #00a884; color: #fff; border: none; border-radius: 8px; font-size: 15px; font-weight: 700; cursor: pointer; }
        .btn:hover { background: #06976f; }
        .actions { text-align: center; margin-top: 24px; }
        @media (max-width: 640px) { .grid { grid-template-columns: 1fr; } .col12 { grid-column: span 1; } }
    </style>
</head>
<body>
<div class="container">
    <?php if ($logoUrl !== ''): ?>
    <div class="logo"><img src="<?= e($logoUrl) ?>" alt="MultiLife Care"></div>
    <?php endif; ?>

    <div class="card">
        <h1>Atualização Cadastral</h1>
        <p class="intro">Olá, <strong><?= e($user['name']) ?></strong>! Complete seus dados abaixo para finalizarmos seu cadastro. Leva poucos minutos.</p>
    </div>

    <?php if ($saved): ?>
    <div class="success">✅ Cadastro enviado com sucesso! Obrigado. Nossa equipe já recebeu suas informações.</div>
    <?php endif; ?>

    <form method="post" action="/complete_registration_post.php" novalidate>
        <input type="hidden" name="token" value="<?= e($token) ?>">

        <div class="card">
            <div class="section-title">Dados de contato</div>
            <div class="grid">
                <div class="field col12"><label>Nome completo *</label><input name="full_name" required maxlength="160" value="<?= e($val('full_name', 'name')) ?>"></div>
                <div class="field"><label>E-mail</label><input type="email" name="email" maxlength="190" value="<?= e($val('email', 'email')) ?>" placeholder="email@exemplo.com"></div>
                <div class="field"><label>Telefone/WhatsApp *</label><input name="phone" required maxlength="30" value="<?= e($val('phone', 'phone')) ?>"></div>
                <div class="field col12"><label>Cidades de atuação</label><input name="cities_of_operation" maxlength="255" value="<?= e($val('cities_of_operation')) ?>" placeholder="Ex: São Paulo, Campinas"></div>
            </div>

            <div class="section-title">Identificação</div>
            <div class="grid">
                <div class="field"><label>Estado civil</label><?= cr_select('marital_status', ['Solteiro(a)','Casado(a)','Divorciado(a)','Viúvo(a)','União Estável'], $val('marital_status')) ?></div>
                <div class="field"><label>Sexo</label><?= cr_select('sex', ['Masculino','Feminino','Outro'], $val('sex')) ?></div>
                <div class="field"><label>Religião</label><input name="religion" maxlength="60" value="<?= e($val('religion')) ?>"></div>
                <div class="field"><label>Naturalidade</label><input name="birthplace" maxlength="120" value="<?= e($val('birthplace')) ?>"></div>
                <div class="field"><label>Nacionalidade</label><input name="nationality" maxlength="80" value="<?= e($val('nationality')) ?>"></div>
                <div class="field"><label>Escolaridade</label><?= cr_select('education_level', ['Ensino Fundamental','Ensino Médio','Ensino Superior','Pós-graduação','Mestrado','Doutorado'], $val('education_level')) ?></div>
            </div>

            <div class="section-title">Endereço</div>
            <div class="grid">
                <div class="field"><label>Logradouro</label><input name="address_street" maxlength="160" value="<?= e($val('address_street')) ?>"></div>
                <div class="field"><label>Número</label><input name="address_number" maxlength="20" value="<?= e($val('address_number')) ?>"></div>
                <div class="field"><label>Complemento</label><input name="address_complement" maxlength="80" value="<?= e($val('address_complement')) ?>"></div>
                <div class="field"><label>Bairro</label><input name="address_neighborhood" maxlength="80" value="<?= e($val('address_neighborhood')) ?>"></div>
                <div class="field"><label>UF</label><select name="address_state"><?= cr_uf_options($ufs, $ufNames, $addrState) ?></select></div>
                <div class="field"><label>Cidade</label><input name="address_city" maxlength="120" value="<?= e($addrCity) ?>"></div>
                <div class="field"><label>CEP</label><input name="address_zip" maxlength="12" value="<?= e($val('address_zip')) ?>" placeholder="00000-000"></div>
            </div>

            <div class="section-title">Documentos e Conselho</div>
            <div class="grid">
                <div class="field"><label>RG</label><input name="rg" maxlength="30" value="<?= e($val('rg')) ?>"></div>
                <div class="field"><label>Sigla do Conselho</label><?= cr_select('council_abbr', ['COREN','CRM','CRF','CREFITO','CRN','CREFONO','CRP','CRBM','Outro'], $val('council_abbr')) ?></div>
                <div class="field"><label>Número do Conselho</label><input name="council_number" maxlength="30" value="<?= e($val('council_number')) ?>"></div>
                <div class="field"><label>UF do Conselho</label><select name="council_state"><?= cr_uf_options($ufs, $ufNames, $councilState) ?></select></div>
            </div>

            <div class="section-title">Dados bancários / PIX</div>
            <div class="grid">
                <div class="field"><label>Banco</label><input name="bank_name" maxlength="80" value="<?= e($val('bank_name')) ?>"></div>
                <div class="field"><label>Agência</label><input name="bank_agency" maxlength="20" value="<?= e($val('bank_agency')) ?>"></div>
                <div class="field"><label>Conta</label><input name="bank_account" maxlength="30" value="<?= e($val('bank_account')) ?>"></div>
                <div class="field"><label>Tipo de conta</label><?= cr_select('bank_account_type', ['Corrente','Poupança','Salário'], $val('bank_account_type')) ?></div>
                <div class="field"><label>Titular da conta</label><input name="bank_account_holder" maxlength="160" value="<?= e($val('bank_account_holder')) ?>"></div>
                <div class="field"><label>CPF do titular</label><input name="bank_account_holder_cpf" maxlength="20" value="<?= e($val('bank_account_holder_cpf')) ?>"></div>
                <div class="field"><label>Chave PIX</label><input name="pix_key" maxlength="120" value="<?= e($val('pix_key')) ?>"></div>
                <div class="field"><label>Titular do PIX</label><input name="pix_holder" maxlength="160" value="<?= e($val('pix_holder')) ?>"></div>
            </div>

            <div class="section-title">Informações técnicas</div>
            <div class="grid">
                <div class="field">
                    <label>Especialidade principal</label>
                    <?php
                    $specSel = $val('specialty', 'specialty');
                    echo '<select name="specialty"><option value="">Selecione...</option>';
                    foreach ($specialties as $sp) {
                        $sel = ($specSel === (string)$sp) ? ' selected' : '';
                        echo '<option value="' . e($sp) . '"' . $sel . '>' . e($sp) . '</option>';
                    }
                    echo '</select>';
                    ?>
                </div>
                <div class="field"><label>Tempo de atuação</label><input name="years_of_experience" maxlength="40" value="<?= e($val('years_of_experience')) ?>" placeholder="Ex: 5 anos"></div>
                <div class="field col12"><label>Experiência em home care</label><textarea name="home_care_experience"><?= e($val('home_care_experience')) ?></textarea></div>
                <div class="field col12"><label>Especializações adicionais</label><textarea name="specializations" placeholder="Separadas por vírgula (opcional)"><?= e($val('specializations')) ?></textarea></div>
            </div>

            <div class="actions">
                <button class="btn" type="submit">Enviar cadastro</button>
            </div>
        </div>
    </form>
</div>
</body>
</html>

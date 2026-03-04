<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('professional_applications.manage');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = db()->prepare('SELECT pa.*, u.name AS reviewed_by_name FROM professional_applications pa LEFT JOIN users u ON u.id = pa.reviewed_by_user_id WHERE pa.id = :id');
$stmt->execute(['id' => $id]);
$pa = $stmt->fetch();

if (!$pa) {
    flash_set('error', 'Candidatura não encontrada.');
    header('Location: /professional_applications_list.php');
    exit;
}

view_header('Candidatura #' . (string)$pa['id']);

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:12px;color:hsl(var(--muted-foreground));margin-bottom:6px">Candidatura</div>';
echo '<div style="font-size:22px;font-weight:900">#' . (int)$pa['id'] . ' — ' . h((string)$pa['full_name']) . '</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">';
echo '<strong>Status:</strong> ' . h((string)$pa['status']) . ' &nbsp; <strong>E-mail:</strong> ' . h((string)$pa['email']) . ' &nbsp; <strong>Telefone:</strong> ' . h((string)$pa['phone']);
echo '</div>';
echo '</div>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/professional_applications_list.php">Voltar</a>';

echo '<form method="post" action="/professional_applications_approve_post.php" style="display:inline">';
echo '<input type="hidden" name="id" value="' . (int)$pa['id'] . '">';
echo '<button class="btn btnPrimary" type="submit" onclick="return confirm(\'Aprovar e criar acesso?\')">Aprovar</button>';
echo '</form>';

echo '<form method="post" action="/professional_applications_need_more_info_post.php" style="display:inline">';
echo '<input type="hidden" name="id" value="' . (int)$pa['id'] . '">';
echo '<button class="btn" type="submit">Solicitar complemento</button>';
echo '</form>';

echo '<form method="post" action="/professional_applications_reject_post.php" style="display:inline">';
echo '<input type="hidden" name="id" value="' . (int)$pa['id'] . '">';
echo '<button class="btn" type="submit" onclick="return confirm(\'Reprovar candidatura?\')">Reprovar</button>';
echo '</form>';

echo '</div>';
echo '</div>';

echo '</section>';

$sections = [
    'Identificação' => [
        'Estado civil' => $pa['marital_status'] ?? '',
        'Sexo' => $pa['sex'] ?? '',
        'Religião' => $pa['religion'] ?? '',
        'Naturalidade' => $pa['birthplace'] ?? '',
        'Nacionalidade' => $pa['nationality'] ?? '',
        'Escolaridade' => $pa['education_level'] ?? '',
        'Cidades de atuação' => $pa['cities_of_operation'] ?? '',
    ],
    'Endereço' => [
        'Logradouro' => $pa['address_street'] ?? '',
        'Número' => $pa['address_number'] ?? '',
        'Complemento' => $pa['address_complement'] ?? '',
        'Bairro' => $pa['address_neighborhood'] ?? '',
        'Cidade' => $pa['address_city'] ?? '',
        'UF' => $pa['address_state'] ?? '',
        'CEP' => $pa['address_zip'] ?? '',
    ],
    'Documentos' => [
        'RG' => $pa['rg'] ?? '',
        'Conselho' => trim((string)($pa['council_abbr'] ?? '') . ' ' . (string)($pa['council_number'] ?? '') . ((string)($pa['council_state'] ?? '') !== '' ? '/' . (string)($pa['council_state'] ?? '') : '')),
    ],
    'Dados bancários' => [
        'Banco' => $pa['bank_name'] ?? '',
        'Agência' => $pa['bank_agency'] ?? '',
        'Conta' => $pa['bank_account'] ?? '',
        'Tipo' => $pa['bank_account_type'] ?? '',
        'Titular' => $pa['bank_account_holder'] ?? '',
        'CPF titular' => $pa['bank_account_holder_cpf'] ?? '',
        'PIX' => $pa['pix_key'] ?? '',
        'Titular PIX' => $pa['pix_holder'] ?? '',
    ],
    'Informações técnicas' => [
        'Experiência home care' => $pa['home_care_experience'] ?? '',
        'Tempo de atuação' => $pa['years_of_experience'] ?? '',
        'Especializações/Pós' => $pa['specializations'] ?? '',
    ],
    'Revisão (Admin)' => [
        'Nota' => $pa['admin_note'] ?? '',
        'Revisado por' => $pa['reviewed_by_name'] ?? '',
        'Revisado em' => $pa['reviewed_at'] ?? '',
        'Usuário criado' => $pa['created_user_id'] ?? '',
    ],
];

foreach ($sections as $title => $fields) {
    echo '<section class="card col12">';
    echo '<div style="font-weight:900;margin-bottom:8px">' . h($title) . '</div>';
    echo '<div style="display:grid;gap:8px">';
    foreach ($fields as $label => $value) {
        $v = trim((string)$value);
        echo '<div class="pill" style="display:block">';
        echo '<strong>' . h($label) . ':</strong> ' . h($v !== '' ? $v : '-');
        echo '</div>';
    }
    echo '</div>';
    echo '</section>';
}

echo '</div>';

view_footer();

<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

// Debug: verificar se POST está sendo recebido
error_log('=== APPLY PROFESSIONAL POST INICIADO ===');
error_log('POST data: ' . print_r($_POST, true));

$fullName = trim((string)($_POST['full_name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$phone = trim((string)($_POST['phone'] ?? ''));

error_log("Dados recebidos - Nome: $fullName, Email: $email, Phone: $phone");

if ($fullName === '' || $email === '' || $phone === '') {
    flash_set('error', 'Preencha nome, e-mail e telefone.');
    header('Location: /apply_professional.php');
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash_set('error', 'E-mail inválido.');
    header('Location: /apply_professional.php');
    exit;
}

// Evita duplicidade com usuários já existentes
$stmt = db()->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
$stmt->execute(['email' => $email]);
if ($stmt->fetch()) {
    flash_set('error', 'Já existe uma conta com esse e-mail.');
    header('Location: /apply_professional.php');
    exit;
}

// Evita duplicidade de candidaturas
$stmt = db()->prepare('SELECT id FROM professional_applications WHERE email = :email LIMIT 1');
$stmt->execute(['email' => $email]);
if ($stmt->fetch()) {
    flash_set('error', 'Já existe uma candidatura com esse e-mail. Aguarde a avaliação.');
    header('Location: /apply_professional.php');
    exit;
}

// UF agora vem de dropdown, já está validado
$state1 = trim((string)($_POST['address_state'] ?? ''));
$state2 = trim((string)($_POST['council_state'] ?? ''));

$stmt = db()->prepare(
    'INSERT INTO professional_applications (
        status, full_name, email, phone,
        cities_of_operation, marital_status, sex, religion, birthplace, nationality, education_level,
        address_street, address_number, address_complement, address_neighborhood, address_city, address_state, address_zip,
        rg, council_abbr, council_number, council_state,
        bank_name, bank_agency, bank_account, bank_account_type, bank_account_holder, bank_account_holder_cpf, pix_key, pix_holder,
        home_care_experience, years_of_experience, specializations
     ) VALUES (
        \'pending\', :full_name, :email, :phone,
        :cities_of_operation, :marital_status, :sex, :religion, :birthplace, :nationality, :education_level,
        :address_street, :address_number, :address_complement, :address_neighborhood, :address_city, :address_state, :address_zip,
        :rg, :council_abbr, :council_number, :council_state,
        :bank_name, :bank_agency, :bank_account, :bank_account_type, :bank_account_holder, :bank_account_holder_cpf, :pix_key, :pix_holder,
        :home_care_experience, :years_of_experience, :specializations
     )'
);

$fields = [
    'full_name','email','phone','cities_of_operation','marital_status','sex','religion','birthplace','nationality','education_level',
    'address_street','address_number','address_complement','address_neighborhood','address_city','address_zip',
    'rg','council_abbr','council_number',
    'bank_name','bank_agency','bank_account','bank_account_type','bank_account_holder','bank_account_holder_cpf','pix_key','pix_holder',
    'home_care_experience','years_of_experience','specializations',
];

$params = [
    'full_name' => $fullName,
    'email' => $email,
    'phone' => $phone,
    'address_state' => $state1 !== '' ? $state1 : null,
    'council_state' => $state2 !== '' ? $state2 : null,
];

foreach ($fields as $f) {
    if (!array_key_exists($f, $params)) {
        $v = trim((string)($_POST[$f] ?? ''));
        $params[$f] = ($v !== '') ? $v : null;
    }
}

error_log('Tentando inserir candidatura no banco...');
error_log('Params: ' . print_r($params, true));

try {
    $stmt->execute($params);
    error_log('Candidatura inserida com sucesso! ID: ' . db()->lastInsertId());
    flash_set('success', 'Candidatura enviada com sucesso! Aguarde a avaliação da nossa equipe. Você pode fazer login após aprovação.');
    header('Location: /apply_professional.php');
    exit;
} catch (Exception $e) {
    error_log('Erro ao inserir candidatura: ' . $e->getMessage());
    flash_set('error', 'Erro ao enviar candidatura. Por favor, tente novamente ou entre em contato com o suporte.');
    header('Location: /apply_professional.php');
    exit;
}

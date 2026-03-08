<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('hr.manage');

$employeeId = trim((string)($_POST['employee_id'] ?? ''));
$isNew = $employeeId === 'new';

// Dados Pessoais
$fullName = trim((string)($_POST['full_name'] ?? ''));
$cpf = trim((string)($_POST['cpf'] ?? ''));
$rg = trim((string)($_POST['rg'] ?? ''));
$rgIssuer = trim((string)($_POST['rg_issuer'] ?? ''));
$rgIssueDate = trim((string)($_POST['rg_issue_date'] ?? ''));
$birthDate = trim((string)($_POST['birth_date'] ?? ''));
$gender = trim((string)($_POST['gender'] ?? ''));
$maritalStatus = trim((string)($_POST['marital_status'] ?? ''));
$nationality = trim((string)($_POST['nationality'] ?? ''));
$birthCity = trim((string)($_POST['birth_city'] ?? ''));
$birthState = trim((string)($_POST['birth_state'] ?? ''));
$motherName = trim((string)($_POST['mother_name'] ?? ''));
$fatherName = trim((string)($_POST['father_name'] ?? ''));

// Dados Profissionais - buscar da role selecionada
$roleId = (int)($_POST['role_id'] ?? 0);

// Buscar nome da role para usar como position
$position = '';
$department = '';
if ($roleId > 0) {
    $roleStmt = db()->prepare('SELECT name FROM roles WHERE id = :id');
    $roleStmt->execute(['id' => $roleId]);
    $roleData = $roleStmt->fetch();
    if ($roleData) {
        $position = (string)$roleData['name']; // Ex: "Administrador", "Financeiro"
        // Departamento será o mesmo que a função por padrão
        $department = (string)$roleData['name'];
    }
}

// Documentação Trabalhista
$ctpsNumber = trim((string)($_POST['ctps_number'] ?? ''));
$ctpsSeries = trim((string)($_POST['ctps_series'] ?? ''));
$ctpsState = trim((string)($_POST['ctps_state'] ?? ''));
$ctpsIssueDate = trim((string)($_POST['ctps_issue_date'] ?? ''));
$pisPasep = trim((string)($_POST['pis_pasep'] ?? ''));
$voterTitle = trim((string)($_POST['voter_title'] ?? ''));
$voterZone = trim((string)($_POST['voter_zone'] ?? ''));
$voterSection = trim((string)($_POST['voter_section'] ?? ''));
$militaryCertificate = trim((string)($_POST['military_certificate'] ?? ''));

// Contato
$phone = trim((string)($_POST['phone'] ?? ''));
$phoneSecondary = trim((string)($_POST['phone_secondary'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$emergencyContactName = trim((string)($_POST['emergency_contact_name'] ?? ''));
$emergencyContactPhone = trim((string)($_POST['emergency_contact_phone'] ?? ''));

// Endereço
$addressCep = trim((string)($_POST['address_cep'] ?? ''));
$addressStreet = trim((string)($_POST['address_street'] ?? ''));
$addressNumber = trim((string)($_POST['address_number'] ?? ''));
$addressComplement = trim((string)($_POST['address_complement'] ?? ''));
$addressNeighborhood = trim((string)($_POST['address_neighborhood'] ?? ''));
$addressCity = trim((string)($_POST['address_city'] ?? ''));
$addressState = trim((string)($_POST['address_state'] ?? ''));
$addressCountry = trim((string)($_POST['address_country'] ?? ''));

// Dados Bancários
$bankName = trim((string)($_POST['bank_name'] ?? ''));
$bankAgency = trim((string)($_POST['bank_agency'] ?? ''));
$bankAccount = trim((string)($_POST['bank_account'] ?? ''));
$bankAccountType = trim((string)($_POST['bank_account_type'] ?? ''));
$bankPixKey = trim((string)($_POST['bank_pix_key'] ?? ''));

// Escolaridade
$educationLevel = trim((string)($_POST['education_level'] ?? ''));
$educationCourse = trim((string)($_POST['education_course'] ?? ''));
$educationInstitution = trim((string)($_POST['education_institution'] ?? ''));
$educationYear = trim((string)($_POST['education_year'] ?? ''));

// Saúde
$bloodType = trim((string)($_POST['blood_type'] ?? ''));
$allergies = trim((string)($_POST['allergies'] ?? ''));
$medicalRestrictions = trim((string)($_POST['medical_restrictions'] ?? ''));

// Validações
if ($fullName === '') {
    flash_set('error', 'Informe o nome completo.');
    header('Location: /hr_employee_profile.php?id=' . urlencode($employeeId) . '&tab=cadastro');
    exit;
}

if ($roleId === 0) {
    flash_set('error', 'Selecione a função no sistema.');
    header('Location: /hr_employee_profile.php?id=' . urlencode($employeeId) . '&tab=cadastro');
    exit;
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash_set('error', 'E-mail inválido.');
    header('Location: /hr_employee_profile.php?id=' . urlencode($employeeId) . '&tab=cadastro');
    exit;
}

// Upload de foto
$photoUrl = null;
if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
    $uploadDir = __DIR__ . '/uploads/employees/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $fileExtension = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
    
    if (!in_array($fileExtension, $allowedExtensions)) {
        flash_set('error', 'Formato de imagem inválido. Use JPG, PNG ou GIF.');
        header('Location: /hr_employee_profile.php?id=' . urlencode($employeeId) . '&tab=cadastro');
        exit;
    }
    
    if ($_FILES['photo']['size'] > 2 * 1024 * 1024) {
        flash_set('error', 'Imagem muito grande. Máximo 2MB.');
        header('Location: /hr_employee_profile.php?id=' . urlencode($employeeId) . '&tab=cadastro');
        exit;
    }
    
    $fileName = uniqid('emp_') . '.' . $fileExtension;
    $filePath = $uploadDir . $fileName;
    
    if (move_uploaded_file($_FILES['photo']['tmp_name'], $filePath)) {
        $photoUrl = '/uploads/employees/' . $fileName;
    }
}

if ($isNew) {
    // Criar novo funcionário
    $sql = 'INSERT INTO hr_employees (
        full_name, photo_url, position, department, role_id, cpf, rg, rg_issuer, rg_issue_date, birth_date, gender, marital_status,
        nationality, birth_city, birth_state, mother_name, father_name,
        ctps_number, ctps_series, ctps_state, ctps_issue_date, pis_pasep, voter_title, voter_zone, voter_section, military_certificate,
        phone, phone_secondary, email, emergency_contact_name, emergency_contact_phone,
        address_cep, address_street, address_number, address_complement, address_neighborhood, address_city, address_state, address_country,
        bank_name, bank_agency, bank_account, bank_account_type, bank_pix_key,
        education_level, education_course, education_institution, education_year,
        blood_type, allergies, medical_restrictions,
        status
    ) VALUES (
        :full_name, :photo_url, :position, :department, :role_id, :cpf, :rg, :rg_issuer, :rg_issue_date, :birth_date, :gender, :marital_status,
        :nationality, :birth_city, :birth_state, :mother_name, :father_name,
        :ctps_number, :ctps_series, :ctps_state, :ctps_issue_date, :pis_pasep, :voter_title, :voter_zone, :voter_section, :military_certificate,
        :phone, :phone_secondary, :email, :emergency_contact_name, :emergency_contact_phone,
        :address_cep, :address_street, :address_number, :address_complement, :address_neighborhood, :address_city, :address_state, :address_country,
        :bank_name, :bank_agency, :bank_account, :bank_account_type, :bank_pix_key,
        :education_level, :education_course, :education_institution, :education_year,
        :blood_type, :allergies, :medical_restrictions,
        "active"
    )';
    
    $stmt = db()->prepare($sql);
    $stmt->execute([
        'full_name' => $fullName,
        'photo_url' => $photoUrl,
        'position' => $position,
        'department' => $department !== '' ? $department : null,
        'role_id' => $roleId,
        'cpf' => $cpf !== '' ? $cpf : null,
        'rg' => $rg !== '' ? $rg : null,
        'rg_issuer' => $rgIssuer !== '' ? $rgIssuer : null,
        'rg_issue_date' => $rgIssueDate !== '' ? $rgIssueDate : null,
        'birth_date' => $birthDate !== '' ? $birthDate : null,
        'gender' => $gender !== '' ? $gender : null,
        'marital_status' => $maritalStatus !== '' ? $maritalStatus : null,
        'nationality' => $nationality !== '' ? $nationality : null,
        'birth_city' => $birthCity !== '' ? $birthCity : null,
        'birth_state' => $birthState !== '' ? $birthState : null,
        'mother_name' => $motherName !== '' ? $motherName : null,
        'father_name' => $fatherName !== '' ? $fatherName : null,
        'ctps_number' => $ctpsNumber !== '' ? $ctpsNumber : null,
        'ctps_series' => $ctpsSeries !== '' ? $ctpsSeries : null,
        'ctps_state' => $ctpsState !== '' ? $ctpsState : null,
        'ctps_issue_date' => $ctpsIssueDate !== '' ? $ctpsIssueDate : null,
        'pis_pasep' => $pisPasep !== '' ? $pisPasep : null,
        'voter_title' => $voterTitle !== '' ? $voterTitle : null,
        'voter_zone' => $voterZone !== '' ? $voterZone : null,
        'voter_section' => $voterSection !== '' ? $voterSection : null,
        'military_certificate' => $militaryCertificate !== '' ? $militaryCertificate : null,
        'phone' => $phone !== '' ? $phone : null,
        'phone_secondary' => $phoneSecondary !== '' ? $phoneSecondary : null,
        'email' => $email !== '' ? $email : null,
        'emergency_contact_name' => $emergencyContactName !== '' ? $emergencyContactName : null,
        'emergency_contact_phone' => $emergencyContactPhone !== '' ? $emergencyContactPhone : null,
        'address_cep' => $addressCep !== '' ? $addressCep : null,
        'address_street' => $addressStreet !== '' ? $addressStreet : null,
        'address_number' => $addressNumber !== '' ? $addressNumber : null,
        'address_complement' => $addressComplement !== '' ? $addressComplement : null,
        'address_neighborhood' => $addressNeighborhood !== '' ? $addressNeighborhood : null,
        'address_city' => $addressCity !== '' ? $addressCity : null,
        'address_state' => $addressState !== '' ? $addressState : null,
        'address_country' => $addressCountry !== '' ? $addressCountry : null,
        'bank_name' => $bankName !== '' ? $bankName : null,
        'bank_agency' => $bankAgency !== '' ? $bankAgency : null,
        'bank_account' => $bankAccount !== '' ? $bankAccount : null,
        'bank_account_type' => $bankAccountType !== '' ? $bankAccountType : null,
        'bank_pix_key' => $bankPixKey !== '' ? $bankPixKey : null,
        'education_level' => $educationLevel !== '' ? $educationLevel : null,
        'education_course' => $educationCourse !== '' ? $educationCourse : null,
        'education_institution' => $educationInstitution !== '' ? $educationInstitution : null,
        'education_year' => $educationYear !== '' ? (int)$educationYear : null,
        'blood_type' => $bloodType !== '' ? $bloodType : null,
        'allergies' => $allergies !== '' ? $allergies : null,
        'medical_restrictions' => $medicalRestrictions !== '' ? $medicalRestrictions : null,
    ]);
    
    $newId = (int)db()->lastInsertId();
    
    audit_log('create', 'hr_employees', (string)$newId, null, ['full_name' => $fullName]);
    
    // Criar usuário automaticamente se tiver e-mail
    if ($email !== '') {
        // Verificar se já existe usuário com este e-mail
        $checkStmt = db()->prepare('SELECT id FROM users WHERE email = :email');
        $checkStmt->execute(['email' => $email]);
        $existingUser = $checkStmt->fetch();
        
        if (!$existingUser) {
            // Criar usuário com senha padrão
            $defaultPassword = 'padrao123456';
            $userStmt = db()->prepare('INSERT INTO users (name, email, password, status) VALUES (:name, :email, :password, "active")');
            $userStmt->execute([
                'name' => $fullName,
                'email' => $email,
                'password' => password_hash($defaultPassword, PASSWORD_DEFAULT)
            ]);
            
            $userId = (int)db()->lastInsertId();
            
            // Vincular role ao usuário
            $roleStmt = db()->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)');
            $roleStmt->execute([
                'user_id' => $userId,
                'role_id' => $roleId
            ]);
            
            // Vincular usuário ao funcionário
            $updateStmt = db()->prepare('UPDATE hr_employees SET user_id = :user_id WHERE id = :id');
            $updateStmt->execute([
                'user_id' => $userId,
                'id' => $newId
            ]);
            
            audit_log('create', 'users', (string)$userId, null, [
                'name' => $fullName,
                'email' => $email,
                'created_from_hr' => true,
                'employee_id' => $newId
            ]);
        }
    }
    
    flash_set('success', 'Funcionário cadastrado com sucesso! Login: ' . $email . ' | Senha padrão: padrao123456');
    header('Location: /hr_employee_profile.php?id=' . $newId . '&tab=contrato');
    exit;
    
} else {
    // Atualizar funcionário existente
    $updateFields = [
        'full_name = :full_name',
        'position = :position', 'department = :department', 'role_id = :role_id',
        'cpf = :cpf', 'rg = :rg', 'rg_issuer = :rg_issuer', 'rg_issue_date = :rg_issue_date',
        'birth_date = :birth_date', 'gender = :gender', 'marital_status = :marital_status',
        'nationality = :nationality', 'birth_city = :birth_city', 'birth_state = :birth_state',
        'mother_name = :mother_name', 'father_name = :father_name',
        'ctps_number = :ctps_number', 'ctps_series = :ctps_series', 'ctps_state = :ctps_state', 'ctps_issue_date = :ctps_issue_date',
        'pis_pasep = :pis_pasep', 'voter_title = :voter_title', 'voter_zone = :voter_zone', 'voter_section = :voter_section', 'military_certificate = :military_certificate',
        'phone = :phone', 'phone_secondary = :phone_secondary', 'email = :email',
        'emergency_contact_name = :emergency_contact_name', 'emergency_contact_phone = :emergency_contact_phone',
        'address_cep = :address_cep', 'address_street = :address_street', 'address_number = :address_number',
        'address_complement = :address_complement', 'address_neighborhood = :address_neighborhood',
        'address_city = :address_city', 'address_state = :address_state', 'address_country = :address_country',
        'bank_name = :bank_name', 'bank_agency = :bank_agency', 'bank_account = :bank_account',
        'bank_account_type = :bank_account_type', 'bank_pix_key = :bank_pix_key',
        'education_level = :education_level', 'education_course = :education_course',
        'education_institution = :education_institution', 'education_year = :education_year',
        'blood_type = :blood_type', 'allergies = :allergies', 'medical_restrictions = :medical_restrictions'
    ];
    
    if ($photoUrl !== null) {
        $updateFields[] = 'photo_url = :photo_url';
    }
    
    $sql = 'UPDATE hr_employees SET ' . implode(', ', $updateFields) . ' WHERE id = :id';
    
    $params = [
        'id' => (int)$employeeId,
        'full_name' => $fullName,
        'position' => $position,
        'department' => $department !== '' ? $department : null,
        'role_id' => $roleId,
        'cpf' => $cpf !== '' ? $cpf : null,
        'rg' => $rg !== '' ? $rg : null,
        'rg_issuer' => $rgIssuer !== '' ? $rgIssuer : null,
        'rg_issue_date' => $rgIssueDate !== '' ? $rgIssueDate : null,
        'birth_date' => $birthDate !== '' ? $birthDate : null,
        'gender' => $gender !== '' ? $gender : null,
        'marital_status' => $maritalStatus !== '' ? $maritalStatus : null,
        'nationality' => $nationality !== '' ? $nationality : null,
        'birth_city' => $birthCity !== '' ? $birthCity : null,
        'birth_state' => $birthState !== '' ? $birthState : null,
        'mother_name' => $motherName !== '' ? $motherName : null,
        'father_name' => $fatherName !== '' ? $fatherName : null,
        'ctps_number' => $ctpsNumber !== '' ? $ctpsNumber : null,
        'ctps_series' => $ctpsSeries !== '' ? $ctpsSeries : null,
        'ctps_state' => $ctpsState !== '' ? $ctpsState : null,
        'ctps_issue_date' => $ctpsIssueDate !== '' ? $ctpsIssueDate : null,
        'pis_pasep' => $pisPasep !== '' ? $pisPasep : null,
        'voter_title' => $voterTitle !== '' ? $voterTitle : null,
        'voter_zone' => $voterZone !== '' ? $voterZone : null,
        'voter_section' => $voterSection !== '' ? $voterSection : null,
        'military_certificate' => $militaryCertificate !== '' ? $militaryCertificate : null,
        'phone' => $phone !== '' ? $phone : null,
        'phone_secondary' => $phoneSecondary !== '' ? $phoneSecondary : null,
        'email' => $email !== '' ? $email : null,
        'emergency_contact_name' => $emergencyContactName !== '' ? $emergencyContactName : null,
        'emergency_contact_phone' => $emergencyContactPhone !== '' ? $emergencyContactPhone : null,
        'address_cep' => $addressCep !== '' ? $addressCep : null,
        'address_street' => $addressStreet !== '' ? $addressStreet : null,
        'address_number' => $addressNumber !== '' ? $addressNumber : null,
        'address_complement' => $addressComplement !== '' ? $addressComplement : null,
        'address_neighborhood' => $addressNeighborhood !== '' ? $addressNeighborhood : null,
        'address_city' => $addressCity !== '' ? $addressCity : null,
        'address_state' => $addressState !== '' ? $addressState : null,
        'address_country' => $addressCountry !== '' ? $addressCountry : null,
        'bank_name' => $bankName !== '' ? $bankName : null,
        'bank_agency' => $bankAgency !== '' ? $bankAgency : null,
        'bank_account' => $bankAccount !== '' ? $bankAccount : null,
        'bank_account_type' => $bankAccountType !== '' ? $bankAccountType : null,
        'bank_pix_key' => $bankPixKey !== '' ? $bankPixKey : null,
        'education_level' => $educationLevel !== '' ? $educationLevel : null,
        'education_course' => $educationCourse !== '' ? $educationCourse : null,
        'education_institution' => $educationInstitution !== '' ? $educationInstitution : null,
        'education_year' => $educationYear !== '' ? (int)$educationYear : null,
        'blood_type' => $bloodType !== '' ? $bloodType : null,
        'allergies' => $allergies !== '' ? $allergies : null,
        'medical_restrictions' => $medicalRestrictions !== '' ? $medicalRestrictions : null,
    ];
    
    if ($photoUrl !== null) {
        $params['photo_url'] = $photoUrl;
    }
    
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    
    audit_log('update', 'hr_employees', $employeeId, null, ['full_name' => $fullName]);
    
    flash_set('success', 'Dados atualizados com sucesso!');
    header('Location: /hr_employee_profile.php?id=' . $employeeId . '&tab=cadastro');
    exit;
}

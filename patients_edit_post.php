<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('patients.manage');

$id = (int)($_POST['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM patients WHERE id = :id AND deleted_at IS NULL');
$stmt->execute(['id' => $id]);
$old = $stmt->fetch();

if (!$old) {
    flash_set('error', 'Paciente não encontrado.');
    header('Location: /patients_list.php');
    exit;
}

$fullName = trim((string)($_POST['full_name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$birthDate = trim((string)($_POST['birth_date'] ?? ''));
$state = strtoupper(trim((string)($_POST['address_state'] ?? '')));

if ($fullName === '') {
    flash_set('error', 'Informe o nome completo.');
    header('Location: /patients_edit.php?id=' . $id);
    exit;
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash_set('error', 'E-mail inválido.');
    header('Location: /patients_edit.php?id=' . $id);
    exit;
}

if ($birthDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDate)) {
    flash_set('error', 'Data de nascimento inválida.');
    header('Location: /patients_edit.php?id=' . $id);
    exit;
}

if ($state !== '' && !preg_match('/^[A-Z]{2}$/', $state)) {
    flash_set('error', 'UF inválida.');
    header('Location: /patients_edit.php?id=' . $id);
    exit;
}

$healthAllergies = trim((string)($_POST['health_allergies'] ?? ''));
$healthMedications = trim((string)($_POST['health_medications'] ?? ''));
$healthConditions = trim((string)($_POST['health_conditions'] ?? ''));
$healthRestrictions = trim((string)($_POST['health_restrictions'] ?? ''));
$healthBloodType = trim((string)($_POST['health_blood_type'] ?? ''));
$healthNotes = trim((string)($_POST['health_notes'] ?? ''));

$hasHealthGuided = ($healthAllergies !== '' || $healthMedications !== '' || $healthConditions !== '' || $healthRestrictions !== '' || $healthBloodType !== '' || $healthNotes !== '');
if ($hasHealthGuided) {
    $health = [
        'allergies' => $healthAllergies !== '' ? $healthAllergies : null,
        'medications' => $healthMedications !== '' ? $healthMedications : null,
        'conditions' => $healthConditions !== '' ? $healthConditions : null,
        'restrictions' => $healthRestrictions !== '' ? $healthRestrictions : null,
        'blood_type' => $healthBloodType !== '' ? $healthBloodType : null,
        'notes' => $healthNotes !== '' ? $healthNotes : null,
    ];
    $_POST['health_json'] = json_encode($health, JSON_UNESCAPED_UNICODE);
}

$respName = trim((string)($_POST['responsible_name'] ?? ''));
$respRelationship = trim((string)($_POST['responsible_relationship'] ?? ''));
$respCpf = trim((string)($_POST['responsible_cpf'] ?? ''));
$respPhone = trim((string)($_POST['responsible_phone'] ?? ''));
$respEmail = trim((string)($_POST['responsible_email'] ?? ''));
$respNotes = trim((string)($_POST['responsible_notes'] ?? ''));

if ($respEmail !== '' && !filter_var($respEmail, FILTER_VALIDATE_EMAIL)) {
    flash_set('error', 'E-mail do responsável inválido.');
    header('Location: /patients_edit.php?id=' . $id);
    exit;
}

$hasRespGuided = ($respName !== '' || $respRelationship !== '' || $respCpf !== '' || $respPhone !== '' || $respEmail !== '' || $respNotes !== '');
if ($hasRespGuided) {
    $resp = [
        'name' => $respName !== '' ? $respName : null,
        'relationship' => $respRelationship !== '' ? $respRelationship : null,
        'cpf' => $respCpf !== '' ? $respCpf : null,
        'phone' => $respPhone !== '' ? $respPhone : null,
        'email' => $respEmail !== '' ? $respEmail : null,
        'notes' => $respNotes !== '' ? $respNotes : null,
    ];
    $_POST['responsible_json'] = json_encode($resp, JSON_UNESCAPED_UNICODE);
}

$mhMainComplaints = trim((string)($_POST['mh_main_complaints'] ?? ''));
$mhPastDiseases = trim((string)($_POST['mh_past_diseases'] ?? ''));
$mhSurgeries = trim((string)($_POST['mh_surgeries'] ?? ''));
$mhHospitalizations = trim((string)($_POST['mh_hospitalizations'] ?? ''));
$mhFamilyHistory = trim((string)($_POST['mh_family_history'] ?? ''));
$mhHabits = trim((string)($_POST['mh_habits'] ?? ''));
$mhNotes = trim((string)($_POST['mh_notes'] ?? ''));

$hasMhGuided = ($mhMainComplaints !== '' || $mhPastDiseases !== '' || $mhSurgeries !== '' || $mhHospitalizations !== '' || $mhFamilyHistory !== '' || $mhHabits !== '' || $mhNotes !== '');
if ($hasMhGuided) {
    $mh = [
        'main_complaints' => $mhMainComplaints !== '' ? $mhMainComplaints : null,
        'past_diseases' => $mhPastDiseases !== '' ? $mhPastDiseases : null,
        'surgeries' => $mhSurgeries !== '' ? $mhSurgeries : null,
        'hospitalizations' => $mhHospitalizations !== '' ? $mhHospitalizations : null,
        'family_history' => $mhFamilyHistory !== '' ? $mhFamilyHistory : null,
        'habits' => $mhHabits !== '' ? $mhHabits : null,
        'notes' => $mhNotes !== '' ? $mhNotes : null,
    ];
    $_POST['medical_history_json'] = json_encode($mh, JSON_UNESCAPED_UNICODE);
}

$lgpdConsentStatus = trim((string)($_POST['lgpd_consent_status'] ?? ''));
$lgpdConsentAt = trim((string)($_POST['lgpd_consent_at'] ?? ''));
$lgpdConsentVersion = trim((string)($_POST['lgpd_consent_version'] ?? ''));
$lgpdConsentChannel = trim((string)($_POST['lgpd_consent_channel'] ?? ''));
$lgpdNotes = trim((string)($_POST['lgpd_notes'] ?? ''));

$allowedConsentStatus = ['', 'consented', 'denied', 'pending'];
if (!in_array($lgpdConsentStatus, $allowedConsentStatus, true)) {
    flash_set('error', 'Consentimento LGPD inválido.');
    header('Location: /patients_edit.php?id=' . $id);
    exit;
}

if ($lgpdConsentAt !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $lgpdConsentAt)) {
    flash_set('error', 'Data do consentimento LGPD inválida.');
    header('Location: /patients_edit.php?id=' . $id);
    exit;
}

$hasLgpdGuided = ($lgpdConsentStatus !== '' || $lgpdConsentAt !== '' || $lgpdConsentVersion !== '' || $lgpdConsentChannel !== '' || $lgpdNotes !== '');
if ($hasLgpdGuided) {
    $lgpd = [
        'consent_status' => $lgpdConsentStatus !== '' ? $lgpdConsentStatus : null,
        'consent_at' => $lgpdConsentAt !== '' ? $lgpdConsentAt : null,
        'consent_version' => $lgpdConsentVersion !== '' ? $lgpdConsentVersion : null,
        'consent_channel' => $lgpdConsentChannel !== '' ? $lgpdConsentChannel : null,
        'notes' => $lgpdNotes !== '' ? $lgpdNotes : null,
    ];
    $_POST['lgpd_json'] = json_encode($lgpd, JSON_UNESCAPED_UNICODE);
}

$fields = [
    'cpf','rg','birth_date','sex','marital_status','profession','education_level',
    'whatsapp','email','phone_primary','phone_secondary','preferred_contact',
    'address_zip','address_street','address_number','address_complement','address_neighborhood','address_city','address_state','address_country',
    'emergency_name','emergency_relationship','emergency_phone',
    'insurance_name','insurance_card_number','insurance_valid_until','insurance_notes',
    'health_json','medical_history_json','documents_json','finance_json','lgpd_json','responsible_json',
    'admin_status','unit','doctor_responsible',
];

$set = ['full_name = :full_name'];
$params = ['id' => $id, 'full_name' => $fullName];

foreach ($fields as $f) {
    $v = trim((string)($_POST[$f] ?? ''));
    if ($f === 'address_state') {
        $v = $state;
    }
    if ($f === 'birth_date') {
        $v = $birthDate;
    }
    if ($f === 'insurance_valid_until' && $v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        flash_set('error', 'Validade do convênio inválida.');
        header('Location: /patients_edit.php?id=' . $id);
        exit;
    }

    if (in_array($f, ['health_json','medical_history_json','documents_json','finance_json','lgpd_json','responsible_json'], true) && $v !== '') {
        $decoded = json_decode($v, true);
        if ($decoded === null && strtolower($v) !== 'null') {
            flash_set('error', 'Campo JSON inválido: ' . $f);
            header('Location: /patients_edit.php?id=' . $id);
            exit;
        }
    }
    $set[] = $f . ' = :' . $f;
    $params[$f] = ($v !== '') ? $v : null;
}

$stmt = db()->prepare('UPDATE patients SET ' . implode(', ', $set) . ' WHERE id = :id');
$stmt->execute($params);

audit_log('update', 'patients', (string)$id, $old, ['full_name' => $fullName]);

flash_set('success', 'Paciente atualizado.');
header('Location: /patients_view.php?id=' . $id);
exit;

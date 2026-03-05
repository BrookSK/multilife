<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('patients.manage');

// 1. Identificação
$fullName = trim((string)($_POST['full_name'] ?? ''));
$socialName = trim((string)($_POST['social_name'] ?? ''));
$sex = trim((string)($_POST['sex'] ?? ''));
$gender = trim((string)($_POST['gender'] ?? ''));
$birthDate = trim((string)($_POST['birth_date'] ?? ''));
$cpf = trim((string)($_POST['cpf'] ?? ''));
$rg = trim((string)($_POST['rg'] ?? ''));
$rgIssuer = trim((string)($_POST['rg_issuer'] ?? ''));
$nationality = trim((string)($_POST['nationality'] ?? ''));
$birthCity = trim((string)($_POST['birth_city'] ?? ''));
$birthState = trim((string)($_POST['birth_state'] ?? ''));
$maritalStatus = trim((string)($_POST['marital_status'] ?? ''));
$profession = trim((string)($_POST['profession'] ?? ''));
$educationLevel = trim((string)($_POST['education_level'] ?? ''));

// 2. Contato
$phonePrimary = trim((string)($_POST['phone_primary'] ?? ''));
$phoneSecondary = trim((string)($_POST['phone_secondary'] ?? ''));
$whatsapp = trim((string)($_POST['whatsapp'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$preferredContact = trim((string)($_POST['preferred_contact'] ?? ''));

// 3. Endereço
$addressZip = trim((string)($_POST['address_zip'] ?? ''));
$addressStreet = trim((string)($_POST['address_street'] ?? ''));
$addressNumber = trim((string)($_POST['address_number'] ?? ''));
$addressComplement = trim((string)($_POST['address_complement'] ?? ''));
$addressNeighborhood = trim((string)($_POST['address_neighborhood'] ?? ''));
$addressCity = trim((string)($_POST['address_city'] ?? ''));
$addressState = trim((string)($_POST['address_state'] ?? ''));
$addressCountry = trim((string)($_POST['address_country'] ?? ''));

// 4. Emergência
$emergencyName = trim((string)($_POST['emergency_name'] ?? ''));
$emergencyRelationship = trim((string)($_POST['emergency_relationship'] ?? ''));
$emergencyPhone = trim((string)($_POST['emergency_phone'] ?? ''));
$emergencyPhoneSecondary = trim((string)($_POST['emergency_phone_secondary'] ?? ''));
$emergencyEmail = trim((string)($_POST['emergency_email'] ?? ''));

// 5. Convênio
$hasInsurance = (int)($_POST['has_insurance'] ?? 0);
$insuranceName = trim((string)($_POST['insurance_name'] ?? ''));
$insuranceCardNumber = trim((string)($_POST['insurance_card_number'] ?? ''));
$insurancePlan = trim((string)($_POST['insurance_plan'] ?? ''));
$insuranceValidUntil = trim((string)($_POST['insurance_valid_until'] ?? ''));
$insuranceHolderName = trim((string)($_POST['insurance_holder_name'] ?? ''));
$insuranceDependencyLevel = trim((string)($_POST['insurance_dependency_level'] ?? ''));
$insuranceCompany = trim((string)($_POST['insurance_company'] ?? ''));
$insuranceNotes = trim((string)($_POST['insurance_notes'] ?? ''));

// 6. Informações Médicas
$bloodType = trim((string)($_POST['blood_type'] ?? ''));
$rhFactor = trim((string)($_POST['rh_factor'] ?? ''));
$heightCm = $_POST['height_cm'] ?? null;
$weightKg = $_POST['weight_kg'] ?? null;
$bloodPressure = trim((string)($_POST['blood_pressure'] ?? ''));
$heartRate = $_POST['heart_rate'] ?? null;
$bodyTemperature = $_POST['body_temperature'] ?? null;

// 12. Hábitos
$smoker = trim((string)($_POST['smoker'] ?? ''));
$alcoholConsumption = trim((string)($_POST['alcohol_consumption'] ?? ''));
$drugUse = trim((string)($_POST['drug_use'] ?? ''));
$physicalActivity = trim((string)($_POST['physical_activity'] ?? ''));
$exerciseFrequency = trim((string)($_POST['exercise_frequency'] ?? ''));
$dietType = trim((string)($_POST['diet_type'] ?? ''));

// 13. Biometria
$waistCircumference = $_POST['waist_circumference_cm'] ?? null;
$bodyFatPercentage = $_POST['body_fat_percentage'] ?? null;
$muscleMass = $_POST['muscle_mass_kg'] ?? null;
$oxygenSaturation = $_POST['oxygen_saturation'] ?? null;

// 15. Administrativo
$adminStatus = trim((string)($_POST['admin_status'] ?? 'Ativo'));
$unit = trim((string)($_POST['unit'] ?? ''));
$doctorResponsible = trim((string)($_POST['doctor_responsible'] ?? ''));

// 16. LGPD
$consentDataUsage = isset($_POST['consent_data_usage']) ? 1 : 0;
$consentPrivacyTerms = isset($_POST['consent_privacy_terms']) ? 1 : 0;
$consentContact = isset($_POST['consent_contact']) ? 1 : 0;
$consentDataSharing = isset($_POST['consent_data_sharing']) ? 1 : 0;

if ($fullName === '') {
    flash_set('error', 'Informe o nome completo.');
    header('Location: /patients_create.php');
    exit;
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash_set('error', 'E-mail inválido.');
    header('Location: /patients_create.php');
    exit;
}

if ($birthDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDate)) {
    flash_set('error', 'Data de nascimento inválida.');
    header('Location: /patients_create.php');
    exit;
}

$db = db();
$db->beginTransaction();
try {
    // Calcular IMC se altura e peso fornecidos
    $bmi = null;
    if ($heightCm !== null && $weightKg !== null && (float)$heightCm > 0) {
        $heightM = (float)$heightCm / 100;
        $bmi = (float)$weightKg / ($heightM * $heightM);
    }

    $stmt = $db->prepare(
        'INSERT INTO patients (
            full_name, social_name, sex, gender, birth_date, cpf, rg, rg_issuer,
            nationality, birth_city, birth_state, marital_status, profession, education_level,
            phone_primary, phone_secondary, whatsapp, email, preferred_contact,
            address_zip, address_street, address_number, address_complement,
            address_neighborhood, address_city, address_state, address_country,
            emergency_name, emergency_relationship, emergency_phone, emergency_phone_secondary, emergency_email,
            has_insurance, insurance_name, insurance_card_number, insurance_plan,
            insurance_valid_until, insurance_holder_name, insurance_dependency_level,
            insurance_company, insurance_notes,
            blood_type, rh_factor, height_cm, weight_kg, bmi,
            blood_pressure, heart_rate, body_temperature,
            smoker, alcohol_consumption, drug_use, physical_activity, exercise_frequency, diet_type,
            waist_circumference_cm, body_fat_percentage, muscle_mass_kg, oxygen_saturation,
            admin_status, unit, doctor_responsible, registration_date,
            consent_data_usage, consent_privacy_terms, consent_contact, consent_data_sharing
        ) VALUES (
            :full_name, :social_name, :sex, :gender, :birth_date, :cpf, :rg, :rg_issuer,
            :nationality, :birth_city, :birth_state, :marital_status, :profession, :education_level,
            :phone_primary, :phone_secondary, :whatsapp, :email, :preferred_contact,
            :address_zip, :address_street, :address_number, :address_complement,
            :address_neighborhood, :address_city, :address_state, :address_country,
            :emergency_name, :emergency_relationship, :emergency_phone, :emergency_phone_secondary, :emergency_email,
            :has_insurance, :insurance_name, :insurance_card_number, :insurance_plan,
            :insurance_valid_until, :insurance_holder_name, :insurance_dependency_level,
            :insurance_company, :insurance_notes,
            :blood_type, :rh_factor, :height_cm, :weight_kg, :bmi,
            :blood_pressure, :heart_rate, :body_temperature,
            :smoker, :alcohol_consumption, :drug_use, :physical_activity, :exercise_frequency, :diet_type,
            :waist_circumference_cm, :body_fat_percentage, :muscle_mass_kg, :oxygen_saturation,
            :admin_status, :unit, :doctor_responsible, CURDATE(),
            :consent_data_usage, :consent_privacy_terms, :consent_contact, :consent_data_sharing
        )'
    );
    
    $stmt->execute([
        'full_name' => $fullName,
        'social_name' => $socialName !== '' ? $socialName : null,
        'sex' => $sex !== '' ? $sex : null,
        'gender' => $gender !== '' ? $gender : null,
        'birth_date' => $birthDate !== '' ? $birthDate : null,
        'cpf' => $cpf !== '' ? $cpf : null,
        'rg' => $rg !== '' ? $rg : null,
        'rg_issuer' => $rgIssuer !== '' ? $rgIssuer : null,
        'nationality' => $nationality !== '' ? $nationality : null,
        'birth_city' => $birthCity !== '' ? $birthCity : null,
        'birth_state' => $birthState !== '' ? $birthState : null,
        'marital_status' => $maritalStatus !== '' ? $maritalStatus : null,
        'profession' => $profession !== '' ? $profession : null,
        'education_level' => $educationLevel !== '' ? $educationLevel : null,
        'phone_primary' => $phonePrimary !== '' ? $phonePrimary : null,
        'phone_secondary' => $phoneSecondary !== '' ? $phoneSecondary : null,
        'whatsapp' => $whatsapp !== '' ? $whatsapp : null,
        'email' => $email !== '' ? $email : null,
        'preferred_contact' => $preferredContact !== '' ? $preferredContact : null,
        'address_zip' => $addressZip !== '' ? $addressZip : null,
        'address_street' => $addressStreet !== '' ? $addressStreet : null,
        'address_number' => $addressNumber !== '' ? $addressNumber : null,
        'address_complement' => $addressComplement !== '' ? $addressComplement : null,
        'address_neighborhood' => $addressNeighborhood !== '' ? $addressNeighborhood : null,
        'address_city' => $addressCity !== '' ? $addressCity : null,
        'address_state' => $addressState !== '' ? $addressState : null,
        'address_country' => $addressCountry !== '' ? $addressCountry : null,
        'emergency_name' => $emergencyName !== '' ? $emergencyName : null,
        'emergency_relationship' => $emergencyRelationship !== '' ? $emergencyRelationship : null,
        'emergency_phone' => $emergencyPhone !== '' ? $emergencyPhone : null,
        'emergency_phone_secondary' => $emergencyPhoneSecondary !== '' ? $emergencyPhoneSecondary : null,
        'emergency_email' => $emergencyEmail !== '' ? $emergencyEmail : null,
        'has_insurance' => $hasInsurance,
        'insurance_name' => $insuranceName !== '' ? $insuranceName : null,
        'insurance_card_number' => $insuranceCardNumber !== '' ? $insuranceCardNumber : null,
        'insurance_plan' => $insurancePlan !== '' ? $insurancePlan : null,
        'insurance_valid_until' => $insuranceValidUntil !== '' ? $insuranceValidUntil : null,
        'insurance_holder_name' => $insuranceHolderName !== '' ? $insuranceHolderName : null,
        'insurance_dependency_level' => $insuranceDependencyLevel !== '' ? $insuranceDependencyLevel : null,
        'insurance_company' => $insuranceCompany !== '' ? $insuranceCompany : null,
        'insurance_notes' => $insuranceNotes !== '' ? $insuranceNotes : null,
        'blood_type' => $bloodType !== '' ? $bloodType : null,
        'rh_factor' => $rhFactor !== '' ? $rhFactor : null,
        'height_cm' => $heightCm !== null && $heightCm !== '' ? (float)$heightCm : null,
        'weight_kg' => $weightKg !== null && $weightKg !== '' ? (float)$weightKg : null,
        'bmi' => $bmi,
        'blood_pressure' => $bloodPressure !== '' ? $bloodPressure : null,
        'heart_rate' => $heartRate !== null && $heartRate !== '' ? (int)$heartRate : null,
        'body_temperature' => $bodyTemperature !== null && $bodyTemperature !== '' ? (float)$bodyTemperature : null,
        'smoker' => $smoker !== '' ? $smoker : null,
        'alcohol_consumption' => $alcoholConsumption !== '' ? $alcoholConsumption : null,
        'drug_use' => $drugUse !== '' ? $drugUse : null,
        'physical_activity' => $physicalActivity !== '' ? $physicalActivity : null,
        'exercise_frequency' => $exerciseFrequency !== '' ? $exerciseFrequency : null,
        'diet_type' => $dietType !== '' ? $dietType : null,
        'waist_circumference_cm' => $waistCircumference !== null && $waistCircumference !== '' ? (float)$waistCircumference : null,
        'body_fat_percentage' => $bodyFatPercentage !== null && $bodyFatPercentage !== '' ? (float)$bodyFatPercentage : null,
        'muscle_mass_kg' => $muscleMass !== null && $muscleMass !== '' ? (float)$muscleMass : null,
        'oxygen_saturation' => $oxygenSaturation !== null && $oxygenSaturation !== '' ? (float)$oxygenSaturation : null,
        'admin_status' => $adminStatus,
        'unit' => $unit !== '' ? $unit : null,
        'doctor_responsible' => $doctorResponsible !== '' ? $doctorResponsible : null,
        'consent_data_usage' => $consentDataUsage,
        'consent_privacy_terms' => $consentPrivacyTerms,
        'consent_contact' => $consentContact,
        'consent_data_sharing' => $consentDataSharing,
    ]);

    $id = (string)$db->lastInsertId();
    audit_log('create', 'patients', $id, null, ['full_name' => $fullName]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

flash_set('success', 'Paciente criado com sucesso.');
header('Location: /patients_view.php?id=' . urlencode($id));
exit;

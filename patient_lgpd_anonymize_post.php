<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('patients.lgpd.anonymize');

$id = (int)($_POST['id'] ?? 0);
$note = trim((string)($_POST['note'] ?? ''));

$db = db();

$stmt = $db->prepare('SELECT * FROM patients WHERE id = :id AND deleted_at IS NULL');
$stmt->execute(['id' => $id]);
$old = $stmt->fetch();

if (!$old) {
    flash_set('error', 'Paciente não encontrado.');
    header('Location: /patients_list.php');
    exit;
}

$anonName = 'Paciente #' . (int)$id . ' (anonimizado)';

$payload = [
    'full_name' => $anonName,
    'cpf' => null,
    'rg' => null,
    'birth_date' => null,
    'sex' => null,
    'marital_status' => null,
    'profession' => null,
    'education_level' => null,
    'photo_path' => null,

    'email' => null,
    'phone_primary' => null,
    'phone_secondary' => null,
    'whatsapp' => null,
    'preferred_contact' => null,

    'address_zip' => null,
    'address_street' => null,
    'address_number' => null,
    'address_complement' => null,
    'address_neighborhood' => null,
    'address_city' => null,
    'address_state' => null,
    'address_country' => null,

    'emergency_name' => null,
    'emergency_relationship' => null,
    'emergency_phone' => null,

    'insurance_name' => null,
    'insurance_card_number' => null,
    'insurance_valid_until' => null,
    'insurance_notes' => null,

    'health_json' => null,
    'medical_history_json' => null,
    'documents_json' => null,
    'finance_json' => null,
    'lgpd_json' => json_encode([
        'consent_status' => null,
        'consent_at' => null,
        'consent_version' => null,
        'consent_channel' => null,
        'notes' => $note !== '' ? $note : 'anonimizado',
        'anonymized_at' => (new DateTime())->format('Y-m-d H:i:s'),
        'anonymized_by_user_id' => auth_user_id(),
    ], JSON_UNESCAPED_UNICODE),
    'responsible_json' => null,

    'admin_status' => (string)($old['admin_status'] ?? null),
    'unit' => (string)($old['unit'] ?? null),
    'doctor_responsible' => (string)($old['doctor_responsible'] ?? null),
];

$db->beginTransaction();
try {
    $set = [];
    $params = ['id' => $id];
    foreach ($payload as $k => $v) {
        $set[] = $k . ' = :' . $k;
        $params[$k] = $v;
    }

    $stmt = $db->prepare('UPDATE patients SET ' . implode(', ', $set) . ' WHERE id = :id');
    $stmt->execute($params);

    audit_log('update', 'patients_lgpd_anonymize', (string)$id, $old, ['note' => $note]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

flash_set('success', 'Paciente anonimizado.');
header('Location: /patients_view.php?id=' . $id);
exit;

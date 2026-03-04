<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('patient_links.manage');

$patientId = (int)($_POST['patient_id'] ?? 0);
$professionalIds = $_POST['professional_ids'] ?? [];
if (!is_array($professionalIds)) {
    $professionalIds = [];
}

$stmt = db()->prepare('SELECT id FROM patients WHERE id = :id AND deleted_at IS NULL');
$stmt->execute(['id' => $patientId]);
if (!$stmt->fetch()) {
    flash_set('error', 'Paciente não encontrado.');
    header('Location: /patients_list.php');
    exit;
}

$oldStmt = db()->prepare('SELECT professional_user_id, specialty, is_active FROM patient_professionals WHERE patient_id = :pid');
$oldStmt->execute(['pid' => $patientId]);
$old = $oldStmt->fetchAll();

$valid = [];
if (count($professionalIds) > 0) {
    $placeholders = implode(',', array_fill(0, count($professionalIds), '?'));
    $stmt = db()->prepare(
        "SELECT u.id FROM users u INNER JOIN user_roles ur ON ur.user_id = u.id INNER JOIN roles r ON r.id = ur.role_id WHERE r.slug = 'profissional' AND u.id IN ($placeholders)"
    );
    $stmt->execute(array_map('intval', $professionalIds));
    foreach ($stmt->fetchAll() as $r) {
        $valid[] = (int)$r['id'];
    }
}

$db = db();
$db->beginTransaction();
try {
    $stmt = $db->prepare('DELETE FROM patient_professionals WHERE patient_id = :pid');
    $stmt->execute(['pid' => $patientId]);

    if (count($valid) > 0) {
        $ins = $db->prepare('INSERT INTO patient_professionals (patient_id, professional_user_id, specialty, is_active) VALUES (:pid, :uid, :spec, :active)');
        foreach ($valid as $uid) {
            $spec = trim((string)($_POST['specialty_' . $uid] ?? ''));
            $active = (int)($_POST['is_active_' . $uid] ?? 1);
            $ins->execute([
                'pid' => $patientId,
                'uid' => $uid,
                'spec' => $spec !== '' ? $spec : null,
                'active' => ($active === 1) ? 1 : 0,
            ]);
        }
    }

    $newStmt = $db->prepare('SELECT professional_user_id, specialty, is_active FROM patient_professionals WHERE patient_id = :pid');
    $newStmt->execute(['pid' => $patientId]);
    $new = $newStmt->fetchAll();

    audit_log('update', 'patient_professionals', (string)$patientId, $old, $new);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

flash_set('success', 'Vínculos atualizados.');
header('Location: /patients_view.php?id=' . $patientId);
exit;

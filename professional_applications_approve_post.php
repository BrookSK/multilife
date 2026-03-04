<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('professional_applications.manage');

$id = (int)($_POST['id'] ?? 0);

$db = db();

$stmt = $db->prepare('SELECT * FROM professional_applications WHERE id = :id');
$stmt->execute(['id' => $id]);
$pa = $stmt->fetch();

if (!$pa) {
    flash_set('error', 'Candidatura não encontrada.');
    header('Location: /professional_applications_list.php');
    exit;
}

if ((string)$pa['status'] === 'approved' && $pa['created_user_id'] !== null) {
    flash_set('success', 'Candidatura já aprovada.');
    header('Location: /professional_applications_view.php?id=' . $id);
    exit;
}

// Gera senha provisória (stub: envio por WhatsApp/e-mail será no módulo de integrações)
$tmpPassword = substr(bin2hex(random_bytes(8)), 0, 12);
$hash = password_hash($tmpPassword, PASSWORD_BCRYPT);

$db->beginTransaction();
try {
    // Cria usuário
    $stmt = $db->prepare('INSERT INTO users (name, email, password_hash, status) VALUES (:name, :email, :hash, :status)');
    $stmt->execute([
        'name' => (string)$pa['full_name'],
        'email' => (string)$pa['email'],
        'hash' => $hash,
        'status' => 'active',
    ]);

    $userId = (int)$db->lastInsertId();

    // Vincula role profissional
    $stmt = $db->prepare("SELECT id FROM roles WHERE slug = 'profissional' LIMIT 1");
    $stmt->execute();
    $role = $stmt->fetch();
    if ($role) {
        $roleId = (int)$role['id'];
        $stmt = $db->prepare('INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (:uid, :rid)');
        $stmt->execute(['uid' => $userId, 'rid' => $roleId]);
    }

    // Atualiza candidatura
    $stmt = $db->prepare('UPDATE professional_applications SET status = \'approved\', reviewed_by_user_id = :rid, reviewed_at = NOW(), created_user_id = :uid WHERE id = :id');
    $stmt->execute([
        'rid' => auth_user_id(),
        'uid' => $userId,
        'id' => $id,
    ]);

    audit_log('create', 'users_from_application', (string)$id, null, ['created_user_id' => $userId]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

flash_set('success', 'Aprovado. Senha provisória: ' . $tmpPassword);
header('Location: /professional_applications_view.php?id=' . $id);
exit;

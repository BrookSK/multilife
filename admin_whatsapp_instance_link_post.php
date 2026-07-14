<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('whatsapp.manage');

$instanceId = (int)($_POST['instance_id'] ?? 0);
$instanceName = trim((string)($_POST['instance_name'] ?? ''));
$userId = (int)($_POST['user_id'] ?? 0);

// Se veio instance_name (criação via JS), buscar ou criar o registro
if ($instanceId <= 0 && $instanceName !== '') {
    $stmt = db()->prepare("SELECT id FROM whatsapp_instances WHERE instance_name = :name LIMIT 1");
    $stmt->execute(['name' => $instanceName]);
    $row = $stmt->fetch();
    if ($row) {
        $instanceId = (int)$row['id'];
    } else {
        // Criar registro se não existir
        $insStmt = db()->prepare("INSERT INTO whatsapp_instances (instance_name, status, is_default, connection_status, user_id, created_by) VALUES (:name, 'active', 0, 'disconnected', :uid, :uid)");
        $insStmt->execute(['name' => $instanceName, 'uid' => $userId > 0 ? $userId : null]);
        $instanceId = (int)db()->lastInsertId();
    }
}

if ($instanceId <= 0) {
    flash_set('error', 'Instância inválida.');
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/admin_settings.php'));
    exit;
}

if ($userId === 0) {
    // Desvincular
    $result = whatsapp_unlink_instance($instanceId);
    if ($result) {
        flash_set('success', 'Instância desvinculada do usuário.');
    } else {
        flash_set('error', 'Não foi possível desvincular (instância padrão não pode ser desvinculada).');
    }
} else {
    // Vincular ao usuário
    $result = whatsapp_link_instance_to_user($instanceId, $userId);
    if ($result) {
        $userStmt = db()->prepare('SELECT name FROM users WHERE id = ?');
        $userStmt->execute([$userId]);
        $userName = (string)($userStmt->fetchColumn() ?: 'ID ' . $userId);
        
        audit_log('update', 'whatsapp_instance_link', (string)$instanceId, null, [
            'user_id' => $userId,
            'user_name' => $userName,
        ]);
        flash_set('success', 'Instância vinculada ao usuário: ' . $userName);
    } else {
        flash_set('error', 'Não foi possível vincular (instância não encontrada ou inativa).');
    }
}

// Se veio via AJAX (fetch), retornar JSON
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'multipart/form-data') !== false && !empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'instance_id' => $instanceId]);
    exit;
}

header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/admin_settings.php'));
exit;

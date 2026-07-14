<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('whatsapp.manage');

$instanceId = (int)($_POST['instance_id'] ?? 0);
$userId = (int)($_POST['user_id'] ?? 0);

if ($instanceId <= 0) {
    flash_set('error', 'Instância inválida.');
    header('Location: /admin_whatsapp_instances.php');
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
        // Buscar nome do usuário para log
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

header('Location: /admin_whatsapp_instances.php');
exit;

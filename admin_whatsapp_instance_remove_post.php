<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('whatsapp.manage');

$instanceId = (int)($_POST['instance_id'] ?? 0);

if ($instanceId <= 0) {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strpos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') !== false) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'ID inválido']);
        exit;
    }
    flash_set('error', 'Instância inválida.');
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/admin_settings.php'));
    exit;
}

// Verificar que não é a instância padrão
$stmt = db()->prepare("SELECT id, instance_name, is_default FROM whatsapp_instances WHERE id = :id LIMIT 1");
$stmt->execute(['id' => $instanceId]);
$instance = $stmt->fetch();

if (!$instance) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Instância não encontrada']);
    exit;
}

if ((int)$instance['is_default'] === 1) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Não é possível remover a instância padrão']);
    exit;
}

// Marcar como inativa (soft delete)
$upd = db()->prepare("UPDATE whatsapp_instances SET status = 'inactive', user_id = NULL, connection_status = 'disconnected' WHERE id = :id");
$upd->execute(['id' => $instanceId]);

audit_log('delete', 'whatsapp_instance_remove', (string)$instanceId, null, [
    'instance_name' => $instance['instance_name'],
]);

error_log("[WHATSAPP] Instância '{$instance['instance_name']}' (ID: $instanceId) removida/desativada");

header('Content-Type: application/json');
echo json_encode(['success' => true]);
exit;

<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage'); // Apenas admins podem excluir

$groupJid = isset($_GET['id']) ? trim((string)$_GET['id']) : '';

if (empty($groupJid)) {
    flash_set('error', 'Grupo não especificado.');
    header('Location: /chat_groups.php');
    exit;
}

try {
    // Buscar nome do grupo antes de excluir
    $stmt = db()->prepare("SELECT group_name FROM chat_groups WHERE group_jid = ?");
    $stmt->execute([$groupJid]);
    $group = $stmt->fetch();
    
    if (!$group) {
        flash_set('error', 'Grupo não encontrado.');
        header('Location: /chat_groups.php');
        exit;
    }
    
    // Excluir grupo
    $deleteStmt = db()->prepare("DELETE FROM chat_groups WHERE group_jid = ?");
    $deleteStmt->execute([$groupJid]);
    
    // Registrar no log de auditoria
    audit_log('group_deleted', 'Grupo excluído do sistema: ' . $group['group_name'] . ' (JID: ' . $groupJid . ')');
    
    flash_set('success', 'Grupo "' . $group['group_name'] . '" excluído com sucesso do sistema.');
    
} catch (Exception $e) {
    flash_set('error', 'Erro ao excluir grupo: ' . $e->getMessage());
}

header('Location: /chat_groups.php');
exit;

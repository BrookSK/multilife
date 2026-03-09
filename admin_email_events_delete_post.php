<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

header('Content-Type: application/json; charset=utf-8');

try {
    $eventId = isset($_POST['id']) ? (int)$_POST['id'] : null;
    
    if (!$eventId) {
        throw new Exception('ID do evento não fornecido');
    }
    
    // Deletar evento (CASCADE vai deletar links e arquivos automaticamente)
    $stmt = db()->prepare("DELETE FROM email_events WHERE id = ?");
    $stmt->execute([$eventId]);
    
    if ($stmt->rowCount() === 0) {
        throw new Exception('Evento não encontrado');
    }
    
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    error_log("[EMAIL_EVENT_DELETE] Erro: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

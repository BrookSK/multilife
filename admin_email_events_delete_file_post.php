<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

header('Content-Type: application/json; charset=utf-8');

try {
    $fileId = isset($_POST['file_id']) ? (int)$_POST['file_id'] : null;
    
    if (!$fileId) {
        throw new Exception('ID do arquivo não fornecido');
    }
    
    // Buscar arquivo para deletar do disco
    $stmt = db()->prepare("SELECT file_path FROM email_event_files WHERE id = ?");
    $stmt->execute([$fileId]);
    $file = $stmt->fetch();
    
    if (!$file) {
        throw new Exception('Arquivo não encontrado');
    }
    
    // Deletar arquivo do disco
    $filePath = __DIR__ . $file['file_path'];
    if (file_exists($filePath)) {
        unlink($filePath);
    }
    
    // Deletar registro do banco
    $stmtDelete = db()->prepare("DELETE FROM email_event_files WHERE id = ?");
    $stmtDelete->execute([$fileId]);
    
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    error_log("[EMAIL_EVENT_FILE_DELETE] Erro: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

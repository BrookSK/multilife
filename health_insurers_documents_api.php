<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

header('Content-Type: application/json');

$db = db();

// Garantir que a tabela existe
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS health_insurer_documents (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            health_insurer_id INT UNSIGNED NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            file_size INT UNSIGNED DEFAULT 0,
            mime_type VARCHAR(100) DEFAULT NULL,
            uploaded_by_user_id INT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_insurer (health_insurer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (Throwable $e) {
    // Tabela já existe, ignorar
}

// GET: Listar documentos
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    $insurerId = (int)($_GET['insurer_id'] ?? 0);
    
    if ($action === 'list' && $insurerId > 0) {
        $stmt = $db->prepare("SELECT id, file_name, file_path, file_size, mime_type, created_at FROM health_insurer_documents WHERE health_insurer_id = ? ORDER BY created_at DESC");
        $stmt->execute([$insurerId]);
        $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'documents' => $docs]);
        exit;
    }
    
    echo json_encode(['success' => false, 'error' => 'Parâmetros inválidos']);
    exit;
}

// POST: Upload ou Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // JSON body (delete)
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($contentType, 'application/json') !== false) {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? '';
        
        if ($action === 'delete') {
            $docId = (int)($input['document_id'] ?? 0);
            if ($docId <= 0) {
                echo json_encode(['success' => false, 'error' => 'ID inválido']);
                exit;
            }
            
            // Buscar arquivo para deletar fisicamente
            $stmt = $db->prepare("SELECT file_path FROM health_insurer_documents WHERE id = ?");
            $stmt->execute([$docId]);
            $doc = $stmt->fetch();
            
            if ($doc) {
                $filePath = __DIR__ . '/' . ltrim($doc['file_path'], '/');
                if (file_exists($filePath)) {
                    @unlink($filePath);
                }
                $db->prepare("DELETE FROM health_insurer_documents WHERE id = ?")->execute([$docId]);
            }
            
            echo json_encode(['success' => true]);
            exit;
        }
        
        echo json_encode(['success' => false, 'error' => 'Ação inválida']);
        exit;
    }
    
    // Multipart form (upload)
    $action = $_POST['action'] ?? '';
    $insurerId = (int)($_POST['insurer_id'] ?? 0);
    
    if ($action === 'upload' && $insurerId > 0) {
        $allowedTypes = [
            'application/pdf', 
            'application/msword', 
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'image/jpeg', 'image/png', 'image/webp'
        ];
        $allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'webp'];
        $maxSize = 10 * 1024 * 1024; // 10MB
        
        $uploadDir = __DIR__ . '/uploads/insurer_docs/' . $insurerId . '/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $files = $_FILES['files'] ?? [];
        if (!isset($files['name']) || !is_array($files['name'])) {
            echo json_encode(['success' => false, 'error' => 'Nenhum arquivo enviado']);
            exit;
        }
        
        $uploaded = 0;
        $errors = [];
        
        for ($i = 0; $i < count($files['name']); $i++) {
            $fileName = $files['name'][$i];
            $tmpName = $files['tmp_name'][$i];
            $fileSize = (int)$files['size'][$i];
            $fileType = $files['type'][$i];
            $error = $files['error'][$i];
            
            if ($error !== UPLOAD_ERR_OK) {
                $errors[] = "$fileName: erro no upload";
                continue;
            }
            
            if ($fileSize > $maxSize) {
                $errors[] = "$fileName: excede 10MB";
                continue;
            }
            
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExtensions)) {
                $errors[] = "$fileName: formato não permitido";
                continue;
            }
            
            // Gerar nome único
            $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName);
            $uniqueName = time() . '_' . bin2hex(random_bytes(4)) . '_' . $safeName;
            $destPath = $uploadDir . $uniqueName;
            
            if (move_uploaded_file($tmpName, $destPath)) {
                $relativePath = '/uploads/insurer_docs/' . $insurerId . '/' . $uniqueName;
                
                $stmt = $db->prepare("
                    INSERT INTO health_insurer_documents (health_insurer_id, file_name, file_path, file_size, mime_type, uploaded_by_user_id)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$insurerId, $fileName, $relativePath, $fileSize, $fileType, auth_user_id()]);
                $uploaded++;
            } else {
                $errors[] = "$fileName: falha ao salvar";
            }
        }
        
        if ($uploaded > 0) {
            echo json_encode(['success' => true, 'uploaded' => $uploaded, 'errors' => $errors]);
        } else {
            echo json_encode(['success' => false, 'error' => implode('; ', $errors) ?: 'Nenhum arquivo enviado']);
        }
        exit;
    }
    
    echo json_encode(['success' => false, 'error' => 'Parâmetros inválidos']);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Método não suportado']);

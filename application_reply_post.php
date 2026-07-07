<?php
/**
 * Processa resposta do candidato (página pública, sem login).
 */

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$token = trim((string)($_POST['token'] ?? ''));

if ($token === '' || strlen($token) < 32) {
    http_response_code(400);
    echo 'Token inválido.';
    exit;
}

$stmt = db()->prepare('SELECT id, status FROM professional_applications WHERE reply_token = :token');
$stmt->execute(['token' => $token]);
$app = $stmt->fetch();

if (!$app) {
    http_response_code(404);
    echo 'Candidatura não encontrada.';
    exit;
}

$appId = (int)$app['id'];
$replyType = (string)($_POST['reply_type'] ?? 'text');

if (!in_array($replyType, ['text', 'image', 'file'], true)) {
    $replyType = 'text';
}

if ($replyType === 'text') {
    $content = trim((string)($_POST['content'] ?? ''));
    if ($content === '') {
        header('Location: /application_reply.php?token=' . urlencode($token) . '&error=empty');
        exit;
    }
    
    // Salvar resposta de texto
    $insertStmt = db()->prepare(
        'INSERT INTO professional_application_replies (application_id, reply_type, content, file_path, file_name, file_size) '
        . 'VALUES (:app_id, :type, :content, NULL, NULL, NULL)'
    );
    $insertStmt->execute([
        'app_id' => $appId,
        'type' => 'text',
        'content' => $content,
    ]);
} else {
    // Upload de arquivo(s)/imagem(ns)
    if (!isset($_FILES['files']) || !is_array($_FILES['files']['name'])) {
        header('Location: /application_reply.php?token=' . urlencode($token) . '&error=upload');
        exit;
    }

    $maxSize = 10 * 1024 * 1024; // 10MB por arquivo
    $uploadDir = __DIR__ . '/uploads/application_replies/' . $appId;
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $filesCount = count($_FILES['files']['name']);
    $uploaded = 0;

    for ($i = 0; $i < $filesCount; $i++) {
        if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) {
            continue;
        }
        if ((int)$_FILES['files']['size'][$i] > $maxSize) {
            continue;
        }

        $fileName = basename((string)$_FILES['files']['name'][$i]);
        $fileSize = (int)$_FILES['files']['size'][$i];
        $tmpPath = (string)$_FILES['files']['tmp_name'][$i];

        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $uniqueName = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $destPath = $uploadDir . '/' . $uniqueName;

        if (!move_uploaded_file($tmpPath, $destPath)) {
            continue;
        }

        $filePath = '/uploads/application_replies/' . $appId . '/' . $uniqueName;

        // Determinar tipo
        $itemType = $replyType;
        if ($replyType === 'image') {
            $imageInfo = @getimagesize($destPath);
            if ($imageInfo === false) {
                $itemType = 'file';
            }
        }

        $insertStmt = db()->prepare(
            'INSERT INTO professional_application_replies (application_id, reply_type, content, file_path, file_name, file_size) '
            . 'VALUES (:app_id, :type, NULL, :file_path, :file_name, :file_size)'
        );
        $insertStmt->execute([
            'app_id' => $appId,
            'type' => $itemType,
            'file_path' => $filePath,
            'file_name' => $fileName,
            'file_size' => $fileSize,
        ]);
        $uploaded++;
    }

    if ($uploaded === 0) {
        header('Location: /application_reply.php?token=' . urlencode($token) . '&error=upload');
        exit;
    }
}

// Criar notificação interna para o admin
try {
    $notifStmt = db()->prepare(
        "INSERT INTO notifications (user_id, type, title, body, link, created_at) "
        . "SELECT id, 'info', :title, :body, :link, NOW() FROM users WHERE id IN "
        . "(SELECT DISTINCT user_id FROM user_roles WHERE role_id IN (SELECT id FROM roles WHERE slug = 'admin'))"
    );
    $notifStmt->execute([
        'title' => 'Candidatura #' . $appId . ' - Resposta recebida',
        'body' => 'O candidato enviou um complemento (' . $replyType . ').',
        'link' => '/professional_applications_view.php?id=' . $appId,
    ]);
} catch (Throwable $e) {
    // Não bloquear se notificação falhar
    error_log('[APP_REPLY] Erro notificação: ' . $e->getMessage());
}

header('Location: /application_reply.php?token=' . urlencode($token) . '&sent=1');
exit;

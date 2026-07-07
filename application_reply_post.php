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

$content = null;
$filePath = null;
$fileName = null;
$fileSize = null;

if ($replyType === 'text') {
    $content = trim((string)($_POST['content'] ?? ''));
    if ($content === '') {
        header('Location: /application_reply.php?token=' . urlencode($token) . '&error=empty');
        exit;
    }
} else {
    // Upload de arquivo/imagem
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        header('Location: /application_reply.php?token=' . urlencode($token) . '&error=upload');
        exit;
    }

    $file = $_FILES['file'];
    $maxSize = 10 * 1024 * 1024; // 10MB

    if ((int)$file['size'] > $maxSize) {
        header('Location: /application_reply.php?token=' . urlencode($token) . '&error=size');
        exit;
    }

    $fileName = basename((string)$file['name']);
    $fileSize = (int)$file['size'];

    // Diretório de upload
    $uploadDir = __DIR__ . '/uploads/application_replies/' . $appId;
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // Nome único
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $uniqueName = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destPath = $uploadDir . '/' . $uniqueName;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        header('Location: /application_reply.php?token=' . urlencode($token) . '&error=save');
        exit;
    }

    $filePath = '/uploads/application_replies/' . $appId . '/' . $uniqueName;

    // Se é imagem, verificar se é válida
    if ($replyType === 'image') {
        $imageInfo = @getimagesize($destPath);
        if ($imageInfo === false) {
            // Não é imagem válida, tratar como arquivo
            $replyType = 'file';
        }
    }
}

// Salvar no banco
$insertStmt = db()->prepare(
    'INSERT INTO professional_application_replies (application_id, reply_type, content, file_path, file_name, file_size) '
    . 'VALUES (:app_id, :type, :content, :file_path, :file_name, :file_size)'
);
$insertStmt->execute([
    'app_id' => $appId,
    'type' => $replyType,
    'content' => $content,
    'file_path' => $filePath,
    'file_name' => $fileName,
    'file_size' => $fileSize,
]);

// Criar notificação interna para o admin
try {
    $notifStmt = db()->prepare(
        "INSERT INTO notifications (user_id, type, title, body, link, created_at) "
        . "SELECT id, 'info', :title, :body, :link, NOW() FROM users WHERE id IN "
        . "(SELECT DISTINCT user_id FROM role_user WHERE role_id IN (SELECT id FROM roles WHERE slug = 'admin'))"
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

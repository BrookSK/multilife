<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('chat.manage');

header('Content-Type: application/json');

$response = ['success' => false, 'error' => ''];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['error'] = 'Método não permitido';
    echo json_encode($response);
    exit;
}

$remoteJid = trim($_POST['remote_jid'] ?? '');
$mediaType = trim($_POST['media_type'] ?? ''); // audio, image, video, document

if (empty($remoteJid) || empty($mediaType)) {
    $response['error'] = 'Parâmetros obrigatórios faltando';
    echo json_encode($response);
    exit;
}

// Verificar se há arquivo enviado
if (!isset($_FILES['media']) || $_FILES['media']['error'] !== UPLOAD_ERR_OK) {
    $response['error'] = 'Nenhum arquivo enviado ou erro no upload';
    echo json_encode($response);
    exit;
}

$file = $_FILES['media'];
$fileName = $file['name'];
$fileTmpPath = $file['tmp_name'];
$fileSize = $file['size'];
$fileMimeType = $file['type'];

// Validar tipo de arquivo
$allowedTypes = [
    'audio' => ['audio/mpeg', 'audio/mp3', 'audio/ogg', 'audio/wav', 'audio/webm'],
    'image' => ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'],
    'video' => ['video/mp4', 'video/webm', 'video/ogg', 'video/quicktime'],
    'document' => ['application/pdf']
];

if (!isset($allowedTypes[$mediaType]) || !in_array($fileMimeType, $allowedTypes[$mediaType])) {
    $response['error'] = 'Tipo de arquivo não permitido para ' . $mediaType;
    echo json_encode($response);
    exit;
}

// Limitar tamanho do arquivo (25MB para vídeo, 10MB para outros)
$maxSize = $mediaType === 'video' ? 25 * 1024 * 1024 : 10 * 1024 * 1024;
if ($fileSize > $maxSize) {
    $response['error'] = 'Arquivo muito grande. Máximo: ' . ($maxSize / 1024 / 1024) . 'MB';
    echo json_encode($response);
    exit;
}

try {
    // Criar diretório de uploads se não existir
    $uploadDir = __DIR__ . '/uploads/chat_media/' . date('Y-m');
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    // Gerar nome único para o arquivo
    $fileExtension = pathinfo($fileName, PATHINFO_EXTENSION);
    $uniqueFileName = uniqid('chat_', true) . '.' . $fileExtension;
    $destinationPath = $uploadDir . '/' . $uniqueFileName;
    
    // Mover arquivo para destino
    if (!move_uploaded_file($fileTmpPath, $destinationPath)) {
        throw new Exception('Erro ao salvar arquivo');
    }
    
    // URL pública do arquivo
    $mediaUrl = '/uploads/chat_media/' . date('Y-m') . '/' . $uniqueFileName;
    
    // Transcrição de áudio (se aplicável)
    $transcription = null;
    if ($mediaType === 'audio') {
        // TODO: Implementar transcrição usando Whisper API ou similar
        // Por enquanto, deixar como placeholder
        $transcription = '[Transcrição automática será implementada]';
    }
    
    // Buscar configurações da Evolution API
    $baseUrl = admin_setting_get('evolution.base_url');
    $apiKey = admin_setting_get('evolution.api_key');
    $instanceName = admin_setting_get('evolution.instance');
    
    if (empty($baseUrl) || empty($apiKey) || empty($instanceName)) {
        throw new Exception('Evolution API não configurada');
    }
    
    // Enviar mídia via Evolution API
    $api = new EvolutionApiV1();
    
    // Preparar URL completa do arquivo
    $fullMediaUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') 
                    . '://' . $_SERVER['HTTP_HOST'] . $mediaUrl;
    
    // Enviar baseado no tipo
    $apiResponse = null;
    switch ($mediaType) {
        case 'audio':
            $apiResponse = $api->sendAudio($remoteJid, $fullMediaUrl);
            break;
        case 'image':
            $caption = trim($_POST['caption'] ?? '');
            $apiResponse = $api->sendImage($remoteJid, $fullMediaUrl, $caption);
            break;
        case 'video':
            $caption = trim($_POST['caption'] ?? '');
            $apiResponse = $api->sendVideo($remoteJid, $fullMediaUrl, $caption);
            break;
        case 'document':
            $apiResponse = $api->sendDocument($remoteJid, $fullMediaUrl, $fileName);
            break;
    }
    
    $httpCode = (int)($apiResponse['status'] ?? 0);
    
    if ($httpCode !== 200 && $httpCode !== 201) {
        throw new Exception('Erro ao enviar mídia via WhatsApp: ' . ($apiResponse['body_raw'] ?? 'Erro desconhecido'));
    }
    
    // Salvar mensagem no banco de dados
    $timestamp = time();
    $normalizedJid = normalizeJid($remoteJid);
    
    $stmt = db()->prepare("
        INSERT INTO chat_messages 
        (remote_jid, message_text, message_type, media_url, media_mime_type, media_filename, media_size, audio_transcription, from_me, message_timestamp)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
    ");
    
    $messageText = $mediaType === 'audio' ? '[Áudio]' : 
                   ($mediaType === 'image' ? '[Imagem]' : 
                   ($mediaType === 'video' ? '[Vídeo]' : '[Documento]'));
    
    $stmt->execute([
        $normalizedJid,
        $messageText,
        $mediaType,
        $mediaUrl,
        $fileMimeType,
        $fileName,
        $fileSize,
        $transcription,
        $timestamp
    ]);
    
    // Atualizar última mensagem do contato
    $updateStmt = db()->prepare("
        UPDATE chat_contacts 
        SET last_message_timestamp = ?,
            last_message_text = ?,
            last_message_type = ?
        WHERE remote_jid = ?
    ");
    $updateStmt->execute([$timestamp, $messageText, $mediaType, $normalizedJid]);
    
    $response['success'] = true;
    $response['media_url'] = $mediaUrl;
    $response['message_type'] = $mediaType;
    $response['timestamp'] = $timestamp;
    if ($transcription) {
        $response['transcription'] = $transcription;
    }
    
} catch (Exception $e) {
    error_log('Erro ao enviar mídia: ' . $e->getMessage());
    $response['error'] = $e->getMessage();
}

// Função para normalizar JID (mesma do chat_web.php)
function normalizeJid(string $jid): string {
    $numberOnly = preg_replace('/@(s\.whatsapp\.net|g\.us|lid|c\.us|broadcast)$/', '', $jid);
    if (strpos($jid, '@g.us') !== false) {
        return $numberOnly . '@g.us';
    }
    return $numberOnly . '@s.whatsapp.net';
}

echo json_encode($response);

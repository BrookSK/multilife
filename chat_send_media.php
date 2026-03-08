<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('chat.manage');

header('Content-Type: application/json');

$debugId = uniqid('MEDIA_');
error_log("[$debugId] === INICIO UPLOAD DE MÍDIA ===");
error_log("[$debugId] POST: " . json_encode(array_keys($_POST)));
error_log("[$debugId] FILES: " . json_encode(array_keys($_FILES)));

$response = ['success' => false, 'error' => ''];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error_log("[$debugId] ERRO: Método não permitido - " . $_SERVER['REQUEST_METHOD']);
    $response['error'] = 'Método não permitido';
    echo json_encode($response);
    exit;
}

$remoteJid = trim($_POST['remote_jid'] ?? '');
$mediaType = trim($_POST['media_type'] ?? ''); // audio, image, video, document

error_log("[$debugId] remote_jid: '$remoteJid' | media_type: '$mediaType'");

if (empty($remoteJid) || empty($mediaType)) {
    error_log("[$debugId] ERRO: Parâmetros obrigatórios faltando");
    $response['error'] = 'Parâmetros obrigatórios faltando';
    echo json_encode($response);
    exit;
}

// Verificar se há arquivo enviado
if (!isset($_FILES['media'])) {
    error_log("[$debugId] ERRO: Nenhum arquivo no \$_FILES['media']");
    $response['error'] = 'Nenhum arquivo enviado';
    echo json_encode($response);
    exit;
}

$fileError = $_FILES['media']['error'];
error_log("[$debugId] Upload error code: $fileError");

if ($fileError !== UPLOAD_ERR_OK) {
    $errorMessages = [
        UPLOAD_ERR_INI_SIZE => 'Arquivo excede upload_max_filesize do php.ini',
        UPLOAD_ERR_FORM_SIZE => 'Arquivo excede MAX_FILE_SIZE do formulário',
        UPLOAD_ERR_PARTIAL => 'Upload parcial do arquivo',
        UPLOAD_ERR_NO_FILE => 'Nenhum arquivo foi enviado',
        UPLOAD_ERR_NO_TMP_DIR => 'Pasta temporária ausente',
        UPLOAD_ERR_CANT_WRITE => 'Falha ao escrever arquivo no disco',
        UPLOAD_ERR_EXTENSION => 'Upload bloqueado por extensão PHP'
    ];
    $errorMsg = $errorMessages[$fileError] ?? 'Erro desconhecido no upload';
    error_log("[$debugId] ERRO: $errorMsg (code: $fileError)");
    $response['error'] = 'Erro no upload: ' . $errorMsg;
    echo json_encode($response);
    exit;
}

$file = $_FILES['media'];
$fileName = $file['name'];
$fileTmpPath = $file['tmp_name'];
$fileSize = $file['size'];
$fileMimeType = $file['type'];

error_log("[$debugId] Arquivo recebido: '$fileName' | Tamanho: $fileSize bytes | MIME: '$fileMimeType'");

// Validar tipo de arquivo
$allowedTypes = [
    'audio' => ['audio/mpeg', 'audio/mp3', 'audio/ogg', 'audio/wav', 'audio/webm'],
    'image' => ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'],
    'video' => ['video/mp4', 'video/webm', 'video/ogg', 'video/quicktime'],
    'document' => ['application/pdf']
];

if (!isset($allowedTypes[$mediaType])) {
    error_log("[$debugId] ERRO: Tipo de mídia inválido: '$mediaType'");
    $response['error'] = 'Tipo de mídia inválido';
    echo json_encode($response);
    exit;
}

if (!in_array($fileMimeType, $allowedTypes[$mediaType])) {
    error_log("[$debugId] ERRO: MIME type '$fileMimeType' não permitido para '$mediaType'");
    error_log("[$debugId] Tipos permitidos: " . json_encode($allowedTypes[$mediaType]));
    $response['error'] = 'Tipo de arquivo não permitido para ' . $mediaType;
    echo json_encode($response);
    exit;
}

error_log("[$debugId] Validação de tipo OK");

// Limitar tamanho do arquivo (25MB para vídeo, 10MB para outros)
$maxSize = $mediaType === 'video' ? 25 * 1024 * 1024 : 10 * 1024 * 1024;
if ($fileSize > $maxSize) {
    error_log("[$debugId] ERRO: Arquivo muito grande - $fileSize bytes (máx: $maxSize bytes)");
    $response['error'] = 'Arquivo muito grande. Máximo: ' . ($maxSize / 1024 / 1024) . 'MB';
    echo json_encode($response);
    exit;
}

error_log("[$debugId] Validação de tamanho OK");

try {
    // Criar diretório de uploads se não existir
    $uploadDir = __DIR__ . '/uploads/chat_media/' . date('Y-m');
    error_log("[$debugId] Diretório de upload: $uploadDir");
    
    if (!is_dir($uploadDir)) {
        error_log("[$debugId] Criando diretório de upload...");
        if (!mkdir($uploadDir, 0755, true)) {
            throw new Exception('Falha ao criar diretório de upload');
        }
        error_log("[$debugId] Diretório criado com sucesso");
    }
    
    // Gerar nome único para o arquivo
    $fileExtension = pathinfo($fileName, PATHINFO_EXTENSION);
    $uniqueFileName = uniqid('chat_', true) . '.' . $fileExtension;
    $destinationPath = $uploadDir . '/' . $uniqueFileName;
    
    error_log("[$debugId] Movendo arquivo de '$fileTmpPath' para '$destinationPath'");
    
    // Mover arquivo para destino
    if (!move_uploaded_file($fileTmpPath, $destinationPath)) {
        error_log("[$debugId] ERRO: Falha ao mover arquivo");
        throw new Exception('Erro ao salvar arquivo');
    }
    
    error_log("[$debugId] Arquivo salvo com sucesso");
    
    // URL pública do arquivo
    $mediaUrl = '/uploads/chat_media/' . date('Y-m') . '/' . $uniqueFileName;
    error_log("[$debugId] URL da mídia: $mediaUrl");
    
    // Transcrição de áudio (se aplicável)
    $transcription = null;
    if ($mediaType === 'audio') {
        $transcription = '[Transcrição automática será implementada]';
        error_log("[$debugId] Áudio detectado - transcrição placeholder adicionada");
    }
    
    // Buscar configurações da Evolution API
    $baseUrl = admin_setting_get('evolution.base_url');
    $apiKey = admin_setting_get('evolution.api_key');
    $instanceName = admin_setting_get('evolution.instance');
    
    error_log("[$debugId] Evolution API - baseUrl: '$baseUrl' | instance: '$instanceName'");
    
    if (empty($baseUrl) || empty($apiKey) || empty($instanceName)) {
        error_log("[$debugId] ERRO: Evolution API não configurada");
        throw new Exception('Evolution API não configurada');
    }
    
    // Enviar mídia via Evolution API
    $api = new EvolutionApiV1();
    
    // Preparar URL completa do arquivo
    $fullMediaUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') 
                    . '://' . $_SERVER['HTTP_HOST'] . $mediaUrl;
    
    error_log("[$debugId] URL completa para Evolution API: $fullMediaUrl");
    error_log("[$debugId] Enviando mídia via Evolution API - Tipo: $mediaType | JID: $remoteJid");
    
    // Enviar baseado no tipo
    $apiResponse = null;
    switch ($mediaType) {
        case 'audio':
            error_log("[$debugId] Chamando sendAudio()");
            $apiResponse = $api->sendAudio($remoteJid, $fullMediaUrl);
            break;
        case 'image':
            $caption = trim($_POST['caption'] ?? '');
            error_log("[$debugId] Chamando sendImage() - Caption: '$caption'");
            $apiResponse = $api->sendImage($remoteJid, $fullMediaUrl, $caption);
            break;
        case 'video':
            $caption = trim($_POST['caption'] ?? '');
            error_log("[$debugId] Chamando sendVideo() - Caption: '$caption'");
            $apiResponse = $api->sendVideo($remoteJid, $fullMediaUrl, $caption);
            break;
        case 'document':
            error_log("[$debugId] Chamando sendDocument()");
            $apiResponse = $api->sendDocument($remoteJid, $fullMediaUrl, $fileName);
            break;
    }
    
    $httpCode = (int)($apiResponse['status'] ?? 0);
    $responseBody = is_string($apiResponse['body_raw'] ?? null) ? $apiResponse['body_raw'] : json_encode($apiResponse['json'] ?? []);
    
    error_log("[$debugId] Resposta Evolution API - HTTP: $httpCode | Body: " . substr($responseBody, 0, 500));
    
    if ($httpCode !== 200 && $httpCode !== 201) {
        error_log("[$debugId] ERRO: Falha ao enviar via Evolution API");
        throw new Exception('Erro ao enviar mídia via WhatsApp: ' . ($apiResponse['body_raw'] ?? 'Erro desconhecido'));
    }
    
    error_log("[$debugId] Mídia enviada com sucesso via Evolution API");
    
    // Salvar mensagem no banco de dados
    $timestamp = time();
    $normalizedJid = normalizeJid($remoteJid);
    
    error_log("[$debugId] Salvando no banco de dados - JID: $normalizedJid | Timestamp: $timestamp");
    
    $stmt = db()->prepare("
        INSERT INTO chat_messages 
        (remote_jid, message_text, message_type, media_url, media_mime_type, media_filename, media_size, audio_transcription, from_me, message_timestamp)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
    ");
    
    $messageText = $mediaType === 'audio' ? '[Áudio]' : 
                   ($mediaType === 'image' ? '[Imagem]' : 
                   ($mediaType === 'video' ? '[Vídeo]' : '[Documento]'));
    
    error_log("[$debugId] Texto da mensagem: '$messageText'");
    
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
    
    $insertId = db()->lastInsertId();
    error_log("[$debugId] Mensagem salva no banco - ID: $insertId");
    
    // Atualizar última mensagem do contato
    error_log("[$debugId] Atualizando última mensagem do contato");
    $updateStmt = db()->prepare("
        UPDATE chat_contacts 
        SET last_message_timestamp = ?,
            last_message_text = ?,
            last_message_type = ?
        WHERE remote_jid = ?
    ");
    $updateStmt->execute([$timestamp, $messageText, $mediaType, $normalizedJid]);
    $rowsAffected = $updateStmt->rowCount();
    error_log("[$debugId] Contato atualizado - Linhas afetadas: $rowsAffected");
    
    $response['success'] = true;
    $response['media_url'] = $mediaUrl;
    $response['message_type'] = $mediaType;
    $response['timestamp'] = $timestamp;
    if ($transcription) {
        $response['transcription'] = $transcription;
    }
    
    error_log("[$debugId] === UPLOAD CONCLUÍDO COM SUCESSO ===");
    
} catch (Exception $e) {
    error_log("[$debugId] === ERRO EXCEPTION ===");
    error_log("[$debugId] Mensagem: " . $e->getMessage());
    error_log("[$debugId] Arquivo: " . $e->getFile());
    error_log("[$debugId] Linha: " . $e->getLine());
    error_log("[$debugId] Stack trace: " . $e->getTraceAsString());
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

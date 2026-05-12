<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();

header('Content-Type: application/json');

// Aceitar tanto GET quanto POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);
    exit;
}

$baseUrl = admin_setting_get('evolution.base_url');
$apiKey = admin_setting_get('evolution.api_key');
$instanceName = admin_setting_get('evolution.instance');

if (empty($baseUrl) || empty($apiKey) || empty($instanceName)) {
    echo json_encode(['success' => false, 'error' => 'Evolution API não configurada']);
    exit;
}

try {
    // Buscar grupos da Evolution API - tentar múltiplos formatos de endpoint
    $endpoints = [
        $baseUrl . '/group/fetchAllGroups/' . urlencode($instanceName) . '?getParticipants=false',
        $baseUrl . '/group/fetchAllGroups/' . urlencode($instanceName) . '?getMembers=false',
        $baseUrl . '/group/fetchAllGroups/' . urlencode($instanceName),
    ];
    
    $response = '';
    $httpCode = 0;
    $curlError = '';
    $groupsUrl = '';
    
    foreach ($endpoints as $url) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['apikey: ' . $apiKey]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        
        if ($code === 200 && !empty($resp) && $resp !== '[]' && $resp !== 'null' && $resp !== '""') {
            $response = $resp;
            $httpCode = $code;
            $curlError = $err;
            $groupsUrl = $url;
            break;
        }
        
        // Guardar último resultado mesmo se vazio
        $response = $resp;
        $httpCode = $code;
        $curlError = $err;
        $groupsUrl = $url;
    }
    
    if ($httpCode === 0) {
        error_log("Erro de conexão ao buscar grupos - URL: $groupsUrl - cURL Error: $curlError");
        echo json_encode([
            'success' => false, 
            'error' => 'Falha de conexão com a Evolution API. Verifique se a URL está correta e acessível.',
            'details' => 'URL: ' . $groupsUrl,
            'curl_error' => $curlError
        ]);
        exit;
    }
    
    if ($httpCode !== 200) {
        $errMsg = ($httpCode === 404)
            ? 'Instância offline ou desconectada do WhatsApp (404). Reconecte em: ' . $baseUrl
            : 'Erro ao buscar grupos. HTTP Code: ' . $httpCode;
        echo json_encode([
            'success'    => false,
            'error'      => $errMsg,
            'http_code'  => $httpCode,
            'curl_error' => $curlError,
        ]);
        exit;
    }
    
    // Se resposta vazia, considerar como "sem grupos"
    if (empty($response) || $response === '[]' || $response === 'null' || $response === '""') {
        echo json_encode(['success' => true, 'count' => 0, 'message' => 'Nenhum grupo encontrado na instância']);
        exit;
    }
    
    $groupsData = json_decode($response, true);
    
    if (!is_array($groupsData)) {
        // Pode ser que a resposta esteja encapsulada em outro formato
        // Tentar extrair de formatos alternativos da Evolution API v2
        if (is_string($response)) {
            $decoded = json_decode($response, true);
            if (is_array($decoded) && isset($decoded['data']) && is_array($decoded['data'])) {
                $groupsData = $decoded['data'];
            } elseif (is_array($decoded) && isset($decoded['groups']) && is_array($decoded['groups'])) {
                $groupsData = $decoded['groups'];
            }
        }
        
        if (!is_array($groupsData)) {
            error_log("[CHAT_SYNC] Resposta inválida da API. HTTP: $httpCode. Response (primeiros 500 chars): " . substr((string)$response, 0, 500));
            echo json_encode([
                'success' => false, 
                'error' => 'Resposta inválida da API. Verifique os logs para detalhes.',
                'http_code' => $httpCode,
                'response_preview' => substr((string)$response, 0, 200)
            ]);
            exit;
        }
    }
    
    // Criar tabela se não existir
    db()->exec("
        CREATE TABLE IF NOT EXISTS chat_groups (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            group_jid VARCHAR(100) NOT NULL UNIQUE,
            group_name VARCHAR(255) NOT NULL,
            group_description TEXT DEFAULT NULL,
            group_picture_url TEXT DEFAULT NULL,
            specialty VARCHAR(100) DEFAULT NULL,
            region VARCHAR(100) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE INDEX idx_group_jid (group_jid),
            INDEX idx_specialty (specialty),
            INDEX idx_region (region)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    $count = 0;
    foreach ($groupsData as $group) {
        $groupJid = $group['id'] ?? '';
        $groupName = $group['subject'] ?? 'Grupo sem nome';
        $groupPic = $group['picture'] ?? null;
        
        if (!empty($groupJid)) {
            $stmt = db()->prepare("
                INSERT INTO chat_groups (group_jid, group_name, group_picture_url)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    group_name = VALUES(group_name),
                    group_picture_url = VALUES(group_picture_url),
                    updated_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([$groupJid, $groupName, $groupPic]);
            $count++;
        }
    }
    
    echo json_encode(['success' => true, 'count' => $count]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

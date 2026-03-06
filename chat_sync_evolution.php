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
    // Buscar grupos da Evolution API
    $groupsUrl = $baseUrl . '/group/fetchAllGroups/' . urlencode($instanceName);
    $ch = curl_init($groupsUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['apikey: ' . $apiKey]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        error_log("Erro ao buscar grupos - HTTP Code: $httpCode - Response: $response - cURL Error: $curlError");
        echo json_encode([
            'success' => false, 
            'error' => 'Erro ao buscar grupos. HTTP Code: ' . $httpCode,
            'response' => $response,
            'curl_error' => $curlError
        ]);
        exit;
    }
    
    $groupsData = json_decode($response, true);
    
    if (!is_array($groupsData)) {
        echo json_encode(['success' => false, 'error' => 'Resposta inválida da API']);
        exit;
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

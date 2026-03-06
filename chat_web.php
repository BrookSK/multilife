<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
// Qualquer usuário logado pode acessar o chat
// rbac_require_permission('chat.manage');

// Definir variáveis GET no início
$selectedChat = isset($_GET['chat']) ? trim((string)$_GET['chat']) : '';
$chatType = isset($_GET['type']) ? trim((string)$_GET['type']) : 'all';
$searchQuery = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

// Buscar configurações da Evolution API
$baseUrl = admin_setting_get('evolution.base_url');
$apiKey = admin_setting_get('evolution.api_key');
$instanceName = admin_setting_get('evolution.instance');

$success = '';
$error = '';

// Função para formatar telefone no padrão Evolution API
function format_phone_evolution($phone) {
    if (empty($phone)) return '';
    
    // Remover todos os caracteres não numéricos
    $cleaned = preg_replace('/[^0-9]/', '', $phone);
    
    // Se começar com 0, remover
    if (substr($cleaned, 0, 1) === '0') {
        $cleaned = substr($cleaned, 1);
    }
    
    // Se não tiver código do país (55 para Brasil), adicionar
    if (strlen($cleaned) === 10 || strlen($cleaned) === 11) {
        $cleaned = '55' . $cleaned;
    }
    
    return $cleaned;
}

// PROCESSAR ENVIO DE MENSAGEM
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'send_message') {
        // Buscar número de qualquer um dos 3 campos possíveis
        $phoneNumber = trim($_POST['phone_number'] ?? '');
        if (empty($phoneNumber)) {
            $phoneNumber = trim($_POST['contact_phone_professional'] ?? '');
        }
        if (empty($phoneNumber)) {
            $phoneNumber = trim($_POST['contact_phone_patient'] ?? '');
        }
        if (empty($phoneNumber)) {
            $phoneNumber = trim($_POST['contact_phone_manual'] ?? '');
        }
        
        $message = trim($_POST['message'] ?? '');
        
        if (empty($phoneNumber) || empty($message)) {
            $error = 'Número e mensagem são obrigatórios';
        } else {
            // Usar o JID diretamente se já contém @
            $remoteJid = $phoneNumber;
            
            // Se não contém @, formatar como número de telefone individual
            if (strpos($remoteJid, '@') === false) {
                // Formatar número automaticamente para padrão Evolution API
                $remoteJid = format_phone_evolution($phoneNumber);
                $remoteJid .= '@s.whatsapp.net';
            }
            // Se já contém @g.us (grupo) ou @s.whatsapp.net (individual), usar como está
            
            // Enviar mensagem via API Evolution
            $url = $baseUrl . '/message/sendText/' . urlencode($instanceName);
            $payload = json_encode([
                'number' => $remoteJid,
                'textMessage' => [
                    'text' => $message
                ]
            ]);
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'apikey: ' . $apiKey,
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200 || $httpCode === 201) {
                // OBRIGATÓRIO: Salvar mensagem no banco de dados
                $savedToDb = false;
                $dbError = '';
                
                try {
                    // Verificar se tabela existe
                    $tableExists = db()->query("SHOW TABLES LIKE 'chat_messages'")->fetch();
                    
                    if (!$tableExists) {
                        // Criar tabela apenas se não existir
                        db()->exec("
                            CREATE TABLE chat_messages (
                                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                                remote_jid VARCHAR(100) NOT NULL,
                                message_text TEXT NOT NULL,
                                from_me TINYINT(1) NOT NULL DEFAULT 0,
                                message_timestamp INT UNSIGNED NOT NULL,
                                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                                PRIMARY KEY (id),
                                INDEX idx_remote_jid (remote_jid),
                                INDEX idx_timestamp (message_timestamp)
                            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                        ");
                    }
                    
                    $timestamp = time();
                    $stmt = db()->prepare("
                        INSERT INTO chat_messages (remote_jid, message_text, from_me, message_timestamp)
                        VALUES (?, ?, 1, ?)
                    ");
                    $savedToDb = $stmt->execute([$remoteJid, $message, $timestamp]);
                    
                    // Verificar se realmente salvou
                    if ($savedToDb) {
                        $lastId = db()->lastInsertId();
                        if (!$lastId) {
                            $savedToDb = false;
                            $dbError = "INSERT não retornou ID";
                        } else {
                            // Salvar/atualizar contato na tabela de chats ativos
                            try {
                                // Criar tabela de contatos se não existir
                                db()->exec("
                                    CREATE TABLE IF NOT EXISTS chat_contacts (
                                        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                                        remote_jid VARCHAR(100) NOT NULL UNIQUE,
                                        contact_name VARCHAR(255) DEFAULT NULL,
                                        profile_picture_url TEXT DEFAULT NULL,
                                        is_group TINYINT(1) NOT NULL DEFAULT 0,
                                        last_message_timestamp INT UNSIGNED DEFAULT NULL,
                                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                                        PRIMARY KEY (id),
                                        UNIQUE INDEX idx_remote_jid (remote_jid),
                                        INDEX idx_last_message (last_message_timestamp)
                                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                                ");
                                
                                // Inserir ou atualizar contato
                                $isGroup = strpos($remoteJid, '@g.us') !== false ? 1 : 0;
                                $contactName = str_replace(['@s.whatsapp.net', '@g.us'], '', $remoteJid);
                                
                                $stmtContact = db()->prepare("
                                    INSERT INTO chat_contacts (remote_jid, contact_name, is_group, last_message_timestamp)
                                    VALUES (?, ?, ?, ?)
                                    ON DUPLICATE KEY UPDATE 
                                        last_message_timestamp = VALUES(last_message_timestamp),
                                        updated_at = CURRENT_TIMESTAMP
                                ");
                                $stmtContact->execute([$remoteJid, $contactName, $isGroup, $timestamp]);
                                
                                // Buscar perfil do contato da Evolution API
                                try {
                                    $profileUrl = $baseUrl . '/chat/fetchProfile/' . urlencode($instanceName);
                                    $profilePayload = json_encode(['number' => $remoteJid]);
                                    
                                    $ch = curl_init($profileUrl);
                                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                    curl_setopt($ch, CURLOPT_POST, true);
                                    curl_setopt($ch, CURLOPT_POSTFIELDS, $profilePayload);
                                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                                        'apikey: ' . $apiKey,
                                        'Content-Type: application/json'
                                    ]);
                                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                                    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                                    
                                    $profileResponse = curl_exec($ch);
                                    $profileHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                                    curl_close($ch);
                                    
                                    if ($profileHttpCode === 200 && $profileResponse) {
                                        $profileData = json_decode($profileResponse, true);
                                        // API retorna 'picture' ao invés de 'profilePictureUrl'
                                        $profilePic = $profileData['picture'] ?? null;
                                        
                                        // Atualizar contato com foto (API não retorna nome)
                                        if ($profilePic) {
                                            $updateStmt = db()->prepare("
                                                UPDATE chat_contacts 
                                                SET profile_picture_url = ?
                                                WHERE remote_jid = ?
                                            ");
                                            $updateStmt->execute([$profilePic, $remoteJid]);
                                            error_log("Foto de perfil atualizada: " . $profilePic);
                                        }
                                    }
                                } catch (Exception $e) {
                                    error_log("Erro ao buscar perfil: " . $e->getMessage());
                                }
                            } catch (Exception $e) {
                                error_log("Erro ao salvar contato: " . $e->getMessage());
                            }
                        }
                    }
                    
                } catch (Exception $e) {
                    $dbError = $e->getMessage();
                    error_log("ERRO CRÍTICO ao salvar mensagem: " . $dbError);
                }
                
                // Se não salvou no banco, mostrar erro e NÃO redirecionar
                if (!$savedToDb) {
                    $error = "ERRO CRÍTICO: Mensagem não foi salva no banco de dados. Erro: " . $dbError . ". Entre em contato com o suporte.";
                } else {
                    // Sucesso - redirecionar
                    header('Location: /chat_web.php?chat=' . urlencode($remoteJid) . '&success=1');
                    exit();
                }
            } else {
                $responseData = json_decode($response, true);
                $errorMsg = $responseData['message'] ?? $response;
                $error = 'Erro ao enviar mensagem. HTTP Code: ' . $httpCode . ' - ' . $errorMsg;
            }
        }
    } elseif ($_POST['action'] === 'create_group') {
        error_log("=== CRIAR GRUPO INICIADO ===");
        $specialty = trim($_POST['specialty'] ?? '');
        $location = strtoupper(trim($_POST['location'] ?? ''));
        error_log("Specialty: $specialty, Location: $location");
        
        if (empty($specialty) || empty($location)) {
            $error = 'Especialidade e localização são obrigatórias.';
            error_log("ERRO: Campos obrigatórios vazios");
        } elseif (empty($baseUrl) || empty($apiKey) || empty($instanceName)) {
            $error = 'Evolution API não configurada. Configure em Configurações > Evolution API.';
            error_log("ERRO: Evolution API não configurada");
        } else {
            // Gerar número sequencial do grupo
            try {
                $countStmt = db()->prepare('SELECT COUNT(*) FROM chat_groups WHERE specialty = ? AND region = ?');
                $countStmt->execute([$specialty, $location]);
                $groupNumber = (int)$countStmt->fetchColumn() + 1;
            } catch (Exception $e) {
                $groupNumber = 1;
            }
            
            // Padrão: Especialidade - Localização - Número
            $groupName = $specialty . ' - ' . $location . ' - ' . $groupNumber;
            
            // Criar grupo - tentar com número no formato correto
            // Nos logs do webhook, o número aparece como sender
            // Vamos tentar apenas com o número sem @s.whatsapp.net
            $url = $baseUrl . '/group/create/' . urlencode($instanceName);
            
            // Tentar com apenas o número (a API pode adicionar o sufixo automaticamente)
            $payload = json_encode([
                'subject' => $groupName,
                'description' => 'Grupo criado pelo sistema MultiLife',
                'participants' => ['5517991253062']
            ]);
            
            error_log("Criando grupo: $groupName");
            error_log("URL: $url");
            error_log("Payload: $payload");
            
            // Tentar criar o grupo com até 3 tentativas (erro 1006 é intermitente)
            $maxRetries = 3;
            $response = '';
            $httpCode = 0;
            $curlError = '';
            
            for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
                error_log("Tentativa $attempt de $maxRetries para criar grupo: $groupName");
                
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => "",
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => "POST",
                    CURLOPT_POSTFIELDS => $payload,
                    CURLOPT_HTTPHEADER => [
                        "Content-Type: application/json",
                        "apikey: " . $apiKey
                    ],
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false
                ]);
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);
                
                error_log("Tentativa $attempt - HTTP Code: $httpCode - Response: $response");
                
                // Se sucesso ou erro que não seja 1006, parar tentativas
                if ($httpCode === 200 || $httpCode === 201) {
                    error_log("Grupo criado com sucesso na tentativa $attempt");
                    break;
                }
                
                $responseData = json_decode($response, true);
                $errorMsg = $responseData['response']['message'][0] ?? '';
                
                // Se não for erro 1006, não faz sentido tentar novamente
                if ($errorMsg !== 'Error creating group') {
                    break;
                }
                
                // Aguardar antes de tentar novamente
                if ($attempt < $maxRetries) {
                    error_log("Erro 1006 - aguardando 3 segundos antes da próxima tentativa...");
                    sleep(3);
                }
            }
            
            if ($httpCode === 200 || $httpCode === 201) {
                $responseData = json_decode($response, true);
                $groupJid = $responseData['id'] ?? '';
                
                error_log("Group JID: $groupJid");
                
                // Salvar grupo no banco de dados
                if (!empty($groupJid)) {
                    try {
                        $stmt = db()->prepare("
                            INSERT INTO chat_groups (group_jid, group_name, specialty, region, created_at)
                            VALUES (?, ?, ?, ?, NOW())
                        ");
                        $stmt->execute([$groupJid, $groupName, $specialty, $location]);
                        
                        error_log("Grupo salvo no banco com sucesso");
                        audit_log('group_created', 'Grupo criado: ' . $groupName . ' (JID: ' . $groupJid . ')');
                        $success = '✅ Grupo criado com sucesso: ' . $groupName . '. Agora você pode convidar participantes via chat.';
                        
                        // Log no console do navegador
                        echo '<script>console.log("✅ GRUPO CRIADO COM SUCESSO");';
                        echo 'console.log("Nome:", ' . json_encode($groupName) . ');';
                        echo 'console.log("JID:", ' . json_encode($groupJid) . ');';
                        echo 'console.log("Specialty:", ' . json_encode($specialty) . ');';
                        echo 'console.log("Region:", ' . json_encode($location) . ');';
                        echo 'console.log("Salvo no banco: SIM");</script>';
                    } catch (Exception $e) {
                        error_log("Erro ao salvar grupo no banco: " . $e->getMessage());
                        $error = 'Grupo criado na Evolution API, mas erro ao salvar no banco: ' . $e->getMessage();
                        
                        echo '<script>console.error("❌ ERRO AO SALVAR NO BANCO");';
                        echo 'console.error("Erro:", ' . json_encode($e->getMessage()) . ');</script>';
                    }
                } else {
                    error_log("ERRO: Group JID vazio na resposta da API");
                    $error = 'Erro: API não retornou o ID do grupo. Response: ' . $response;
                    
                    echo '<script>console.error("❌ ERRO: API NÃO RETORNOU JID");';
                    echo 'console.error("Response:", ' . json_encode($response) . ');</script>';
                }
            } else {
                error_log("Erro ao criar grupo - HTTP Code: $httpCode - Response: $response - cURL Error: $curlError");
                if ($httpCode === 0) {
                    $error = '❌ Falha de conexão com a Evolution API. Verifique se a URL está correta e o servidor está acessível.';
                } else {
                    $error = '❌ Erro ao criar grupo. HTTP Code: ' . $httpCode . '. Detalhes: ' . ($response ?: $curlError);
                }
                
                echo '<script>console.error("❌ ERRO AO CRIAR GRUPO NA API");';
                echo 'console.error("HTTP Code:", ' . json_encode($httpCode) . ');';
                echo 'console.error("Response:", ' . json_encode($response) . ');';
                echo 'console.error("cURL Error:", ' . json_encode($curlError) . ');</script>';
            }
        }
    }
}

// Carregar chats salvos do banco de dados
$chats = [];
$messages = [];
$selectedChatData = [];
$debugLogs = [];

// Criar tabela de grupos se não existir
$groups = [];
try {
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
} catch (Exception $e) {
    error_log("Erro ao criar tabela chat_groups: " . $e->getMessage());
}

// Buscar grupos do banco com filtros
$specialtyFilter = isset($_GET['specialty']) ? trim($_GET['specialty']) : '';
$regionFilter = isset($_GET['region']) ? trim($_GET['region']) : '';

try {
    $tableCheck = db()->query("SHOW TABLES LIKE 'chat_groups'")->fetch();
    if ($tableCheck) {
        $whereClauses = [];
        $params = [];
        
        // FILTRO OBRIGATÓRIO: Apenas grupos criados pelo sistema (com specialty preenchida)
        $whereClauses[] = "specialty IS NOT NULL AND specialty != ''";
        
        if (!empty($specialtyFilter)) {
            $whereClauses[] = "specialty = ?";
            $params[] = $specialtyFilter;
        }
        
        if (!empty($regionFilter)) {
            $whereClauses[] = "region = ?";
            $params[] = $regionFilter;
        }
        
        $whereSQL = 'WHERE ' . implode(' AND ', $whereClauses);
        
        $stmt = db()->prepare("
            SELECT 
                group_jid as id,
                group_name as name,
                group_picture_url as profilePictureUrl,
                specialty,
                region
            FROM chat_groups
            $whereSQL
            ORDER BY group_name ASC
        ");
        $stmt->execute($params);
        $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
        error_log("Grupos encontrados no banco: " . count($groups));
        if (count($groups) > 0) {
            error_log("Primeiro grupo: " . json_encode($groups[0]));
        }
    }
} catch (Exception $e) {
    error_log("Erro ao carregar grupos do banco: " . $e->getMessage());
}

// Buscar chats ativos da tabela chat_contacts com filtros de status
try {
    $tableCheck = db()->query("SHOW TABLES LIKE 'chat_contacts'")->fetch();
    if ($tableCheck) {
        // Verificar e adicionar colunas necessárias
        try {
            $columns = db()->query("SHOW COLUMNS FROM chat_contacts LIKE 'profile_picture_url'")->fetch();
            if (!$columns) {
                db()->exec("ALTER TABLE chat_contacts ADD COLUMN profile_picture_url TEXT DEFAULT NULL AFTER contact_name");
            }
            $statusCol = db()->query("SHOW COLUMNS FROM chat_contacts LIKE 'status'")->fetch();
            if (!$statusCol) {
                db()->exec("ALTER TABLE chat_contacts ADD COLUMN status VARCHAR(20) DEFAULT 'aguardando' AFTER profile_picture_url");
            }
        } catch (Exception $e) {
            error_log("Erro ao verificar/adicionar colunas: " . $e->getMessage());
        }
        
        // Construir query com filtros
        $whereClauses = [];
        $params = [];
        
        if ($chatType === 'atendendo') {
            $whereClauses[] = "status = 'atendendo'";
        } elseif ($chatType === 'aguardando') {
            $whereClauses[] = "status = 'aguardando'";
        } elseif ($chatType === 'resolvidos') {
            $whereClauses[] = "status = 'resolvido'";
        } elseif ($chatType === 'organizacao') {
            $whereClauses[] = "contact_name LIKE '%Organização%' OR contact_name LIKE '%Admin%'";
        }
        
        if (!empty($searchQuery)) {
            $whereClauses[] = "(contact_name LIKE ? OR remote_jid LIKE ?)";
            $params[] = '%' . $searchQuery . '%';
            $params[] = '%' . $searchQuery . '%';
        }
        
        // Não buscar chats se estiver na aba de grupos
        if ($chatType !== 'grupos') {
            $whereSQL = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';
            
            $stmt = db()->prepare("
                SELECT 
                    remote_jid as id,
                    contact_name as name,
                    profile_picture_url as profilePictureUrl,
                    is_group,
                    status,
                    last_message_timestamp as lastMsgTimestamp
                FROM chat_contacts
                $whereSQL
                ORDER BY last_message_timestamp DESC
                LIMIT 50
            ");
            $stmt->execute($params);
            $chats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (Exception $e) {
    error_log("Erro ao carregar chats: " . $e->getMessage());
}

// Carregar mensagens do banco de dados para o chat selecionado
if (!empty($selectedChat)) {
    try {
        // Garantir que tabela existe com estrutura correta
        $tableCheck = db()->query("SHOW TABLES LIKE 'chat_messages'")->fetch();
        if (!$tableCheck) {
            // Criar tabela com estrutura correta
            db()->exec("
                CREATE TABLE chat_messages (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    remote_jid VARCHAR(100) NOT NULL,
                    message_text TEXT NOT NULL,
                    from_me TINYINT(1) NOT NULL DEFAULT 0,
                    message_timestamp INT UNSIGNED NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    INDEX idx_remote_jid (remote_jid),
                    INDEX idx_timestamp (message_timestamp)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
        
        $stmt = db()->prepare("
            SELECT message_text as text, from_me as fromMe, message_timestamp as timestamp
            FROM chat_messages
            WHERE remote_jid = ?
            ORDER BY message_timestamp ASC
        ");
        $stmt->execute([$selectedChat]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Converter fromMe para boolean
        foreach ($messages as &$msg) {
            $msg['fromMe'] = (bool)$msg['fromMe'];
        }
        unset($msg);
    } catch (Exception $e) {
        error_log("Erro ao carregar mensagens do banco: " . $e->getMessage());
        $messages = [];
    }
    
    // Mensagens vêm APENAS do banco de dados
    // Não usar sessão - tudo deve estar no banco
}

// Se um chat foi selecionado, verificar se existe na lista de chats
if (!empty($selectedChat)) {
    $chatExists = false;
    foreach ($chats as $chat) {
        if ($chat['id'] === $selectedChat) {
            $chatExists = true;
            $selectedChatData = $chat;
            break;
        }
    }
}

// Verificar se há mensagem de sucesso
if (isset($_GET['success']) && $_GET['success'] === '1') {
    $success = 'Mensagem enviada com sucesso!';
}

// Buscar profissionais e pacientes para seletor de contatos
$professionals = [];
$patients = [];

try {
    // Buscar profissionais da tabela professionals (não users)
    $stmt = db()->prepare("
        SELECT id, name, phone_primary as phone
        FROM professionals
        WHERE phone_primary IS NOT NULL
        AND phone_primary != ''
        ORDER BY name ASC
    ");
    $stmt->execute();
    $professionals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Buscar pacientes
    $stmt = db()->prepare("
        SELECT id, name, phone_primary as phone
        FROM patients
        WHERE phone_primary IS NOT NULL
        AND phone_primary != ''
        ORDER BY name ASC
    ");
    $stmt->execute();
    $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Erro ao buscar contatos: " . $e->getMessage());
}

// DESABILITADO: API Evolution não retorna mensagens corretas
// A API retorna sempre as mesmas mensagens antigas de canais, independente do filtro
// Até que a API seja corrigida ou sincronizada, mensagens não serão carregadas
$isPrivateChat = !empty($selectedChat) && strpos($selectedChat, '@g.us') === false;
if (false && $isPrivateChat && !empty($baseUrl) && !empty($apiKey) && !empty($instanceName)) {
    try {
        // Preparar payload da requisição
        // NOTA: A API Evolution IGNORA o filtro remoteJid, então buscamos mais mensagens
        // e filtramos no PHP depois para garantir que pegamos as corretas
        $requestPayload = [
            'limit' => 100  // Buscar 100 mensagens para ter certeza de pegar as do chat correto
        ];
        
        $requestJson = json_encode($requestPayload);
        
        // Preparar payload com ordenação por data DESC (mais recentes primeiro)
        $requestPayload = [
            'limit' => 100,
            'sort' => [
                'messageTimestamp' => -1  // -1 = DESC (mais recentes primeiro)
            ]
        ];
        $requestJson = json_encode($requestPayload);
        
        // Log detalhado da requisição
        $debugLogs[] = "=== CHAT_WEB DEBUG ===";
        $debugLogs[] = "Chat selecionado: " . $selectedChat;
        $debugLogs[] = "URL: " . $baseUrl . '/chat/findMessages/' . urlencode($instanceName);
        $debugLogs[] = "Payload: " . $requestJson;
        error_log("=== CHAT_WEB DEBUG ===");
        error_log("Chat selecionado: " . $selectedChat);
        error_log("URL: " . $baseUrl . '/chat/findMessages/' . urlencode($instanceName));
        error_log("Payload: " . $requestJson);
        
        // Usar endpoint /chat/findMessages com POST e ordenação
        $ch = curl_init($baseUrl . '/chat/findMessages/' . urlencode($instanceName));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'apikey: ' . $apiKey,
            'Content-Type: application/json',
            'Cache-Control: no-cache, no-store, must-revalidate',
            'Pragma: no-cache'
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $requestJson);
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        // Log da resposta
        $debugLogs[] = "HTTP Code: " . $httpCode;
        if ($curlError) {
            $debugLogs[] = "cURL Error: " . $curlError;
        }
        $debugLogs[] = "Resposta completa da API: " . substr($response, 0, 1000);
        error_log("HTTP Code: " . $httpCode);
        if ($curlError) {
            error_log("cURL Error: " . $curlError);
        }
        error_log("Resposta completa da API: " . substr($response, 0, 500));
        
        if ($httpCode === 200) {
            $data = json_decode($response, true);
            if (isset($data) && is_array($data)) {
                $messages = $data;
                error_log("Total mensagens recebidas (ordenadas por data DESC): " . count($messages));
                
                // Log das primeiras mensagens para verificar
                foreach (array_slice($messages, 0, 5) as $idx => $msg) {
                    $msgRemoteJid = $msg['key']['remoteJid'] ?? 'N/A';
                    $msgText = substr($msg['message']['conversation'] ?? $msg['message']['extendedTextMessage']['text'] ?? '', 0, 50);
                    error_log("Mensagem #" . $idx . " - remoteJid: " . $msgRemoteJid . " - Texto: " . $msgText);
                }
                
                // Aplicar filtro PHP (API ignora remoteJid no payload)
                error_log("Aplicando filtro PHP para chat: {$selectedChat}");
                $messages = array_filter($messages, function($msg) use ($selectedChat) {
                    return ($msg['key']['remoteJid'] ?? '') === $selectedChat;
                });
                $messages = array_values($messages);
                error_log("Após filtro PHP: " . count($messages) . " mensagens do chat correto");
                
                // Limitar a 10 mensagens
                if (count($messages) > 10) {
                    $messages = array_slice($messages, 0, 10);
                    error_log("Limitado a 10 mensagens mais recentes");
                }
                
                // Ordenar mensagens por timestamp (mais recentes no topo)
                usort($messages, function($a, $b) {
                    $timeA = $a['messageTimestamp'] ?? 0;
                    $timeB = $b['messageTimestamp'] ?? 0;
                    return $timeB - $timeA; // Invertido para mais recentes primeiro
                });
            }
        }
        
        // Buscar dados do chat selecionado
        foreach ($chats as $chat) {
            if (($chat['id'] ?? '') === $selectedChat) {
                $selectedChatData = $chat;
                
                // Buscar perfil completo para garantir nome e foto corretos
                if (!strpos($selectedChat, '@g.us')) {
                    try {
                        $number = str_replace('@s.whatsapp.net', '', $selectedChat);
                        $chProfile = curl_init($baseUrl . '/chat/fetchProfile/' . urlencode($instanceName) . '?number=' . urlencode($number));
                        curl_setopt($chProfile, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($chProfile, CURLOPT_HTTPHEADER, [
                            'apikey: ' . $apiKey,
                            'Cache-Control: no-cache'
                        ]);
                        curl_setopt($chProfile, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($chProfile, CURLOPT_TIMEOUT, 5);
                        curl_setopt($chProfile, CURLOPT_FRESH_CONNECT, true);
                        
                        $profileResponse = curl_exec($chProfile);
                        $profileHttpCode = curl_getinfo($chProfile, CURLINFO_HTTP_CODE);
                        curl_close($chProfile);
                        
                        if ($profileHttpCode === 200) {
                            $profileData = json_decode($profileResponse, true);
                            if (isset($profileData['profilePictureUrl'])) {
                                $selectedChatData['profilePictureUrl'] = $profileData['profilePictureUrl'];
                            }
                            if (isset($profileData['name']) && !empty($profileData['name'])) {
                                $selectedChatData['name'] = $profileData['name'];
                            }
                        }
                    } catch (Exception $e) {
                        // Ignorar erro de perfil
                    }
                }
                break;
            }
        }
    } catch (Exception $e) {
        // Erro ao buscar mensagens
    }
}

view_header('Chat ao Vivo');

// Renderizar modais PRIMEIRO (antes de qualquer JavaScript que os acesse)
// Modal: Nova Conversa
echo '<div id="newChatModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:10000;align-items:center;justify-content:center">';
echo '<div style="background:#fff;border-radius:12px;width:90%;max-width:500px;max-height:80vh;overflow:hidden;display:flex;flex-direction:column">';
echo '<div style="padding:20px;border-bottom:1px solid #e0e0e0;display:flex;justify-content:space-between;align-items:center">';
echo '<h2 style="margin:0;font-size:20px;color:#111b21">Nova Conversa</h2>';
echo '<button onclick="closeNewChatModal()" style="background:none;border:none;font-size:24px;cursor:pointer;color:#54656f">&times;</button>';
echo '</div>';
echo '<div style="flex:1;overflow-y:auto;padding:20px">';
echo '<form method="post" action="/chat_web.php" id="newChatForm">';
echo '<input type="hidden" name="action" value="send_message">';
echo '<div style="display:flex;gap:8px;margin-bottom:20px;border-bottom:1px solid #e0e0e0">';
echo '<button type="button" onclick="switchTab(\'professionals\')" id="tabProfessionals" style="padding:12px 16px;background:none;border:none;border-bottom:2px solid #00a884;color:#00a884;font-weight:600;cursor:pointer">Profissionais</button>';
echo '<button type="button" onclick="switchTab(\'patients\')" id="tabPatients" style="padding:12px 16px;background:none;border:none;border-bottom:2px solid transparent;color:#54656f;cursor:pointer">Pacientes</button>';
echo '<button type="button" onclick="switchTab(\'manual\')" id="tabManual" style="padding:12px 16px;background:none;border:none;border-bottom:2px solid transparent;color:#54656f;cursor:pointer">Número Manual</button>';
echo '</div>';
echo '<div id="contentProfessionals" style="display:block">';
echo '<label style="display:block;margin-bottom:8px;font-weight:600;color:#111b21">Selecione um Profissional:</label>';
echo '<select name="contact_phone_professional" id="professionalSelect" style="width:100%;padding:12px;border:1px solid #d1d7db;border-radius:8px;font-size:14px;margin-bottom:16px">';
echo '<option value="">-- Selecione --</option>';
foreach ($professionals as $prof) {
    $phone = $prof['phone'] ?? '';
    $name = $prof['name'] ?? '';
    if (!empty($phone)) {
        echo '<option value="' . h($phone) . '">' . h($name) . ' - ' . h($phone) . '</option>';
    }
}
echo '</select>';
echo '</div>';
echo '<div id="contentPatients" style="display:none">';
echo '<label style="display:block;margin-bottom:8px;font-weight:600;color:#111b21">Selecione um Paciente:</label>';
echo '<select name="contact_phone_patient" id="patientSelect" style="width:100%;padding:12px;border:1px solid #d1d7db;border-radius:8px;font-size:14px;margin-bottom:16px">';
echo '<option value="">-- Selecione --</option>';
foreach ($patients as $patient) {
    $phone = $patient['phone'] ?? '';
    $name = $patient['name'] ?? '';
    if (!empty($phone)) {
        echo '<option value="' . h($phone) . '">' . h($name) . ' - ' . h($phone) . '</option>';
    }
}
echo '</select>';
echo '</div>';
echo '<div id="contentManual" style="display:none">';
echo '<label style="display:block;margin-bottom:8px;font-weight:600;color:#111b21">Digite o Número:</label>';
echo '<input type="text" name="contact_phone_manual" id="manualPhone" placeholder="Ex: 5511999999999" style="width:100%;padding:12px;border:1px solid #d1d7db;border-radius:8px;font-size:14px;margin-bottom:8px">';
echo '<p style="font-size:12px;color:#667781;margin:0">Formato: Código do país + DDD + número (sem espaços ou caracteres especiais)</p>';
echo '</div>';
echo '<label style="display:block;margin:16px 0 8px;font-weight:600;color:#111b21">Mensagem:</label>';
echo '<textarea name="message" rows="4" placeholder="Digite sua mensagem..." required style="width:100%;padding:12px;border:1px solid #d1d7db;border-radius:8px;font-size:14px;resize:vertical"></textarea>';
echo '<div style="display:flex;gap:12px;margin-top:20px">';
echo '<button type="button" onclick="closeNewChatModal()" style="flex:1;padding:12px;background:#f0f2f5;border:none;border-radius:8px;font-size:14px;font-weight:600;color:#54656f;cursor:pointer">Cancelar</button>';
echo '<button type="submit" style="flex:1;padding:12px;background:#00a884;border:none;border-radius:8px;font-size:14px;font-weight:600;color:#fff;cursor:pointer">Enviar</button>';
echo '</div>';
echo '</form>';
echo '</div>';
echo '</div>';
echo '</div>';

// Modal: Criar Grupo
echo '<div id="createGroupModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:10000;align-items:center;justify-content:center">';
echo '<div style="background:#fff;border-radius:12px;width:90%;max-width:500px;max-height:80vh;overflow:hidden;display:flex;flex-direction:column">';
echo '<div style="padding:20px;border-bottom:1px solid #e0e0e0;display:flex;justify-content:space-between;align-items:center">';
echo '<h2 style="margin:0;font-size:20px;color:#111b21">Novo grupo WhatsApp</h2>';
echo '<button onclick="closeCreateGroupModal()" style="background:none;border:none;font-size:24px;cursor:pointer;color:#54656f">&times;</button>';
echo '</div>';
echo '<div style="flex:1;overflow-y:auto;padding:20px">';
echo '<form method="post" action="/chat_web.php" id="createGroupForm">';
echo '<input type="hidden" name="action" value="create_group">';
echo '<p style="font-size:13px;color:#667781;margin:0 0 16px;padding:12px;background:#e7f8f4;border-radius:8px">';
echo '<strong>Padrão:</strong> Especialidade - UF/Cidade - Número<br>';
echo 'Exemplo: Fisioterapia - SP - 1';
echo '</p>';
echo '<label style="display:block;margin-bottom:8px;font-weight:600;color:#111b21">Especialidade *</label>';
echo '<select name="specialty" required style="width:100%;padding:12px;border:1px solid #d1d7db;border-radius:8px;font-size:14px;margin-bottom:16px">';
echo '<option value="">Selecione...</option>';
try {
    $specialtiesStmt = db()->query("SELECT DISTINCT name FROM specialties WHERE status = 'active' ORDER BY name ASC");
    $specialtiesList = $specialtiesStmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($specialtiesList as $specName) {
        echo '<option value="' . h($specName) . '">' . h($specName) . '</option>';
    }
} catch (Exception $e) {
    error_log("Erro ao buscar especialidades: " . $e->getMessage());
}
echo '</select>';
echo '<label style="display:block;margin-bottom:8px;font-weight:600;color:#111b21">UF *</label>';
echo '<select name="state" id="modalGroupState" required style="width:100%;padding:12px;border:1px solid #d1d7db;border-radius:8px;font-size:14px;margin-bottom:16px">';
echo '<option value="">Selecione...</option>';
echo '<option value="AC">AC - Acre</option>';
echo '<option value="AL">AL - Alagoas</option>';
echo '<option value="AP">AP - Amapá</option>';
echo '<option value="AM">AM - Amazonas</option>';
echo '<option value="BA">BA - Bahia</option>';
echo '<option value="CE">CE - Ceará</option>';
echo '<option value="DF">DF - Distrito Federal</option>';
echo '<option value="ES">ES - Espírito Santo</option>';
echo '<option value="GO">GO - Goiás</option>';
echo '<option value="MA">MA - Maranhão</option>';
echo '<option value="MT">MT - Mato Grosso</option>';
echo '<option value="MS">MS - Mato Grosso do Sul</option>';
echo '<option value="MG">MG - Minas Gerais</option>';
echo '<option value="PA">PA - Pará</option>';
echo '<option value="PB">PB - Paraíba</option>';
echo '<option value="PR">PR - Paraná</option>';
echo '<option value="PE">PE - Pernambuco</option>';
echo '<option value="PI">PI - Piauí</option>';
echo '<option value="RJ">RJ - Rio de Janeiro</option>';
echo '<option value="RN">RN - Rio Grande do Norte</option>';
echo '<option value="RS">RS - Rio Grande do Sul</option>';
echo '<option value="RO">RO - Rondônia</option>';
echo '<option value="RR">RR - Roraima</option>';
echo '<option value="SC">SC - Santa Catarina</option>';
echo '<option value="SP">SP - São Paulo</option>';
echo '<option value="SE">SE - Sergipe</option>';
echo '<option value="TO">TO - Tocantins</option>';
echo '</select>';
echo '<label style="display:block;margin-bottom:8px;font-weight:600;color:#111b21">Cidade (opcional)</label>';
echo '<select name="city" id="modalGroupCity" disabled style="width:100%;padding:12px;border:1px solid #d1d7db;border-radius:8px;font-size:14px;margin-bottom:16px">';
echo '<option value="">Selecione o estado primeiro...</option>';
echo '</select>';
echo '<input type="hidden" name="location" id="modalLocation">';
echo '<div style="display:flex;gap:12px;margin-top:24px">';
echo '<button type="button" onclick="closeCreateGroupModal()" style="flex:1;padding:12px;background:#f0f2f5;border:none;border-radius:8px;font-size:14px;font-weight:600;color:#54656f;cursor:pointer">Cancelar</button>';
echo '<button type="submit" id="createGroupBtn" style="flex:1;padding:12px;background:#00a884;border:none;border-radius:8px;font-size:14px;font-weight:600;color:#fff;cursor:pointer">Criar Grupo</button>';
echo '</div>';
echo '</form>';
echo '</div>';
echo '</div>';
echo '<script>';
echo 'document.getElementById("createGroupForm").addEventListener("submit", function(e) {';
echo '  console.log("=== FORM SUBMIT ===");';
echo '  const specialty = document.querySelector("[name=specialty]").value;';
echo '  const state = document.querySelector("[name=state]").value;';
echo '  const city = document.querySelector("[name=city]").value;';
echo '  console.log("Specialty:", specialty);';
echo '  console.log("State:", state);';
echo '  console.log("City:", city);';
echo '  if(!specialty || !state) {';
echo '    e.preventDefault();';
echo '    alert("❌ Erro: Preencha Especialidade e UF.");';
echo '    console.error("Campos obrigatórios vazios");';
echo '    return false;';
echo '  }';
echo '  // Preencher location automaticamente se estiver vazio';
echo '  const locationInput = document.querySelector("[name=location]");';
echo '  if(!locationInput.value) {';
echo '    locationInput.value = city ? (city + " - " + state) : state;';
echo '  }';
echo '  console.log("Location preenchido:", locationInput.value);';
echo '  console.log("Formulário válido, enviando...");';
echo '});';
echo '</script>';
echo '</div>';

// Exibir mensagens de sucesso/erro
if (!empty($success)) {
    echo '<div style="position:fixed;top:20px;right:20px;background:#d4edda;color:#155724;padding:16px 20px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.15);z-index:10001">';
    echo '<strong>✓ Sucesso:</strong> ' . h($success);
    echo '</div>';
    // Não redirecionar - manter o chat aberto
    echo '<script>setTimeout(() => { document.querySelector(".fixed").style.display = "none"; }, 3000);</script>';
}
if (!empty($error)) {
    echo '<div style="position:fixed;top:20px;right:20px;background:#f8d7da;color:#721c24;padding:16px 20px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.15);z-index:10001">';
    echo '<strong>✗ Erro:</strong> ' . h($error);
    echo '</div>';
}

// JavaScript para funcionalidades dos modais (DEPOIS dos modais serem renderizados)
echo '<script>';
echo 'function openNewChatModal() {';
echo '  document.getElementById("newChatModal").style.display = "flex";';
echo '}';
echo 'function closeNewChatModal() {';
echo '  document.getElementById("newChatModal").style.display = "none";';
echo '}';
echo 'let groupModalInitialized = false;';
echo 'function openCreateGroupModal() {';
echo '  document.getElementById("createGroupModal").style.display = "flex";';
echo '  if(!groupModalInitialized) {';
echo '    initGroupModalCities();';
echo '    groupModalInitialized = true;';
echo '  }';
echo '}';
echo 'function closeCreateGroupModal() {';
echo '  document.getElementById("createGroupModal").style.display = "none";';
echo '}';
echo 'function initGroupModalCities() {';
echo '  const modalStateSelect = document.getElementById("modalGroupState");';
echo '  const modalCitySelect = document.getElementById("modalGroupCity");';
echo '  const modalLocationInput = document.getElementById("modalLocation");';
echo '  if(!modalStateSelect || !modalCitySelect) {';
echo '    console.error("Elementos do modal não encontrados");';
echo '    return;';
echo '  }';
echo '  modalStateSelect.addEventListener("change", async function() {';
echo '    const uf = this.value;';
echo '    modalCitySelect.innerHTML = "<option value=\'\'>Carregando...</option>";';
echo '    modalCitySelect.disabled = true;';
echo '    if(!uf) {';
echo '      modalCitySelect.innerHTML = "<option value=\'\'>Selecione o estado primeiro...</option>";';
echo '      modalCitySelect.disabled = true;';
echo '      modalLocationInput.value = "";';
echo '      return;';
echo '    }';
echo '    modalLocationInput.value = uf;';
echo '    try {';
echo '      const response = await fetch("https://servicodados.ibge.gov.br/api/v1/localidades/estados/" + uf + "/municipios?orderBy=nome");';
echo '      if(!response.ok) throw new Error("Erro ao buscar cidades");';
echo '      const cidades = await response.json();';
echo '      modalCitySelect.innerHTML = "<option value=\'\'>Selecione...</option>";';
echo '      cidades.forEach(cidade => {';
echo '        const opt = document.createElement("option");';
echo '        opt.value = cidade.nome;';
echo '        opt.textContent = cidade.nome;';
echo '        modalCitySelect.appendChild(opt);';
echo '      });';
echo '      modalCitySelect.disabled = false;';
echo '    } catch(err) {';
echo '      console.error("Erro ao buscar cidades:", err);';
echo '      modalCitySelect.innerHTML = "<option value=\'\'>Erro ao carregar</option>";';
echo '      modalCitySelect.disabled = false;';
echo '    }';
echo '  });';
echo '  modalCitySelect.addEventListener("change", function() {';
echo '    const uf = modalStateSelect.value;';
echo '    const city = this.value;';
echo '    if(city) {';
echo '      modalLocationInput.value = city + " - " + uf;';
echo '    } else {';
echo '      modalLocationInput.value = uf;';
echo '    }';
echo '  });';
echo '}';
echo 'function switchTab(tab) {';
echo '  document.getElementById("tabProfessionals").style.borderBottomColor = "transparent";';
echo '  document.getElementById("tabProfessionals").style.color = "#54656f";';
echo '  document.getElementById("tabPatients").style.borderBottomColor = "transparent";';
echo '  document.getElementById("tabPatients").style.color = "#54656f";';
echo '  document.getElementById("tabManual").style.borderBottomColor = "transparent";';
echo '  document.getElementById("tabManual").style.color = "#54656f";';
echo '  document.getElementById("contentProfessionals").style.display = "none";';
echo '  document.getElementById("contentPatients").style.display = "none";';
echo '  document.getElementById("contentManual").style.display = "none";';
echo '  document.getElementById("professionalSelect").value = "";';
echo '  document.getElementById("patientSelect").value = "";';
echo '  document.getElementById("manualPhone").value = "";';
echo '  if (tab === "professionals") {';
echo '    document.getElementById("tabProfessionals").style.borderBottomColor = "#00a884";';
echo '    document.getElementById("tabProfessionals").style.color = "#00a884";';
echo '    document.getElementById("contentProfessionals").style.display = "block";';
echo '  } else if (tab === "patients") {';
echo '    document.getElementById("tabPatients").style.borderBottomColor = "#00a884";';
echo '    document.getElementById("tabPatients").style.color = "#00a884";';
echo '    document.getElementById("contentPatients").style.display = "block";';
echo '  } else if (tab === "manual") {';
echo '    document.getElementById("tabManual").style.borderBottomColor = "#00a884";';
echo '    document.getElementById("tabManual").style.color = "#00a884";';
echo '    document.getElementById("contentManual").style.display = "block";';
echo '  }';
echo '}';
echo '</script>';

// DEBUG: Exibir logs na tela
if (!empty($debugLogs)) {
    echo '<div style="background:#fff3cd;border:1px solid #ffc107;padding:15px;margin:15px;border-radius:5px;font-family:monospace;font-size:12px;max-height:300px;overflow-y:auto;">';
    echo '<strong style="color:#856404;display:block;margin-bottom:10px;">🔍 DEBUG - Resposta da API Evolution:</strong>';
    foreach ($debugLogs as $log) {
        echo '<div style="margin:5px 0;padding:5px;background:#fff;border-left:3px solid #ffc107;">' . htmlspecialchars($log) . '</div>';
    }
    echo '</div>';
}

// Meta tags para prevenir cache
echo '<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">';
echo '<meta http-equiv="Pragma" content="no-cache">';
echo '<meta http-equiv="Expires" content="0">';

// JavaScript para forçar reload sem cache quando parâmetro refresh está presente
echo '<script>';
echo 'if(window.location.search.includes("refresh=1")){';
echo '  if(!sessionStorage.getItem("chatRefreshed")){';
echo '    sessionStorage.setItem("chatRefreshed","1");';
echo '    window.location.href=window.location.pathname+window.location.search.replace(/[?&]refresh=1/,"").replace(/[?&]t=[0-9]+/,"");';
echo '  }else{';
echo '    sessionStorage.removeItem("chatRefreshed");';
echo '  }';
echo '}';
echo '</script>';

// CSS customizado para WhatsApp Web com 3 colunas
echo '<style>';
echo '.whatsapp-container{display:flex;height:calc(100vh - 64px);background:#f0f2f5;overflow:hidden}';
echo '.whatsapp-sidebar{width:380px;background:#fff;border-right:1px solid #d1d7db;display:flex;flex-direction:column}';
echo '.whatsapp-header{padding:12px 16px;background:#f0f2f5;border-bottom:1px solid #d1d7db;display:flex;align-items:center;justify-content:space-between}';
echo '.whatsapp-search{padding:8px 16px;background:#fff}';
echo '.whatsapp-search input{width:100%;padding:8px 12px;border:1px solid #d1d7db;border-radius:8px;font-size:14px}';
echo '.whatsapp-tabs{display:flex;gap:0;padding:0;background:#fff;border-bottom:1px solid #d1d7db;overflow-x:auto}';
echo '.whatsapp-tab{padding:10px 12px;font-size:13px;font-weight:500;color:#54656f;cursor:pointer;border-bottom:3px solid transparent;transition:all .2s;white-space:nowrap;flex-shrink:0}';
echo '.whatsapp-tab:hover{background:#f5f6f6}';
echo '.whatsapp-tab.active{color:#00a884;border-bottom-color:#00a884}';
echo '.whatsapp-chats{flex:1;overflow-y:auto;background:#fff}';
echo '.whatsapp-chat-item{padding:12px 16px;border-bottom:1px solid #f0f2f5;cursor:pointer;transition:background .2s;display:flex;gap:12px;align-items:flex-start}';
echo '.whatsapp-chat-item:hover{background:#f5f6f6}';
echo '.whatsapp-chat-item.active{background:#f0f2f5}';
echo '.whatsapp-chat-avatar{width:48px;height:48px;border-radius:50%;background:#dfe5e7;display:flex;align-items:center;justify-content:center;font-weight:600;color:#54656f;flex-shrink:0}';
echo '.whatsapp-chat-info{flex:1;min-width:0}';
echo '.whatsapp-chat-name{font-size:16px;font-weight:500;color:#111b21;margin-bottom:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}';
echo '.whatsapp-chat-preview{font-size:14px;color:#667781;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}';
echo '.whatsapp-chat-meta{text-align:right;font-size:12px;color:#667781;flex-shrink:0}';
echo '.whatsapp-main{flex:1;display:flex;flex-direction:column;background:#efeae2}';
echo '.whatsapp-chat-header{padding:12px 16px;background:#f0f2f5;border-bottom:1px solid #d1d7db;display:flex;align-items:center;justify-content:space-between}';
echo '.whatsapp-chat-header-info{display:flex;align-items:center;gap:12px}';
echo '.whatsapp-chat-header-actions{display:flex;gap:8px}';
echo '.whatsapp-action-btn{width:40px;height:40px;border-radius:50%;background:transparent;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#54656f;transition:background .2s;pointer-events:auto;position:relative;z-index:10}';
echo '.whatsapp-action-btn:hover{background:#f5f6f6}';
echo '.whatsapp-messages{flex:1;overflow-y:auto;padding:20px;background-image:url("data:image/svg+xml,%3Csvg width=\'100%25\' height=\'100%25\' xmlns=\'http://www.w3.org/2000/svg\'%3E%3Cdefs%3E%3Cpattern id=\'pattern\' x=\'0\' y=\'0\' width=\'40\' height=\'40\' patternUnits=\'userSpaceOnUse\'%3E%3Cpath d=\'M0 20h40M20 0v40\' stroke=\'%23e9edef\' stroke-width=\'0.5\' fill=\'none\'/%3E%3C/pattern%3E%3C/defs%3E%3Crect width=\'100%25\' height=\'100%25\' fill=\'url(%23pattern)\'/%3E%3C/svg%3E")}';
echo '.whatsapp-message{margin-bottom:12px;display:flex}';
echo '.whatsapp-message.out{justify-content:flex-end}';
echo '.whatsapp-message.in{justify-content:flex-start}';
echo '.whatsapp-message-bubble{max-width:65%;padding:8px 12px;border-radius:8px;position:relative}';
echo '.whatsapp-message.out .whatsapp-message-bubble{background:#d9fdd3;border-bottom-right-radius:0}';
echo '.whatsapp-message.in .whatsapp-message-bubble{background:#fff;border-bottom-left-radius:0;box-shadow:0 1px 0.5px rgba(0,0,0,.13)}';
echo '.whatsapp-message-text{font-size:14px;color:#111b21;line-height:1.5;word-wrap:break-word}';
echo '.whatsapp-message-time{font-size:11px;color:#667781;margin-top:4px;text-align:right}';
echo '.whatsapp-input-area{padding:12px 16px;background:#f0f2f5;border-top:1px solid #d1d7db;display:flex;gap:8px;align-items:flex-end}';
echo '.whatsapp-input{flex:1;padding:10px 12px;border:none;border-radius:8px;font-size:15px;resize:none;max-height:120px;font-family:inherit}';
echo '.whatsapp-send-btn{width:48px;height:48px;border-radius:50%;background:#00a884;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#fff;transition:background .2s}';
echo '.whatsapp-send-btn:hover{background:#06cf9c}';
echo '.whatsapp-send-btn:disabled{background:#d1d7db;cursor:not-allowed}';
echo '@keyframes spin{0%{transform:rotate(0deg)}100%{transform:rotate(360deg)}}';
echo '.whatsapp-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;color:#667781}';
echo '.whatsapp-empty svg{width:360px;height:360px;opacity:.6;margin-bottom:24px}';
echo '.whatsapp-empty h2{font-size:32px;font-weight:300;margin-bottom:16px}';
echo '.whatsapp-empty p{font-size:14px;line-height:1.5;text-align:center;max-width:480px}';
echo '.whatsapp-dropdown{position:absolute;top:100%;right:0;margin-top:4px;background:#fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.15);min-width:200px;z-index:9999;display:none}';
echo '.whatsapp-dropdown.show{display:block}';
echo '.whatsapp-dropdown-item{padding:12px 16px;font-size:14px;color:#3b4a54;cursor:pointer;transition:background .2s;display:flex;align-items:center;gap:12px}';
echo '.whatsapp-dropdown-item:hover{background:#f5f6f6}';
echo '.whatsapp-dropdown-item:first-child{border-radius:8px 8px 0 0}';
echo '.whatsapp-dropdown-item:last-child{border-radius:0 0 8px 8px}';
echo '.whatsapp-group-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;background:#e9edef;border-radius:12px;font-size:12px;color:#54656f;margin-left:8px}';
echo '.whatsapp-info-panel{width:340px;background:#fff;border-left:1px solid #d1d7db;display:flex;flex-direction:column;overflow-y:auto}';
echo '.whatsapp-info-header{padding:16px;background:#f0f2f5;border-bottom:1px solid #d1d7db;font-weight:600;font-size:16px;color:#111b21;display:flex;align-items:center;justify-content:space-between}';
echo '.whatsapp-info-section{padding:16px;border-bottom:1px solid #f0f2f5}';
echo '.whatsapp-info-label{font-size:12px;color:#667781;margin-bottom:8px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px}';
echo '.whatsapp-info-value{font-size:14px;color:#111b21;margin-bottom:12px}';
echo '.whatsapp-info-section button{transition:all .2s}';
echo '.whatsapp-info-section button:hover{transform:translateY(-1px);box-shadow:0 2px 4px rgba(0,0,0,.1)}';
echo '.whatsapp-info-section select, .whatsapp-info-section textarea{font-family:inherit;font-size:14px}';
echo '.whatsapp-info-section select:focus, .whatsapp-info-section textarea:focus{outline:none;border-color:#00a884;box-shadow:0 0 0 2px rgba(0,168,132,.1)}';
echo '.whatsapp-info-avatar{width:120px;height:120px;border-radius:50%;background:#dfe5e7;display:flex;align-items:center;justify-content:center;font-size:48px;font-weight:600;color:#54656f;margin:0 auto 16px}';
echo '.whatsapp-status-badge{display:inline-block;padding:4px 12px;border-radius:12px;font-size:12px;font-weight:600}';
echo '.whatsapp-status-badge.atendendo{background:#dcf8c6;color:#0a8754}';
echo '.whatsapp-status-badge.aguardando{background:#fff3cd;color:#856404}';
echo '.whatsapp-status-badge.resolvido{background:#d1d7db;color:#54656f}';
echo '.whatsapp-sync-btn{padding:8px 16px;background:#00a884;color:#fff;border:none;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:6px;transition:background .2s}';
echo '.whatsapp-sync-btn:hover{background:#06cf9c}';
echo '.whatsapp-sync-btn:disabled{background:#d1d7db;cursor:not-allowed}';
echo '@keyframes rotate{0%{transform:rotate(0deg)}100%{transform:rotate(360deg)}}';
echo '.rotating{animation:rotate 1s linear infinite}';
echo '</style>';

// Exibir logs de debug no topo da página
if (!empty($debugLogs)) {
    echo '<div style="background:#fff3cd;border:2px solid #ffc107;padding:20px;margin:20px;border-radius:8px;font-family:monospace;font-size:12px;max-height:400px;overflow-y:auto">';
    echo '<h3 style="margin:0 0 10px;color:#856404">🔍 Debug de Ordenação</h3>';
    foreach ($debugLogs as $log) {
        echo '<div style="padding:2px 0;color:#856404">' . h($log) . '</div>';
    }
    echo '</div>';
}

echo '<div style="padding:12px 16px;background:#f0f2f5;border-bottom:1px solid #d1d7db;display:flex;gap:10px;align-items:center;flex-wrap:wrap">';
echo '<h2 style="margin:0;flex:1;font-size:18px;color:#111b21">Chat WhatsApp</h2>';
echo '<button onclick="syncEvolution()" id="syncBtn" class="whatsapp-sync-btn">';
echo '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" id="syncIcon"><path d="M23 4v6h-6M1 20v-6h6M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/></svg>';
echo 'Sincronizar';
echo '</button>';
echo '<button onclick="openNewChatModal()" style="padding:10px 18px;background:#00a884;color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:8px;transition:background .2s">';
echo '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>';
echo 'Nova Conversa';
echo '</button>';
echo '<button onclick="openCreateGroupModal()" style="padding:10px 18px;background:#00a884;color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:8px;transition:background .2s">';
echo '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>';
echo 'Criar Grupo';
echo '</button>';
echo '<a href="/chat_groups.php" style="padding:10px 18px;background:#fff;color:#54656f;border:1px solid #d1d7db;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:8px;text-decoration:none;transition:all .2s">';
echo '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/><line x1="12" y1="9" x2="12" y2="15"/><line x1="9" y1="12" x2="15" y2="12"/></svg>';
echo 'Gerenciar Grupos';
echo '</a>';
echo '<a href="/evolution_qrcode.php" style="padding:10px 18px;background:#fff;color:#54656f;border:1px solid #d1d7db;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:8px;text-decoration:none;transition:all .2s">';
echo '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>';
echo 'QR Code';
echo '</a>';
echo '</div>';

echo '<div class="whatsapp-container">';

// Sidebar esquerda - Lista de conversas
echo '<div class="whatsapp-sidebar">';

// Header com avatar do usuário
echo '<div class="whatsapp-header">';
echo '<div class="whatsapp-chat-avatar">' . strtoupper(substr(auth_user()['name'] ?? 'U', 0, 1)) . '</div>';
echo '<div style="font-size:14px;color:#111b21;font-weight:500">' . h(auth_user()['name'] ?? 'Usuário') . '</div>';
echo '</div>';

// Busca
echo '<div class="whatsapp-search">';
echo '<form method="get" action="/chat_web.php">';
if (!empty($selectedChat)) {
    echo '<input type="hidden" name="chat" value="' . h($selectedChat) . '">';
}
if (!empty($chatType)) {
    echo '<input type="hidden" name="type" value="' . h($chatType) . '">';
}
echo '<input type="text" name="q" value="' . h($searchQuery ?? '') . '" placeholder="Pesquisar conversas">';
echo '</form>';
echo '</div>';

// Abas de navegação
echo '<div class="whatsapp-tabs">';
$tabs = [
    'atendendo' => 'Atendendo',
    'aguardando' => 'Aguardando',
    'resolvidos' => 'Resolvidos',
    'grupos' => 'Grupos',
    'organizacao' => 'Chat Organização'
];
foreach ($tabs as $tabKey => $tabLabel) {
    $activeClass = ($chatType === $tabKey) ? 'active' : '';
    echo '<div class="whatsapp-tab ' . $activeClass . '" onclick="window.location.href=\'/chat_web.php?type=' . $tabKey . '\'">';
    echo h($tabLabel);
    echo '</div>';
}
echo '</div>';

// Abas removidas temporariamente para simplificar

// Lista de conversas
echo '<div class="whatsapp-chats" id="chatsList">';

// Se for aba de grupos, exibir grupos da tabela chat_groups
if ($chatType === 'grupos') {
    if (!empty($groups)) {
        foreach ($groups as $group) {
            $groupId = $group['id'] ?? '';
            $groupName = $group['name'] ?? 'Grupo sem nome';
            $groupPic = $group['profilePictureUrl'] ?? '';
            $isActive = ($groupId === $selectedChat) ? ' active' : '';
            
            echo '<a href="/chat_web.php?chat=' . urlencode($groupId) . '&type=grupos" class="whatsapp-chat-item' . $isActive . '">';
            if (!empty($groupPic)) {
                echo '<div class="whatsapp-chat-avatar" style="background-image:url(' . h($groupPic) . ');background-size:cover;background-position:center"></div>';
            } else {
                echo '<div class="whatsapp-chat-avatar" style="background:#00a884;color:#fff">';
                echo '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>';
                echo '</div>';
            }
            echo '<div class="whatsapp-chat-info">';
            echo '<div class="whatsapp-chat-name">' . h($groupName) . '</div>';
            if (!empty($group['specialty']) || !empty($group['region'])) {
                echo '<div class="whatsapp-chat-preview" style="font-size:12px;color:#667781">';
                if (!empty($group['specialty'])) echo h($group['specialty']);
                if (!empty($group['specialty']) && !empty($group['region'])) echo ' • ';
                if (!empty($group['region'])) echo h($group['region']);
                echo '</div>';
            }
            echo '</div>';
            echo '</a>';
        }
    } else {
        echo '<div style="padding:40px 20px;text-align:center;color:#667781">';
        echo '<svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin:0 auto 16px;opacity:0.3"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>';
        echo '<p style="margin:0;font-weight:600">Nenhum grupo encontrado</p>';
        echo '<p style="margin:8px 0 0;font-size:13px">Crie um grupo para começar</p>';
        echo '</div>';
    }
} else {
    // Exibir chats normais
    if (!empty($chats)) {
        foreach ($chats as $chat) {
            $chatId = $chat['id'] ?? '';
            $chatName = $chat['name'] ?? $chatId;
            $isGroup = strpos($chatId, '@g.us') !== false;
            $isActive = $selectedChat === $chatId ? ' active' : '';
            $lastMsg = ''; 
            $lastTime = isset($chat['lastMsgTimestamp']) && $chat['lastMsgTimestamp'] > 0 ? date('H:i', $chat['lastMsgTimestamp']) : '';
            
            $initials = strtoupper(substr($chatName, 0, 2));
            $profilePic = $chat['profilePictureUrl'] ?? '';
            
            echo '<a href="/chat_web.php?chat=' . urlencode($chatId) . '&type=' . urlencode($chatType) . '" class="whatsapp-chat-item' . $isActive . '">';
            if (!empty($profilePic)) {
                echo '<div class="whatsapp-chat-avatar" style="background-image:url(' . h($profilePic) . ');background-size:cover;background-position:center"></div>';
            } else {
                echo '<div class="whatsapp-chat-avatar">' . h($initials) . '</div>';
            }
            echo '<div class="whatsapp-chat-info">';
            echo '<div class="whatsapp-chat-name">' . h($chatName);
            if ($isGroup) {
                echo '<span class="whatsapp-group-badge">';
                echo '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>';
                echo 'Grupo';
                echo '</span>';
            }
            echo '</div>';
            echo '<div class="whatsapp-chat-preview">' . h(mb_strimwidth($lastMsg, 0, 50, '...')) . '</div>';
            echo '</div>';
            echo '<div class="whatsapp-chat-meta">' . h($lastTime) . '</div>';
            echo '</a>';
        }
    } else {
        echo '<div style="padding:40px 20px;text-align:center;color:#667781">';
        echo '<p>Nenhuma conversa encontrada.</p>';
        if (empty($baseUrl) || empty($apiKey)) {
            echo '<p style="margin-top:12px;font-size:13px">Configure as credenciais da Evolution API em Configurações.</p>';
        }
        echo '</div>';
    }
}
echo '</div>';

echo '</div>';

// Área principal - Chat
echo '<div class="whatsapp-main">';

if (empty($selectedChat)) {
    // Tela vazia quando nenhum chat selecionado
    echo '<div class="whatsapp-empty">';
    echo '<svg viewBox="0 0 303 172"><path fill="#DFE5E7" d="M170.5 0c-20.8 0-37.7 16.9-37.7 37.7 0 8.2 2.6 15.8 7 22l-7 25.9 26.6-7c6 4.1 13.3 6.5 21.1 6.5 20.8 0 37.7-16.9 37.7-37.7S191.3 0 170.5 0zm0 67.5c-16.4 0-29.8-13.4-29.8-29.8s13.4-29.8 29.8-29.8 29.8 13.4 29.8 29.8-13.4 29.8-29.8 29.8z"/><path fill="#DFE5E7" d="M87.2 117.5c-20.8 0-37.7 16.9-37.7 37.7 0 8.2 2.6 15.8 7 22l-7 25.9 26.6-7c6 4.1 13.3 6.5 21.1 6.5 20.8 0 37.7-16.9 37.7-37.7s-16.9-37.4-37.7-37.4zm0 67.5c-16.4 0-29.8-13.4-29.8-29.8s13.4-29.8 29.8-29.8 29.8 13.4 29.8 29.8-13.4 29.8-29.8 29.8z"/></svg>';
    echo '<h2>WhatsApp Web</h2>';
    echo '<p>Envie e receba mensagens sem manter seu celular conectado.<br>Use o WhatsApp em até 4 dispositivos vinculados e 1 celular ao mesmo tempo.</p>';
    echo '</div>';
} else {
    $chatName = $selectedChatData['name'] ?? $selectedChat;
    $isGroup = strpos($selectedChat, '@g.us') !== false;
    $profilePic = $selectedChatData['profilePictureUrl'] ?? '';

    // Cabeçalho do chat
    echo '<div class="whatsapp-chat-header">';
    echo '<div class="whatsapp-chat-header-info">';
    // Indicador de fonte de dados
    echo '<div style="position:absolute;top:4px;right:4px;font-size:9px;color:#00a884;background:#e7f8f4;padding:2px 6px;border-radius:4px;font-weight:600">EVOLUTION API</div>';
    $initials = strtoupper(substr($chatName, 0, 2));
    if (!empty($profilePic)) {
        echo '<div class="whatsapp-chat-avatar" style="background-image:url(' . h($profilePic) . ');background-size:cover;background-position:center"></div>';
    } else {
        echo '<div class="whatsapp-chat-avatar">' . h($initials) . '</div>';
    }
    echo '<div>';
    echo '<div style="font-weight:600;font-size:16px;color:#111b21">' . h($chatName) . '</div>';
    if ($isGroup) {
        $participantCount = count($selectedChatData['participants'] ?? []);
        echo '<div style="font-size:13px;color:#667781">' . $participantCount . ' participantes</div>';
    } else {
        echo '<div style="font-size:13px;color:#667781">online</div>';
    }
    echo '</div>';
    echo '</div>';
    echo '<div class="whatsapp-chat-header-actions">';
    echo '<button class="whatsapp-action-btn" onclick="searchInChat()">';
    echo '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>';
    echo '</button>';
    if ($isGroup) {
        echo '<button class="whatsapp-action-btn" onclick="window.location.href=\'/chat_manage_group.php?id=' . urlencode($selectedChat) . '\'">';
        echo '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>';
        echo '</button>';
    }
    echo '<button class="whatsapp-action-btn" onclick="toggleChatMenu(event)">';
    echo '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/></svg>';
    echo '</button>';
    echo '</div>';
    echo '</div>';
    
    // Área de mensagens
    echo '<div class="whatsapp-messages" id="messagesContainer">';
    
    // Se não houver mensagens, mostrar mensagem informativa
    if (empty($messages)) {
        echo '<div style="text-align:center;padding:40px;color:#667781">';
        echo '<p style="font-size:14px">Nenhuma mensagem ainda.</p>';
        echo '<p style="font-size:13px;opacity:0.7;margin-top:8px">Envie uma mensagem para iniciar a conversa.</p>';
        echo '</div>';
    }
    
    // Renderizar mensagens
    if (!empty($messages)) {
        foreach ($messages as $msg) {
            $isFromMe = $msg['fromMe'] ?? false;
            $messageClass = $isFromMe ? 'out' : 'in';
            $messageText = $msg['text'] ?? '';
            $timestamp = isset($msg['timestamp']) ? date('H:i', $msg['timestamp']) : '';
            
            echo '<div class="whatsapp-message ' . $messageClass . '">';
            echo '<div class="whatsapp-message-bubble">';
            echo '<div class="whatsapp-message-text">' . h($messageText) . '</div>';
            echo '<div class="whatsapp-message-time">' . h($timestamp) . '</div>';
            echo '</div>';
            echo '</div>';
        }
    }
    echo '</div>';
    
    // Auto-refresh desabilitado para não atrapalhar o uso
    // if (!empty($selectedChat)) {
    //     echo '<script>';
    //     echo 'setInterval(function() {';
    //     echo '  window.location.reload();';
    //     echo '}, 10000);';
    //     echo '</script>';
    // }
    
    // Formulário de envio
    echo '<div class="whatsapp-input-area">';
    echo '<form method="post" action="/chat_web.php?chat=' . urlencode($selectedChat) . '" style="display:flex;gap:8px;align-items:flex-end;width:100%" id="sendMessageForm">';
    echo '<input type="hidden" name="action" value="send_message">';
    echo '<input type="hidden" name="phone_number" value="' . h($selectedChat) . '">';
    echo '<textarea class="whatsapp-input" name="message" placeholder="Digite uma mensagem" rows="1" required></textarea>';
    echo '<button type="submit" class="whatsapp-send-btn">';
    echo '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>';
    echo '</button>';
    echo '</form>';
    echo '</div>';
}

echo '</div>';

// Painel lateral direito - Informações de captação
if (!empty($selectedChat)) {
    echo '<div class="whatsapp-info-panel">';
    
    // Header do painel
    echo '<div class="whatsapp-info-header">';
    echo 'Informações';
    echo '<button onclick="closeInfoPanel()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#54656f">&times;</button>';
    echo '</div>';
    
    // Avatar/Foto
    $contactName = '';
    $contactPhone = '';
    $contactStatus = 'aguardando';
    foreach ($chats as $chat) {
        if ($chat['id'] === $selectedChat) {
            $contactName = $chat['name'] ?? '';
            $contactStatus = $chat['status'] ?? 'aguardando';
            $profilePic = $chat['profilePictureUrl'] ?? '';
            break;
        }
    }
    
    echo '<div class="whatsapp-info-section" style="text-align:center">';
    if (!empty($profilePic)) {
        echo '<div class="whatsapp-info-avatar" style="background-image:url(' . h($profilePic) . ');background-size:cover;background-position:center"></div>';
    } else {
        $initials = strtoupper(substr($contactName ?: 'C', 0, 2));
        echo '<div class="whatsapp-info-avatar">' . h($initials) . '</div>';
    }
    echo '<h3 style="margin:8px 0;font-size:18px;color:#111b21">' . h($contactName ?: 'Contato') . '</h3>';
    echo '<p style="margin:0;font-size:13px;color:#667781">' . h($selectedChat) . '</p>';
    echo '</div>';
    
    // Status do atendimento
    echo '<div class="whatsapp-info-section">';
    echo '<div class="whatsapp-info-label">Status do Atendimento</div>';
    $statusClass = $contactStatus === 'atendendo' ? 'atendendo' : ($contactStatus === 'resolvido' ? 'resolvido' : 'aguardando');
    $statusLabel = $contactStatus === 'atendendo' ? 'Atendendo' : ($contactStatus === 'resolvido' ? 'Resolvido' : 'Aguardando');
    echo '<span class="whatsapp-status-badge ' . $statusClass . '">' . h($statusLabel) . '</span>';
    echo '<select onchange="updateStatus(this.value)" style="width:100%;margin-top:8px;padding:8px;border:1px solid #d1d7db;border-radius:6px">';
    echo '<option value="aguardando" ' . ($contactStatus === 'aguardando' ? 'selected' : '') . '>Aguardando</option>';
    echo '<option value="atendendo" ' . ($contactStatus === 'atendendo' ? 'selected' : '') . '>Atendendo</option>';
    echo '<option value="resolvido" ' . ($contactStatus === 'resolvido' ? 'selected' : '') . '>Resolvido</option>';
    echo '</select>';
    echo '</div>';
    
    // Informações de captação
    echo '<div class="whatsapp-info-section">';
    echo '<div class="whatsapp-info-label">Tipo de Captação</div>';
    echo '<select id="captureType" style="width:100%;padding:8px;border:1px solid #d1d7db;border-radius:6px;margin-bottom:12px">';
    echo '<option value="">Selecione...</option>';
    echo '<option value="paciente">Paciente</option>';
    echo '<option value="profissional">Profissional</option>';
    echo '<option value="empresa">Empresa</option>';
    echo '<option value="parceiro">Parceiro</option>';
    echo '</select>';
    
    echo '<div class="whatsapp-info-label">Observações</div>';
    echo '<textarea id="captureNotes" rows="4" style="width:100%;padding:8px;border:1px solid #d1d7db;border-radius:6px;resize:vertical" placeholder="Anotações sobre a captação..."></textarea>';
    
    echo '<button onclick="saveCaptureInfo()" style="width:100%;margin-top:12px;padding:10px;background:#00a884;color:#fff;border:none;border-radius:6px;font-weight:600;cursor:pointer">';
    echo 'Salvar Informações';
    echo '</button>';
    echo '</div>';
    
    // Cadastro de Profissional/Paciente
    echo '<div class="whatsapp-info-section">';
    echo '<div class="whatsapp-info-label">Cadastrar Contato</div>';
    echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">';
    echo '<button onclick="cadastrarProfissional()" style="padding:10px;background:#00a884;color:#fff;border:none;border-radius:6px;font-weight:600;cursor:pointer;font-size:13px">';
    echo '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block;vertical-align:middle;margin-right:4px"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>';
    echo 'Profissional';
    echo '</button>';
    echo '<button onclick="cadastrarPaciente()" style="padding:10px;background:#00a884;color:#fff;border:none;border-radius:6px;font-weight:600;cursor:pointer;font-size:13px">';
    echo '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block;vertical-align:middle;margin-right:4px"><path d="M16 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="8.5" cy="7" r="4"/><path d="M20 8v6M23 11h-6"/></svg>';
    echo 'Paciente';
    echo '</button>';
    echo '</div>';
    echo '</div>';
    
    // Convidar para Grupo
    echo '<div class="whatsapp-info-section">';
    echo '<div class="whatsapp-info-label">Convidar para Grupo</div>';
    
    echo '<div class="whatsapp-info-label" style="margin-top:12px">Especialidade</div>';
    echo '<select id="groupSpecialty" onchange="loadGroupsByFilter()" style="width:100%;padding:8px;border:1px solid #d1d7db;border-radius:6px;margin-bottom:12px">';
    echo '<option value="">Todas as especialidades</option>';
    
    // Buscar especialidades únicas dos grupos
    try {
        $specialtiesStmt = db()->query("SELECT DISTINCT specialty FROM chat_groups WHERE specialty IS NOT NULL AND specialty != '' ORDER BY specialty");
        $groupSpecialties = $specialtiesStmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($groupSpecialties as $spec) {
            echo '<option value="' . h($spec) . '">' . h($spec) . '</option>';
        }
    } catch (Exception $e) {
        error_log("Erro ao buscar especialidades: " . $e->getMessage());
    }
    echo '</select>';
    
    echo '<div class="whatsapp-info-label">Cidade/Região</div>';
    echo '<select id="groupRegion" onchange="loadGroupsByFilter()" style="width:100%;padding:8px;border:1px solid #d1d7db;border-radius:6px;margin-bottom:12px">';
    echo '<option value="">Todas as regiões</option>';
    
    // Buscar regiões únicas dos grupos
    try {
        $regionsStmt = db()->query("SELECT DISTINCT region FROM chat_groups WHERE region IS NOT NULL AND region != '' ORDER BY region");
        $groupRegions = $regionsStmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($groupRegions as $reg) {
            echo '<option value="' . h($reg) . '">' . h($reg) . '</option>';
        }
    } catch (Exception $e) {
        error_log("Erro ao buscar regiões: " . $e->getMessage());
    }
    echo '</select>';
    
    echo '<div class="whatsapp-info-label">Selecionar Grupo</div>';
    echo '<select id="selectedGroup" style="width:100%;padding:8px;border:1px solid #d1d7db;border-radius:6px;margin-bottom:12px">';
    echo '<option value="">Carregando grupos...</option>';
    echo '</select>';
    
    echo '<div class="whatsapp-info-label">Mensagem de Boas-Vindas</div>';
    echo '<textarea id="welcomeMessage" rows="3" style="width:100%;padding:8px;border:1px solid #d1d7db;border-radius:6px;resize:vertical;margin-bottom:12px" placeholder="Olá! Você foi convidado(a) para participar do nosso grupo...">Olá! Você foi convidado(a) para participar do nosso grupo. Seja bem-vindo(a)!</textarea>';
    
    echo '<button onclick="sendGroupInvite()" style="width:100%;padding:10px;background:#00a884;color:#fff;border:none;border-radius:6px;font-weight:600;cursor:pointer">';
    echo '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline-block;vertical-align:middle;margin-right:6px"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>';
    echo 'Enviar Convite';
    echo '</button>';
    echo '</div>';
    
    // Ações rápidas
    echo '<div class="whatsapp-info-section">';
    echo '<div class="whatsapp-info-label">Ações Rápidas</div>';
    echo '<button onclick="dispararFluxo()" style="width:100%;margin-bottom:8px;padding:10px;background:#fff;color:#54656f;border:1px solid #d1d7db;border-radius:6px;font-weight:600;cursor:pointer;transition:background .2s">';
    echo 'Disparar Fluxo';
    echo '</button>';
    echo '<button onclick="dispararRemarketing()" style="width:100%;padding:10px;background:#fff;color:#54656f;border:1px solid #d1d7db;border-radius:6px;font-weight:600;cursor:pointer;transition:background .2s">';
    echo 'Disparar Remarketing';
    echo '</button>';
    echo '</div>';
    
    echo '</div>';
}

echo '</div>'; // Fecha whatsapp-container

// JavaScript para funcionalidades
echo '<script>';

// Validação do formulário antes de enviar
echo 'document.addEventListener("DOMContentLoaded", function() {';
echo '  const form = document.getElementById("newChatForm");';
echo '  if (form) {';
echo '    form.addEventListener("submit", function(e) {';
echo '      const profPhone = document.getElementById("professionalSelect").value;';
echo '      const patPhone = document.getElementById("patientSelect").value;';
echo '      const manualPhone = document.getElementById("manualPhone").value;';
echo '      if (!profPhone && !patPhone && !manualPhone) {';
echo '        e.preventDefault();';
echo '        alert("Por favor, selecione um contato ou digite um número manualmente.");';
echo '        return false;';
echo '      }';
echo '    });';
echo '  }';
echo '});';

echo 'console.log("=== CHAT WEB DEBUG ===");';
echo 'console.log("Chat selecionado:", "' . addslashes($selectedChat) . '");';
echo 'console.log("Total de conversas carregadas:", ' . count($chats) . ');';
echo 'console.log("Total de mensagens carregadas:", ' . count($messages) . ');';
echo 'console.log("");';
echo 'console.log("--- CONVERSAS RECEBIDAS ---");';
echo 'const conversasRaw = [];'; // Temporariamente desabilitado: json_encode($chats)
echo 'const conversas = Array.isArray(conversasRaw) ? conversasRaw : (conversasRaw ? Object.values(conversasRaw) : []);';
echo 'console.log("Tipo de conversas:", typeof conversas, "- É array?", Array.isArray(conversas));';
echo 'console.log("Total de conversas:", conversas.length);';
echo 'if(conversas.length === 0){';
echo '  console.warn("⚠️ NENHUMA CONVERSA CARREGADA!");';
echo '}';
echo 'conversas.forEach((conv, idx) => {';
echo '  console.log(`Conversa #${idx}:`);';
echo '  console.log("  ID:", conv.id);';
echo '  console.log("  Nome:", conv.name || "Sem nome");';
echo '  console.log("  pushName:", conv.pushName || "N/A");';
echo '  console.log("  Foto URL:", conv.profilePictureUrl || "Sem foto");';
echo '  if(conv.profilePictureUrl){';
echo '    console.log("  ✅ TEM FOTO");';
echo '  }else{';
echo '    console.log("  ❌ SEM FOTO");';
echo '  }';
echo '});';
echo 'console.log("");';
echo 'console.log("--- CHAT SELECIONADO - DETALHES ---");';
echo 'const chatSelecionado = conversas.find(c => c.id === "' . addslashes($selectedChat) . '");';
echo 'if(chatSelecionado){';
echo '  console.log("Nome do chat:", chatSelecionado.name);';
echo '  console.log("Foto do chat:", chatSelecionado.profilePictureUrl || "SEM FOTO");';
echo '  if(chatSelecionado.profilePictureUrl){';
echo '    console.log("✅ FOTO ENCONTRADA:", chatSelecionado.profilePictureUrl);';
echo '    const img = new Image();';
echo '    img.onload = () => console.log("✅ FOTO CARREGOU COM SUCESSO");';
echo '    img.onerror = () => console.error("❌ ERRO AO CARREGAR FOTO");';
echo '    img.src = chatSelecionado.profilePictureUrl;';
echo '  }else{';
echo '    console.log("❌ FOTO NÃO DISPONÍVEL");';
echo '  }';
echo '}';
echo 'console.log("");';
echo 'console.log("--- MENSAGENS DO CHAT SELECIONADO ---");';

// Função de sincronização com Evolution
echo 'function syncEvolution() {';
echo '  const btn = document.getElementById("syncBtn");';
echo '  const icon = document.getElementById("syncIcon");';
echo '  btn.disabled = true;';
echo '  icon.classList.add("rotating");';
echo '  fetch("/chat_sync_evolution.php")';
echo '    .then(r => r.json())';
echo '    .then(data => {';
echo '      if(data.success) {';
echo '        alert("✅ Sincronização concluída!\\n\\n" + data.count + " grupos sincronizados.");';
echo '        window.location.reload();';
echo '      } else {';
echo '        let errorMsg = "❌ Erro na sincronização:\\n\\n" + data.error;';
echo '        if(data.curl_error) {';
echo '          errorMsg += "\\n\\nDetalhes: " + data.curl_error;';
echo '        }';
echo '        if(data.details) {';
echo '          errorMsg += "\\n\\n" + data.details;';
echo '        }';
echo '        errorMsg += "\\n\\n💡 Verifique:\\n- URL da Evolution API está correta\\n- Servidor Evolution está online\\n- Firewall não está bloqueando";';
echo '        alert(errorMsg);';
echo '      }';
echo '    })';
echo '    .catch(e => {';
echo '      alert("❌ Erro ao sincronizar:\\n\\n" + e.message + "\\n\\n💡 Verifique se o servidor está acessível.");';
echo '    })';
echo '    .finally(() => {';
echo '      btn.disabled = false;';
echo '      icon.classList.remove("rotating");';
echo '    });';
echo '}';

// Função para atualizar status
echo 'function updateStatus(status) {';
echo '  const chatId = "' . addslashes($selectedChat) . '";';
echo '  fetch("/chat_update_status.php", {';
echo '    method: "POST",';
echo '    headers: {"Content-Type": "application/json"},';
echo '    body: JSON.stringify({chat_id: chatId, status: status})';
echo '  })';
echo '  .then(r => r.json())';
echo '  .then(data => {';
echo '    if(data.success) {';
echo '      window.location.reload();';
echo '    } else {';
echo '      alert("Erro ao atualizar status");';
echo '    }';
echo '  });';
echo '}';

// Função para salvar informações de captação
echo 'function saveCaptureInfo() {';
echo '  const chatId = "' . addslashes($selectedChat) . '";';
echo '  const type = document.getElementById("captureType").value;';
echo '  const notes = document.getElementById("captureNotes").value;';
echo '  fetch("/chat_save_capture.php", {';
echo '    method: "POST",';
echo '    headers: {"Content-Type": "application/json"},';
echo '    body: JSON.stringify({chat_id: chatId, capture_type: type, capture_notes: notes})';
echo '  })';
echo '  .then(r => r.json())';
echo '  .then(data => {';
echo '    if(data.success) {';
echo '      alert("Informações salvas com sucesso!");';
echo '    } else {';
echo '      alert("Erro ao salvar informações");';
echo '    }';
echo '  });';
echo '}';

echo 'function cadastrarProfissional() {';
echo '  const chatId = "' . addslashes($selectedChat) . '";';
echo '  const phone = chatId.replace("@s.whatsapp.net", "").replace("@g.us", "");';
echo '  window.location.href = "/professionals_create.php?phone=" + encodeURIComponent(phone) + "&from_chat=1";';
echo '}';

echo 'function cadastrarPaciente() {';
echo '  const chatId = "' . addslashes($selectedChat) . '";';
echo '  const phone = chatId.replace("@s.whatsapp.net", "").replace("@g.us", "");';
echo '  window.location.href = "/patients_create.php?phone=" + encodeURIComponent(phone) + "&from_chat=1";';
echo '}';

echo 'function loadGroupsByFilter() {';
echo '  const specialty = document.getElementById("groupSpecialty").value;';
echo '  const region = document.getElementById("groupRegion").value;';
echo '  const select = document.getElementById("selectedGroup");';
echo '  ';
echo '  select.innerHTML = "<option value=\"\">Carregando...</option>";';
echo '  ';
echo '  fetch("/chat_get_filtered_groups.php?specialty=" + encodeURIComponent(specialty) + "&region=" + encodeURIComponent(region))';
echo '    .then(r => r.json())';
echo '    .then(data => {';
echo '      if(data.success && data.groups) {';
echo '        select.innerHTML = "<option value=\"\">Selecione um grupo...</option>";';
echo '        data.groups.forEach(group => {';
echo '          const option = document.createElement("option");';
echo '          option.value = group.group_jid;';
echo '          option.textContent = group.group_name + (group.specialty ? " (" + group.specialty + ")" : "");';
echo '          select.appendChild(option);';
echo '        });';
echo '        if(data.groups.length === 0) {';
echo '          select.innerHTML = "<option value=\"\">Nenhum grupo encontrado</option>";';
echo '        }';
echo '      } else {';
echo '        select.innerHTML = "<option value=\"\">Erro ao carregar grupos</option>";';
echo '      }';
echo '    })';
echo '    .catch(e => {';
echo '      select.innerHTML = "<option value=\"\">Erro ao carregar grupos</option>";';
echo '      console.error("Erro:", e);';
echo '    });';
echo '}';

echo 'function sendGroupInvite() {';
echo '  const chatId = "' . addslashes($selectedChat) . '";';
echo '  const groupJid = document.getElementById("selectedGroup").value;';
echo '  const welcomeMessage = document.getElementById("welcomeMessage").value;';
echo '  ';
echo '  if(!groupJid) {';
echo '    alert("Por favor, selecione um grupo");';
echo '    return;';
echo '  }';
echo '  ';
echo '  if(confirm("Deseja enviar o convite para este grupo?")) {';
echo '    const btn = event.target;';
echo '    btn.disabled = true;';
echo '    btn.textContent = "Enviando...";';
echo '    ';
echo '    fetch("/chat_send_group_invite.php", {';
echo '      method: "POST",';
echo '      headers: {"Content-Type": "application/json"},';
echo '      body: JSON.stringify({';
echo '        chat_id: chatId,';
echo '        group_jid: groupJid,';
echo '        welcome_message: welcomeMessage';
echo '      })';
echo '    })';
echo '    .then(r => r.json())';
echo '    .then(data => {';
echo '      if(data.success) {';
echo '        alert("Convite enviado com sucesso!");';
echo '        document.getElementById("selectedGroup").value = "";';
echo '      } else {';
echo '        alert("Erro ao enviar convite: " + (data.error || "Erro desconhecido"));';
echo '      }';
echo '    })';
echo '    .catch(e => {';
echo '      alert("Erro ao enviar convite: " + e.message);';
echo '    })';
echo '    .finally(() => {';
echo '      btn.disabled = false;';
echo '      btn.innerHTML = \'<svg width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" style=\"display:inline-block;vertical-align:middle;margin-right:6px\"><path d=\"M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2\"/><circle cx=\"9\" cy=\"7\" r=\"4\"/><path d=\"M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75\"/></svg>Enviar Convite\';';
echo '    });';
echo '  }';
echo '}';

echo 'if(document.getElementById("groupSpecialty")) {';
echo '  loadGroupsByFilter();';
echo '}';

echo 'function dispararFluxo() { alert("Funcionalidade em desenvolvimento"); }';
echo 'function dispararRemarketing() { alert("Funcionalidade em desenvolvimento"); }';
echo 'function closeInfoPanel() { window.location.href = "/chat_web.php"; }';

echo 'const mensagensRaw = [];'; // Temporariamente desabilitado: json_encode($messages)
echo 'const mensagens = Array.isArray(mensagensRaw) ? mensagensRaw : (mensagensRaw ? Object.values(mensagensRaw) : []);';
echo 'console.log("Tipo de mensagens:", typeof mensagens, "- É array?", Array.isArray(mensagens));';
echo 'console.log("Total de mensagens:", mensagens.length);';
echo 'if(mensagens.length === 0){';
echo '  console.warn("⚠️ NENHUMA MENSAGEM CARREGADA!");';
echo '}';
echo 'mensagens.slice(0, 5).forEach((msg, idx) => {';
echo '  const remoteJid = msg.key?.remoteJid || "N/A";';
echo '  const fromMe = msg.key?.fromMe || false;';
echo '  const text = msg.message?.conversation || msg.message?.extendedTextMessage?.text || "[Mídia/Outro]";';
echo '  const timestamp = msg.messageTimestamp ? new Date(msg.messageTimestamp * 1000).toLocaleString() : "N/A";';
echo '  console.log(`Mensagem #${idx}:`);';
echo '  console.log("  remoteJid:", remoteJid);';
echo '  console.log("  De mim:", fromMe);';
echo '  console.log("  Texto:", text.substring(0, 100));';
echo '  console.log("  Data/Hora:", timestamp);';
echo '});';
echo 'if(mensagens.length > 5){';
echo '  console.log(`... e mais ${mensagens.length - 5} mensagens`);';
echo '}';
echo 'console.log("");';
echo 'console.log("=== DADOS COMPLETOS (JSON) ===");';
echo 'console.log("Conversas completas:", conversas);';
echo 'console.log("Mensagens completas:", mensagens);';
echo 'console.log("=== FIM DEBUG ===");';
echo '';
echo 'function syncWhatsApp(){';
echo '  var msg="Sincronizar todas as conversas e grupos do WhatsApp?";';
echo '  if(confirm(msg)){';
echo '    window.location.href="/chat_sync_whatsapp.php";';
echo '  }';
echo '}';
echo '';
echo 'if(sessionStorage.getItem("forceReload")==="1"){';
echo '  sessionStorage.removeItem("forceReload");';
echo '  if(!window.location.search.includes("refresh=1")){';
echo '    window.location.href=window.location.pathname+"?refresh=1&t="+Date.now();';
echo '  }';
echo '}';
echo 'function toggleActionsMenu(e){';
echo '  if(e){e.preventDefault();e.stopPropagation();}else{e=window.event;if(e){e.cancelBubble=true;}}';
echo '  console.log("toggleActionsMenu chamado");';
echo '  e.stopPropagation();';
echo '  const menu=document.getElementById("actionsMenu");';
echo '  console.log("Menu encontrado:", menu);';
echo '  if(menu){';
echo '    menu.classList.toggle("show");';
echo '    console.log("Menu toggled. Classes:", menu.className);';
echo '  }else{';
echo '    console.error("Menu actionsMenu não encontrado!");';
echo '  }';
echo '}';
echo 'function toggleChatMenu(e){';
echo '  e.stopPropagation();';
echo '  alert("Menu do chat em desenvolvimento");';
echo '}';
echo 'function searchInChat(){';
echo '  alert("Busca no chat em desenvolvimento");';
echo '}';
echo 'document.addEventListener("click",function(){';
echo '  const menu=document.getElementById("actionsMenu");';
echo '  if(menu)menu.classList.remove("show");';
echo '});';
echo 'const messagesContainer=document.getElementById("messagesContainer");';
echo 'if(messagesContainer){';
echo '  messagesContainer.scrollTop=messagesContainer.scrollHeight;';
echo '}';
echo 'let lastMessageCount=' . count($messages) . ';';
echo 'console.log("Polling iniciado. lastMessageCount:", lastMessageCount);';
echo 'if(messagesContainer){';
echo '  setInterval(function(){';
echo '    const chatId="' . addslashes($selectedChat) . '";';
echo '    if(!chatId)return;';
echo '    console.log("Polling mensagens para chat:", chatId);';
echo '    fetch("/chat_get_messages.php?chat_id="+encodeURIComponent(chatId)+"&t="+Date.now(),{';
echo '      cache:"no-cache",';
echo '      headers:{"Cache-Control":"no-cache","Pragma":"no-cache"}';
echo '    })';
echo '      .then(r=>r.json())';
echo '      .then(data=>{';
echo '        if(data.error){';
echo '          console.warn("Erro no polling:", data.error);';
echo '          return;';
echo '        }';
echo '        console.log("Resposta do polling:", data);';
echo '        console.log("Mensagens recebidas:", data.messages?.length || 0);';
echo '        if(data.messages){';
echo '          data.messages.forEach((msg, idx) => {';
echo '            const remoteJid = msg.key?.remoteJid || "N/A";';
echo '            const text = msg.message?.conversation || msg.message?.extendedTextMessage?.text || "";';
echo '            console.log(`Mensagem #${idx} - remoteJid: ${remoteJid} - Texto: ${text.substring(0, 50)}`);';
echo '          });';
echo '        }';
echo '        if(data.messages && data.messages.length > lastMessageCount){';
echo '          console.log("Novas mensagens detectadas! Recarregando...");';
echo '          lastMessageCount=data.messages.length;';
echo '          location.reload();';
echo '        }';
echo '      })';
echo '      .catch(e=>console.error("Erro no polling:",e));';
echo '  },10000);';
echo '}';
echo 'const textarea=document.querySelector(".whatsapp-input");';
echo 'if(textarea){';
echo '  textarea.addEventListener("input",function(){';
echo '    this.style.height="auto";';
echo '    this.style.height=this.scrollHeight+"px";';
echo '  });';
echo '  textarea.addEventListener("keydown",function(e){';
echo '    if(e.key==="Enter"&&!e.shiftKey){';
echo '      e.preventDefault();';
echo '      document.getElementById("sendMessageForm").submit();';
echo '    }';
echo '  });';
echo '}';
echo '</script>';

view_footer();
exit;

// Código antigo removido abaixo
if (false) {
    if ($prefDemandId > 0) {
        $stmt = db()->prepare('SELECT id, title, specialty, location_city, location_state, status FROM demands WHERE id = :id');
        $stmt->execute(['id' => $prefDemandId]);
        $demandCtx = $stmt->fetch();
        if ($demandCtx) {
            echo '<div style="padding:12px;border-radius:10px;background:hsl(var(--accent)/.15);border:1px solid hsl(var(--border));margin-bottom:14px">';
            echo '<div style="font-weight:900;font-size:13px;color:hsl(var(--primary));margin-bottom:8px">📋 Contexto da Demanda</div>';
            echo '<div style="font-size:12px;line-height:1.6">';
            echo '<strong>Card #' . (int)$demandCtx['id'] . ':</strong> ' . h((string)$demandCtx['title']) . '<br>';
            echo '<strong>Especialidade:</strong> ' . h((string)($demandCtx['specialty'] ?? '-')) . '<br>';
            echo '<strong>Local:</strong> ' . h((string)($demandCtx['location_city'] ?? '-')) . '/' . h((string)($demandCtx['location_state'] ?? '-')) . '<br>';
            echo '<strong>Status:</strong> ' . h((string)$demandCtx['status']);
            echo '</div>';
            echo '<div style="margin-top:10px"><a class="btn" href="/demands_view.php?id=' . (int)$demandCtx['id'] . '" style="font-size:12px;padding:6px 12px">Ver card completo</a></div>';
            echo '</div>';
        }
    }

    echo '<div style="font-weight:900;margin-bottom:10px">Contato</div>';
    echo '<div class="pill" style="display:block;margin-bottom:10px">Telefone: ' . h((string)$selected['external_phone']) . '</div>';

    if ($contact) {
        if ($contact['kind'] === 'patient') {
            echo '<div class="pill" style="display:block;margin-bottom:10px">Paciente: ' . h($contact['name']) . '</div>';
            if ($contact['email'] !== '') {
                echo '<div class="pill" style="display:block;margin-bottom:10px">E-mail: ' . h($contact['email']) . '</div>';
            }
            echo '<a class="btn" href="/patients_view.php?id=' . (int)$contact['id'] . '">Abrir paciente</a>';
        } else {
            echo '<div class="pill" style="display:block;margin-bottom:10px">Profissional: ' . h($contact['name']) . '</div>';
            if (isset($contact['status']) && $contact['status'] !== '') {
                echo '<div class="pill" style="display:block;margin-bottom:10px">Status: ' . h((string)$contact['status']) . '</div>';
            }
            echo '<a class="btn" href="/professional_applications_view.php?id=' . (int)$contact['id'] . '">Abrir candidatura</a>';
        }
    } else {
        echo '<div class="pill" style="display:block;margin-bottom:10px">Contato não identificado</div>';
    }

    echo '<div style="height:10px"></div>';
    $admUrl = '/chat_confirm_admission.php?chat_id=' . (int)$selected['id'];
    if ($prefDemandId > 0) {
        $admUrl .= '&demand_id=' . urlencode((string)$prefDemandId);
    }
    echo '<a class="btn btnPrimary" href="' . h($admUrl) . '">Confirmar Admissão</a>';

    echo '<div style="height:14px"></div>';
    echo '<div style="font-weight:900;margin:0 0 10px">Vincular manualmente</div>';
    echo '<form method="post" action="/chat_link_contact_post.php" style="display:grid;gap:10px">';
    echo '<input type="hidden" name="id" value="' . (int)$selected['id'] . '">';
    echo '<select name="kind" required>';
    echo '<option value="">Selecione</option>';
    echo '<option value="patient">Paciente</option>';
    echo '<option value="professional">Profissional (usuário)</option>';
    echo '</select>';
    echo '<input name="ref_id" placeholder="ID do registro" required>';
    echo '<button class="btn" type="submit">Vincular</button>';
    echo '</form>';

    echo '<div style="font-weight:900;margin:14px 0 10px">Transferir</div>';
    echo '<form method="post" action="/chat_transfer_post.php" style="display:grid;gap:10px">';
    echo '<input type="hidden" name="id" value="' . (int)$selected['id'] . '">';
    echo '<select name="to_user_id" required>';
    echo '<option value="">Selecione um usuário</option>';
    foreach ($users as $u) {
        echo '<option value="' . (int)$u['id'] . '">' . h((string)$u['name']) . ' — ' . h((string)$u['email']) . '</option>';
    }
    echo '</select>';
    echo '<input name="note" placeholder="Observação (opcional)">';
    echo '<button class="btn" type="submit">Transferir</button>';
    echo '</form>';

    echo '<div style="height:12px"></div>';
    echo '<a class="btn" href="/chat_view.php?id=' . (int)$selected['id'] . '">Abrir tela clássica</a>';
}

echo '</div>';

echo '</div>';

echo '</section>';

echo '</div>';

view_footer();

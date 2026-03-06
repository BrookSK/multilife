<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
// Qualquer usuário logado pode acessar o chat
// rbac_require_permission('chat.manage');

$selectedChat = isset($_GET['chat']) ? trim((string)$_GET['chat']) : '';
$chatType = isset($_GET['type']) ? trim((string)$_GET['type']) : 'all'; // all, groups, private
$searchQuery = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$forceRefresh = isset($_GET['refresh']) ? true : false;

// Buscar configurações da Evolution API
$baseUrl = admin_setting_get('evolution.base_url');
$apiKey = admin_setting_get('evolution.api_key');
$instanceName = admin_setting_get('evolution.instance');

$chats = [];
$messages = [];
$selectedChatData = null;

// Buscar conversas da Evolution API
$chats = [];
$selectedChatData = [];
if (!empty($baseUrl) && !empty($apiKey) && !empty($instanceName)) {
    try {
        // Adicionar timestamp para evitar cache
        $url = $baseUrl . '/chat/findChats/' . urlencode($instanceName);
        if ($forceRefresh) {
            $url .= '?t=' . time();
        }
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'apikey: ' . $apiKey,
            'Cache-Control: no-cache, no-store, must-revalidate',
            'Pragma: no-cache',
            'Expires: 0'
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $chats = json_decode($response, true);
            if (!is_array($chats)) {
                $chats = [];
            }
            
            // FILTRO 1: Excluir apenas canais @lid (manter grupos e conversas privadas)
            $chats = array_filter($chats, function($chat) {
                $chatId = $chat['id'] ?? '';
                return !empty($chatId) && strpos($chatId, '@lid') === false;
            });
            
            // Ordenar por timestamp da última mensagem (mais recentes primeiro)
            $debugLogs = [];
            $debugLogs[] = "=== DEBUG ORDENAÇÃO DE CONVERSAS ===";
            $debugLogs[] = "Total de conversas recebidas da API: " . count($chats);
            $debugLogs[] = "";
            $debugLogs[] = "--- ANTES DA ORDENAÇÃO (Top 10) ---";
            
            // Log das primeiras 10 conversas ANTES da ordenação
            foreach (array_slice($chats, 0, 10) as $idx => $chat) {
                $timestamp = $chat['lastMessage']['messageTimestamp'] ?? 0;
                $chatId = $chat['id'] ?? 'N/A';
                $chatName = $chat['name'] ?? $chat['subject'] ?? 'Sem nome';
                $date = $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : 'Sem timestamp';
                $debugLogs[] = "#{$idx} - {$chatName} - Timestamp: {$timestamp} - Data: {$date}";
            }
            
            usort($chats, function($a, $b) {
                $timeA = $a['lastMessage']['messageTimestamp'] ?? 0;
                $timeB = $b['lastMessage']['messageTimestamp'] ?? 0;
                
                // Normalizar timestamps: se estiver em milissegundos (> 10 dígitos), converter para segundos
                if ($timeA > 9999999999) $timeA = intval($timeA / 1000);
                if ($timeB > 9999999999) $timeB = intval($timeB / 1000);
                
                return $timeB - $timeA;
            });
            
            $debugLogs[] = "";
            $debugLogs[] = "--- APÓS ORDENAÇÃO (Top 10) ---";
            foreach (array_slice($chats, 0, 10) as $idx => $chat) {
                $timestamp = $chat['lastMessage']['messageTimestamp'] ?? 0;
                $chatId = $chat['id'] ?? 'N/A';
                $chatName = $chat['name'] ?? $chat['subject'] ?? 'Sem nome';
                $date = $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : 'Sem timestamp';
                $debugLogs[] = "#{$idx} - {$chatName} - Timestamp: {$timestamp} - Data: {$date}";
            }
            
            // FILTRO 2: Limitar a 10 conversas mais recentes (grupos + privadas)
            $chats = array_slice($chats, 0, 10);
            
            // Enriquecer dados dos chats com nomes
            foreach ($chats as &$chat) {
                $chatId = $chat['id'] ?? '';
                
                // Buscar nome - usar dados já disponíveis da API
                if (!isset($chat['name']) || empty($chat['name'])) {
                    if (!empty($chatId)) {
                        $isGroup = strpos($chatId, '@g.us') !== false;
                        
                        // Prioridade: pushName > subject (grupos) > número formatado
                        if (isset($chat['pushName']) && !empty($chat['pushName'])) {
                            $chat['name'] = $chat['pushName'];
                        } elseif ($isGroup && isset($chat['subject']) && !empty($chat['subject'])) {
                            // Grupos têm um campo 'subject' com o nome do grupo
                            $chat['name'] = $chat['subject'];
                        } elseif (!$isGroup) {
                            // Apenas formatar número se for conversa privada
                            $number = str_replace(['@s.whatsapp.net', '@lid'], '', $chatId);
                            
                            // Formatar número brasileiro: +55 (XX) XXXXX-XXXX ou (XX) XXXX-XXXX
                            if (preg_match('/^55(\d{2})(\d{4,5})(\d{4})$/', $number, $matches)) {
                                // Número brasileiro com DDI 55
                                $chat['name'] = '+55 (' . $matches[1] . ') ' . $matches[2] . '-' . $matches[3];
                            } elseif (preg_match('/^(\d{2})(\d{4,5})(\d{4})$/', $number, $matches)) {
                                // Número brasileiro sem DDI
                                $chat['name'] = '(' . $matches[1] . ') ' . $matches[2] . '-' . $matches[3];
                            } else {
                                // Outros formatos - adicionar + se tiver mais de 10 dígitos
                                $chat['name'] = strlen($number) > 10 ? '+' . $number : $number;
                            }
                        } else {
                            // Fallback para grupos sem subject
                            $chat['name'] = 'Grupo';
                        }
                    }
                }
                
                // Usar profilePictureUrl se já vier da API
                if (!isset($chat['profilePictureUrl'])) {
                    $chat['profilePictureUrl'] = null;
                }
            }
            unset($chat);
            
            // Filtrar por busca (se houver)
            if (!empty($searchQuery)) {
                $chats = array_filter($chats, function($chat) use ($searchQuery) {
                    $name = $chat['name'] ?? $chat['id'] ?? '';
                    $id = $chat['id'] ?? '';
                    return stripos($name, $searchQuery) !== false || stripos($id, $searchQuery) !== false;
                });
            }
        }
    } catch (Exception $e) {
        // Erro ao buscar chats
    }
}

// DEBUG: Exibir logs na tela
$debugLogs = [];

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

// CSS customizado para WhatsApp Web
echo '<style>';
echo '.whatsapp-container{display:flex;height:calc(100vh - 64px);background:#f0f2f5;overflow:hidden}';
echo '.whatsapp-sidebar{width:420px;background:#fff;border-right:1px solid #d1d7db;display:flex;flex-direction:column}';
echo '.whatsapp-header{padding:16px;background:#f0f2f5;border-bottom:1px solid #d1d7db;display:flex;align-items:center;justify-content:space-between}';
echo '.whatsapp-search{padding:8px 16px;background:#fff}';
echo '.whatsapp-search input{width:100%;padding:8px 12px;border:1px solid #d1d7db;border-radius:8px;font-size:14px}';
echo '.whatsapp-tabs{display:flex;gap:4px;padding:0 16px;background:#fff;border-bottom:1px solid #d1d7db}';
echo '.whatsapp-tab{padding:12px 16px;font-size:14px;font-weight:500;color:#54656f;cursor:pointer;border-bottom:3px solid transparent;transition:all .2s}';
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

echo '<div class="whatsapp-container">';

// Sidebar esquerda - Lista de conversas
echo '<div class="whatsapp-sidebar">';

// Header com avatar do usuário
echo '<div class="whatsapp-header">';
echo '<div class="whatsapp-chat-avatar">' . strtoupper(substr(auth_user()['name'] ?? 'U', 0, 1)) . '</div>';
echo '<div style="display:flex;gap:4px">';
echo '<button class="whatsapp-action-btn" onclick="syncWhatsApp()" title="Sincronizar WhatsApp">';
echo '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0118.8-4.3M22 12.5a10 10 0 01-18.8 4.2"/></svg>';
echo '</button>';
echo '<button class="whatsapp-action-btn" onclick="window.location.href=\'/evolution_qrcode.php\'" title="Conectar via QR Code">';
echo '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>';
echo '</button>';
echo '</div>';
echo '</div>';

// Busca
echo '<div class="whatsapp-search">';
echo '<form method="get" action="/chat_web.php">';
echo '<input type="hidden" name="type" value="' . h($chatType) . '">';
if (!empty($selectedChat)) {
    echo '<input type="hidden" name="chat" value="' . h($selectedChat) . '">';
}
echo '<input type="text" name="q" value="' . h($searchQuery) . '" placeholder="Pesquisar ou começar uma nova conversa">';
echo '</form>';
echo '</div>';

// Abas de filtro
echo '<div class="whatsapp-tabs">';
$allActive = $chatType === 'all' ? ' active' : '';
$groupsActive = $chatType === 'groups' ? ' active' : '';
$privateActive = $chatType === 'private' ? ' active' : '';
echo '<a href="/chat_web.php?type=all" class="whatsapp-tab' . $allActive . '">Todas</a>';
echo '<a href="/chat_web.php?type=groups" class="whatsapp-tab' . $groupsActive . '">Grupos</a>';
echo '<a href="/chat_web.php?type=private" class="whatsapp-tab' . $privateActive . '">Conversas</a>';
echo '</div>';

// Lista de conversas (máximo 10 conversas: grupos + privadas)
echo '<div class="whatsapp-chats" id="chatsList">';
if (empty($chats)) {
    echo '<div style="padding:40px 20px;text-align:center;color:#667781">';
    echo '<p>Nenhuma conversa encontrada.</p>';
    if (empty($baseUrl) || empty($apiKey)) {
        echo '<p style="margin-top:12px;font-size:13px">Configure as credenciais da Evolution API em Configurações.</p>';
    }
    echo '</div>';
} else {
    foreach ($chats as $chat) {
        $chatId = $chat['id'] ?? '';
        $chatName = $chat['name'] ?? $chatId;
        $isGroup = strpos($chatId, '@g.us') !== false;
        $isActive = $selectedChat === $chatId ? ' active' : '';
        $lastMsg = $chat['lastMessage']['message'] ?? '';
        $lastTime = isset($chat['lastMessage']['messageTimestamp']) ? date('H:i', $chat['lastMessage']['messageTimestamp']) : '';
        
        $initials = strtoupper(substr($chatName, 0, 2));
        $profilePic = $chat['profilePictureUrl'] ?? '';
        
        echo '<a href="/chat_web.php?type=' . h($chatType) . '&chat=' . urlencode($chatId) . '" class="whatsapp-chat-item' . $isActive . '">';
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
    if ($isGroup) {
        // Mensagem informativa para grupos
        echo '<div style="text-align:center;padding:40px;color:#667781;background:#f0f2f5;margin:20px;border-radius:8px">';
        echo '<svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin:0 auto 16px;opacity:0.5"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>';
        echo '<h3 style="margin:0 0 8px;color:#111b21">Grupo: ' . h($chatName) . '</h3>';
        echo '<p style="margin:0;font-size:14px">Histórico de mensagens não disponível para grupos.</p>';
        echo '<p style="margin:8px 0 0;font-size:13px;opacity:0.7">Apenas conversas privadas carregam mensagens.</p>';
        echo '</div>';
    } else {
        // Mensagem explicativa sobre API Evolution
        echo '<div style="text-align:center;padding:40px;color:#667781;background:#fff3cd;margin:20px;border-radius:8px;border:1px solid #ffc107">';
        echo '<svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#ffc107" stroke-width="1.5" style="margin:0 auto 16px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';
        echo '<h3 style="margin:0 0 8px;color:#856404">Mensagens Temporariamente Indisponíveis</h3>';
        echo '<p style="margin:0;font-size:14px;color:#856404">A API Evolution está retornando dados desatualizados.</p>';
        echo '<p style="margin:8px 0 0;font-size:13px;color:#856404;opacity:0.8">As mensagens serão carregadas assim que a API sincronizar corretamente.</p>';
        echo '<p style="margin:12px 0 0;font-size:13px;color:#856404"><strong>Conversa:</strong> ' . h($chatName) . '</p>';
        echo '</div>';
    }
    
    // Código antigo de renderização de mensagens (desabilitado)
    if (false) {
        foreach ($messages as $msg) {
            $isFromMe = isset($msg['key']['fromMe']) && $msg['key']['fromMe'] === true;
            $messageClass = $isFromMe ? 'out' : 'in';
            $messageText = $msg['message']['conversation'] ?? $msg['message']['extendedTextMessage']['text'] ?? '';
            $timestamp = isset($msg['messageTimestamp']) ? date('H:i', $msg['messageTimestamp']) : '';
            
            echo '<div class="whatsapp-message ' . $messageClass . '">';
            echo '<div class="whatsapp-message-bubble">';
            echo '<div class="whatsapp-message-text">' . h($messageText) . '</div>';
            echo '<div class="whatsapp-message-time">' . h($timestamp) . '</div>';
            echo '</div>';
            echo '</div>';
        }
    }
    echo '</div>';
    
    // Formulário de envio
    echo '<div class="whatsapp-input-area">';
    echo '<form method="post" action="/chat_send_message.php" style="display:flex;gap:8px;align-items:flex-end;width:100%" id="sendMessageForm">';
    echo '<input type="hidden" name="chat_id" value="' . h($selectedChat) . '">';
    echo '<textarea class="whatsapp-input" name="message" placeholder="Digite uma mensagem" rows="1" required></textarea>';
    echo '<button type="submit" class="whatsapp-send-btn">';
    echo '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>';
    echo '</button>';
    echo '</form>';
    echo '</div>';
}

echo '</div>';

echo '</div>'; // Fecha whatsapp-container

// JavaScript para funcionalidades
echo '<script>';
echo 'console.log("=== CHAT WEB DEBUG ===");';
echo 'console.log("Chat selecionado:", "' . addslashes($selectedChat) . '");';
echo 'console.log("Total de conversas carregadas:", ' . count($chats) . ');';
echo 'console.log("Total de mensagens carregadas:", ' . count($messages) . ');';
echo 'console.log("");';
echo 'console.log("--- CONVERSAS RECEBIDAS ---");';
echo 'const conversasRaw = ' . json_encode($chats) . ';';
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
echo 'const mensagensRaw = ' . json_encode($messages) . ';';
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
echo '    const chatId="' . addslashes($selectedChat) . '"';
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
echo '            console.log(`Mensagem #${idx} - remoteJid: ${remoteJid} - Texto: ${text.substring(0, 50)}`);
';
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

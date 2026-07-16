<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

$id = (int)($_POST['id'] ?? 0);
$subRequestId = (int)($_POST['sub_request_id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM demands WHERE id = :id');
$stmt->execute(['id' => $id]);
$d = $stmt->fetch();

if (!$d) {
    flash_set('error', 'Demanda não encontrada.');
    header('Location: /demands_list.php');
    exit;
}

// Se foi selecionada uma sub-solicitação, usar os dados dela
$subRequest = null;
if ($subRequestId > 0) {
    $stmtSub = db()->prepare('SELECT * FROM demand_sub_requests WHERE id = :id AND demand_id = :did');
    $stmtSub->execute(['id' => $subRequestId, 'did' => $id]);
    $subRequest = $stmtSub->fetch();
    if (!$subRequest) {
        flash_set('error', 'Sub-solicitação não encontrada.');
        header('Location: /demands_view.php?id=' . $id);
        exit;
    }
    if ($subRequest['status'] === 'em_captacao' || $subRequest['status'] === 'concluido') {
        flash_set('error', 'Esta sub-solicitação já está em captação ou concluída.');
        header('Location: /demands_view.php?id=' . $id);
        exit;
    }
}

// Usar dados da sub-solicitação se disponível, senão usar dados da demanda
$city = (string)($subRequest ? ($subRequest['location_city'] ?? $d['location_city'] ?? '') : ($d['location_city'] ?? ''));
$state = (string)($subRequest ? ($subRequest['location_state'] ?? $d['location_state'] ?? '') : ($d['location_state'] ?? ''));
$specialty = (string)($subRequest ? ($subRequest['specialty'] ?? '') : ($d['specialty'] ?? ''));

// Buscar grupos compatíveis - tentar match progressivo
$groups = [];
$jidFilter = ' AND evolution_group_jid LIKE \'%@g.us\'';

// Tentativa 1: Match exato por especialidade + estado + cidade (case-insensitive)
if (trim($specialty) !== '' && count($groups) === 0) {
    $sql = 'SELECT id, name, evolution_group_jid FROM whatsapp_groups WHERE status = \'active\' AND evolution_group_jid IS NOT NULL AND evolution_group_jid <> \'\'' . $jidFilter;
    $conditions = [];
    $params = [];
    
    $conditions[] = '(LOWER(specialty) = LOWER(:sp))';
    $params['sp'] = $specialty;
    
    if (trim($state) !== '') {
        $conditions[] = '(state IS NULL OR state = \'\' OR LOWER(state) = LOWER(:st))';
        $params['st'] = $state;
    }
    
    if (trim($city) !== '') {
        $conditions[] = '(city IS NULL OR city = \'\' OR LOWER(city) = LOWER(:city))';
        $params['city'] = $city;
    }
    
    $sql .= ' AND ' . implode(' AND ', $conditions) . ' ORDER BY id DESC LIMIT 1';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $groups = $stmt->fetchAll();
}

// Tentativa 2: Só por especialidade (ignorar localização, case-insensitive)
if (count($groups) === 0 && trim($specialty) !== '') {
    $sql2 = 'SELECT id, name, evolution_group_jid FROM whatsapp_groups WHERE status = \'active\' AND evolution_group_jid IS NOT NULL AND evolution_group_jid <> \'\'' . $jidFilter . ' AND LOWER(specialty) = LOWER(:sp) ORDER BY id DESC LIMIT 1';
    $stmt2 = db()->prepare($sql2);
    $stmt2->execute(['sp' => $specialty]);
    $groups = $stmt2->fetchAll();
}

// Tentativa 3: Especialidade com LIKE (caso tenha diferença de acentuação)
if (count($groups) === 0 && trim($specialty) !== '') {
    $sql3 = 'SELECT id, name, evolution_group_jid FROM whatsapp_groups WHERE status = \'active\' AND evolution_group_jid IS NOT NULL AND evolution_group_jid <> \'\'' . $jidFilter . ' AND (specialty LIKE :sp_like OR name LIKE :name_like) ORDER BY id DESC LIMIT 1';
    $stmt3 = db()->prepare($sql3);
    $stmt3->execute(['sp_like' => '%' . $specialty . '%', 'name_like' => '%' . $specialty . '%']);
    $groups = $stmt3->fetchAll();
}

if (count($groups) === 0) {
    // NENHUM GRUPO ENCONTRADO — Criar automaticamente
    error_log("[DISPATCH] Nenhum grupo encontrado para spec='$specialty' city='$city' state='$state'. Criando automaticamente...");
    
    try {
        $api = new EvolutionApiV1();
        
        // Gerar nome do grupo: Especialidade - Cidade/UF - N
        $location = trim($city !== '' ? ($city . ($state !== '' ? '/' . $state : '')) : $state);
        if ($location === '') $location = 'Geral';
        
        // Contar grupos existentes para gerar número sequencial
        $countStmt = db()->prepare('SELECT COUNT(*) FROM whatsapp_groups WHERE specialty = ?');
        $countStmt->execute([$specialty]);
        $groupNumber = (int)$countStmt->fetchColumn() + 1;
        
        $groupName = $specialty . ' - ' . $location . ' - ' . $groupNumber;
        
        // Buscar profissionais da especialidade para adicionar ao grupo
        // Match progressivo: exato → contém → primeira palavra da especialidade
        $profsStmt = db()->prepare("
            SELECT u.phone FROM users u
            INNER JOIN user_roles ur ON ur.user_id = u.id
            INNER JOIN roles r ON r.id = ur.role_id
            WHERE u.status = 'active' AND r.slug = 'profissional'
            AND (
                u.specialty = ? 
                OR u.specialty LIKE ? 
                OR ? LIKE CONCAT('%', u.specialty, '%')
                OR u.specialty LIKE ?
            )
            AND u.phone IS NOT NULL AND u.phone != ''
        ");
        // Ex: specialty='Fisioterapia Domiciliar' → busca exato, LIKE '%Fisioterapia Domiciliar%', 
        // 'Fisioterapia Domiciliar' LIKE '%Fisioterapia%' (match inverso), LIKE 'Fisioterapia%' (primeira palavra)
        $firstWord = explode(' ', trim($specialty))[0];
        $profsStmt->execute([$specialty, '%' . $specialty . '%', $specialty, $firstWord . '%']);
        $profPhones = $profsStmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Limpar telefones (apenas dígitos) e remover duplicados
        $participants = [];
        foreach ($profPhones as $phone) {
            $clean = preg_replace('/\D+/', '', $phone);
            if (strlen($clean) >= 10) {
                if (strlen($clean) === 10 || strlen($clean) === 11) {
                    $clean = '55' . $clean;
                }
                $participants[] = $clean;
            }
        }
        
        // MULTI-INSTÂNCIA: Adicionar TODOS os números WhatsApp conectados no sistema
        // Cada instância conectada (de cada usuário) entra como participante do grupo
        $connectedNumbers = whatsapp_get_all_connected_numbers();
        foreach ($connectedNumbers as $connNum) {
            if (!in_array($connNum, $participants, true)) {
                $participants[] = $connNum;
            }
        }
        error_log("[DISPATCH] Adicionados " . count($connectedNumbers) . " números de instâncias conectadas ao grupo");
        
        $participants = array_values(array_unique($participants));
        
        // Garantir pelo menos 1 participante (o próprio admin)
        if (empty($participants)) {
            $adminPhone = preg_replace('/\D+/', '', (string)admin_setting_get('evolution.admin_phone', '5517991253062'));
            $participants[] = $adminPhone;
        }
        
        // Criar grupo via Evolution API
        $baseUrl = rtrim((string)admin_setting_get('evolution.base_url', ''), '/');
        $apiKey = (string)admin_setting_get('evolution.api_key', '');
        $instanceName = (string)admin_setting_get('evolution.instance', '');
        
        $createUrl = $baseUrl . '/group/create/' . urlencode($instanceName);
        $createPayload = json_encode([
            'subject' => $groupName,
            'description' => 'Grupo criado automaticamente pelo sistema MultiLife - ' . $specialty,
            'participants' => $participants,
        ]);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $createUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => $createPayload,
            CURLOPT_HTTPHEADER => ["Content-Type: application/json", "apikey: " . $apiKey],
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        
        $createResponse = curl_exec($ch);
        $createHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($createHttpCode === 200 || $createHttpCode === 201) {
            $createData = json_decode($createResponse, true);
            $newGroupJid = $createData['id'] ?? '';
            
            if (!empty($newGroupJid)) {
                // Salvar grupo no banco
                $stmtNewGroup = db()->prepare(
                    'INSERT INTO whatsapp_groups (name, evolution_group_jid, contacts_count, specialty, city, state, status) VALUES (:n, :jid, :cnt, :sp, :city, :st, \'active\') ON DUPLICATE KEY UPDATE name = VALUES(name)'
                );
                $stmtNewGroup->execute([
                    'n' => $groupName,
                    'jid' => $newGroupJid,
                    'cnt' => count($participants),
                    'sp' => $specialty,
                    'city' => $city,
                    'st' => $state,
                ]);
                $newGroupDbId = (int)db()->lastInsertId();
                
                // Também salvar na chat_groups
                try {
                    db()->prepare("INSERT IGNORE INTO chat_groups (group_jid, group_name, specialty, region, created_at) VALUES (?, ?, ?, ?, NOW())")
                        ->execute([$newGroupJid, $groupName, $specialty, $location]);
                } catch (Exception $e) {}
                
                // Usar o grupo recém-criado para o dispatch
                $groups = [['id' => $newGroupDbId, 'name' => $groupName, 'evolution_group_jid' => $newGroupJid]];
                
                error_log("[DISPATCH] ✅ Grupo criado automaticamente: '$groupName' (JID: $newGroupJid) com " . count($participants) . " participantes");
                
                // Configurar grupo para apenas admins enviarem mensagens
                try {
                    $api2 = new EvolutionApiV1();
                    $settingsResult = $api2->updateGroupSetting($newGroupJid, 'announcement');
                    $settingsCode = $settingsResult['status'] ?? 0;
                    $settingsJson = $settingsResult['json'] ?? $settingsResult['body_raw'] ?? '';
                    error_log("[DISPATCH] Grupo configurado como 'somente admins': HTTP $settingsCode | Response: " . json_encode($settingsJson));
                    
                    if ($settingsCode < 200 || $settingsCode >= 300) {
                        error_log("[DISPATCH] ⚠️ FALHA ao configurar grupo como announcement! HTTP $settingsCode");
                        error_log("[DISPATCH] groupJid usado: $newGroupJid");
                    }
                } catch (Exception $e) {
                    error_log("[DISPATCH] Erro ao configurar grupo: " . $e->getMessage());
                }
                
                // MULTI-INSTÂNCIA: Promover todos os números de instâncias conectadas a admin do grupo
                // Isso garante que todas as instâncias possam enviar mensagens no grupo (announcement mode)
                try {
                    $instanceNumbers = whatsapp_get_all_connected_numbers();
                    if (!empty($instanceNumbers)) {
                        $api3 = new EvolutionApiV1();
                        $adminParticipants = [];
                        foreach ($instanceNumbers as $num) {
                            $adminParticipants[] = $num . '@s.whatsapp.net';
                        }
                        $promoteResult = $api3->updateGroupMembers($newGroupJid, 'promote', $adminParticipants);
                        $promoteCode = $promoteResult['status'] ?? 0;
                        error_log("[DISPATCH] Instâncias promovidas a admin: HTTP $promoteCode (" . count($adminParticipants) . " números)");
                    }
                } catch (Exception $e) {
                    error_log("[DISPATCH] Erro ao promover instâncias a admin: " . $e->getMessage());
                }
                
                // Pequeno delay para o grupo estabilizar antes de enviar mensagem
                sleep(3);
            } else {
                flash_set('error', 'Erro ao criar grupo automaticamente: API não retornou JID.');
                header('Location: /demands_view.php?id=' . $id);
                exit;
            }
        } else {
            $errorMsg = substr($createResponse, 0, 200);
            error_log("[DISPATCH] Erro ao criar grupo: HTTP $createHttpCode - $errorMsg");
            flash_set('error', 'Erro ao criar grupo automaticamente (HTTP ' . $createHttpCode . '). Crie o grupo manualmente e tente novamente.');
            header('Location: /demands_view.php?id=' . $id);
            exit;
        }
    } catch (Exception $e) {
        flash_set('error', 'Erro ao criar grupo: ' . $e->getMessage());
        header('Location: /demands_view.php?id=' . $id);
        exit;
    }
}

$tpl = trim((string)admin_setting_get(
    'demands.whatsapp_template',
    ''
));

// Se template vazio ou não configurado, usar padrão com bairro e cidade
if ($tpl === '') {
    $tpl = "[CAPTAÇÃO #{id}]\n{title}\n\n📍 *Local:*\n{neighborhood_city}\n\n🏥 *Especialidade:* {specialty}\n📅 *Frequência:* {frequency}\n\n👆 *Tem interesse e disponibilidade?*\nReaja a esta mensagem com qualquer emoji para demonstrar interesse. Entraremos em contato no privado para alinhar os detalhes.";
}

// Montar endereço completo (rua, número, bairro)
$street = (string)($subRequest ? ($subRequest['location_street'] ?? $d['location_street'] ?? '') : ($d['location_street'] ?? ''));
$neighborhood = (string)($subRequest ? ($subRequest['location_neighborhood'] ?? $d['location_neighborhood'] ?? '') : ($d['location_neighborhood'] ?? ''));
$number = (string)($subRequest ? ($subRequest['location_number'] ?? $d['location_number'] ?? '') : ($d['location_number'] ?? ''));
$complement = (string)($subRequest ? ($subRequest['location_complement'] ?? $d['location_complement'] ?? '') : ($d['location_complement'] ?? ''));

$addressParts = [];
if (trim($street) !== '') {
    $addr = trim($street);
    if (trim($number) !== '') $addr .= ', ' . trim($number);
    $addressParts[] = $addr;
}
if (trim($complement) !== '') $addressParts[] = trim($complement);
if (trim($neighborhood) !== '') $addressParts[] = trim($neighborhood);
$fullAddress = count($addressParts) > 0 ? implode(' - ', $addressParts) : '';

// Frequência: usar helper se disponível
$freqRaw = $subRequest && trim((string)($subRequest['frequency'] ?? '')) !== '' 
    ? (string)$subRequest['frequency'] 
    : (trim((string)($d['frequency'] ?? '')) !== '' ? (string)$d['frequency'] : '');
$freqDisplay = $freqRaw;
if ($freqRaw !== '' && function_exists('frequency_get_label')) {
    $normalized = frequency_normalize($freqRaw);
    if ($normalized !== '') {
        $freqDisplay = frequency_get_label($normalized) . ' (' . frequency_get_weekday_description($normalized) . ')';
    }
}

$repl = [
    '{id}' => (string)$d['id'],
    '{title}' => (string)$d['title'],
    '{city}' => $city !== '' ? $city : '-',
    '{state}' => $state !== '' ? $state : '-',
    '{address}' => $fullAddress !== '' ? $fullAddress : '',
    '{street}' => $street !== '' ? $street : '-',
    '{neighborhood}' => $neighborhood !== '' ? $neighborhood : '-',
    '{neighborhood_city}' => trim(($neighborhood !== '' ? $neighborhood . ' - ' : '') . ($city !== '' ? $city : '') . ($state !== '' ? '/' . $state : '')),
    '{specialty}' => $specialty !== '' ? $specialty : '-',
    '{frequency}' => $freqDisplay !== '' ? $freqDisplay : '-',
    '{description}' => mb_strimwidth(
        ($subRequest ? (string)($subRequest['description'] ?? $d['description'] ?? '') : (string)($d['description'] ?? '')),
        0, 500, '...'
    ),
    '{origin}' => (string)($d['origin_email'] ?? ''),
];

$msg = strtr($tpl, $repl);

// Proteção contra envio duplo: verificar se já existe dispatch recente (< 60s) para esta demanda + sub-request
$recentSql = "SELECT id FROM demand_dispatch_logs WHERE demand_id = :did AND created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)";
$recentParams = ['did' => $id];
if ($subRequestId > 0) {
    // Se tiver sub_request, verificar se a mensagem contém a especialidade da sub_request
    $recentSql .= " AND message LIKE :splike";
    $recentParams['splike'] = '%' . ($subRequest['specialty'] ?? '') . '%';
}
$recentSql .= " LIMIT 1";
$recentDispatch = db()->prepare($recentSql);
$recentDispatch->execute($recentParams);
if ($recentDispatch->fetch()) {
    flash_set('error', 'Captação já foi disparada há menos de 1 minuto. Evite clicar duas vezes.');
    header('Location: /demands_view.php?id=' . $id);
    exit;
}

// ====================================================================
// SINCRONIZAR MEMBROS DO GRUPO: Antes de enviar a captação, verificar se há profissionais
// compatíveis que ainda não estão no grupo e adicioná-los.
// Isso garante que profissionais novos recebam a captação.
// ====================================================================
if (count($groups) > 0) {
    $targetGroup = $groups[0];
    $targetJid = (string)($targetGroup['evolution_group_jid'] ?? '');
    
    if ($targetJid !== '') {
        try {
            $syncApi = new EvolutionApiV1();
            
            // Buscar profissionais compatíveis com a especialidade (mesmo match progressivo)
            $firstWord = explode(' ', trim($specialty))[0];
            $syncProfsStmt = db()->prepare("
                SELECT u.phone FROM users u
                INNER JOIN user_roles ur ON ur.user_id = u.id
                INNER JOIN roles r ON r.id = ur.role_id
                WHERE u.status = 'active' AND r.slug = 'profissional'
                AND (
                    u.specialty = ? 
                    OR u.specialty LIKE ? 
                    OR ? LIKE CONCAT('%', u.specialty, '%')
                    OR u.specialty LIKE ?
                )
                AND u.phone IS NOT NULL AND u.phone != ''
            ");
            $syncProfsStmt->execute([$specialty, '%' . $specialty . '%', $specialty, $firstWord . '%']);
            $syncProfPhones = $syncProfsStmt->fetchAll(PDO::FETCH_COLUMN);
            
            $phonesToAdd = [];
            foreach ($syncProfPhones as $phone) {
                $clean = preg_replace('/\D+/', '', $phone);
                if (strlen($clean) >= 10) {
                    if (strlen($clean) === 10 || strlen($clean) === 11) {
                        $clean = '55' . $clean;
                    }
                    $phonesToAdd[] = $clean . '@s.whatsapp.net';
                }
            }
            
            // Adicionar números de instâncias conectadas também
            $instanceNumbers = whatsapp_get_all_connected_numbers();
            foreach ($instanceNumbers as $num) {
                $phonesToAdd[] = $num . '@s.whatsapp.net';
            }
            
            $phonesToAdd = array_values(array_unique($phonesToAdd));
            
            if (!empty($phonesToAdd)) {
                // Adicionar ao grupo (a API ignora quem já é membro)
                $syncApi->updateGroupMembers($targetJid, 'add', $phonesToAdd);
                error_log("[DISPATCH] Sincronização de membros: adicionados " . count($phonesToAdd) . " números ao grupo $targetJid");
                
                // Pequeno delay para estabilizar
                usleep(500000); // 500ms
            }
        } catch (Exception $e) {
            error_log("[DISPATCH] Erro ao sincronizar membros do grupo: " . $e->getMessage());
            // Não bloquear o dispatch por causa disso
        }
    }
}

$db = db();
$db->beginTransaction();
try {
    $ins = $db->prepare('INSERT INTO demand_dispatch_logs (demand_id, group_id, dispatched_by_user_id, message, capture_token, dispatch_status) VALUES (:did, :gid, :uid, :msg, :token, :st)');
    foreach ($groups as $g) {
        $token = '#CAP' . (string)$id . '-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));
        $msgWithToken = $msg . "\n\n" . $token;
        $ins->execute([
            'did' => $id,
            'gid' => (int)$g['id'],
            'uid' => auth_user_id(),
            'msg' => $msgWithToken,
            'token' => $token,
            'st' => 'queued',
        ]);
    }

    // Atualiza status para em_captacao e assume para o usuário que disparou
    if ((string)$d['status'] === 'aguardando_captacao') {
        $upd = $db->prepare('UPDATE demands SET status = \'em_captacao\', assumed_by_user_id = :uid, assumed_at = NOW() WHERE id = :id');
        $upd->execute(['id' => $id, 'uid' => auth_user_id()]);

        $log = $db->prepare('INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (:did, :os, :ns, :uid, :note)');
        $log->execute([
            'did' => $id,
            'os' => 'aguardando_captacao',
            'ns' => 'em_captacao',
            'uid' => auth_user_id(),
            'note' => 'realizar captacao',
        ]);
    }

    audit_log('create', 'demand_dispatch', (string)$id, null, ['groups' => array_map(fn($x) => (int)$x['id'], $groups)]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

// Envio via Evolution (fora da transação)
$api = null;
try {
    $api = new EvolutionApiV1();
    
    // Verificar se a instância está conectada antes de tentar enviar
    try {
        $connState = $api->connectionState();
        $state = $connState['json']['instance']['state'] ?? ($connState['json']['state'] ?? '');
        if ($state !== 'open' && $state !== 'connected') {
            // WhatsApp desconectado — reverter status e avisar o usuário
            db()->prepare('UPDATE demand_dispatch_logs SET dispatch_status = \'error\', error_message = \'WhatsApp desconectado\' WHERE demand_id = :did AND dispatch_status = \'queued\'')
                ->execute(['did' => $id]);
            
            // Reverter status da demanda
            if ((string)$d['status'] === 'aguardando_captacao') {
                db()->prepare('UPDATE demands SET status = \'aguardando_captacao\', assumed_by_user_id = NULL, assumed_at = NULL WHERE id = :id')
                    ->execute(['id' => $id]);
            }
            
            flash_set('error', 'WhatsApp está desconectado. A captação não foi enviada. Reconecte em: Configurações → WhatsApp Conexão → aba Conexão.');
            header('Location: /demands_view.php?id=' . $id);
            exit;
        }
    } catch (Throwable $connErr) {
        error_log("[DISPATCH] Erro ao verificar conexão: " . $connErr->getMessage());
        // Não bloquear — tentar enviar mesmo assim
    }
} catch (Throwable $e) {
    // registra erro em todos
    $upd = db()->prepare('UPDATE demand_dispatch_logs SET dispatch_status = \'error\', error_message = :err WHERE demand_id = :did AND dispatch_status = \'queued\'');
    $upd->execute(['err' => 'Evolution API não configurada: ' . mb_strimwidth($e->getMessage(), 0, 220, ''), 'did' => $id]);
    flash_set('error', 'WhatsApp não configurado. Configure em: Configurações → WhatsApp Conexão → aba Credenciais.');
    header('Location: /demands_view.php?id=' . $id);
    exit;
}

$selLogs = db()->prepare(
    'SELECT dl.id, dl.message, g.evolution_group_jid FROM demand_dispatch_logs dl LEFT JOIN whatsapp_groups g ON g.id = dl.group_id WHERE dl.demand_id = :did AND dl.dispatch_status = \'queued\''
);
$selLogs->execute(['did' => $id]);
$toSend = $selLogs->fetchAll();

$updOne = db()->prepare('UPDATE demand_dispatch_logs SET dispatch_status = :st, error_message = :err WHERE id = :id');

$sent = 0;
$errCount = 0;
foreach ($toSend as $row) {
    $logId = (int)$row['id'];
    $jid = (string)($row['evolution_group_jid'] ?? '');
    $msgRow = (string)($row['message'] ?? $msg);
    if ($jid === '') {
        $updOne->execute(['st' => 'error', 'err' => 'Grupo sem evolution_group_jid configurado.', 'id' => $logId]);
        $errCount++;
        continue;
    }

    try {
        $res = $api->sendText($jid, $msgRow);
        $ok = isset($res['status']) && (int)$res['status'] >= 200 && (int)$res['status'] < 300;
        if ($ok) {
            // Extrair o message_id retornado pela API (para vincular reações futuras)
            $externalMsgId = null;
            $jsonRes = $res['json'] ?? null;
            if (is_array($jsonRes) && isset($jsonRes['key']['id'])) {
                $externalMsgId = (string)$jsonRes['key']['id'];
            }
            
            $updOne->execute(['st' => 'sent', 'err' => null, 'id' => $logId]);
            
            // Salvar external_message_id para vincular reações
            if ($externalMsgId) {
                $stmtExtId = db()->prepare('UPDATE demand_dispatch_logs SET external_message_id = :eid WHERE id = :id');
                $stmtExtId->execute(['eid' => $externalMsgId, 'id' => $logId]);
            }
            
            $sent++;
            
            // Salvar na chat_messages para aparecer no chat
            try {
                $stmtChat = db()->prepare(
                    'INSERT INTO chat_messages (remote_jid, message_text, from_me, message_timestamp, external_message_id) VALUES (?, ?, 1, ?, ?)'
                );
                $stmtChat->execute([$jid, $msgRow, time(), $externalMsgId]);
            } catch (Throwable $chatErr) {
                error_log('[DISPATCH] Erro ao salvar em chat_messages: ' . $chatErr->getMessage());
            }
        } else {
            $updOne->execute(['st' => 'error', 'err' => 'HTTP ' . (string)($res['status'] ?? ''), 'id' => $logId]);
            $errCount++;
        }
    } catch (Throwable $e) {
        $updOne->execute(['st' => 'error', 'err' => mb_strimwidth($e->getMessage(), 0, 255, ''), 'id' => $logId]);
        $errCount++;
        continue;
    }
}

if ($sent > 0 && $errCount === 0) {
    flash_set('success', 'Captação enviada via WhatsApp.');
} elseif ($sent > 0) {
    flash_set('error', 'Captação enviada para alguns grupos, mas houve erros em outros.');
} else {
    flash_set('error', 'Falha ao enviar captação via WhatsApp. Verifique os logs.');
}

// Atualizar status da sub-solicitação somente se pelo menos um envio foi bem-sucedido
if ($subRequest !== null && $sent > 0) {
    $updSub = db()->prepare('UPDATE demand_sub_requests SET status = \'em_captacao\', dispatched_at = NOW() WHERE id = :id');
    $updSub->execute(['id' => $subRequestId]);
}

header('Location: /demands_view.php?id=' . $id);
exit;

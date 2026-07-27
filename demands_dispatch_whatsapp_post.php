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
        // MULTI-INSTÂNCIA: Encontrar uma instância conectada para criar o grupo
        $baseUrl = rtrim((string)admin_setting_get('evolution.base_url', ''), '/');
        $apiKey = (string)admin_setting_get('evolution.api_key', '');
        $instanceName = '';
        
        // Buscar todas as instâncias ativas, priorizando as que já estão marcadas como conectadas no banco
        $allInstForGroup = db()->prepare("
            SELECT instance_name, connection_status FROM whatsapp_instances 
            WHERE status = 'active' 
            ORDER BY 
                CASE WHEN connection_status = 'connected' THEN 0 ELSE 1 END ASC,
                is_default DESC, 
                id ASC
        ");
        $allInstForGroup->execute();
        $instRows = $allInstForGroup->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($instRows as $instRow) {
            $instCandidate = (string)($instRow['instance_name'] ?? '');
            if ($instCandidate === '') continue;
            try {
                $tryApi = new EvolutionApiV1($baseUrl, $apiKey, $instCandidate);
                $connStateRes = $tryApi->connectionState();
                $connJson = $connStateRes['json'] ?? [];
                $connHttpCode = (int)($connStateRes['status'] ?? 0);
                
                // Extrair estado da conexão - múltiplos formatos possíveis da Evolution API
                $connInstanceState = '';
                if (isset($connJson['instance']['state'])) {
                    $connInstanceState = (string)$connJson['instance']['state'];
                } elseif (isset($connJson['state'])) {
                    $connInstanceState = (string)$connJson['state'];
                } elseif (isset($connJson['instance']['status'])) {
                    $connInstanceState = (string)$connJson['instance']['status'];
                } elseif (isset($connJson['status']) && is_string($connJson['status'])) {
                    $connInstanceState = (string)$connJson['status'];
                }
                
                $connInstanceStateLower = strtolower(trim($connInstanceState));
                error_log("[DISPATCH] Instância '$instCandidate': connectionState HTTP=$connHttpCode, state='$connInstanceState'");
                
                if (in_array($connInstanceStateLower, ['open', 'connected'], true)) {
                    $instanceName = $instCandidate;
                    error_log("[DISPATCH] Instância conectada para criação de grupo: $instanceName");
                    break;
                }
                
                // Se estado é 'close', tentar reconectar via /instance/connect (sem QR, apenas restart de sessão)
                if ($connInstanceStateLower === 'close' || $connInstanceStateLower === 'closed') {
                    error_log("[DISPATCH] Instância '$instCandidate' com estado '$connInstanceState' — tentando reconectar...");
                    try {
                        // Tentar connect (reconecta sessão existente se ainda válida)
                        $connectRes = $tryApi->connectInstance($instCandidate);
                        $connectCode = (int)($connectRes['status'] ?? 0);
                        $connectJson = $connectRes['json'] ?? [];
                        error_log("[DISPATCH] Connect da instância '$instCandidate': HTTP $connectCode");
                        
                        // Se retornou QR code, a sessão expirou e precisa escanear novamente
                        $hasQr = isset($connectJson['base64']) || isset($connectJson['code']) || isset($connectJson['qrcode']);
                        if ($hasQr) {
                            error_log("[DISPATCH] Instância '$instCandidate' requer QR Code - sessão expirada");
                            // Atualizar status no banco para refletir realidade
                            whatsapp_update_connection_status($instCandidate, 'disconnected');
                            continue;
                        }
                        
                        // Aguardar reconexão (até 10 segundos)
                        $reconnected = false;
                        for ($attempt = 0; $attempt < 5; $attempt++) {
                            sleep(2);
                            $recheckRes = $tryApi->connectionState();
                            $recheckJson = $recheckRes['json'] ?? [];
                            $recheckState = strtolower(trim((string)($recheckJson['instance']['state'] ?? ($recheckJson['state'] ?? ''))));
                            error_log("[DISPATCH] Tentativa " . ($attempt + 1) . " de reconexão '$instCandidate': state='$recheckState'");
                            
                            if (in_array($recheckState, ['open', 'connected'], true)) {
                                $reconnected = true;
                                break;
                            }
                            // Se estado mudou para connecting, aguardar mais
                            if ($recheckState !== 'connecting' && $recheckState !== 'close') {
                                break;
                            }
                        }
                        
                        if ($reconnected) {
                            $instanceName = $instCandidate;
                            whatsapp_update_connection_status($instCandidate, 'connected');
                            error_log("[DISPATCH] ✅ Instância '$instCandidate' reconectada com sucesso!");
                            break;
                        } else {
                            error_log("[DISPATCH] Instância '$instCandidate' não reconectou.");
                            whatsapp_update_connection_status($instCandidate, 'disconnected');
                        }
                    } catch (Throwable $reconnErr) {
                        error_log("[DISPATCH] Erro ao tentar reconectar '$instCandidate': " . $reconnErr->getMessage());
                    }
                } else {
                    error_log("[DISPATCH] Instância '$instCandidate' não conectada (state='$connInstanceState')");
                }
            } catch (Throwable $e) {
                error_log("[DISPATCH] Instância '$instCandidate' erro ao verificar: " . $e->getMessage());
                continue;
            }
        }
        
        // Se nenhuma instância está conectada, mostrar erro com orientação clara
        if ($instanceName === '') {
            flash_set('error', 'Nenhuma instância WhatsApp está conectada. Vá em Configurações → WhatsApp Conexão → aba Instâncias e reconecte escaneando o QR Code.');
            header('Location: /demands_view.php?id=' . $id);
            exit;
        }
        
        if ($baseUrl === '' || $apiKey === '') {
            flash_set('error', 'Evolution API não configurada (base_url/api_key). Configure em: Configurações → WhatsApp Conexão → aba Credenciais.');
            header('Location: /demands_view.php?id=' . $id);
            exit;
        }
        
        $api = new EvolutionApiV1($baseUrl, $apiKey, $instanceName);
        
        // Gerar nome do grupo: Especialidade - Cidade/UF - N
        $location = trim($city !== '' ? ($city . ($state !== '' ? '/' . $state : '')) : $state);
        if ($location === '') $location = 'Geral';
        
        // Contar grupos existentes para gerar número sequencial
        $countStmt = db()->prepare('SELECT COUNT(*) FROM whatsapp_groups WHERE specialty = ?');
        $countStmt->execute([$specialty]);
        $groupNumber = (int)$countStmt->fetchColumn() + 1;
        
        $groupName = $specialty . ' - ' . $location . ' - ' . $groupNumber;
        
        // Buscar profissionais da especialidade para adicionar ao grupo
        // Match progressivo e flexível:
        // 1. Match exato (u.specialty = 'Fisioterapia Domiciliar')
        // 2. Especialidade contém o termo buscado (u.specialty LIKE '%Fisioterapia Domiciliar%')
        // 3. Match inverso: o termo buscado contém a especialidade do profissional
        //    ('Fisioterapia Domiciliar' LIKE '%Fisioterapia%') → pega profissionais com specialty 'Fisioterapia'
        // 4. Primeira palavra da especialidade (u.specialty LIKE 'Fisioterapia%')
        //    → pega 'Fisioterapia Geral', 'Fisioterapia Esportiva', 'Fisioterapia do Trabalho', etc.
        $firstWord = explode(' ', trim($specialty))[0];
        $profsStmt = db()->prepare("
            SELECT DISTINCT u.phone FROM users u
            INNER JOIN user_roles ur ON ur.user_id = u.id
            INNER JOIN roles r ON r.id = ur.role_id
            WHERE u.status = 'active' AND r.slug = 'profissional'
            AND (
                u.specialty = ? 
                OR u.specialty LIKE ? 
                OR ? LIKE CONCAT('%', u.specialty, '%')
                OR u.specialty LIKE ?
                OR LOWER(u.specialty) LIKE LOWER(?)
            )
            AND u.phone IS NOT NULL AND u.phone != ''
        ");
        // Ex: specialty='Fisioterapia Domiciliar' → busca exato, LIKE '%Fisioterapia Domiciliar%', 
        // 'Fisioterapia Domiciliar' LIKE '%Fisioterapia%' (match inverso), LIKE 'Fisioterapia%' (primeira palavra),
        // e LIKE '%fisioterapia%' (case-insensitive por palavra-chave principal)
        $profsStmt->execute([$specialty, '%' . $specialty . '%', $specialty, $firstWord . '%', '%' . $firstWord . '%']);
        $profPhones = $profsStmt->fetchAll(PDO::FETCH_COLUMN);
        
        error_log("[DISPATCH] Busca de profissionais para '$specialty' (firstWord='$firstWord'): encontrados " . count($profPhones) . " telefones");
        
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
        
        // Criar grupo via Evolution API (usando instância conectada detectada acima)
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
            $newGroupJid = $createData['id'] ?? ($createData['groupJid'] ?? ($createData['jid'] ?? ($createData['group']['id'] ?? '')));
            
            // Log para debug
            error_log("[DISPATCH] Resposta criação grupo: " . mb_strimwidth($createResponse, 0, 500, '...'));
            
            // Garantir que o JID termine com @g.us
            if (!empty($newGroupJid) && strpos($newGroupJid, '@') === false) {
                $newGroupJid = $newGroupJid . '@g.us';
            }
            
            // Se o JID não parece um grupo válido (formato esperado: NUMBERS-NUMBERS@g.us ou NUMBERS@g.us),
            // tentar buscar o grupo recém-criado via fetchAllGroups pelo nome
            if (!empty($newGroupJid) && strpos($newGroupJid, '-') === false) {
                error_log("[DISPATCH] JID sem hífen detectado ($newGroupJid), tentando buscar JID real via fetchAllGroups...");
                try {
                    $fetchApi = $api;
                    $allGroupsRes = $fetchApi->fetchAllGroups(false);
                    $allGroupsCode = (int)($allGroupsRes['status'] ?? 0);
                    if ($allGroupsCode >= 200 && $allGroupsCode < 300) {
                        $groupsList = $allGroupsRes['json'] ?? [];
                        // Procurar pelo nome do grupo recém-criado
                        foreach ($groupsList as $grp) {
                            $grpSubject = $grp['subject'] ?? ($grp['name'] ?? '');
                            $grpId = $grp['id'] ?? ($grp['jid'] ?? '');
                            if ($grpSubject === $groupName && strpos($grpId, '-') !== false) {
                                error_log("[DISPATCH] JID real encontrado via fetchAllGroups: $grpId (antigo: $newGroupJid)");
                                $newGroupJid = $grpId;
                                break;
                            }
                        }
                    }
                } catch (Exception $e) {
                    error_log("[DISPATCH] Erro ao buscar JID real: " . $e->getMessage());
                }
            }
            
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
                    $api2 = $api;
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
                        $api3 = $api;
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
    $tpl = "[CAPTAÇÃO #{id}]\n{title}\n\n📍 *Local:*\n{neighborhood_city}\n\n🏥 *Especialidade:* {specialty}\n📅 *Frequência:* {frequency}\n\n{ai_summary_block}👆 *Tem interesse e disponibilidade?*\nReaja a esta mensagem com qualquer emoji para demonstrar interesse. Entraremos em contato no privado para alinhar os detalhes.";
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

// ====================================================================
// SANITIZAÇÃO DA MENSAGEM: Sigilo do paciente, filtro de especialidade, remoção de valores
// ====================================================================

// Gerar iniciais do paciente (ex: "Maria Aparecida da Silva Braga" → "M.A.")
$patientName = trim((string)($d['patient_name'] ?? ''));
$patientInitials = '';
if ($patientName !== '') {
    // Pegar apenas palavras relevantes (ignorar preposições: da, de, do, dos, das)
    $nameParts = preg_split('/\s+/', $patientName);
    $prepositions = ['da', 'de', 'do', 'dos', 'das', 'e'];
    $initials = [];
    foreach ($nameParts as $part) {
        $part = trim($part);
        if ($part === '' || in_array(mb_strtolower($part), $prepositions, true)) continue;
        $initials[] = mb_strtoupper(mb_substr($part, 0, 1));
    }
    // Usar apenas as 2 primeiras iniciais (nome + primeiro sobrenome relevante)
    $patientInitials = implode('.', array_slice($initials, 0, 2)) . '.';
}

// 1. Substituir nome do paciente por iniciais no título
$titleForMsg = (string)$d['title'];
if ($patientName !== '') {
    // Substituir o nome completo pelas iniciais
    $titleForMsg = str_ireplace($patientName, $patientInitials, $titleForMsg);
}
// Se ainda tem "para [texto que parece nome]" (caso o nome no título seja diferente do banco)
// Substituir por "para [iniciais]"
if ($patientInitials !== '') {
    $titleForMsg = preg_replace('/\s+para\s+[A-ZÀ-ÚÇ][a-zà-úç]+(\s+[A-ZÀ-Úa-zà-úç]+){1,5}\s*$/iu', ' para ' . $patientInitials, $titleForMsg);
}
// Limpar espaços
$titleForMsg = trim(preg_replace('/\s{2,}/', ' ', $titleForMsg));

// Substituir "multidisciplinar" já que estamos filtrando para 1 especialidade
$titleForMsg = preg_replace('/\s*multidisciplinar\s*/iu', ' ', $titleForMsg);
$titleForMsg = trim(preg_replace('/\s{2,}/', ' ', $titleForMsg));

// Montar título genérico com a especialidade se ficou muito curto
if (mb_strlen($titleForMsg) < 15 && $specialty !== '') {
    $titleForMsg = $titleForMsg . ' de ' . $specialty;
}

// 2. Sanitizar ai_summary: remover nome do paciente, filtrar por especialidade, remover valores
$aiSummaryRaw = trim((string)($d['ai_summary'] ?? ''));
$aiSummarySanitized = $aiSummaryRaw;

if ($aiSummarySanitized !== '') {
    // 2a. Substituir nome do paciente por iniciais no quadro clínico
    if ($patientName !== '') {
        $aiSummarySanitized = str_ireplace($patientName, $patientInitials, $aiSummarySanitized);
    }
    // Limpar padrões residuais
    $aiSummarySanitized = preg_replace('/,\s*,/', ',', $aiSummarySanitized);
    
    // Reformatar início: "O paciente M.A., 72 anos" ou "O paciente, 72 anos" → "O paciente de, 72 anos"
    // Manter o formato "O paciente [iniciais], [idade] anos"
    $aiSummarySanitized = preg_replace('/^(?:O\s+)?(?:paciente|Paciente)\s*(?:' . preg_quote($patientInitials, '/') . '\s*)?,?\s*(\d+\s*anos)/iu', 'O paciente ' . $patientInitials . ', $1', $aiSummarySanitized);
    // Se não começa com "O paciente" ainda (ex: texto começava com nome direto), prefixar
    if (!preg_match('/^O\s+paciente\b/iu', $aiSummarySanitized)) {
        // Tentar extrair idade do texto
        if (preg_match('/(\d{1,3})\s*anos/iu', $aiSummarySanitized, $ageMatch)) {
            $age = $ageMatch[1];
            // Remover a idade duplicada se já estiver no início do texto
            $aiSummarySanitized = preg_replace('/^[^.]*?\d{1,3}\s*anos\s*,?\s*/iu', '', $aiSummarySanitized);
            $aiSummarySanitized = 'O paciente ' . $patientInitials . ', ' . $age . ' anos, ' . ltrim($aiSummarySanitized, ', ');
        } else {
            $aiSummarySanitized = 'O paciente ' . $patientInitials . '. ' . ltrim($aiSummarySanitized, ', ');
        }
    }
    
    $aiSummarySanitized = preg_replace('/\s{2,}/', ' ', $aiSummarySanitized);
    
    // 2b. Remover TODOS os valores financeiros
    // Remove frases inteiras sobre valor autorizado
    $aiSummarySanitized = preg_replace('/[^.\n]*valor\s+autorizado[^.\n]*\.?/iu', '', $aiSummarySanitized);
    // Remove frases sobre valor do plano, custo, preço
    $aiSummarySanitized = preg_replace('/[^.\n]*(?:valor|custo|preço)\s+(?:é|será|de|do\s+plano|mensal|total)[^.\n]*\.?/iu', '', $aiSummarySanitized);
    // Remove R$ com valor
    $aiSummarySanitized = preg_replace('/,?\s*(?:no valor de|de|é de|será de|valor de)?\s*R\$\s*[\d.,]+(?:\s*(?:por|\/)\s*(?:mês|sessão|dia|semana|hora|atendimento))?/iu', '', $aiSummarySanitized);
    // Remove valores numéricos em formato monetário sem R$ (ex: "500,00 por mês", "11.500,00 por mês")
    $aiSummarySanitized = preg_replace('/\.?\s*\d{1,3}(?:\.\d{3})*,\d{2}\s*(?:por|\/)\s*(?:mês|sessão|dia|semana|hora|atendimento)/iu', '', $aiSummarySanitized);
    // Remove "valor" seguido de número com vírgula decimal
    $aiSummarySanitized = preg_replace('/,?\s*(?:valor|custo)\s*(?:de|:)?\s*\d{1,3}(?:\.\d{3})*,\d{2}\s*(?:reais|por\s+\w+)?/iu', '', $aiSummarySanitized);
    // Remove R$ sozinho que pode ter ficado
    $aiSummarySanitized = preg_replace('/R\$\s*[\d.,]+/iu', '', $aiSummarySanitized);
    // Limpar pontuação solta
    $aiSummarySanitized = preg_replace('/\.\s*\./', '.', $aiSummarySanitized);
    $aiSummarySanitized = preg_replace('/,\s*\./', '.', $aiSummarySanitized);
    $aiSummarySanitized = preg_replace('/,\s*,/', ',', $aiSummarySanitized);
    
    // 2c. Filtrar por especialidade: manter apenas informações da especialidade da captação
    // Separar por frases/sentenças e filtrar as que mencionam outras especialidades
    $specialtiesAll = ['fisioterapia', 'fonoaudiologia', 'enfermagem', 'psicologia', 'terapia ocupacional', 
                       'nutrição', 'nutri', 'medicina', 'médico', 'técnico de enfermagem'];
    // Identificar a especialidade da captação atual (normalizar para match)
    $currentSpecNormalized = mb_strtolower(trim(explode(' ', $specialty)[0])); // Ex: "fisioterapia" de "Fisioterapia Domiciliar"
    
    // Outras especialidades (todas exceto a atual)
    $otherSpecs = array_filter($specialtiesAll, function($s) use ($currentSpecNormalized) {
        return mb_strpos($s, $currentSpecNormalized) === false && mb_strpos($currentSpecNormalized, $s) === false;
    });
    
    if (!empty($otherSpecs)) {
        // Tratar o texto por sentenças (separadas por . ou ,)
        // Abordagem: remover trechos de listas multidisciplinares que mencionam outras especialidades
        // Ex: "incluindo fisioterapia (3x/semana), fonoaudiologia (2x/semana), enfermagem (diária) e psicologia (1x/semana)"
        // Se captação é de fisioterapia, remover "fonoaudiologia (2x/semana), enfermagem (diária) e psicologia (1x/semana)"
        
        // Padrão: remover itens de lista separados por vírgula ou "e" que contenham outras especialidades
        foreach ($otherSpecs as $otherSpec) {
            // Remove "especialidade (frequência)," ou ", especialidade (frequência)" ou "e especialidade (frequência)"
            $aiSummarySanitized = preg_replace('/,?\s*(?:e\s+)?' . preg_quote($otherSpec, '/') . '\s*\([^)]*\)/iu', '', $aiSummarySanitized);
            // Remove menções simples como "especialidade X vezes/semana"
            $aiSummarySanitized = preg_replace('/,?\s*(?:e\s+)?' . preg_quote($otherSpec, '/') . '\s+\d+[x×]\s*[\/\\\\]\s*\w+/iu', '', $aiSummarySanitized);
        }
        
        // Ajustar "incluindo fisioterapia (3x/semana)" → limpar "incluindo" se sobrou só uma especialidade
        $aiSummarySanitized = preg_replace('/incluindo\s+(' . preg_quote(explode(' ', $specialty)[0], '/') . ')/iu', '$1', $aiSummarySanitized);
        
        // Substituir "atendimento multidisciplinar" por "atendimento" quando filtramos para 1 especialidade
        $aiSummarySanitized = preg_replace('/atendimento\s+multidisciplinar\s+domiciliar/iu', 'atendimento domiciliar', $aiSummarySanitized);
        $aiSummarySanitized = preg_replace('/atendimento\s+multidisciplinar/iu', 'atendimento', $aiSummarySanitized);
        
        // Remover "solicita-se atendimento domiciliar, " ficando com vírgula solta
        $aiSummarySanitized = preg_replace('/,\s*,/', ',', $aiSummarySanitized);
        $aiSummarySanitized = preg_replace('/,\s*\./', '.', $aiSummarySanitized);
        $aiSummarySanitized = preg_replace('/\.\s*,/', '.', $aiSummarySanitized);
    }
    
    // Limpar espaços e linhas extras
    $aiSummarySanitized = preg_replace('/\n{3,}/', "\n\n", $aiSummarySanitized);
    $aiSummarySanitized = preg_replace('/\s{2,}/', ' ', $aiSummarySanitized);
    $aiSummarySanitized = trim($aiSummarySanitized);
}

$repl = [
    '{id}' => (string)$d['id'],
    '{title}' => $titleForMsg,
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
    '{ai_summary}' => mb_strimwidth($aiSummarySanitized, 0, 800, '...'),
    '{ai_summary_block}' => $aiSummarySanitized !== ''
        ? "📋 *Quadro Clínico:*\n" . mb_strimwidth($aiSummarySanitized, 0, 800, '...') . "\n\n"
        : '',
    '{captation_note}' => trim((string)($d['captation_note'] ?? '')),
    '{captation_note_block}' => trim((string)($d['captation_note'] ?? '')) !== ''
        ? "📝 *Observação:*\n" . trim((string)($d['captation_note'] ?? '')) . "\n\n"
        : '',
    '{origin}' => (string)($d['origin_email'] ?? ''),
];

$msg = strtr($tpl, $repl);

// Se o template customizado não contém placeholder de quadro clínico mas existe conteúdo,
// anexar automaticamente antes do CTA (👆 Tem interesse)
if ($aiSummarySanitized !== '' && strpos($tpl, '{ai_summary') === false) {
    $clinicalBlock = "\n\n📋 *Quadro Clínico:*\n" . mb_strimwidth($aiSummarySanitized, 0, 800, '...');
    // Inserir antes do CTA se existir, senão no final
    $ctaPos = mb_strpos($msg, '👆');
    if ($ctaPos === false) {
        $ctaPos = mb_strpos($msg, '*Tem interesse');
    }
    if ($ctaPos !== false) {
        $msg = mb_substr($msg, 0, $ctaPos) . $clinicalBlock . "\n\n" . mb_substr($msg, $ctaPos);
    } else {
        $msg .= $clinicalBlock;
    }
}

// Se o template não contém placeholder de observação mas existe conteúdo, anexar automaticamente
$captationNote = trim((string)($d['captation_note'] ?? ''));
if ($captationNote !== '' && strpos($tpl, '{captation_note') === false) {
    $noteBlock = "\n\n📝 *Observação:*\n" . $captationNote;
    $ctaPos = mb_strpos($msg, '👆');
    if ($ctaPos === false) {
        $ctaPos = mb_strpos($msg, '*Tem interesse');
    }
    if ($ctaPos !== false) {
        $msg = mb_substr($msg, 0, $ctaPos) . $noteBlock . "\n\n" . mb_substr($msg, $ctaPos);
    } else {
        $msg .= $noteBlock;
    }
}

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
            // Usar a primeira instância conectada disponível para sincronizar membros
            $syncApi = null;
            $syncInstRows = db()->prepare("
                SELECT instance_name, connection_status FROM whatsapp_instances 
                WHERE status = 'active' 
                ORDER BY CASE WHEN connection_status = 'connected' THEN 0 ELSE 1 END ASC, is_default DESC, id ASC
            ");
            $syncInstRows->execute();
            $syncInstList = $syncInstRows->fetchAll(PDO::FETCH_ASSOC);
            foreach ($syncInstList as $syncInstRow) {
                $syncInstName = (string)($syncInstRow['instance_name'] ?? '');
                if ($syncInstName === '') continue;
                try {
                    $trySyncApi = new EvolutionApiV1(
                        rtrim((string)admin_setting_get('evolution.base_url', ''), '/'),
                        (string)admin_setting_get('evolution.api_key', ''),
                        $syncInstName
                    );
                    $syncConn = $trySyncApi->connectionState();
                    $syncConnJson = $syncConn['json'] ?? [];
                    $syncState = (string)($syncConnJson['instance']['state'] ?? ($syncConnJson['state'] ?? ''));
                    if (in_array(strtolower(trim($syncState)), ['open', 'connected'], true)) {
                        $syncApi = $trySyncApi;
                        break;
                    }
                } catch (Throwable $e) { continue; }
            }
            // Fallback: usar instância marcada como connected no banco
            if ($syncApi === null) {
                foreach ($syncInstList as $syncInstRow) {
                    if (($syncInstRow['connection_status'] ?? '') === 'connected' && ($syncInstRow['instance_name'] ?? '') !== '') {
                        try {
                            $syncApi = new EvolutionApiV1(
                                rtrim((string)admin_setting_get('evolution.base_url', ''), '/'),
                                (string)admin_setting_get('evolution.api_key', ''),
                                (string)$syncInstRow['instance_name']
                            );
                            break;
                        } catch (Throwable $e) { /* skip */ }
                    }
                }
            }
            if ($syncApi === null) {
                $syncApi = new EvolutionApiV1(); // último fallback
            }
            
            // Buscar profissionais compatíveis com a especialidade (mesmo match progressivo e flexível)
            $firstWord = explode(' ', trim($specialty))[0];
            $syncProfsStmt = db()->prepare("
                SELECT DISTINCT u.phone FROM users u
                INNER JOIN user_roles ur ON ur.user_id = u.id
                INNER JOIN roles r ON r.id = ur.role_id
                WHERE u.status = 'active' AND r.slug = 'profissional'
                AND (
                    u.specialty = ? 
                    OR u.specialty LIKE ? 
                    OR ? LIKE CONCAT('%', u.specialty, '%')
                    OR u.specialty LIKE ?
                    OR LOWER(u.specialty) LIKE LOWER(?)
                )
                AND u.phone IS NOT NULL AND u.phone != ''
            ");
            $syncProfsStmt->execute([$specialty, '%' . $specialty . '%', $specialty, $firstWord . '%', '%' . $firstWord . '%']);
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
// MULTI-INSTÂNCIA: Tentar todas as instâncias conectadas, não apenas a padrão
$api = null;
$apiInstanceName = null;
try {
    // Buscar todas as instâncias ativas
    $allInstances = db()->prepare("
        SELECT instance_name, token, is_default, connection_status 
        FROM whatsapp_instances 
        WHERE status = 'active' 
        ORDER BY 
            CASE WHEN connection_status = 'connected' THEN 0 ELSE 1 END ASC,
            is_default DESC, 
            id ASC
    ");
    $allInstances->execute();
    $instances = $allInstances->fetchAll();
    
    $baseUrl = (string)admin_setting_get('evolution.base_url', '');
    $apiKey = (string)admin_setting_get('evolution.api_key', '');
    
    // Tentar cada instância até encontrar uma conectada
    foreach ($instances as $inst) {
        $instName = (string)($inst['instance_name'] ?? '');
        if ($instName === '') continue;
        
        try {
            $tryApi = new EvolutionApiV1($baseUrl, $apiKey, $instName);
            $connState = $tryApi->connectionState();
            $connJson = $connState['json'] ?? [];
            
            // Extrair estado - múltiplos formatos possíveis
            $connInstanceState = '';
            if (isset($connJson['instance']['state'])) {
                $connInstanceState = (string)$connJson['instance']['state'];
            } elseif (isset($connJson['state'])) {
                $connInstanceState = (string)$connJson['state'];
            } elseif (isset($connJson['instance']['status'])) {
                $connInstanceState = (string)$connJson['instance']['status'];
            } elseif (isset($connJson['status']) && is_string($connJson['status'])) {
                $connInstanceState = (string)$connJson['status'];
            }
            
            $connInstanceStateLower = strtolower(trim($connInstanceState));
            
            if (in_array($connInstanceStateLower, ['open', 'connected'], true)) {
                $api = $tryApi;
                $apiInstanceName = $instName;
                error_log("[DISPATCH] Instância conectada encontrada: $instName");
                break;
            }
            
            // Se estado é 'close', tentar reconectar
            if ($connInstanceStateLower === 'close' || $connInstanceStateLower === 'closed') {
                error_log("[DISPATCH-SEND] Instância '$instName' com estado '$connInstanceState' — tentando reconectar...");
                try {
                    $connectRes = $tryApi->connectInstance($instName);
                    $connectCode = (int)($connectRes['status'] ?? 0);
                    $connectJson = $connectRes['json'] ?? [];
                    error_log("[DISPATCH-SEND] Connect '$instName': HTTP $connectCode");
                    
                    // Se retornou QR code, sessão expirou
                    $hasQr = isset($connectJson['base64']) || isset($connectJson['code']) || isset($connectJson['qrcode']);
                    if (!$hasQr) {
                        for ($retryAttempt = 0; $retryAttempt < 5; $retryAttempt++) {
                            sleep(2);
                            $recheckRes = $tryApi->connectionState();
                            $recheckState = strtolower(trim((string)(($recheckRes['json']['instance']['state'] ?? ($recheckRes['json']['state'] ?? '')))));
                            if (in_array($recheckState, ['open', 'connected'], true)) {
                                $api = $tryApi;
                                $apiInstanceName = $instName;
                                whatsapp_update_connection_status($instName, 'connected');
                                error_log("[DISPATCH-SEND] ✅ Instância '$instName' reconectada!");
                                break 2; // sai do for e do foreach
                            }
                            if ($recheckState !== 'connecting' && $recheckState !== 'close') break;
                        }
                    } else {
                        whatsapp_update_connection_status($instName, 'disconnected');
                    }
                } catch (Throwable $reconnErr) {
                    error_log("[DISPATCH-SEND] Erro ao reconectar '$instName': " . $reconnErr->getMessage());
                }
            }
            
            error_log("[DISPATCH] Instância '$instName' não conectada (state=$connInstanceState), tentando próxima...");
        } catch (Throwable $instErr) {
            error_log("[DISPATCH] Erro ao verificar instância '$instName': " . $instErr->getMessage());
            continue;
        }
    }
    
    // Fallback: se nenhuma instância do banco funcionou, tentar a instância padrão das admin_settings
    if ($api === null) {
        $defaultInstName = (string)admin_setting_get('evolution.instance', '');
        if ($defaultInstName !== '') {
            try {
                $tryApi = new EvolutionApiV1();
                $connState = $tryApi->connectionState();
                $connJson = $connState['json'] ?? [];
                $connInstanceState = (string)($connJson['instance']['state'] ?? ($connJson['state'] ?? ''));
                $connInstanceStateLower = strtolower(trim($connInstanceState));
                if (in_array($connInstanceStateLower, ['open', 'connected'], true)) {
                    $api = $tryApi;
                    $apiInstanceName = $defaultInstName;
                    error_log("[DISPATCH] Instância padrão admin_settings conectada: $defaultInstName");
                }
            } catch (Throwable $defErr) {
                error_log("[DISPATCH] Instância padrão admin_settings falhou: " . $defErr->getMessage());
            }
        }
        
        // Último recurso: usar instância que está como 'connected' no banco sem verificar API
        if ($api === null) {
            foreach ($instances as $inst) {
                $instName = (string)($inst['instance_name'] ?? '');
                $instConnStatus = (string)($inst['connection_status'] ?? '');
                if ($instName !== '' && $instConnStatus === 'connected') {
                    try {
                        $api = new EvolutionApiV1($baseUrl, $apiKey, $instName);
                        $apiInstanceName = $instName;
                        error_log("[DISPATCH] Usando instância '$instName' baseado no connection_status do banco (API não confirmou)");
                        break;
                    } catch (Throwable $e) {
                        continue;
                    }
                }
            }
        }
    }
    
    // Se nenhuma instância está conectada, reverter e avisar
    if ($api === null) {
        db()->prepare('UPDATE demand_dispatch_logs SET dispatch_status = \'error\', error_message = \'Nenhuma instância WhatsApp conectada\' WHERE demand_id = :did AND dispatch_status = \'queued\'')
            ->execute(['did' => $id]);
        
        // Reverter status da demanda
        if ((string)$d['status'] === 'aguardando_captacao') {
            db()->prepare('UPDATE demands SET status = \'aguardando_captacao\', assumed_by_user_id = NULL, assumed_at = NULL WHERE id = :id')
                ->execute(['id' => $id]);
        }
        
        flash_set('error', 'Nenhuma instância WhatsApp está conectada. Verifique em: Configurações → WhatsApp Conexão → aba Conexão.');
        header('Location: /demands_view.php?id=' . $id);
        exit;
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
        $res = $api->sendTextToGroup($jid, $msgRow);
        $ok = isset($res['status']) && (int)$res['status'] >= 200 && (int)$res['status'] < 300;
        
        // Se falhou e o JID não tem hífen (possível LID), tentar buscar JID real
        if (!$ok && strpos($jid, '-') === false) {
            error_log("[DISPATCH] Envio falhou para JID sem hífen ($jid). Buscando JID real via fetchAllGroups...");
            try {
                // Buscar nome do grupo no banco para fazer match
                $stmtGrpName = db()->prepare('SELECT name FROM whatsapp_groups WHERE evolution_group_jid = ?');
                $stmtGrpName->execute([$jid]);
                $grpNameRow = $stmtGrpName->fetch();
                $grpName = $grpNameRow ? $grpNameRow['name'] : '';
                
                if ($grpName !== '') {
                    $allGroupsRes = $api->fetchAllGroups(false);
                    $allGroupsCode = (int)($allGroupsRes['status'] ?? 0);
                    if ($allGroupsCode >= 200 && $allGroupsCode < 300) {
                        $groupsList = $allGroupsRes['json'] ?? [];
                        foreach ($groupsList as $grp) {
                            $grpSubject = $grp['subject'] ?? ($grp['name'] ?? '');
                            $grpId = $grp['id'] ?? ($grp['jid'] ?? '');
                            if ($grpSubject === $grpName && $grpId !== '' && $grpId !== $jid) {
                                error_log("[DISPATCH] JID real encontrado: $grpId (antigo: $jid). Atualizando banco e reenviando...");
                                // Atualizar o JID no banco
                                db()->prepare('UPDATE whatsapp_groups SET evolution_group_jid = ? WHERE evolution_group_jid = ?')
                                    ->execute([$grpId, $jid]);
                                // Tentar enviar com o JID correto
                                usleep(500000);
                                $res = $api->sendTextToGroup($grpId, $msgRow);
                                $ok = isset($res['status']) && (int)$res['status'] >= 200 && (int)$res['status'] < 300;
                                if ($ok) {
                                    $jid = $grpId; // Usar JID correto para salvar em chat_messages
                                }
                                break;
                            }
                        }
                    }
                }
            } catch (Throwable $lookupErr) {
                error_log("[DISPATCH] Erro ao buscar JID real: " . $lookupErr->getMessage());
            }
        }
        
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

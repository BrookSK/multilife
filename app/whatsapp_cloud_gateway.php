<?php

declare(strict_types=1);

/**
 * "Servidor" da API oficial dentro do próprio PHP.
 *
 * O resto do sistema fala com o WhatsApp pelas rotas da Evolution v2
 * (POST /message/sendText/{instância}, GET /group/participants/{instância}...).
 * No modo API oficial, WhatsAppApi::request() e http_json_request() entregam
 * essas chamadas para wa_cloud_gateway(), que faz o equivalente na Graph API da
 * Meta e devolve a resposta no MESMO formato. Assim captação, chat, grupos,
 * avisos automáticos e cron funcionam sem mudar cada tela.
 *
 * O que muda por regra da Meta (e como fica):
 *  - mensagem livre só até 24 h depois da última mensagem do contato: depois
 *    disso o envio sai pelo modelo aprovado de "Aviso" (texto numa variável);
 *  - grupo: ninguém é adicionado direto. "Adicionar participante" vira envio do
 *    link de convite para a pessoa (texto ou modelo "Convite para grupo");
 *    máximo 8 participantes; sem administradores nem "somente admins";
 *  - não há QR Code, histórico antigo, foto de perfil, apagar/editar mensagem.
 */

/** @return array{status:int, headers_raw:string, body_raw:string, json:mixed} */
function wa_cg_resp(int $status, $json): array
{
    $body = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    return ['status' => $status, 'headers_raw' => '', 'body_raw' => (string)$body, 'json' => json_decode((string)$body, true)];
}

function wa_cg_error(int $status, string $message, array $extra = []): array
{
    $reason = [400 => 'Bad Request', 401 => 'Unauthorized', 404 => 'Not Found', 429 => 'Too Many Requests', 500 => 'Internal Server Error', 501 => 'Not Implemented', 502 => 'Bad Gateway'][$status] ?? 'Error';
    return wa_cg_resp($status, ['status' => $status, 'error' => $reason, 'response' => ['message' => $message]] + $extra);
}

/** Erro da Meta repassado (status HTTP da Meta, texto em português). */
function wa_cg_meta_error(array $res, string $prefix = ''): array
{
    $status = (int)($res['status'] ?? 0);
    if ($status < 400) {
        $status = 502;
    }
    $j = is_array($res['json'] ?? null) ? $res['json'] : [];
    return wa_cg_error($status, ($prefix !== '' ? $prefix . ': ' : '') . wa_cloud_error_text($res), [
        'cloud_error' => is_array($j['error'] ?? null) ? $j['error'] : ['message' => (string)($res['error'] ?? '')],
    ]);
}

/** Ponto de entrada: mesma assinatura de WhatsAppApi::request(). */
function wa_cloud_gateway(string $method, string $path, array $query = [], $body = null, int $timeoutSeconds = 30): array
{
    wa_cloud_ensure_schema();
    $method = strtoupper($method);
    // Caminho pode vir com ?query (chamadas antigas montavam a URL inteira)
    $qPos = strpos($path, '?');
    if ($qPos !== false) {
        parse_str(substr($path, $qPos + 1), $fromPath);
        $query = array_merge(is_array($fromPath) ? $fromPath : [], $query);
        $path = substr($path, 0, $qPos);
    }
    if (is_string($body)) {
        $decoded = json_decode($body, true);
        $body = is_array($decoded) ? $decoded : [];
    } elseif (is_object($body)) {
        $body = json_decode((string)json_encode($body), true) ?: [];
    } elseif (!is_array($body)) {
        $body = [];
    }
    $seg = array_values(array_filter(explode('/', trim($path, '/')), static fn($s) => $s !== ''));
    $group = $seg[0] ?? '';
    $action = $seg[1] ?? '';
    $instName = isset($seg[2]) ? rawurldecode($seg[2]) : '';
    $extra = $seg[3] ?? '';

    if ($group === '' || $group === 'health') {
        $h = wa_cloud_health(6);
        return wa_cg_resp(200, ['status' => 200, 'message' => 'API oficial do WhatsApp (Cloud API)', 'clientName' => 'cloud-api',
            'version' => wa_cloud_graph_version(), 'ready' => $h['ok'], 'phase' => $h['ok'] ? 'pronto' : $h['error'], 'error' => $h['ok'] ? null : $h['error']]);
    }

    try {
        // Rotas sem instância
        if ($group === 'instance' && $action === 'fetchInstances') {
            return wa_cg_fetch_instances((string)($query['instanceName'] ?? ''));
        }
        if ($group === 'instance' && $action === 'create') {
            $name = (string)($body['instanceName'] ?? '');
            return wa_cloud_instance($name) !== null
                ? wa_cg_resp(201, ['instance' => ['instanceName' => $name, 'status' => 'open', 'integration' => 'WHATSAPP-CLOUD'], 'hash' => ['apikey' => 'cloud-api']])
                : wa_cg_error(400, 'Na API oficial o número é cadastrado em Configurações → WhatsApp → API oficial (escolha o número da conta da Meta).');
        }

        $row = wa_cloud_instance($instName);
        if ($row === null) {
            return wa_cg_error(404, 'The "' . $instName . '" instance does not exist (número não cadastrado na API oficial: Configurações → WhatsApp → API oficial).');
        }

        switch ($group . '/' . $action) {
            // ----- instância -----
            case 'instance/connect':
            case 'instance/connectionState':
                return wa_cg_connection_state($row, $action === 'connect');
            case 'instance/restart':
                return wa_cg_resp(200, ['instance' => ['instanceName' => $instName, 'state' => 'open'], 'message' => 'Na API oficial não há reconexão: o número fica sempre ativo na Meta.']);
            case 'instance/logout':
                return wa_cg_error(400, wa_provider_cannot_reason('logout'));
            case 'instance/delete':
                return wa_cg_resp(200, ['status' => 'SUCCESS', 'error' => false, 'response' => ['message' => 'Removido só do sistema (o número continua na conta da Meta).']]);

            // ----- webhook / opções: configurados no app da Meta -----
            case 'webhook/set':
            case 'webhook/find':
                return wa_cg_resp(200, ['webhook' => ['enabled' => true, 'url' => wa_cloud_webhook_url(), 'webhookBase64' => true, 'webhookByEvents' => false,
                    'events' => defined('WHATSAPP_WEBHOOK_EVENTS') ? WHATSAPP_WEBHOOK_EVENTS : []], 'note' => 'Na API oficial o webhook é configurado no app da Meta.']);
            case 'settings/set':
            case 'settings/find':
                return wa_cg_resp(200, ['settings' => ['rejectCall' => false, 'groupsIgnore' => false, 'alwaysOnline' => true, 'readMessages' => false, 'syncFullHistory' => false]]);

            // ----- mensagens -----
            case 'message/sendText':
                return wa_cg_send_text($row, $body);
            case 'message/sendMedia':
                return wa_cg_send_media($row, $body);
            case 'message/sendWhatsAppAudio':
                return wa_cg_send_media($row, ['number' => $body['number'] ?? '', 'mediatype' => 'audio', 'media' => $body['audio'] ?? '', 'ptt' => true, 'quoted' => $body['quoted'] ?? null]);
            case 'message/sendSticker':
                return wa_cg_send_media($row, ['number' => $body['number'] ?? '', 'mediatype' => 'sticker', 'media' => $body['sticker'] ?? '']);
            case 'message/sendReaction':
                return wa_cg_send_reaction($row, $body);
            case 'message/sendLocation':
                return wa_cg_send_location($row, $body);
            case 'message/sendContact':
                return wa_cg_send_contact($row, $body);
            case 'message/sendTemplate':
                return wa_cg_send_template($row, $body);
            case 'message/sendButtons':
                return wa_cg_send_buttons($row, $body);
            case 'message/sendList':
                return wa_cg_send_list($row, $body);
            case 'message/sendPoll':
                return wa_cg_error(400, wa_provider_cannot_reason('poll'));
            case 'message/sendStatus':
                return wa_cg_error(400, wa_provider_cannot_reason('status'));

            // ----- conversas -----
            case 'chat/findChats':
                return wa_cg_find_chats($row);
            case 'chat/findMessages':
                return wa_cg_find_messages($row, $body);
            case 'chat/getBase64FromMediaMessage':
                return wa_cg_get_media($row, $body);
            case 'chat/markMessageAsRead':
                return wa_cg_mark_read($row, $body);
            case 'chat/whatsappNumbers':
                $out = [];
                foreach ((array)($body['numbers'] ?? []) as $n) {
                    $t = wa_cloud_target((string)$n);
                    $out[] = ['exists' => true, 'jid' => wa_cloud_contact_jid($t['to'] ?? null, $t['recipient'] ?? null), 'number' => (string)$n,
                        'note' => wa_provider_cannot_reason('check_number')];
                }
                return wa_cg_resp(200, $out);
            case 'chat/fetchProfilePictureUrl':
                return wa_cg_resp(200, ['wuid' => (string)($body['number'] ?? ''), 'profilePictureUrl' => null]);
            case 'chat/fetchProfile':
                return wa_cg_fetch_profile($body + $query);
            case 'chat/findContacts':
                return wa_cg_find_contacts($row, $body);
            case 'chat/archiveChat':
                return wa_cg_resp(200, ['chatId' => '', 'archived' => (bool)($body['archive'] ?? true)]);
            case 'chat/findStatusMessage':
                return wa_cg_resp(200, []);
            case 'chat/deleteMessageForEveryone':
                return wa_cg_error(400, wa_provider_cannot_reason('delete_message'));
            case 'chat/updateMessage':
                return wa_cg_error(400, wa_provider_cannot_reason('edit_message'));

            // ----- grupos -----
            case 'group/create':
                return wa_cg_group_create($row, $body, $timeoutSeconds);
            case 'group/fetchAllGroups':
                return wa_cg_group_list($row, in_array(strtolower((string)($query['getParticipants'] ?? 'false')), ['1', 'true'], true));
            case 'group/findGroupInfos':
                return wa_cg_group_info($row, (string)($query['groupJid'] ?? ''), false);
            case 'group/participants':
                return wa_cg_group_info($row, (string)($query['groupJid'] ?? ''), true);
            case 'group/updateParticipant':
                $act = strtolower((string)($body['action'] ?? $extra));
                return wa_cg_group_participants($row, (string)($body['groupJid'] ?? ($query['groupJid'] ?? '')), $act, (array)($body['participants'] ?? []), !empty($body['forceInvite']));
            case 'group/updateSetting':
                return wa_cg_resp(200, ['skipped' => true, 'message' => wa_provider_cannot_reason('group_announcement')]);
            case 'group/updateGroupSubject':
                return wa_cg_group_update($row, (string)($query['groupJid'] ?? ($body['groupJid'] ?? '')), ['subject' => mb_substr((string)($body['subject'] ?? ''), 0, 128)]);
            case 'group/updateGroupDescription':
                return wa_cg_group_update($row, (string)($query['groupJid'] ?? ($body['groupJid'] ?? '')), ['description' => mb_substr((string)($body['description'] ?? ''), 0, 2048)]);
            case 'group/updateGroupPicture':
                return wa_cg_group_picture($row, (string)($query['groupJid'] ?? ($body['groupJid'] ?? '')), (string)($body['image'] ?? ''));
            case 'group/inviteCode':
                return wa_cg_group_invite($row, (string)($query['groupJid'] ?? ''), false);
            case 'group/revokeInviteCode':
                return wa_cg_group_invite($row, (string)($query['groupJid'] ?? ($body['groupJid'] ?? '')), true);
            case 'group/sendInvite':
                return wa_cg_group_send_invites($row, (string)($body['groupJid'] ?? ''), (array)($body['numbers'] ?? []), (string)($body['description'] ?? ''));
            case 'group/delete':
                return wa_cg_group_delete($row, (string)($query['groupJid'] ?? ($body['groupJid'] ?? '')));
            case 'group/leaveGroup':
                return wa_cg_error(400, wa_provider_cannot_reason('group_leave'));
            case 'group/toggleEphemeral':
                return wa_cg_error(400, 'A API oficial não tem mensagens temporárias em grupo.');
            case 'group/acceptInviteCode':
            case 'group/inviteInfo':
                return wa_cg_error(400, 'Na API oficial o número da empresa não entra em grupos por convite.');
        }
        return wa_cg_error(404, 'Rota não disponível na API oficial: ' . $method . ' /' . implode('/', array_slice($seg, 0, 2)));
    } catch (Throwable $e) {
        error_log('[WA_CLOUD_GATEWAY] ' . $path . ': ' . $e->getMessage());
        return wa_cg_error(500, 'Erro interno na integração com a API oficial: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Instâncias
// ---------------------------------------------------------------------------

function wa_cg_instance_view(array $row): array
{
    $display = (string)preg_replace('/\D+/', '', (string)($row['cloud_display_phone'] ?? ($row['owner_phone_formatted'] ?? '')));
    $open = strtolower((string)($row['connection_status'] ?? 'connected')) !== 'disconnected';
    return [
        'id' => (string)($row['id'] ?? ''),
        'name' => (string)$row['instance_name'],
        'instanceName' => (string)$row['instance_name'],
        'connectionStatus' => $open ? 'open' : 'close',
        'ownerJid' => $display !== '' ? $display . '@s.whatsapp.net' : null,
        'owner' => $display !== '' ? $display . '@s.whatsapp.net' : null,
        'profileName' => (string)($row['cloud_verified_name'] ?? ''),
        'integration' => 'WHATSAPP-CLOUD',
        'number' => $display,
        'cloudPhoneNumberId' => (string)$row['cloud_phone_number_id'],
        'Webhook' => ['url' => wa_cloud_webhook_url(), 'enabled' => true],
    ];
}

function wa_cg_fetch_instances(string $only): array
{
    $out = [];
    foreach (wa_cloud_instances() as $row) {
        if ($only !== '' && (string)$row['instance_name'] !== $only) {
            continue;
        }
        $out[] = wa_cg_instance_view($row);
    }
    return wa_cg_resp(200, $out);
}

/** Situação do número na Meta (guardada por 60 s). */
function wa_cg_connection_state(array $row, bool $isConnect): array
{
    $name = (string)$row['instance_name'];
    $checked = strtotime((string)($row['cloud_checked_at'] ?? '')) ?: 0;
    $state = (string)($row['cloud_state'] ?? '');
    $info = null;
    if ($state === '' || time() - $checked > 60) {
        $res = wa_cloud_phone_info((string)$row['cloud_phone_number_id'], 8);
        if (wa_cloud_ok($res)) {
            $info = (array)$res['json'];
            $state = strtoupper((string)($info['status'] ?? 'CONNECTED')) ?: 'CONNECTED';
            $display = (string)preg_replace('/\D+/', '', (string)($info['display_phone_number'] ?? ''));
            try {
                db()->prepare("UPDATE whatsapp_instances SET cloud_state = ?, cloud_checked_at = NOW(), cloud_quality = ?, cloud_verified_name = COALESCE(?, cloud_verified_name),
                                   cloud_display_phone = COALESCE(NULLIF(?, ''), cloud_display_phone), connection_status = ?,
                                   owner_phone_formatted = COALESCE(NULLIF(?, ''), owner_phone_formatted), owner_number = COALESCE(NULLIF(?, ''), owner_number)
                               WHERE instance_name = ?")
                    ->execute([$state, $info['quality_rating'] ?? null, $info['verified_name'] ?? null, $display,
                        in_array($state, ['DISCONNECTED', 'BANNED', 'DELETED'], true) ? 'disconnected' : 'connected', $display, $display, $name]);
            } catch (Throwable $e) {}
        } else {
            $err = wa_cloud_error_text($res);
            try {
                db()->prepare("UPDATE whatsapp_instances SET cloud_state = 'ERRO', cloud_checked_at = NOW() WHERE instance_name = ?")->execute([$name]);
            } catch (Throwable $e) {}
            return wa_cg_resp(200, ['instance' => ['instanceName' => $name, 'state' => 'close'], 'cloud' => ['error' => $err], 'error_message' => $err]);
        }
    }
    $open = !in_array($state, ['DISCONNECTED', 'BANNED', 'DELETED', 'ERRO', 'UNVERIFIED'], true);
    $display = (string)preg_replace('/\D+/', '', (string)($info['display_phone_number'] ?? ($row['cloud_display_phone'] ?? '')));
    return wa_cg_resp(200, [
        'instance' => ['instanceName' => $name, 'state' => $open ? 'open' : 'close', 'owner' => $display !== '' ? $display . '@s.whatsapp.net' : null,
            'profileName' => (string)($info['verified_name'] ?? ($row['cloud_verified_name'] ?? ''))],
        'state' => $open ? 'open' : 'close',
        'cloud' => ['status' => $state, 'quality' => $info['quality_rating'] ?? ($row['cloud_quality'] ?? null),
            'limit' => $info['messaging_limit_tier'] ?? null, 'phoneNumberId' => (string)$row['cloud_phone_number_id']],
        'message' => $isConnect ? wa_provider_cannot_reason('qr_code') : null,
    ]);
}

// ---------------------------------------------------------------------------
// Envio
// ---------------------------------------------------------------------------

/** Id da mensagem citada (quoted) no formato Evolution. */
function wa_cg_quoted_id($quoted): string
{
    if (!is_array($quoted)) {
        return '';
    }
    return (string)($quoted['key']['id'] ?? ($quoted['id'] ?? ''));
}

/**
 * Envia uma mensagem já no formato da Meta.
 *
 * @param array $content    ['type' => ..., <type> => [...]]
 * @param array $evoMessage conteúdo no formato do resto do sistema (cópia/eco)
 * @param array $opts       quoted (id), fallback (callable(): array{ok,template?,error?,rendered?}), purpose, echo_base64
 */
function wa_cg_send(array $row, string $number, array $content, array $evoMessage, array $opts = []): array
{
    $instName = (string)$row['instance_name'];
    $pnid = (string)$row['cloud_phone_number_id'];
    $target = wa_cloud_target($number);
    if ($target['type'] === 'individual' && ($target['to'] ?? ($target['recipient'] ?? '')) === '') {
        return wa_cg_error(400, 'Número de destino inválido: ' . $number);
    }
    $isTemplate = ($content['type'] ?? '') === 'template';
    $via = $isTemplate ? 'template' : (string)($content['type'] ?? 'text');

    // Fora das 24 h: só modelo aprovado
    if (!$isTemplate && $target['type'] === 'individual' && !wa_cloud_window_open($instName, $number)) {
        $fb = isset($opts['fallback']) && is_callable($opts['fallback']) ? ($opts['fallback'])() : null;
        if (!is_array($fb) || empty($fb['ok'])) {
            $why = is_array($fb) && !empty($fb['error']) ? (string)$fb['error']
                : 'Fora da janela de 24 h: a pessoa não mandou mensagem para este número nas últimas 24 h e este tipo de mensagem não pode sair por modelo.';
            wa_event_log('warn', 'enviada', 'fora_da_janela', ['instance' => $instName, 'jid' => $number,
                'summary' => 'Envio NÃO feito pela API oficial: ' . mb_substr($why, 0, 250)]);
            return wa_cg_error(400, $why, ['cloud_window' => 'closed']);
        }
        $content = ['type' => 'template', 'template' => $fb['template']];
        $isTemplate = true;
        $via = 'template:' . (string)($fb['name'] ?? '');
        if (!empty($fb['rendered'])) {
            $evoMessage = ['conversation' => (string)$fb['rendered']];
        }
        wa_event_log('info', 'enviada', 'enviado_por_modelo', ['instance' => $instName, 'jid' => $number,
            'summary' => 'Contato fora da janela de 24 h: a mensagem sai pelo modelo "' . (string)($fb['name'] ?? '') . '"']);
    }

    $payload = ['messaging_product' => 'whatsapp'] + wa_cloud_target_fields($target) + $content;
    $quoted = (string)($opts['quoted'] ?? '');
    if ($quoted !== '' && !$isTemplate) {
        $payload['context'] = ['message_id' => $quoted];
    }
    $res = wa_cloud_graph('POST', $pnid . '/messages', [], $payload, 30);

    // Recusado na hora por estar fora da janela (registro de janela desatualizado): tenta o modelo
    if (!wa_cloud_ok($res) && wa_cloud_error_code($res) === 131047 && !$isTemplate && isset($opts['fallback']) && is_callable($opts['fallback'])) {
        $fb = ($opts['fallback'])();
        if (is_array($fb) && !empty($fb['ok'])) {
            $payload = ['messaging_product' => 'whatsapp'] + wa_cloud_target_fields($target) + ['type' => 'template', 'template' => $fb['template']];
            $res = wa_cloud_graph('POST', $pnid . '/messages', [], $payload, 30);
            $isTemplate = true;
            $via = 'template:' . (string)($fb['name'] ?? '');
            if (!empty($fb['rendered'])) {
                $evoMessage = ['conversation' => (string)$fb['rendered']];
            }
        }
    }
    if (!wa_cloud_ok($res)) {
        return wa_cg_meta_error($res, 'Envio pela API oficial recusado');
    }
    $j = (array)$res['json'];
    $wamid = (string)($j['messages'][0]['id'] ?? '');
    $waId = (string)($j['contacts'][0]['wa_id'] ?? '');
    $userId = (string)($j['contacts'][0]['user_id'] ?? '');
    if ($target['type'] === 'group') {
        $jid = wa_cloud_group_jid($target['group']);
    } else {
        $jid = wa_cloud_contact_jid($waId !== '' ? $waId : ($target['to'] ?? null), $userId !== '' ? $userId : ($target['recipient'] ?? null));
    }
    if ($quoted !== '' && !$isTemplate) {
        $evoMessage = wa_cg_with_context($evoMessage, $quoted);
    }
    $record = [
        'key' => ['remoteJid' => $jid, 'fromMe' => true, 'id' => $wamid],
        'pushName' => '',
        'status' => 'PENDING',
        'message' => $evoMessage,
        'messageType' => array_key_first($evoMessage) ?: 'conversation',
        'messageTimestamp' => time(),
        'instanceId' => (string)$row['cloud_phone_number_id'],
        'source' => 'cloud_api',
        'cloudVia' => $via,
    ];
    if ($wamid !== '') {
        $mediaId = null;
        foreach ($evoMessage as $v) {
            if (is_array($v) && !empty($v['cloudMediaId'])) {
                $mediaId = (string)$v['cloudMediaId'];
            }
        }
        wa_cloud_store_record($instName, $record, $mediaId);
        $echo = $record;
        if (!empty($opts['echo_base64'])) {
            $echo['message']['base64'] = (string)$opts['echo_base64'];
        }
        wa_cloud_echo_queue($instName, $echo);
    }
    return wa_cg_resp(201, $record + ['cloud' => ['via' => $via, 'wa_id' => $waId]]);
}

/** Acrescenta a citação (resposta) ao conteúdo no formato do sistema. */
function wa_cg_with_context(array $evoMessage, string $quotedId): array
{
    $quotedText = '';
    try {
        $st = db()->prepare('SELECT message_text FROM chat_messages WHERE external_message_id = ? LIMIT 1');
        $st->execute([$quotedId]);
        $quotedText = (string)($st->fetchColumn() ?: '');
    } catch (Throwable $e) {}
    $ctx = ['stanzaId' => $quotedId, 'quotedMessage' => ['conversation' => $quotedText]];
    if (isset($evoMessage['conversation'])) {
        return ['extendedTextMessage' => ['text' => (string)$evoMessage['conversation'], 'contextInfo' => $ctx]];
    }
    foreach ($evoMessage as $k => $v) {
        if (is_array($v) && str_ends_with((string)$k, 'Message')) {
            $evoMessage[$k]['contextInfo'] = $ctx;
            break;
        }
    }
    return $evoMessage;
}

/** Plano B fora das 24 h: modelo "Aviso" com o texto inteiro na variável. */
function wa_cg_aviso_fallback(string $text): callable
{
    return static function () use ($text): array {
        $p = wa_cloud_template_payload('aviso', [$text]);
        if (!$p['ok']) {
            return $p;
        }
        $p['rendered'] = wa_cloud_template_render((string)($p['body'] ?? ''), [$text]);
        return $p;
    };
}

function wa_cg_send_text(array $row, array $body): array
{
    $number = (string)($body['number'] ?? '');
    $text = (string)($body['text'] ?? ($body['textMessage']['text'] ?? ''));
    if (trim($text) === '') {
        return wa_cg_error(400, 'Texto vazio.');
    }
    $preview = array_key_exists('linkPreview', $body) ? (bool)$body['linkPreview'] : (bool)preg_match('#https?://#i', $text);
    $content = ['type' => 'text', 'text' => ['body' => mb_substr($text, 0, 4096), 'preview_url' => $preview]];
    $opts = ['quoted' => wa_cg_quoted_id($body['quoted'] ?? null), 'fallback' => wa_cg_aviso_fallback($text)];
    if (!empty($body['cloudForceTemplate'])) {
        $fb = ($opts['fallback'])();
        if (empty($fb['ok'])) {
            return wa_cg_error(400, (string)($fb['error'] ?? 'Modelo de aviso não configurado.'));
        }
        return wa_cg_send($row, $number, ['type' => 'template', 'template' => $fb['template']],
            ['conversation' => (string)($fb['rendered'] ?? $text)], ['quoted' => '']);
    }
    return wa_cg_send($row, $number, $content, ['conversation' => $text], $opts);
}

function wa_cg_send_media(array $row, array $body): array
{
    $number = (string)($body['number'] ?? '');
    $wanted = strtolower((string)($body['mediatype'] ?? ($body['mediaType'] ?? 'document')));
    $media = (string)($body['media'] ?? '');
    $caption = trim((string)($body['caption'] ?? ''));
    $fileName = trim((string)($body['fileName'] ?? ''));
    if (wa_cloud_target($number)['type'] === 'individual' && !wa_cloud_window_open((string)$row['instance_name'], $number)) {
        wa_event_log('warn', 'enviada', 'fora_da_janela', ['instance' => (string)$row['instance_name'], 'jid' => $number,
            'summary' => 'Arquivo NÃO enviado pela API oficial: contato fora da janela de 24 h']);
        return wa_cg_error(400, 'Fora da janela de 24 h: arquivos só podem ser enviados depois que a pessoa responder (fora desse prazo a API oficial só permite modelo aprovado).', ['cloud_window' => 'closed']);
    }
    $file = wa_cloud_media_resolve($media, (string)($body['mimetype'] ?? ''));
    if (!$file['ok']) {
        return wa_cg_error(400, 'Arquivo não enviado: ' . $file['error']);
    }
    $bin = $file['bin'];
    $mime = $file['mime'];
    // Áudio gravado no navegador (WebM) vira Ogg/Opus, o formato de nota de voz
    if ($wanted === 'audio' || str_starts_with($mime, 'audio/')) {
        if (wa_ogg_is_webm($bin)) {
            $ogg = wa_ogg_from_webm($bin);
            if ($ogg !== null) {
                $bin = $ogg;
                $mime = 'audio/ogg';
                $fileName = (string)preg_replace('/\.webm$/i', '.ogg', $fileName !== '' ? $fileName : 'audio.ogg');
            }
        }
        if ($mime === 'audio/ogg' || str_starts_with($mime, 'audio/ogg')) {
            $mime = 'audio/ogg';
        }
    }
    $kind = wa_cloud_media_kind($wanted, $mime);
    $limits = ['image' => 5, 'video' => 16, 'audio' => 16, 'sticker' => 0.5, 'document' => 100];
    if (strlen($bin) > $limits[$kind] * 1048576) {
        return wa_cg_error(400, 'Arquivo grande demais para a API oficial (' . round(strlen($bin) / 1048576, 1) . ' MB; limite de ' . $limits[$kind] . ' MB para ' . $kind . ').');
    }
    if ($fileName === '') {
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'video/mp4' => 'mp4', 'audio/ogg' => 'ogg', 'audio/mpeg' => 'mp3', 'application/pdf' => 'pdf', 'image/webp' => 'webp'][$mime] ?? 'bin';
        $fileName = $kind . '.' . $ext;
    }
    $up = wa_cloud_media_upload((string)$row['cloud_phone_number_id'], $bin, $mime, $fileName);
    if (!$up['ok']) {
        return wa_cg_error(400, 'A Meta recusou o arquivo: ' . $up['error']);
    }
    $obj = ['id' => $up['id']];
    if ($caption !== '' && in_array($kind, ['image', 'video', 'document'], true)) {
        $obj['caption'] = mb_substr($caption, 0, 1024);
    }
    if ($kind === 'document') {
        $obj['filename'] = mb_substr($fileName, 0, 240);
    }
    $evoKey = ['image' => 'imageMessage', 'video' => 'videoMessage', 'audio' => 'audioMessage', 'document' => 'documentMessage', 'sticker' => 'stickerMessage'][$kind];
    $evo = [$evoKey => ['mimetype' => $mime, 'cloudMediaId' => $up['id'], 'fileLength' => strlen($bin)]];
    if ($caption !== '') {
        $evo[$evoKey]['caption'] = $caption;
    }
    if ($kind === 'document') {
        $evo[$evoKey]['fileName'] = $fileName;
    }
    if ($kind === 'audio') {
        $evo[$evoKey]['ptt'] = true;
    }
    return wa_cg_send($row, $number, ['type' => $kind, $kind => $obj], $evo, [
        'quoted' => wa_cg_quoted_id($body['quoted'] ?? null),
        'echo_base64' => strlen($bin) <= 3 * 1048576 ? base64_encode($bin) : '',
        // Arquivo não sai por modelo de texto: fora das 24 h avisa com o motivo
        'fallback' => static fn(): array => ['ok' => false, 'error' => 'Fora da janela de 24 h: arquivos só podem ser enviados depois que a pessoa responder (a API oficial só permite modelo aprovado fora desse prazo).'],
    ]);
}

function wa_cg_send_reaction(array $row, array $body): array
{
    $r = is_array($body['reactionMessage'] ?? null) ? $body['reactionMessage'] : $body;
    $key = is_array($r['key'] ?? null) ? $r['key'] : [];
    $jid = (string)($key['remoteJid'] ?? ($body['number'] ?? ''));
    $msgId = (string)($key['id'] ?? '');
    if ($jid === '' || $msgId === '') {
        return wa_cg_error(400, 'Reação sem a mensagem de destino.');
    }
    $emoji = (string)($r['reaction'] ?? ($r['text'] ?? ''));
    return wa_cg_send($row, $jid, ['type' => 'reaction', 'reaction' => ['message_id' => $msgId, 'emoji' => $emoji]],
        ['reactionMessage' => ['key' => ['remoteJid' => $jid, 'id' => $msgId, 'fromMe' => !empty($key['fromMe'])], 'text' => $emoji]],
        ['fallback' => static fn(): array => ['ok' => false, 'error' => 'Fora da janela de 24 h: não dá para reagir.']]);
}

function wa_cg_send_location(array $row, array $body): array
{
    $l = is_array($body['locationMessage'] ?? null) ? $body['locationMessage'] : $body;
    $lat = (float)($l['latitude'] ?? ($l['degreesLatitude'] ?? 0));
    $lng = (float)($l['longitude'] ?? ($l['degreesLongitude'] ?? 0));
    $loc = ['latitude' => $lat, 'longitude' => $lng];
    if (!empty($l['name'])) $loc['name'] = (string)$l['name'];
    if (!empty($l['address'])) $loc['address'] = (string)$l['address'];
    return wa_cg_send($row, (string)($body['number'] ?? ''), ['type' => 'location', 'location' => $loc],
        ['locationMessage' => ['degreesLatitude' => $lat, 'degreesLongitude' => $lng, 'name' => (string)($l['name'] ?? ''), 'address' => (string)($l['address'] ?? '')]],
        ['fallback' => static fn(): array => ['ok' => false, 'error' => 'Fora da janela de 24 h: localização só depois que a pessoa responder.']]);
}

function wa_cg_send_contact(array $row, array $body): array
{
    $list = $body['contact'] ?? ($body['contactMessage'] ?? []);
    if (isset($list['fullName']) || isset($list['phoneNumber'])) {
        $list = [$list];
    }
    $contacts = [];
    $evo = [];
    foreach ((array)$list as $c) {
        if (!is_array($c)) continue;
        $name = (string)($c['fullName'] ?? ($c['displayName'] ?? 'Contato'));
        $phone = (string)preg_replace('/\D+/', '', (string)($c['phoneNumber'] ?? ($c['wuid'] ?? '')));
        $contacts[] = ['name' => ['formatted_name' => $name, 'first_name' => $name], 'phones' => [['phone' => '+' . $phone, 'type' => 'CELL', 'wa_id' => $phone]]];
        $evo[] = ['displayName' => $name, 'vcard' => "BEGIN:VCARD\nVERSION:3.0\nFN:$name\nTEL;type=CELL;waid=$phone:+$phone\nEND:VCARD"];
    }
    if (!$contacts) {
        return wa_cg_error(400, 'Contato vazio.');
    }
    return wa_cg_send($row, (string)($body['number'] ?? ''), ['type' => 'contacts', 'contacts' => $contacts],
        count($evo) === 1 ? ['contactMessage' => $evo[0]] : ['contactsArrayMessage' => ['contacts' => $evo]],
        ['fallback' => static fn(): array => ['ok' => false, 'error' => 'Fora da janela de 24 h: contato só depois que a pessoa responder.']]);
}

/**
 * Modelo: {number, name, language, components} (formato Evolution para a Cloud API)
 * ou {number, purpose, values, payloads} (uso configurado no sistema).
 */
function wa_cg_send_template(array $row, array $body): array
{
    $number = (string)($body['number'] ?? '');
    if (!empty($body['purpose'])) {
        $p = wa_cloud_template_payload((string)$body['purpose'], (array)($body['values'] ?? []), (array)($body['payloads'] ?? []));
        if (!$p['ok']) {
            return wa_cg_error(400, (string)$p['error']);
        }
        return wa_cg_send($row, $number, ['type' => 'template', 'template' => $p['template']],
            ['conversation' => wa_cloud_template_render((string)($p['body'] ?? ''), (array)($body['values'] ?? []))]);
    }
    $tm = is_array($body['templateMessage'] ?? null) ? $body['templateMessage'] : $body;
    $name = (string)($tm['name'] ?? '');
    if ($name === '') {
        return wa_cg_error(400, 'Informe o nome do modelo.');
    }
    $lang = $tm['language'] ?? 'pt_BR';
    $tpl = ['name' => $name, 'language' => ['code' => is_array($lang) ? (string)($lang['code'] ?? 'pt_BR') : (string)$lang]];
    if (!empty($tm['components'])) {
        $tpl['components'] = $tm['components'];
    }
    return wa_cg_send($row, $number, ['type' => 'template', 'template' => $tpl], ['conversation' => '[Modelo ' . $name . ']']);
}

/**
 * Botões de resposta (até 3). Dentro das 24 h: mensagem interativa; fora: modelo
 * do uso informado em "cloudTemplate" (ex.: captação com "Tenho interesse").
 * Texto maior que 1024 caracteres: vai antes como texto e os botões em seguida.
 */
function wa_cg_send_buttons(array $row, array $body): array
{
    $number = (string)($body['number'] ?? '');
    $title = trim((string)($body['title'] ?? ''));
    $text = trim((string)($body['description'] ?? ($body['text'] ?? '')));
    $footer = trim((string)($body['footer'] ?? ''));
    $buttons = [];
    $labels = [];
    foreach (array_slice((array)($body['buttons'] ?? []), 0, 3) as $i => $b) {
        $label = mb_substr(trim((string)($b['displayText'] ?? ($b['title'] ?? ($b['text'] ?? 'Opção')))), 0, 20);
        $id = mb_substr((string)($b['id'] ?? ('btn' . $i)), 0, 256);
        $buttons[] = ['type' => 'reply', 'reply' => ['id' => $id, 'title' => $label]];
        $labels[] = $label;
    }
    if (!$buttons || $text === '') {
        return wa_cg_error(400, 'Mensagem com botões precisa de texto e de pelo menos um botão.');
    }
    $ct = is_array($body['cloudTemplate'] ?? null) ? $body['cloudTemplate'] : [];
    $instName = (string)$row['instance_name'];
    $extraIds = [];
    $fallback = static function () use ($ct): array {
        if (empty($ct['purpose'])) {
            return ['ok' => false, 'error' => 'Fora da janela de 24 h e sem modelo para esta mensagem.'];
        }
        $p = wa_cloud_template_payload((string)$ct['purpose'], (array)($ct['values'] ?? []), (array)($ct['payloads'] ?? []));
        if ($p['ok']) {
            $p['rendered'] = wa_cloud_template_render((string)($p['body'] ?? ''), (array)($ct['values'] ?? []));
        }
        return $p;
    };
    $individual = wa_cloud_target($number)['type'] === 'individual';
    if ($individual && !wa_cloud_window_open($instName, $number)) {
        return wa_cg_send($row, $number, ['type' => 'text', 'text' => ['body' => $text]], ['conversation' => $text], ['fallback' => $fallback]);
    }
    $bodyText = $text;
    if (mb_strlen($text) > 1024) {
        $first = wa_cg_send($row, $number, ['type' => 'text', 'text' => ['body' => mb_substr($text, 0, 4096), 'preview_url' => false]], ['conversation' => $text], ['fallback' => $fallback]);
        if ((int)$first['status'] >= 300) {
            return $first;
        }
        if (!empty($first['json']['key']['id'])) {
            $extraIds[] = (string)$first['json']['key']['id'];
        }
        $bodyText = trim((string)($body['shortText'] ?? 'Toque no botão abaixo para responder.'));
    }
    $inter = ['type' => 'button', 'body' => ['text' => mb_substr($bodyText, 0, 1024)], 'action' => ['buttons' => $buttons]];
    if ($title !== '') {
        $inter['header'] = ['type' => 'text', 'text' => mb_substr($title, 0, 60)];
    }
    if ($footer !== '') {
        $inter['footer'] = ['text' => mb_substr($footer, 0, 60)];
    }
    $res = wa_cg_send($row, $number, ['type' => 'interactive', 'interactive' => $inter],
        ['conversation' => ($title !== '' ? '*' . $title . "*\n" : '') . $bodyText . "\n\n[" . implode('] [', $labels) . ']'],
        ['fallback' => $fallback]);
    if ($extraIds && (int)$res['status'] < 300) {
        $j = $res['json'];
        $j['extraIds'] = $extraIds;
        return wa_cg_resp((int)$res['status'], $j);
    }
    return $res;
}

function wa_cg_send_list(array $row, array $body): array
{
    $lm = is_array($body['listMessage'] ?? null) ? $body['listMessage'] : $body;
    $sections = [];
    foreach ((array)($lm['sections'] ?? []) as $s) {
        $rows = [];
        foreach ((array)($s['rows'] ?? []) as $r) {
            $rows[] = ['id' => mb_substr((string)($r['rowId'] ?? ($r['id'] ?? uniqid('r'))), 0, 200), 'title' => mb_substr((string)($r['title'] ?? ''), 0, 24),
                'description' => mb_substr((string)($r['description'] ?? ''), 0, 72)];
        }
        if ($rows) {
            $sections[] = ['title' => mb_substr((string)($s['title'] ?? ''), 0, 24), 'rows' => array_slice($rows, 0, 10)];
        }
    }
    $text = (string)($lm['description'] ?? ($lm['text'] ?? ''));
    if (!$sections || trim($text) === '') {
        return wa_cg_error(400, 'Lista sem itens ou sem texto.');
    }
    $inter = ['type' => 'list', 'body' => ['text' => mb_substr($text, 0, 4096)], 'action' => ['button' => mb_substr((string)($lm['buttonText'] ?? 'Ver opções'), 0, 20), 'sections' => $sections]];
    if (!empty($lm['title'])) {
        $inter['header'] = ['type' => 'text', 'text' => mb_substr((string)$lm['title'], 0, 60)];
    }
    if (!empty($lm['footerText'])) {
        $inter['footer'] = ['text' => mb_substr((string)$lm['footerText'], 0, 60)];
    }
    return wa_cg_send($row, (string)($body['number'] ?? ''), ['type' => 'interactive', 'interactive' => $inter], ['conversation' => $text],
        ['fallback' => wa_cg_aviso_fallback($text)]);
}

// ---------------------------------------------------------------------------
// Eco do envio (a Meta não avisa pelo webhook o que a própria API enviou)
// ---------------------------------------------------------------------------

/**
 * Entrega a mensagem enviada ao processamento do webhook ("send.message"), como o
 * Baileys/Evolution fazem: aparece no chat, confirma a captação no grupo etc.
 * Roda no fim do request (depois de quem enviou gravar a própria cópia).
 */
function wa_cloud_echo_queue(string $instanceName, array $record): void
{
    if (!empty($GLOBALS['WA_CLOUD_NO_ECHO'])) {
        return;
    }
    $GLOBALS['__wa_cloud_echoes'][] = ['event' => 'send.message', 'instance' => $instanceName, 'data' => $record,
        'date_time' => date('c'), 'sender' => '', 'server_url' => WA_CLOUD_BASE, 'apikey' => '', 'cloud' => true];
    if (empty($GLOBALS['__wa_cloud_echo_registered'])) {
        $GLOBALS['__wa_cloud_echo_registered'] = true;
        register_shutdown_function('wa_cloud_echo_flush');
    }
}

function wa_cloud_echo_flush(): void
{
    $list = $GLOBALS['__wa_cloud_echoes'] ?? [];
    $GLOBALS['__wa_cloud_echoes'] = [];
    if (!$list) {
        return;
    }
    require_once __DIR__ . '/whatsapp_webhook_handler.php';
    require_once __DIR__ . '/whatsapp_webhook_inbox.php';
    foreach ($list as $payload) {
        try {
            $raw = (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            $stored = wa_inbox_store($raw, $payload);
            if ($stored['status'] === 'queued') {
                wa_inbox_process((int)$stored['id'], $payload);
            } elseif ($stored['status'] === 'unavailable') {
                wa_webhook_handle($payload);
            }
        } catch (Throwable $e) {
            error_log('[WA_CLOUD] eco do envio: ' . $e->getMessage());
        }
    }
    if (function_exists('wa_event_log_flush')) {
        wa_event_log_flush(true);
    }
}

// ---------------------------------------------------------------------------
// Conversas
// ---------------------------------------------------------------------------

function wa_cg_find_chats(array $row): array
{
    $out = [];
    try {
        $st = db()->prepare('SELECT remote_jid, contact_name, custom_name, last_message_timestamp, is_group FROM chat_contacts
                             WHERE instance_name = ? ORDER BY last_message_timestamp DESC LIMIT 500');
        $st->execute([(string)$row['instance_name']]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $out[] = ['id' => (string)$c['remote_jid'], 'remoteJid' => (string)$c['remote_jid'],
                'pushName' => (string)($c['custom_name'] ?: $c['contact_name']), 'name' => (string)($c['custom_name'] ?: $c['contact_name']),
                'profilePicUrl' => null, 'updatedAt' => !empty($c['last_message_timestamp']) ? date('c', (int)$c['last_message_timestamp']) : null];
        }
    } catch (Throwable $e) {
        // chat_contacts sem custom_name em bases antigas
        try {
            $st = db()->prepare('SELECT remote_jid, contact_name FROM chat_contacts WHERE instance_name = ? LIMIT 500');
            $st->execute([(string)$row['instance_name']]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $out[] = ['id' => (string)$c['remote_jid'], 'remoteJid' => (string)$c['remote_jid'], 'pushName' => (string)$c['contact_name']];
            }
        } catch (Throwable $e2) {}
    }
    return wa_cg_resp(200, $out);
}

function wa_cg_find_messages(array $row, array $body): array
{
    $jid = (string)($body['where']['key']['remoteJid'] ?? ($body['where']['remoteJid'] ?? ''));
    $limit = max(1, min(500, (int)($body['offset'] ?? ($body['limit'] ?? 50))));
    $page = max(1, (int)($body['page'] ?? 1));
    $records = [];
    $total = 0;
    try {
        $where = 'instance_name = ?';
        $params = [(string)$row['instance_name']];
        if ($jid !== '') {
            $where .= ' AND remote_jid = ?';
            $params[] = $jid;
        }
        $st = db()->prepare("SELECT COUNT(*) FROM wa_cloud_messages WHERE $where");
        $st->execute($params);
        $total = (int)$st->fetchColumn();
        $st = db()->prepare("SELECT record FROM wa_cloud_messages WHERE $where ORDER BY message_ts DESC, id DESC LIMIT " . $limit . ' OFFSET ' . (($page - 1) * $limit));
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $r) {
            $rec = json_decode((string)$r, true);
            if (is_array($rec)) {
                $records[] = $rec;
            }
        }
    } catch (Throwable $e) {
        error_log('[WA_CLOUD] findMessages: ' . $e->getMessage());
    }
    return wa_cg_resp(200, ['messages' => ['total' => $total, 'pages' => (int)ceil($total / $limit), 'currentPage' => $page, 'records' => $records]]);
}

/** Baixa a mídia de uma mensagem (id da mídia guardado na mensagem ou na cópia local). */
function wa_cg_get_media(array $row, array $body): array
{
    $msg = is_array($body['message'] ?? null) ? $body['message'] : [];
    $key = is_array($msg['key'] ?? null) ? $msg['key'] : [];
    $content = is_array($msg['message'] ?? null) ? $msg['message'] : [];
    $mediaId = '';
    $mime = '';
    $fileName = '';
    $type = 'document';
    foreach (['imageMessage' => 'image', 'videoMessage' => 'video', 'audioMessage' => 'audio', 'documentMessage' => 'document', 'stickerMessage' => 'sticker'] as $k => $t) {
        if (isset($content[$k]) && is_array($content[$k])) {
            $mediaId = (string)($content[$k]['cloudMediaId'] ?? '');
            $mime = (string)($content[$k]['mimetype'] ?? '');
            $fileName = (string)($content[$k]['fileName'] ?? '');
            $type = $t;
            break;
        }
    }
    if ($mediaId === '' && !empty($key['id'])) {
        $found = wa_cloud_find_record((string)$key['id']);
        if ($found) {
            $mediaId = $found['media_id'];
            foreach ((array)($found['record']['message'] ?? []) as $k => $v) {
                if (is_array($v) && isset($v['mimetype'])) {
                    $mime = $mime !== '' ? $mime : (string)$v['mimetype'];
                    $fileName = $fileName !== '' ? $fileName : (string)($v['fileName'] ?? '');
                }
            }
        }
    }
    if ($mediaId === '') {
        return wa_cg_error(404, 'Mídia não encontrada (mensagem sem arquivo na API oficial).');
    }
    $dl = wa_cloud_media_download($mediaId, (string)$row['cloud_phone_number_id']);
    if (!$dl['ok']) {
        return wa_cg_error(502, 'Não foi possível baixar o arquivo da Meta: ' . $dl['error']);
    }
    return wa_cg_resp(200, ['mediaType' => $type, 'fileName' => $fileName, 'mimetype' => $dl['mime'] !== '' ? $dl['mime'] : $mime,
        'size' => ['fileLength' => $dl['size']], 'base64' => base64_encode($dl['bin'])]);
}

function wa_cg_mark_read(array $row, array $body): array
{
    $pnid = (string)$row['cloud_phone_number_id'];
    $lastByJid = [];
    $groupIds = [];
    foreach ((array)($body['readMessages'] ?? []) as $m) {
        if (!is_array($m) || !empty($m['fromMe']) || empty($m['id'])) {
            continue;
        }
        $id = (string)$m['id'];
        if (!str_starts_with($id, 'wamid.')) {
            continue; // mensagem de antes da API oficial
        }
        $jid = (string)($m['remoteJid'] ?? '');
        if (str_contains($jid, '@g.us')) {
            $groupIds[] = $id;
        } else {
            $lastByJid[$jid] = $id; // marcar a última marca as anteriores
        }
    }
    $ok = 0;
    foreach (array_merge(array_values($lastByJid), array_slice($groupIds, -20)) as $id) {
        $res = wa_cloud_graph('POST', $pnid . '/messages', [], ['messaging_product' => 'whatsapp', 'status' => 'read', 'message_id' => $id], 10);
        if (wa_cloud_ok($res)) {
            $ok++;
        }
    }
    return wa_cg_resp(201, ['message' => 'Read messages', 'read' => 'success', 'count' => $ok]);
}

function wa_cg_fetch_profile(array $body): array
{
    $number = (string)($body['number'] ?? '');
    $name = '';
    try {
        $st = db()->prepare('SELECT contact_name FROM chat_contacts WHERE remote_jid = ? LIMIT 1');
        $st->execute([wa_cloud_contact_jid(wa_cloud_target($number)['to'] ?? null, wa_cloud_target($number)['recipient'] ?? null)]);
        $name = (string)($st->fetchColumn() ?: '');
    } catch (Throwable $e) {}
    return wa_cg_resp(200, ['wuid' => $number, 'name' => $name, 'numberExists' => true, 'picture' => null, 'status' => null, 'isBusiness' => false]);
}

function wa_cg_find_contacts(array $row, array $body): array
{
    $id = (string)($body['where']['id'] ?? '');
    $out = [];
    try {
        if ($id !== '') {
            $st = db()->prepare('SELECT remote_jid, contact_name FROM chat_contacts WHERE remote_jid = ? LIMIT 1');
            $st->execute([$id]);
        } else {
            $st = db()->prepare('SELECT remote_jid, contact_name FROM chat_contacts WHERE instance_name = ? AND is_group = 0 LIMIT 1000');
            $st->execute([(string)$row['instance_name']]);
        }
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $out[] = ['id' => (string)$c['remote_jid'], 'remoteJid' => (string)$c['remote_jid'], 'pushName' => (string)$c['contact_name'], 'profilePicUrl' => null];
        }
    } catch (Throwable $e) {}
    return wa_cg_resp(200, $out);
}

// ---------------------------------------------------------------------------
// Grupos
// ---------------------------------------------------------------------------

function wa_cg_group_id(string $groupJid): string
{
    return trim((string)preg_replace('/@g\.us$/', '', trim($groupJid)));
}

/** Números das instâncias do sistema (nunca recebem convite de si mesmos). */
function wa_cg_own_keys(): array
{
    $keys = [];
    foreach (wa_cloud_instances() as $r) {
        foreach (['cloud_display_phone', 'owner_phone_formatted', 'owner_number'] as $f) {
            $d = (string)preg_replace('/\D+/', '', (string)($r[$f] ?? ''));
            if ($d !== '') {
                $keys[dispatch_member_key($d)] = true;
            }
        }
    }
    return $keys;
}

function wa_cg_group_create(array $row, array $body, int $timeoutSeconds): array
{
    $subject = mb_substr(trim((string)($body['subject'] ?? '')), 0, 128);
    if ($subject === '') {
        return wa_cg_error(400, 'Informe o nome do grupo.');
    }
    $desc = mb_substr(trim((string)($body['description'] ?? '')), 0, 2048);
    $pnid = (string)$row['cloud_phone_number_id'];
    $instName = (string)$row['instance_name'];
    $req = ['messaging_product' => 'whatsapp', 'subject' => $subject, 'join_approval_mode' => 'auto_approve'];
    if ($desc !== '') {
        $req['description'] = $desc;
    }
    $res = wa_cloud_graph('POST', $pnid . '/groups', [], $req, 30);
    if (!wa_cloud_ok($res)) {
        return wa_cg_meta_error($res, 'A Meta não criou o grupo');
    }
    $j = (array)$res['json'];
    $groupId = (string)($j['id'] ?? ($j['group_id'] ?? ''));
    $requestId = (string)($j['request_id'] ?? '');
    $inviteLink = (string)($j['invite_link'] ?? '');
    try {
        db()->prepare('INSERT INTO wa_cloud_group_requests (request_id, instance_name, subject, group_id, invite_link, status) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$requestId !== '' ? $requestId : null, $instName, $subject, $groupId !== '' ? $groupId : null, $inviteLink !== '' ? $inviteLink : null, $groupId !== '' ? 'created' : 'pending']);
    } catch (Throwable $e) {}

    // A criação é assíncrona: o id chega pelo webhook (ou aparece na lista de grupos)
    $deadline = time() + max(10, min(45, $timeoutSeconds - 5));
    $lastList = 0;
    while ($groupId === '' && time() < $deadline) {
        usleep(1000000);
        try {
            if ($requestId !== '') {
                $st = db()->prepare("SELECT group_id, invite_link, status, error FROM wa_cloud_group_requests WHERE request_id = ? AND (group_id IS NOT NULL OR status = 'failed') ORDER BY id DESC LIMIT 1");
                $st->execute([$requestId]);
                $r = $st->fetch(PDO::FETCH_ASSOC);
                if ($r) {
                    if ((string)$r['status'] === 'failed') {
                        return wa_cg_error(400, 'A Meta não criou o grupo: ' . (string)$r['error']);
                    }
                    $groupId = (string)$r['group_id'];
                    $inviteLink = (string)($r['invite_link'] ?? '');
                    break;
                }
            }
        } catch (Throwable $e) {}
        if (time() - $lastList >= 4) {
            $lastList = time();
            $list = wa_cloud_graph('GET', $pnid . '/groups', ['limit' => 50], null, 15);
            $newest = null;
            foreach ((array)($list['json']['data']['groups'] ?? ($list['json']['data'] ?? [])) as $g) {
                if (is_array($g) && (string)($g['subject'] ?? '') === $subject) {
                    if ($newest === null || (int)($g['created_at'] ?? 0) > (int)($newest['created_at'] ?? 0)) {
                        $newest = $g;
                    }
                }
            }
            if ($newest !== null && !empty($newest['id'])) {
                $groupId = (string)$newest['id'];
            }
        }
    }
    if ($groupId === '') {
        return wa_cg_error(504, 'A Meta aceitou criar o grupo "' . $subject . '", mas o id não chegou a tempo. Confira o webhook da API oficial e use "Sincronizar grupos" em instantes.', ['request_id' => $requestId]);
    }
    if ($inviteLink === '') {
        $inv = wa_cloud_graph('GET', $groupId . '/invite_link', [], null, 15);
        $inviteLink = (string)($inv['json']['invite_link'] ?? '');
    }
    try {
        db()->prepare("UPDATE wa_cloud_group_requests SET group_id = ?, invite_link = COALESCE(NULLIF(?, ''), invite_link), status = 'created'
                       WHERE (request_id = ? AND ? <> '') OR (group_id = ?)")
            ->execute([$groupId, $inviteLink, $requestId, $requestId, $groupId]);
    } catch (Throwable $e) {}
    wa_cloud_group_link_remember($groupId, $inviteLink);
    wa_event_log('info', 'grupo', 'grupo_criado', ['instance' => $instName, 'jid' => wa_cloud_group_jid($groupId),
        'summary' => 'Grupo "' . $subject . '" criado pela API oficial' . ($inviteLink !== '' ? ' (link: ' . $inviteLink . ')' : '')]);

    // "Participantes" da criação: na API oficial viram convites pelo link
    $invites = ['sent' => 0, 'failed' => []];
    $participants = (array)($body['participants'] ?? []);
    if ($participants && $inviteLink !== '') {
        $r = wa_cg_send_invite_batch($row, $groupId, $subject, $inviteLink, $participants);
        $invites = ['sent' => $r['sent'], 'failed' => $r['failed']];
    }
    $display = (string)preg_replace('/\D+/', '', (string)($row['cloud_display_phone'] ?? ''));
    return wa_cg_resp(201, ['id' => wa_cloud_group_jid($groupId), 'subject' => $subject, 'subjectOwner' => $display . '@s.whatsapp.net',
        'owner' => $display . '@s.whatsapp.net', 'desc' => $desc, 'creation' => time(), 'inviteUrl' => $inviteLink, 'invites' => $invites,
        'participants' => [['id' => $display . '@s.whatsapp.net', 'admin' => 'superadmin']]]);
}

/** Guarda o link de convite do grupo (whatsapp_groups.invite_link). */
function wa_cloud_group_link_remember(string $groupId, string $link): void
{
    if ($groupId === '' || $link === '') {
        return;
    }
    try {
        db()->prepare('UPDATE whatsapp_groups SET invite_link = ? WHERE evolution_group_jid = ?')->execute([$link, wa_cloud_group_jid($groupId)]);
        db()->prepare("UPDATE wa_cloud_group_requests SET invite_link = ? WHERE group_id = ?")->execute([$link, $groupId]);
    } catch (Throwable $e) {}
}

/** Link de convite de um grupo (guardado ou pedido à Meta). */
function wa_cloud_group_link(string $groupId, bool $refresh = false): string
{
    $groupId = wa_cg_group_id($groupId);
    if ($groupId === '') {
        return '';
    }
    wa_cloud_ensure_schema();
    if (!$refresh) {
        try {
            $st = db()->prepare("SELECT invite_link FROM whatsapp_groups WHERE evolution_group_jid = ? AND invite_link IS NOT NULL AND invite_link <> '' LIMIT 1");
            $st->execute([wa_cloud_group_jid($groupId)]);
            $l = (string)($st->fetchColumn() ?: '');
            if ($l === '') {
                $st = db()->prepare("SELECT invite_link FROM wa_cloud_group_requests WHERE group_id = ? AND invite_link IS NOT NULL ORDER BY id DESC LIMIT 1");
                $st->execute([$groupId]);
                $l = (string)($st->fetchColumn() ?: '');
            }
            if ($l !== '') {
                return $l;
            }
        } catch (Throwable $e) {}
    }
    $res = wa_cloud_graph($refresh ? 'POST' : 'GET', $groupId . '/invite_link', [], $refresh ? ['messaging_product' => 'whatsapp'] : null, 15);
    $link = (string)($res['json']['invite_link'] ?? '');
    wa_cloud_group_link_remember($groupId, $link);
    return $link;
}

/** Nome do grupo (do sistema ou da Meta). */
function wa_cg_group_subject(string $groupId): string
{
    try {
        $st = db()->prepare('SELECT name FROM whatsapp_groups WHERE evolution_group_jid = ? LIMIT 1');
        $st->execute([wa_cloud_group_jid($groupId)]);
        $n = (string)($st->fetchColumn() ?: '');
        if ($n !== '') {
            return $n;
        }
    } catch (Throwable $e) {}
    $res = wa_cloud_graph('GET', $groupId, ['fields' => 'subject'], null, 10);
    return (string)($res['json']['subject'] ?? 'grupo');
}

/** Convite (texto dentro das 24 h, modelo "Convite para grupo" fora). */
function wa_cg_send_invite(array $row, string $groupId, string $subject, string $link, string $number, string $note = ''): array
{
    $text = 'Olá! Você foi convidado(a) para o grupo *' . $subject . '* da MultiLife Care.' . ($note !== '' ? "\n\n" . $note : '')
        . "\n\nPara entrar, toque no link: " . $link;
    $fallback = static function () use ($subject, $link): array {
        $p = wa_cloud_template_payload('convite_grupo', [$subject, $link]);
        if ($p['ok']) {
            $p['rendered'] = wa_cloud_template_render((string)($p['body'] ?? ''), [$subject, $link]);
        }
        return $p;
    };
    $r = wa_cg_send($row, $number, ['type' => 'text', 'text' => ['body' => $text, 'preview_url' => true]], ['conversation' => $text], ['fallback' => $fallback]);
    $ok = (int)$r['status'] >= 200 && (int)$r['status'] < 300;
    return ['ok' => $ok, 'error' => $ok ? '' : (string)($r['json']['response']['message'] ?? 'falhou'), 'id' => (string)($r['json']['key']['id'] ?? '')];
}

/**
 * Envia o convite a cada número. Quem já recebeu convite deste grupo nos últimos
 * 30 dias não recebe de novo (a captação em grupo pediria "adicionar" os mesmos
 * profissionais toda vez), a não ser com $force.
 *
 * @return array{sent:int, failed:array, skipped:int}
 */
function wa_cg_send_invite_batch(array $row, string $groupId, string $subject, string $link, array $numbers, string $note = '', bool $force = false): array
{
    $own = wa_cg_own_keys();
    $sent = 0;
    $skipped = 0;
    $failed = [];
    $done = [];
    $already = [];
    if (!$force) {
        try {
            $st = db()->prepare('SELECT contact_key FROM wa_cloud_group_invites WHERE group_id = ? AND invited_at > ?');
            $st->execute([$groupId, time() - 30 * 86400]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $k) {
                $already[(string)$k] = true;
            }
        } catch (Throwable $e) {}
    }
    $mark = db()->prepare('INSERT INTO wa_cloud_group_invites (group_id, contact_key, invited_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE invited_at = VALUES(invited_at)');
    foreach ($numbers as $n) {
        $n = is_array($n) ? (string)($n['id'] ?? ($n['jid'] ?? '')) : (string)$n;
        $key = dispatch_member_key($n);
        if ($key === '' || isset($own[$key]) || isset($done[$key]) || isset($already[$key])) {
            $skipped++;
            continue;
        }
        $done[$key] = true;
        $r = wa_cg_send_invite($row, $groupId, $subject, $link, $n, $note);
        if ($r['ok']) {
            $sent++;
            try { $mark->execute([$groupId, $key, time()]); } catch (Throwable $e) {}
        } else {
            $failed[] = ['number' => $n, 'error' => $r['error']];
        }
        usleep(150000); // ritmo moderado (limite por segundo da Meta)
    }
    wa_event_log($failed ? 'warn' : 'info', 'grupo', 'convites_enviados', ['instance' => (string)$row['instance_name'], 'jid' => wa_cloud_group_jid($groupId),
        'summary' => 'Convites para o grupo "' . $subject . '": ' . $sent . ' enviado(s)' . ($failed ? ', ' . count($failed) . ' com erro' : '') . ($skipped ? ', ' . $skipped . ' ignorado(s)' : ''),
        'details' => ['falhas' => array_slice($failed, 0, 30)]]);
    return ['sent' => $sent, 'failed' => $failed, 'skipped' => $skipped];
}

function wa_cg_group_list(array $row, bool $withParticipants): array
{
    $pnid = (string)$row['cloud_phone_number_id'];
    $query = ['limit' => 200];
    $groups = [];
    for ($page = 0; $page < 20; $page++) {
        $res = wa_cloud_graph('GET', $pnid . '/groups', $query, null, 20);
        if (!wa_cloud_ok($res)) {
            return wa_cg_meta_error($res, 'Não foi possível listar os grupos');
        }
        $data = $res['json']['data'] ?? [];
        $list = is_array($data['groups'] ?? null) ? $data['groups'] : (isset($data[0]) ? $data : []);
        foreach ($list as $g) {
            if (is_array($g) && !empty($g['id'])) {
                $groups[] = $g;
            }
        }
        $after = (string)($res['json']['paging']['cursors']['after'] ?? '');
        if ($after === '' || empty($res['json']['paging']['next'])) {
            break;
        }
        $query['after'] = $after;
    }
    $out = [];
    $display = (string)preg_replace('/\D+/', '', (string)($row['cloud_display_phone'] ?? ''));
    foreach ($groups as $i => $g) {
        $item = ['id' => wa_cloud_group_jid((string)$g['id']), 'subject' => (string)($g['subject'] ?? ''), 'subjectOwner' => $display . '@s.whatsapp.net',
            'owner' => $display . '@s.whatsapp.net', 'creation' => (int)($g['created_at'] ?? 0), 'desc' => '', 'announce' => false, 'restrict' => false];
        if ($withParticipants && $i < 100) {
            $info = wa_cg_group_fetch((string)$g['id']);
            if ($info !== null) {
                $item['participants'] = $info['participants'];
                $item['size'] = count($info['participants']);
                $item['desc'] = $info['desc'];
            }
        }
        $out[] = $item;
    }
    return wa_cg_resp(200, $out);
}

/** Dados do grupo no formato Evolution (participantes com o número da empresa como admin). */
function wa_cg_group_fetch(string $groupId, ?array $row = null): ?array
{
    $res = wa_cloud_graph('GET', $groupId, ['fields' => 'subject,description,participants,total_participant_count,creation_timestamp,suspended,join_approval_mode'], null, 15);
    if (!wa_cloud_ok($res)) {
        return null;
    }
    $j = (array)$res['json'];
    $participants = [];
    foreach ((array)($j['participants'] ?? []) as $p) {
        if (!is_array($p)) continue;
        $jid = wa_cloud_contact_jid($p['wa_id'] ?? null, $p['user_id'] ?? null);
        if ($jid === '') continue;
        $participants[] = ['id' => $jid, 'jid' => $jid, 'phoneNumber' => !empty($p['wa_id']) ? $jid : null, 'admin' => null,
            'name' => (string)($p['username'] ?? '')];
    }
    if ($row !== null) {
        $display = (string)preg_replace('/\D+/', '', (string)($row['cloud_display_phone'] ?? ''));
        if ($display !== '') {
            array_unshift($participants, ['id' => $display . '@s.whatsapp.net', 'jid' => $display . '@s.whatsapp.net', 'phoneNumber' => $display . '@s.whatsapp.net', 'admin' => 'superadmin']);
        }
    }
    return ['subject' => (string)($j['subject'] ?? ''), 'desc' => (string)($j['description'] ?? ''), 'participants' => $participants,
        'size' => (int)($j['total_participant_count'] ?? count($participants)), 'creation' => (int)($j['creation_timestamp'] ?? 0),
        'suspended' => !empty($j['suspended']), 'join_approval_mode' => (string)($j['join_approval_mode'] ?? '')];
}

function wa_cg_group_info(array $row, string $groupJid, bool $onlyParticipants): array
{
    $gid = wa_cg_group_id($groupJid);
    if ($gid === '') {
        return wa_cg_error(400, 'Informe o grupo (groupJid).');
    }
    $info = wa_cg_group_fetch($gid, $row);
    if ($info === null) {
        $res = wa_cloud_graph('GET', $gid, ['fields' => 'subject'], null, 10);
        return wa_cg_meta_error($res, 'Grupo não encontrado na API oficial');
    }
    if ($onlyParticipants) {
        return wa_cg_resp(200, ['participants' => $info['participants']]);
    }
    $display = (string)preg_replace('/\D+/', '', (string)($row['cloud_display_phone'] ?? ''));
    return wa_cg_resp(200, ['id' => wa_cloud_group_jid($gid), 'subject' => $info['subject'], 'desc' => $info['desc'], 'size' => $info['size'],
        'creation' => $info['creation'], 'owner' => $display . '@s.whatsapp.net', 'announce' => false, 'restrict' => false,
        'participants' => $info['participants'], 'suspended' => $info['suspended'], 'inviteUrl' => wa_cloud_group_link($gid)]);
}

/** add = envia o convite; remove = tira do grupo; promote/demote = não existe na API oficial. */
function wa_cg_group_participants(array $row, string $groupJid, string $action, array $participants, bool $forceInvite = false): array
{
    $gid = wa_cg_group_id($groupJid);
    if ($gid === '') {
        return wa_cg_error(400, 'Informe o grupo (groupJid).');
    }
    if ($action === 'promote' || $action === 'demote') {
        return wa_cg_error(400, wa_provider_cannot_reason('group_promote'));
    }
    if ($action === 'add') {
        $link = wa_cloud_group_link($gid);
        if ($link === '') {
            return wa_cg_error(502, 'Não foi possível obter o link de convite do grupo na Meta.');
        }
        $r = wa_cg_send_invite_batch($row, $gid, wa_cg_group_subject($gid), $link, $participants, '', $forceInvite);
        $out = [];
        foreach ($participants as $p) {
            $p = is_array($p) ? (string)($p['id'] ?? '') : (string)$p;
            $fail = null;
            foreach ($r['failed'] as $f) {
                if ($f['number'] === $p) {
                    $fail = $f['error'];
                }
            }
            $out[] = ['jid' => $p, 'status' => $fail === null ? 'invite_sent' : '400', 'error' => $fail];
        }
        if ($r['sent'] === 0 && $r['failed']) {
            return wa_cg_error(400, 'Nenhum convite enviado: ' . (string)$r['failed'][0]['error'], ['participants' => $out]);
        }
        return wa_cg_resp(200, ['updateParticipants' => $out, 'invitesSent' => $r['sent'], 'note' => wa_provider_cannot_reason('group_add_members')]);
    }
    if ($action === 'remove') {
        $users = [];
        foreach ($participants as $p) {
            $p = is_array($p) ? (string)($p['id'] ?? '') : (string)$p;
            $t = wa_cloud_target($p);
            if (!empty($t['to'])) {
                $users[] = ['user' => $t['to']];
            } elseif (!empty($t['recipient'])) {
                $users[] = ['user_id' => $t['recipient']];
            }
        }
        if (!$users) {
            return wa_cg_error(400, 'Nenhum participante válido para remover.');
        }
        $removed = 0;
        $last = null;
        foreach (array_chunk($users, 8) as $chunk) {
            $last = wa_cloud_graph('DELETE', $gid . '/participants', [], ['messaging_product' => 'whatsapp', 'participants' => $chunk], 20);
            if (wa_cloud_ok($last)) {
                $removed += count($chunk);
            }
        }
        if ($removed === 0 && $last !== null) {
            return wa_cg_meta_error($last, 'Não foi possível remover');
        }
        return wa_cg_resp(200, ['updateParticipants' => array_map(static fn($u) => ['jid' => (string)($u['user'] ?? $u['user_id']), 'status' => '200'], $users)]);
    }
    return wa_cg_error(400, 'Ação de participante desconhecida: ' . $action);
}

function wa_cg_group_update(array $row, string $groupJid, array $fields): array
{
    $gid = wa_cg_group_id($groupJid);
    $fields = array_filter($fields, static fn($v) => $v !== '');
    if ($gid === '' || !$fields) {
        return wa_cg_error(400, 'Informe o grupo e o novo valor.');
    }
    $res = wa_cloud_graph('POST', $gid, [], ['messaging_product' => 'whatsapp'] + $fields, 15);
    return wa_cloud_ok($res) ? wa_cg_resp(200, ['update' => 'success']) : wa_cg_meta_error($res, 'Grupo não atualizado');
}

function wa_cg_group_picture(array $row, string $groupJid, string $image): array
{
    $gid = wa_cg_group_id($groupJid);
    $file = wa_cloud_media_resolve($image, 'image/jpeg');
    if ($gid === '' || !$file['ok']) {
        return wa_cg_error(400, 'Foto do grupo: ' . ($file['error'] ?: 'grupo não informado'));
    }
    if ($file['mime'] !== 'image/jpeg') {
        return wa_cg_error(400, 'A foto do grupo na API oficial precisa ser JPEG quadrado (mínimo 192 x 192).');
    }
    $res = wa_cloud_graph('POST', $gid, [], null, 30, ['multipart' => ['messaging_product' => 'whatsapp',
        'file' => ['bin' => $file['bin'], 'mime' => 'image/jpeg', 'name' => 'grupo.jpg']]]);
    return wa_cloud_ok($res) ? wa_cg_resp(200, ['update' => 'success']) : wa_cg_meta_error($res, 'Foto não atualizada');
}

function wa_cg_group_invite(array $row, string $groupJid, bool $reset): array
{
    $gid = wa_cg_group_id($groupJid);
    if ($gid === '') {
        return wa_cg_error(400, 'Informe o grupo (groupJid).');
    }
    $link = wa_cloud_group_link($gid, $reset);
    if ($link === '') {
        return wa_cg_error(502, 'A Meta não devolveu o link de convite.');
    }
    return wa_cg_resp(200, ['inviteUrl' => $link, 'inviteCode' => (string)preg_replace('#^https://chat\.whatsapp\.com/#', '', $link), 'revoked' => $reset]);
}

function wa_cg_group_send_invites(array $row, string $groupJid, array $numbers, string $description): array
{
    $gid = wa_cg_group_id($groupJid);
    $link = $gid !== '' ? wa_cloud_group_link($gid) : '';
    if ($link === '') {
        return wa_cg_error(400, 'Grupo sem link de convite.');
    }
    $r = wa_cg_send_invite_batch($row, $gid, wa_cg_group_subject($gid), $link, $numbers, $description, true);
    if ($r['sent'] === 0 && $r['failed']) {
        return wa_cg_error(400, 'Nenhum convite enviado: ' . (string)$r['failed'][0]['error']);
    }
    return wa_cg_resp(200, ['send' => true, 'inviteUrl' => $link, 'sent' => $r['sent'], 'failed' => $r['failed']]);
}

function wa_cg_group_delete(array $row, string $groupJid): array
{
    $gid = wa_cg_group_id($groupJid);
    if ($gid === '') {
        return wa_cg_error(400, 'Informe o grupo (groupJid).');
    }
    $res = wa_cloud_graph('DELETE', $gid, [], null, 20);
    return wa_cloud_ok($res) ? wa_cg_resp(200, ['deleted' => true]) : wa_cg_meta_error($res, 'Grupo não excluído');
}

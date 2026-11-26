<?php

declare(strict_types=1);

/**
 * Webhook da API oficial (Meta) -> formato que o sistema já processa.
 *
 * whatsapp_cloud_webhook.php confere a assinatura e chama wa_cloud_translate():
 * cada mensagem recebida vira um "messages.upsert" igual ao do Baileys/Evolution
 * (key.remoteJid, key.id, message.conversation, imageMessage...), então chat,
 * reações, interesse em captação, mídia e reabertura de conversa seguem as
 * mesmas regras de app/whatsapp_webhook_handler.php.
 *
 * Eventos que só existem na Meta viram "cloud.*" e são tratados aqui:
 *   cloud.status   enviada / entregue / lida / FALHOU (motivo aparece no chat)
 *   cloud.group    grupo criado/excluído, entrou/saiu participante, ajustes
 *   cloud.template modelo aprovado/recusado/pausado
 *   cloud.account  avisos da conta e do número (qualidade, limite, bloqueio)
 */

/** Confere o X-Hub-Signature-256 (HMAC SHA-256 do corpo com a chave secreta do app). */
function wa_cloud_signature_ok(string $raw, string $header): bool
{
    $secret = wa_cloud_secret('app_secret');
    if ($secret === '') {
        return true; // sem chave configurada: aceita (a tela avisa que falta)
    }
    if (!str_starts_with($header, 'sha256=')) {
        return false;
    }
    return hash_equals(hash_hmac('sha256', $raw, $secret), substr($header, 7));
}

/** Tipo de mensagem da Meta -> conteúdo no formato do sistema. */
function wa_cloud_translate_content(array $m, string $chatJid, string $instance): ?array
{
    $type = (string)($m['type'] ?? '');
    $ctxIn = is_array($m['context'] ?? null) ? $m['context'] : [];
    $ctx = null;
    if (!empty($ctxIn['id'])) {
        $quotedText = '';
        try {
            $st = db()->prepare('SELECT message_text FROM chat_messages WHERE external_message_id = ? LIMIT 1');
            $st->execute([(string)$ctxIn['id']]);
            $quotedText = (string)($st->fetchColumn() ?: '');
        } catch (Throwable $e) {}
        $ctx = ['stanzaId' => (string)$ctxIn['id'], 'quotedMessage' => ['conversation' => $quotedText]];
        if (!empty($ctxIn['from'])) {
            $ctx['participant'] = wa_cloud_contact_jid((string)$ctxIn['from']);
        }
    }
    $withCtx = static function (array $msgObj) use ($ctx): array {
        if ($ctx !== null) {
            $msgObj['contextInfo'] = $ctx;
        }
        return $msgObj;
    };
    $media = static function (string $evoKey, array $src, array $extra = []) use ($withCtx): array {
        $obj = ['mimetype' => (string)($src['mime_type'] ?? ''), 'cloudMediaId' => (string)($src['id'] ?? ''), 'fileSha256' => (string)($src['sha256'] ?? '')];
        if (isset($src['caption']) && $src['caption'] !== '') {
            $obj['caption'] = (string)$src['caption'];
        }
        return [$evoKey => $withCtx($obj + $extra)];
    };
    switch ($type) {
        case 'text':
            $body = (string)($m['text']['body'] ?? '');
            return $ctx !== null ? ['extendedTextMessage' => ['text' => $body, 'contextInfo' => $ctx]] : ['conversation' => $body];
        case 'image':
            return $media('imageMessage', (array)($m['image'] ?? []));
        case 'video':
            return $media('videoMessage', (array)($m['video'] ?? []));
        case 'audio':
            $a = (array)($m['audio'] ?? []);
            return $media('audioMessage', $a, ['ptt' => !empty($a['voice'])]);
        case 'document':
            $d = (array)($m['document'] ?? []);
            return $media('documentMessage', $d, ['fileName' => (string)($d['filename'] ?? 'documento')]);
        case 'sticker':
            return $media('stickerMessage', (array)($m['sticker'] ?? []), ['isAnimated' => !empty($m['sticker']['animated'])]);
        case 'location':
            $l = (array)($m['location'] ?? []);
            return ['locationMessage' => $withCtx(['degreesLatitude' => (float)($l['latitude'] ?? 0), 'degreesLongitude' => (float)($l['longitude'] ?? 0),
                'name' => (string)($l['name'] ?? ''), 'address' => (string)($l['address'] ?? '')])];
        case 'contacts':
            $cards = [];
            foreach ((array)($m['contacts'] ?? []) as $c) {
                if (!is_array($c)) continue;
                $name = (string)($c['name']['formatted_name'] ?? ($c['name']['first_name'] ?? 'Contato'));
                $tel = '';
                foreach ((array)($c['phones'] ?? []) as $ph) {
                    $wa = (string)preg_replace('/\D+/', '', (string)($ph['wa_id'] ?? ($ph['phone'] ?? '')));
                    if ($wa !== '') {
                        $tel = "TEL;type=CELL;waid=$wa:+$wa\n";
                        break;
                    }
                }
                $cards[] = ['displayName' => $name, 'vcard' => "BEGIN:VCARD\nVERSION:3.0\nFN:$name\n{$tel}END:VCARD"];
            }
            if (!$cards) {
                return null;
            }
            return count($cards) === 1 ? ['contactMessage' => $withCtx($cards[0])] : ['contactsArrayMessage' => ['contacts' => $cards]];
        case 'button':
            // resposta rápida de modelo
            return ['buttonsResponseMessage' => $withCtx(['selectedDisplayText' => (string)($m['button']['text'] ?? ''), 'selectedButtonId' => (string)($m['button']['payload'] ?? '')])];
        case 'interactive':
            $i = (array)($m['interactive'] ?? []);
            if (($i['type'] ?? '') === 'button_reply') {
                return ['buttonsResponseMessage' => $withCtx(['selectedDisplayText' => (string)($i['button_reply']['title'] ?? ''), 'selectedButtonId' => (string)($i['button_reply']['id'] ?? '')])];
            }
            if (($i['type'] ?? '') === 'list_reply') {
                return ['listResponseMessage' => $withCtx(['title' => (string)($i['list_reply']['title'] ?? ''), 'description' => (string)($i['list_reply']['description'] ?? ''),
                    'singleSelectReply' => ['selectedRowId' => (string)($i['list_reply']['id'] ?? '')]])];
            }
            if (($i['type'] ?? '') === 'nfm_reply') {
                return ['conversation' => '[Formulário respondido] ' . mb_substr((string)($i['nfm_reply']['response_json'] ?? ''), 0, 1000)];
            }
            return ['conversation' => '[Resposta interativa]'];
        case 'reaction':
            $r = (array)($m['reaction'] ?? []);
            return ['reactionMessage' => ['key' => ['remoteJid' => $chatJid, 'id' => (string)($r['message_id'] ?? ''), 'fromMe' => true], 'text' => (string)($r['emoji'] ?? '')]];
        case 'order':
            return ['conversation' => '🛒 Pedido recebido pelo WhatsApp (' . count((array)($m['order']['product_items'] ?? [])) . ' item(ns))'];
        case 'system':
            return ['conversation' => '[Aviso do WhatsApp] ' . (string)($m['system']['body'] ?? 'mudança no contato')];
        case 'request_welcome':
            return null; // pessoa abriu a conversa sem escrever nada
        case 'unsupported':
        default:
            $why = (string)($m['errors'][0]['error_data']['details'] ?? ($m['errors'][0]['title'] ?? ''));
            return ['conversation' => '[Mensagem que a API oficial não entrega ao sistema' . ($why !== '' ? ': ' . $why : '') . ' — veja no celular]'];
    }
}

/** Botão de interesse? (resposta "Tenho interesse" da captação) */
function wa_cloud_is_interest_reply(array $m): bool
{
    $type = (string)($m['type'] ?? '');
    $id = '';
    $text = '';
    if ($type === 'button') {
        $id = (string)($m['button']['payload'] ?? '');
        $text = (string)($m['button']['text'] ?? '');
    } elseif ($type === 'interactive' && ($m['interactive']['type'] ?? '') === 'button_reply') {
        $id = (string)($m['interactive']['button_reply']['id'] ?? '');
        $text = (string)($m['interactive']['button_reply']['title'] ?? '');
    } else {
        return false;
    }
    return str_starts_with($id, 'interesse') || mb_strtolower(trim($text)) === 'tenho interesse';
}

/**
 * Converte o corpo do webhook da Meta em payloads do sistema.
 * @return array[] lista de payloads {event, instance, data}
 */
function wa_cloud_translate(array $meta): array
{
    $out = [];
    foreach ((array)($meta['entry'] ?? []) as $entry) {
        $wabaId = (string)($entry['id'] ?? '');
        foreach ((array)($entry['changes'] ?? []) as $change) {
            $field = (string)($change['field'] ?? '');
            $value = is_array($change['value'] ?? null) ? $change['value'] : [];
            $pnid = (string)($value['metadata']['phone_number_id'] ?? '');
            $instance = wa_cloud_instance_by_phone_id($pnid);
            if ($pnid !== '' && $instance === '') {
                wa_event_log('warn', 'webhook', 'numero_desconhecido', [
                    'summary' => 'Webhook da API oficial para um número que não está cadastrado no sistema (phone_number_id ' . $pnid . '): cadastre em Configurações → API oficial',
                    'details' => ['phone_number_id' => $pnid, 'display' => $value['metadata']['display_phone_number'] ?? null, 'campo' => $field],
                ]);
                $instance = 'cloud-' . $pnid;
            }
            switch ($field) {
                case 'messages':
                    $out = array_merge($out, wa_cloud_translate_messages($instance, $value));
                    break;
                case 'smb_message_echoes':
                    $out = array_merge($out, wa_cloud_translate_echoes($instance, $value));
                    break;
                case 'group_lifecycle_update':
                case 'group_participants_update':
                case 'group_settings_update':
                case 'group_status_update':
                    foreach ((array)($value['groups'] ?? []) as $g) {
                        if (is_array($g)) {
                            $out[] = ['event' => 'cloud.group', 'instance' => $instance, 'data' => ['field' => $field, 'group' => $g]];
                        }
                    }
                    break;
                case 'message_template_status_update':
                case 'message_template_quality_update':
                case 'message_template_components_update':
                case 'template_category_update':
                    $out[] = ['event' => 'cloud.template', 'instance' => $instance, 'data' => ['field' => $field, 'value' => $value]];
                    break;
                default:
                    $out[] = ['event' => 'cloud.account', 'instance' => $instance, 'data' => ['field' => $field, 'value' => $value, 'waba' => $wabaId]];
            }
        }
    }
    return $out;
}

/** messages: mensagens recebidas e situações (enviada/entregue/lida/falhou). */
function wa_cloud_translate_messages(string $instance, array $value): array
{
    $out = [];
    $names = [];
    foreach ((array)($value['contacts'] ?? []) as $c) {
        if (!is_array($c)) continue;
        $name = trim((string)($c['profile']['name'] ?? ($c['profile']['username'] ?? '')));
        foreach ([(string)($c['wa_id'] ?? ''), (string)($c['user_id'] ?? '')] as $k) {
            if ($k !== '') {
                $names[$k] = $name;
            }
        }
    }
    foreach ((array)($value['messages'] ?? []) as $m) {
        if (!is_array($m) || empty($m['id'])) {
            continue;
        }
        $fromWa = (string)($m['from'] ?? '');
        $fromUser = (string)($m['from_user_id'] ?? '');
        $senderJid = wa_cloud_contact_jid($fromWa, $fromUser);
        $groupId = (string)($m['group_id'] ?? '');
        $chatJid = $groupId !== '' ? wa_cloud_group_jid($groupId) : $senderJid;
        if ($chatJid === '') {
            continue;
        }
        $ts = (int)($m['timestamp'] ?? time());
        $content = wa_cloud_translate_content($m, $chatJid, $instance);
        if ($content === null) {
            continue;
        }
        $key = ['remoteJid' => $chatJid, 'fromMe' => false, 'id' => (string)$m['id']];
        if ($groupId !== '') {
            $key['participant'] = $senderJid;
            $key['participantPn'] = $fromWa !== '' ? $fromWa . '@s.whatsapp.net' : '';
        }
        $record = [
            'key' => $key,
            'pushName' => (string)($names[$fromWa] ?? ($names[$fromUser] ?? '')),
            'message' => $content,
            'messageType' => (string)(array_key_first($content) ?? 'conversation'),
            'messageTimestamp' => $ts,
            'source' => 'cloud_api',
        ];
        $mediaId = null;
        foreach ($content as $v) {
            if (is_array($v) && !empty($v['cloudMediaId'])) {
                $mediaId = (string)$v['cloudMediaId'];
            }
        }
        // Reação não é guardada como mensagem
        if (!isset($content['reactionMessage'])) {
            wa_cloud_store_record($instance, $record, $mediaId);
        }
        // Janela de 24 h: só conversa privada (mensagem do contato)
        if ($groupId === '') {
            wa_cloud_window_touch($instance, $senderJid, $ts);
        }
        $out[] = ['event' => 'messages.upsert', 'instance' => $instance, 'data' => $record, 'source' => 'cloud_api'];

        // "Tenho interesse": também conta como reação na mensagem da captação
        if (!empty($m['context']['id']) && wa_cloud_is_interest_reply($m)) {
            $react = ['key' => $key + ['id' => (string)$m['id'] . '.interesse'], 'pushName' => $record['pushName'], 'messageTimestamp' => $ts,
                'message' => ['reactionMessage' => ['key' => ['remoteJid' => $chatJid, 'id' => (string)$m['context']['id'], 'fromMe' => true], 'text' => '👍']],
                'source' => 'cloud_api'];
            $react['key']['id'] = (string)$m['id'] . '.interesse';
            $out[] = ['event' => 'messages.upsert', 'instance' => $instance, 'data' => $react, 'source' => 'cloud_api'];
        }
    }
    foreach ((array)($value['statuses'] ?? []) as $s) {
        if (!is_array($s) || empty($s['id'])) {
            continue;
        }
        $out[] = ['event' => 'cloud.status', 'instance' => $instance, 'data' => $s + ['key' => ['id' => (string)$s['id'] . ':' . (string)($s['status'] ?? '')]]];
    }
    foreach ((array)($value['errors'] ?? []) as $e) {
        if (is_array($e)) {
            $out[] = ['event' => 'cloud.account', 'instance' => $instance, 'data' => ['field' => 'messages.errors', 'value' => $e]];
        }
    }
    return $out;
}

/** Mensagens enviadas pelo app WhatsApp Business no celular (coexistência). */
function wa_cloud_translate_echoes(string $instance, array $value): array
{
    $out = [];
    foreach ((array)($value['message_echoes'] ?? []) as $m) {
        if (!is_array($m) || empty($m['id'])) {
            continue;
        }
        $toJid = wa_cloud_contact_jid((string)($m['to'] ?? ''), (string)($m['to_user_id'] ?? ''));
        if ($toJid === '') {
            continue;
        }
        $content = wa_cloud_translate_content($m, $toJid, $instance);
        if ($content === null) {
            continue;
        }
        $record = ['key' => ['remoteJid' => $toJid, 'fromMe' => true, 'id' => (string)$m['id']], 'pushName' => '', 'message' => $content,
            'messageType' => (string)(array_key_first($content) ?? 'conversation'), 'messageTimestamp' => (int)($m['timestamp'] ?? time()), 'source' => 'cloud_app'];
        wa_cloud_store_record($instance, $record);
        $out[] = ['event' => 'messages.upsert', 'instance' => $instance, 'data' => $record, 'source' => 'cloud_app'];
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Eventos "cloud.*" (chamados por wa_webhook_handle)
// ---------------------------------------------------------------------------

function wa_cloud_handle_event(string $event, array $payload): void
{
    $instance = (string)($payload['instance'] ?? '');
    $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
    switch ($event) {
        case 'cloud.status':
            wa_cloud_handle_status($instance, $data);
            break;
        case 'cloud.group':
            wa_cloud_handle_group($instance, (string)($data['field'] ?? ''), is_array($data['group'] ?? null) ? $data['group'] : []);
            break;
        case 'cloud.template':
            wa_cloud_handle_template((string)($data['field'] ?? ''), is_array($data['value'] ?? null) ? $data['value'] : []);
            break;
        case 'cloud.account':
            $field = (string)($data['field'] ?? '');
            $v = $data['value'] ?? [];
            $bad = in_array($field, ['account_alerts', 'account_review_update', 'phone_number_quality_update', 'account_update', 'messages.errors', 'security'], true);
            wa_event_log($bad ? 'warn' : 'info', 'conexao', 'aviso_da_meta', [
                'instance' => $instance !== '' ? $instance : null,
                'summary' => 'Aviso da Meta (' . $field . '): ' . mb_substr(wa_cloud_account_summary($field, is_array($v) ? $v : []), 0, 300),
                'details' => $v,
            ]);
            if ($field === 'phone_number_quality_update' && is_array($v)) {
                try {
                    db()->prepare('UPDATE whatsapp_instances SET cloud_quality = ? WHERE cloud_display_phone = ?')
                        ->execute([(string)($v['current_limit'] ?? ($v['event'] ?? '')), (string)preg_replace('/\D+/', '', (string)($v['display_phone_number'] ?? ''))]);
                } catch (Throwable $e) {}
            }
            break;
    }
}

function wa_cloud_account_summary(string $field, array $v): string
{
    if ($field === 'phone_number_quality_update') {
        return 'número ' . (string)($v['display_phone_number'] ?? '') . ': ' . (string)($v['event'] ?? '') . ' · limite ' . (string)($v['current_limit'] ?? '');
    }
    if ($field === 'account_update') {
        return (string)($v['event'] ?? '') . (isset($v['ban_info']) ? ' · bloqueio: ' . json_encode($v['ban_info'], JSON_UNESCAPED_UNICODE) : '');
    }
    if ($field === 'messages.errors') {
        return (string)($v['title'] ?? '') . ' ' . (string)($v['message'] ?? '');
    }
    return mb_substr((string)json_encode($v, JSON_UNESCAPED_UNICODE), 0, 300);
}

/** Situação de uma mensagem enviada. "failed" mostra o motivo no chat. */
function wa_cloud_handle_status(string $instance, array $s): void
{
    $id = (string)($s['id'] ?? '');
    $status = strtolower((string)($s['status'] ?? ''));
    if ($id === '') {
        return;
    }
    if (function_exists('demand_dispatch_direct_status')) {
        demand_dispatch_direct_status($id, $status, $s);
    }
    if ($status !== 'failed') {
        return;
    }
    $err = is_array($s['errors'][0] ?? null) ? $s['errors'][0] : [];
    $code = (int)($err['code'] ?? 0);
    $text = wa_cloud_error_text(['status' => 400, 'json' => ['error' => ['code' => $code, 'message' => (string)($err['message'] ?? ($err['title'] ?? '')),
        'error_data' => ['details' => (string)($err['error_data']['details'] ?? '')]]]]);
    $recipient = (string)($s['recipient_id'] ?? ($s['recipient_user_id'] ?? ''));
    $jid = ($s['recipient_type'] ?? '') === 'group' ? wa_cloud_group_jid($recipient) : wa_cloud_contact_jid($recipient);

    // Fora da janela (registro de janela desatualizado): reenvia pelo modelo de aviso, 1 vez
    $retried = false;
    if ($code === 131047 && $instance !== '') {
        $found = wa_cloud_find_record($id);
        $msg = is_array($found['record']['message'] ?? null) ? $found['record']['message'] : [];
        $origText = (string)($msg['conversation'] ?? ($msg['extendedTextMessage']['text'] ?? ''));
        if ($origText !== '' && ($found['record']['cloudVia'] ?? '') === 'text' && empty($found['record']['cloudRetried'])) {
            try {
                $res = wa_cloud_gateway('POST', '/message/sendText/' . rawurlencode($instance), [], ['number' => $jid, 'text' => $origText, 'cloudForceTemplate' => true], 30);
                $retried = (int)$res['status'] >= 200 && (int)$res['status'] < 300;
                $found['record']['cloudRetried'] = true;
                wa_cloud_store_record($instance, $found['record']);
            } catch (Throwable $e) {}
        }
    }
    try {
        wa_cloud_ensure_schema();
        db()->prepare('UPDATE chat_messages SET send_error = ? WHERE external_message_id = ?')
            ->execute([mb_substr(($retried ? '[reenviada pelo modelo de aviso] ' : '') . $text, 0, 255), $id]);
    } catch (Throwable $e) {}
    wa_event_log($retried ? 'warn' : 'error', 'enviada', $retried ? 'reenviada_por_modelo' : 'envio_falhou', [
        'instance' => $instance !== '' ? $instance : null, 'jid' => $jid, 'message_id' => $id, 'status' => 'failed',
        'summary' => ($retried ? 'A Meta recusou a mensagem livre (fora das 24 h) e o sistema reenviou pelo modelo de aviso' : 'A Meta NÃO entregou a mensagem: ' . $text),
        'details' => ['erro' => $err, 'conversa' => $s['conversation'] ?? null, 'preco' => $s['pricing'] ?? null],
    ]);
}

/** Grupo: criação (id + link), participantes que entraram/saíram, ajustes. */
function wa_cloud_handle_group(string $instance, string $field, array $g): void
{
    wa_cloud_ensure_schema();
    $gid = (string)($g['group_id'] ?? '');
    $type = (string)($g['type'] ?? $field);
    $jid = wa_cloud_group_jid($gid);
    $errText = '';
    if (!empty($g['errors'][0]) && is_array($g['errors'][0])) {
        $e = $g['errors'][0];
        $errText = trim((string)($e['title'] ?? '') . ': ' . (string)($e['error_data']['details'] ?? ($e['message'] ?? '')), ': ');
    }
    if ($type === 'group_create') {
        $req = (string)($g['request_id'] ?? '');
        try {
            if ($errText !== '') {
                db()->prepare("UPDATE wa_cloud_group_requests SET status = 'failed', error = ? WHERE request_id = ?")->execute([mb_substr($errText, 0, 500), $req]);
            } else {
                $up = db()->prepare("UPDATE wa_cloud_group_requests SET group_id = ?, invite_link = ?, status = 'created' WHERE request_id = ?");
                $up->execute([$gid, (string)($g['invite_link'] ?? ''), $req]);
                if ($up->rowCount() === 0) {
                    db()->prepare("INSERT INTO wa_cloud_group_requests (request_id, instance_name, subject, group_id, invite_link, status) VALUES (?, ?, ?, ?, ?, 'created')")
                        ->execute([$req !== '' ? $req : null, $instance, (string)($g['subject'] ?? ''), $gid, (string)($g['invite_link'] ?? '')]);
                }
                if (function_exists('wa_cloud_group_link_remember')) {
                    wa_cloud_group_link_remember($gid, (string)($g['invite_link'] ?? ''));
                }
            }
        } catch (Throwable $e) {}
        wa_event_log($errText !== '' ? 'error' : 'info', 'grupo', $errText !== '' ? 'grupo_nao_criado' : 'grupo_criado_confirmado', [
            'instance' => $instance, 'jid' => $jid,
            'summary' => $errText !== '' ? 'A Meta NÃO criou o grupo "' . (string)($g['subject'] ?? '') . '": ' . $errText
                : 'A Meta confirmou o grupo "' . (string)($g['subject'] ?? '') . '"',
        ]);
        return;
    }
    if ($type === 'group_delete') {
        try {
            db()->prepare("UPDATE whatsapp_groups SET status = 'inactive' WHERE evolution_group_jid = ?")->execute([$jid]);
        } catch (Throwable $e) {}
        wa_event_log($errText !== '' ? 'error' : 'info', 'grupo', 'grupo_excluido', ['instance' => $instance, 'jid' => $jid,
            'summary' => $errText !== '' ? 'Exclusão do grupo falhou: ' . $errText : 'Grupo excluído na API oficial']);
        return;
    }
    if ($type === 'group_participants_add' || $type === 'group_participants_remove') {
        $list = (array)($g[$type === 'group_participants_add' ? 'added_participants' : 'removed_participants'] ?? []);
        $names = [];
        foreach ($list as $p) {
            if (!is_array($p)) continue;
            $pj = wa_cloud_contact_jid($p['wa_id'] ?? ($p['input'] ?? null), $p['user_id'] ?? null);
            $label = (string)($p['username'] ?? '') !== '' ? (string)$p['username'] : (string)preg_replace('/@.*$/', '', $pj);
            $names[] = $label;
        }
        $left = ($g['initiated_by'] ?? '') === 'participant';
        $verb = $type === 'group_participants_add' ? 'entrou no grupo' : ($left ? 'saiu do grupo' : 'foi removido(a) do grupo');
        if ($names && $jid !== '') {
            // Aviso no chat do grupo
            try {
                $text = '👤 ' . implode(', ', $names) . ' ' . $verb . ($type === 'group_participants_add' && ($g['reason'] ?? '') === 'invite_link' ? ' pelo link de convite' : '');
                db()->prepare("INSERT INTO chat_messages (remote_jid, instance_name, message_text, message_type, from_me, message_timestamp, external_message_id)
                               VALUES (?, ?, ?, 'system', 0, ?, ?)")
                    ->execute([$jid, $instance !== '' ? $instance : null, $text, (int)($g['timestamp'] ?? time()),
                        'cloudgrp.' . substr(sha1($jid . '|' . $type . '|' . implode(',', $names) . '|' . (string)($g['timestamp'] ?? '')), 0, 30)]);
            } catch (Throwable $e) {}
        }
        wa_event_log('info', 'grupo', $type === 'group_participants_add' ? 'participante_entrou' : 'participante_saiu', ['instance' => $instance, 'jid' => $jid,
            'summary' => ($names ? implode(', ', $names) : 'Participante') . ' ' . $verb . ($errText !== '' ? ' (erro: ' . $errText . ')' : ''),
            'details' => $g]);
        return;
    }
    if ($type === 'group_join_request_created' || $type === 'group_join_request_revoked') {
        wa_event_log('info', 'grupo', 'pedido_de_entrada', ['instance' => $instance, 'jid' => $jid,
            'summary' => ($type === 'group_join_request_created' ? 'Pedido para entrar no grupo' : 'Pedido para entrar cancelado') . ': ' . (string)($g['wa_id'] ?? ($g['username'] ?? '')),
            'details' => $g]);
        return;
    }
    if ($type === 'group_settings_update' && !empty($g['group_subject']['text']) && !empty($g['group_subject']['update_successful'])) {
        try {
            db()->prepare('UPDATE whatsapp_groups SET name = ? WHERE evolution_group_jid = ?')->execute([(string)$g['group_subject']['text'], $jid]);
        } catch (Throwable $e) {}
    }
    wa_event_log($errText !== '' ? 'warn' : 'info', 'grupo', 'grupo_atualizado', ['instance' => $instance, 'jid' => $jid,
        'summary' => 'Grupo (' . $type . ')' . ($errText !== '' ? ': ' . $errText : ' atualizado') . (isset($g['status']) ? ' · situação ' . (string)$g['status'] : ''),
        'details' => $g]);
}

/** Modelo aprovado / recusado / pausado: atualiza a lista e avisa os administradores. */
function wa_cloud_handle_template(string $field, array $v): void
{
    wa_cloud_ensure_schema();
    $name = (string)($v['message_template_name'] ?? '');
    $lang = (string)($v['message_template_language'] ?? '');
    $event = strtoupper((string)($v['event'] ?? ($v['new_quality_score'] ?? '')));
    if ($name !== '' && $lang !== '' && $field === 'message_template_status_update' && $event !== '') {
        try {
            db()->prepare('UPDATE wa_cloud_templates SET status = ?, rejected_reason = ? WHERE name = ? AND language = ?')
                ->execute([$event, isset($v['reason']) && $v['reason'] !== 'NONE' ? (string)$v['reason'] : null, $name, $lang]);
        } catch (Throwable $e) {}
    }
    $bad = in_array($event, ['REJECTED', 'PAUSED', 'DISABLED', 'FLAGGED', 'RED'], true);
    wa_event_log($bad ? 'warn' : 'info', 'sistema', 'modelo_atualizado', [
        'summary' => 'Modelo "' . $name . '" (' . $lang . '): ' . $event . (!empty($v['reason']) && $v['reason'] !== 'NONE' ? ' · motivo: ' . (string)$v['reason'] : ''),
        'details' => $v,
    ]);
    if ($bad && function_exists('notification_create')) {
        try {
            $admins = db()->query("SELECT DISTINCT ur.user_id FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id WHERE r.slug = 'admin'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($admins as $uid) {
                notification_create((int)$uid, 'whatsapp', 'Modelo do WhatsApp ' . strtolower($event),
                    'O modelo "' . $name . '" da API oficial ficou ' . $event . '. Mensagens que dependem dele podem não sair.', '/admin_whatsapp_cloud.php#modelos');
            }
        } catch (Throwable $e) {}
    }
}

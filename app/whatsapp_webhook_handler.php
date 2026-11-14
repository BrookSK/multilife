<?php

declare(strict_types=1);

/**
 * Recebimento do webhook da Evolution (modelo do helpdeskON).
 *
 * Fluxo: o webhook decodifica o evento e chama wa_webhook_handle(). Cada mensagem
 * passa por UM processamento (wa_wh_process_message): identifica o tipo, grava a
 * mídia, deduplica pelo id da mensagem do WhatsApp e grava em chat_messages e
 * chat_contacts. Tudo síncrono e direto, como no helpdeskON.
 *
 * Particularidades do multilife mantidas:
 *  - WhatsApp de cada mensagem (instance_name) para separar as conversas;
 *  - reação em mensagem de captação registra o interesse do profissional;
 *  - confirmação do disparo de captação (demand_dispatch_confirm_from_webhook);
 *  - saúde da conexão (whatsapp_health_handle_webhook);
 *  - contato indicado no formato "[Contato indicado] 👤 Nome — telefone";
 *  - número real quando o WhatsApp manda só o LID (@lid);
 *  - conversa concluída volta para "aguardando" quando o contato responde.
 */

/** Ponto de entrada: um payload do webhook. */
function wa_webhook_handle(array $payload): void
{
    $event = strtolower(str_replace('_', '.', (string)($payload['event'] ?? '')));
    $instance = trim((string)($payload['instance'] ?? ($payload['instanceName'] ?? '')));
    // Usado pelos helpers (rastreio, mídia, LID)
    $GLOBALS['_webhookInstanceName'] = $instance !== '' ? $instance : null;

    // Saúde da conexão (connection.update / qrcode.updated)
    if (function_exists('whatsapp_health_handle_webhook')
        && whatsapp_health_handle_webhook($event, $instance, $payload)) {
        return;
    }

    // Confirma o disparo de captação quando o eco da mensagem chega ao grupo
    if (function_exists('demand_dispatch_confirm_from_webhook')) {
        try { demand_dispatch_confirm_from_webhook($event, $payload); } catch (Throwable $e) {}
    }

    switch ($event) {
        case 'messages.upsert':
        case 'send.message':
            foreach (wa_wh_message_list($payload['data'] ?? []) as $msg) {
                try {
                    wa_wh_process_message($instance, $msg, $event);
                } catch (Throwable $e) {
                    error_log('[WEBHOOK] erro ao processar mensagem: ' . $e->getMessage());
                    chat_webhook_trace($event, (array)($msg['key'] ?? []), 'erro: ' . $e->getMessage());
                }
            }
            break;
        case 'messages.delete':
            wa_wh_handle_delete($payload['data'] ?? []);
            break;
        case 'contacts.upsert':
        case 'contacts.update':
            wa_wh_handle_contacts($payload['data'] ?? []);
            break;
    }
}

/** Lista de mensagens do payload (formatos v1 e v2). */
function wa_wh_message_list($data): array
{
    if (!is_array($data)) {
        return [];
    }
    if (isset($data['messages']) && is_array($data['messages'])) {
        return array_values(array_filter($data['messages'], 'is_array'));
    }
    if (isset($data[0]) && is_array($data[0])) {
        return array_values(array_filter($data, 'is_array'));
    }
    if (isset($data['key'])) {
        return [$data];
    }
    return [];
}

/**
 * JID da conversa (número real). O WhatsApp pode identificar o contato por um
 * LID (@lid); a Evolution manda o número em remoteJidAlt/senderPn.
 */
function wa_wh_resolve_jid(array $key, bool $fromMe): string
{
    $remoteJid = (string)($key['remoteJid'] ?? '');
    $alt = (string)($key['remoteJidAlt'] ?? '');
    $senderPn = (string)($key['senderPn'] ?? '');
    $senderLid = (string)($key['senderLid'] ?? '');
    if ($remoteJid === '' || strpos($remoteJid, '@g.us') !== false) {
        return $remoteJid;
    }
    $isPn = static fn(string $j): bool => strpos($j, '@s.whatsapp.net') !== false;
    $isLid = static fn(string $j): bool => substr($j, -4) === '@lid';

    // Aprende o par LID <-> número quando a mensagem traz os dois
    if ($isLid($remoteJid) && $isPn($alt)) chat_lid_remember($remoteJid, $alt);
    if ($isPn($remoteJid) && $isLid($alt)) chat_lid_remember($alt, $remoteJid);
    if (!$fromMe) {
        if ($isPn($remoteJid) && $isLid($senderLid)) chat_lid_remember($senderLid, $remoteJid);
        if ($isLid($remoteJid) && $isPn($senderPn)) chat_lid_remember($remoteJid, $senderPn);
    }

    if (!$isLid($remoteJid)) {
        return $remoteJid;
    }
    if ($isPn($alt)) return $alt;
    if (!$fromMe && $isPn($senderPn)) return $senderPn;
    $known = chat_lid_lookup($remoteJid);
    return $known !== '' ? $known : $remoteJid;
}

/** Desembrulha mensagens temporárias / de visualização única / de outro aparelho. */
function wa_wh_unwrap(array $message): array
{
    for ($i = 0; $i < 3; $i++) {
        $changed = false;
        foreach (['ephemeralMessage', 'viewOnceMessage', 'viewOnceMessageV2', 'viewOnceMessageV2Extension', 'deviceSentMessage', 'documentWithCaptionMessage'] as $w) {
            if (isset($message[$w]['message']) && is_array($message[$w]['message'])) {
                $base64 = $message['base64'] ?? null;
                $message = $message[$w]['message'];
                if ($base64 !== null && !isset($message['base64'])) {
                    $message['base64'] = $base64;
                }
                $changed = true;
            }
        }
        if (!$changed) {
            break;
        }
    }
    return $message;
}

/** Textos de sistema do WhatsApp que não são mensagens de verdade. */
function wa_wh_is_system_text(string $text): bool
{
    foreach (['Aguardando mensagem', 'Waiting for message', 'Essa mensagem foi eliminada', 'This message was deleted', 'Uma mensagem foi eliminada'] as $p) {
        if (stripos($text, $p) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Tipo e conteúdo da mensagem (mesma ordem do helpdeskON: o conteúdo real vem
 * primeiro, então a chave de criptografia de grupo não descarta mais o texto).
 *
 * @return array{type:string,text:string,mime:?string,filename:?string,size:?int,media_key:?string}|null null = ignorar
 */
function wa_wh_parse_content(array $m): ?array
{
    $r = ['type' => 'text', 'text' => '', 'mime' => null, 'filename' => null, 'size' => null, 'media_key' => null];

    if (isset($m['conversation'])) {
        $r['text'] = (string)$m['conversation'];
    } elseif (isset($m['extendedTextMessage']['text'])) {
        $r['text'] = (string)$m['extendedTextMessage']['text'];
    } elseif (isset($m['imageMessage'])) {
        $r = array_merge($r, ['type' => 'image', 'text' => (string)($m['imageMessage']['caption'] ?? '') ?: '[Imagem]',
            'mime' => $m['imageMessage']['mimetype'] ?? 'image/jpeg', 'filename' => 'image.jpg',
            'size' => isset($m['imageMessage']['fileLength']) ? (int)$m['imageMessage']['fileLength'] : null, 'media_key' => 'imageMessage']);
    } elseif (isset($m['audioMessage'])) {
        $r = array_merge($r, ['type' => 'audio', 'text' => '[Áudio]',
            'mime' => $m['audioMessage']['mimetype'] ?? 'audio/ogg; codecs=opus', 'filename' => 'audio.ogg',
            'size' => isset($m['audioMessage']['fileLength']) ? (int)$m['audioMessage']['fileLength'] : null, 'media_key' => 'audioMessage']);
    } elseif (isset($m['videoMessage'])) {
        $r = array_merge($r, ['type' => 'video', 'text' => (string)($m['videoMessage']['caption'] ?? '') ?: '[Vídeo]',
            'mime' => $m['videoMessage']['mimetype'] ?? 'video/mp4', 'filename' => 'video.mp4',
            'size' => isset($m['videoMessage']['fileLength']) ? (int)$m['videoMessage']['fileLength'] : null, 'media_key' => 'videoMessage']);
    } elseif (isset($m['documentMessage'])) {
        $r = array_merge($r, ['type' => 'document', 'text' => (string)($m['documentMessage']['caption'] ?? '') ?: '[Documento]',
            'mime' => $m['documentMessage']['mimetype'] ?? 'application/octet-stream',
            'filename' => (string)($m['documentMessage']['fileName'] ?? 'documento'),
            'size' => isset($m['documentMessage']['fileLength']) ? (int)$m['documentMessage']['fileLength'] : null, 'media_key' => 'documentMessage']);
    } elseif (isset($m['stickerMessage'])) {
        $r = array_merge($r, ['type' => 'sticker', 'text' => '[Sticker]', 'mime' => $m['stickerMessage']['mimetype'] ?? 'image/webp',
            'filename' => 'sticker.webp', 'media_key' => 'stickerMessage']);
    } elseif (isset($m['contactMessage']) || isset($m['contactsArrayMessage'])) {
        // Contato indicado (formato lido pelo chat para montar o cartão)
        $contacts = isset($m['contactMessage']) ? [$m['contactMessage']] : (array)($m['contactsArrayMessage']['contacts'] ?? []);
        $parts = [];
        foreach ($contacts as $ct) {
            if (!is_array($ct)) continue;
            $name = trim((string)($ct['displayName'] ?? ''));
            $vcard = (string)($ct['vcard'] ?? '');
            $phone = '';
            if ($vcard !== '' && preg_match('/waid=(\d+)/i', $vcard, $mm)) {
                $phone = $mm[1];
            } elseif ($vcard !== '' && preg_match('/TEL[^:]*:([+\d\s\-()]+)/i', $vcard, $mm2)) {
                $phone = (string)preg_replace('/\D+/', '', $mm2[1]);
            }
            $parts[] = '👤 ' . ($name !== '' ? $name : 'Contato') . ($phone !== '' ? ' — ' . $phone : '');
        }
        $r['type'] = 'contact';
        $r['text'] = '[Contato indicado] ' . implode(' | ', $parts);
    } elseif (isset($m['locationMessage'])) {
        $loc = $m['locationMessage'];
        $name = trim((string)($loc['name'] ?? ''));
        $coords = isset($loc['degreesLatitude'], $loc['degreesLongitude']) ? ' (' . $loc['degreesLatitude'] . ', ' . $loc['degreesLongitude'] . ')' : '';
        $r['text'] = '📍 Localização' . ($name !== '' ? ': ' . $name : '') . $coords;
    } elseif (isset($m['pollCreationMessage']) || isset($m['pollCreationMessageV3'])) {
        $poll = $m['pollCreationMessage'] ?? $m['pollCreationMessageV3'];
        $r['text'] = '📊 Enquete' . (!empty($poll['name']) ? ': ' . $poll['name'] : '');
    } elseif (isset($m['listMessage'])) {
        $r['text'] = '📋 Lista' . (!empty($m['listMessage']['title']) ? ': ' . $m['listMessage']['title'] : '');
    } elseif (isset($m['buttonsResponseMessage']['selectedDisplayText'])) {
        $r['text'] = (string)$m['buttonsResponseMessage']['selectedDisplayText'];
    } elseif (isset($m['listResponseMessage']['title'])) {
        $r['text'] = (string)$m['listResponseMessage']['title'];
    } elseif (isset($m['templateButtonReplyMessage']['selectedDisplayText'])) {
        $r['text'] = (string)$m['templateButtonReplyMessage']['selectedDisplayText'];
    } else {
        // Só chave de criptografia, enquete votada, protocolo etc.: não é mensagem
        return null;
    }

    if ($r['type'] === 'text' && (trim($r['text']) === '' || wa_wh_is_system_text($r['text']))) {
        return null;
    }
    return $r;
}

/** contextInfo (resposta/citação e menções) da mensagem. */
function wa_wh_context(array $m): array
{
    $ctx = null;
    foreach (['extendedTextMessage', 'imageMessage', 'videoMessage', 'audioMessage', 'documentMessage', 'stickerMessage', 'contactMessage'] as $src) {
        if (isset($m[$src]['contextInfo']) && is_array($m[$src]['contextInfo'])) {
            $ctx = $m[$src]['contextInfo'];
            break;
        }
    }
    if ($ctx === null && isset($m['contextInfo']) && is_array($m['contextInfo'])) {
        $ctx = $m['contextInfo'];
    }
    $out = ['quoted_id' => null, 'quoted_text' => null, 'quoted_sender' => null, 'mentions' => null];
    if ($ctx === null) {
        return $out;
    }
    $out['quoted_id'] = $ctx['stanzaId'] ?? null;
    $out['quoted_sender'] = $ctx['participant'] ?? null;
    $q = is_array($ctx['quotedMessage'] ?? null) ? $ctx['quotedMessage'] : [];
    if ($q) {
        $out['quoted_text'] = $q['conversation'] ?? ($q['extendedTextMessage']['text'] ?? ($q['imageMessage']['caption'] ?? ($q['videoMessage']['caption'] ?? ($q['documentMessage']['caption'] ?? null))));
        if (empty($out['quoted_text'])) {
            foreach (['audioMessage' => '[Áudio]', 'imageMessage' => '[Imagem]', 'videoMessage' => '[Vídeo]', 'documentMessage' => '[Documento]', 'stickerMessage' => '[Sticker]'] as $k => $label) {
                if (isset($q[$k])) { $out['quoted_text'] = $label; break; }
            }
        }
    }
    if (!empty($ctx['mentionedJid']) && is_array($ctx['mentionedJid'])) {
        $out['mentions'] = json_encode(array_values($ctx['mentionedJid']));
    }
    return $out;
}

/** Grava a mídia (base64 do payload, ou baixada pela Evolution). Retorna a URL local. */
function wa_wh_store_media(string $instance, array $msg, array $message, array $parsed): ?string
{
    $b64 = (string)($message['base64'] ?? ($msg['message']['base64'] ?? ($msg['base64'] ?? '')));
    if ($b64 !== '') {
        $bin = base64_decode($b64, true);
        if ($bin !== false && $bin !== '') {
            $ext = wa_wh_media_ext((string)$parsed['mime'], (string)$parsed['filename'], (string)$parsed['type']);
            $dir = dirname(__DIR__) . '/uploads/chat_media/' . date('Y-m');
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $name = uniqid('chat_', true) . '.' . $ext;
            if (@file_put_contents($dir . '/' . $name, $bin) !== false) {
                return '/uploads/chat_media/' . date('Y-m') . '/' . $name;
            }
        }
    }
    // Sem base64 no payload: pede o arquivo à Evolution
    $fetched = chat_evolution_fetch_media($msg, $instance);
    return $fetched['url'] ?? null;
}

function wa_wh_media_ext(string $mime, string $filename, string $type): string
{
    $fromName = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $map = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif',
        'video/mp4' => 'mp4', 'audio/ogg' => 'ogg', 'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/aac' => 'aac',
        'application/pdf' => 'pdf',
    ];
    $base = strtolower(trim(explode(';', $mime)[0]));
    $ext = $type === 'document' && $fromName !== '' ? $fromName : ($map[$base] ?? ($fromName !== '' ? $fromName : 'bin'));
    if ($type === 'sticker') {
        $ext = 'webp';
    }
    return (string)preg_replace('/[^a-z0-9]/', '', $ext) ?: 'bin';
}

/** Processa UMA mensagem (recebida, enviada pelo celular ou eco do envio pela API). */
function wa_wh_process_message(string $instance, array $msg, string $event): void
{
    $key = is_array($msg['key'] ?? null) ? $msg['key'] : [];
    $rawJid = (string)($key['remoteJid'] ?? '');
    $extId = (string)($key['id'] ?? '');
    $fromMe = !empty($key['fromMe']);
    if ($rawJid === '' || $extId === '') {
        return;
    }
    if (strpos($rawJid, 'status@') !== false || strpos($rawJid, 'broadcast') !== false) {
        return;
    }

    $message = wa_wh_unwrap(is_array($msg['message'] ?? null) ? $msg['message'] : []);
    $ts = chat_evolution_record_ts($msg);
    if ($ts <= 0) {
        $ts = time();
    }
    $isGroup = strpos($rawJid, '@g.us') !== false;
    $participant = (string)($key['participant'] ?? ($msg['participant'] ?? ''));
    $participantPn = (string)($key['participantPn'] ?? ($key['participantAlt'] ?? ''));
    $pushName = trim((string)($msg['pushName'] ?? ''));

    // Reação: tabela própria + interesse em captação
    if (isset($message['reactionMessage'])) {
        wa_wh_handle_reaction($rawJid, $message['reactionMessage'], $fromMe, $participant, $participantPn, $pushName, $ts, $key);
        return;
    }

    // Mensagem apagada / editada (protocolo)
    if (isset($message['protocolMessage'])) {
        $proto = $message['protocolMessage'];
        $targetId = (string)($proto['key']['id'] ?? '');
        if ($targetId !== '' && isset($proto['editedMessage']) && is_array($proto['editedMessage'])) {
            $edited = wa_wh_parse_content(wa_wh_unwrap($proto['editedMessage']));
            if ($edited && $edited['type'] === 'text') {
                db()->prepare('UPDATE chat_messages SET message_text = ? WHERE external_message_id = ?')->execute([$edited['text'], $targetId]);
            }
        } elseif ($targetId !== '' && (($proto['type'] ?? null) === 'REVOKE' || ($proto['type'] ?? null) === 0)) {
            db()->prepare('DELETE FROM chat_messages WHERE external_message_id = ?')->execute([$targetId]);
        }
        return;
    }

    $parsed = wa_wh_parse_content($message);
    if ($parsed === null) {
        chat_webhook_trace($event, $key, 'ignorada: sem conteúdo | tipos: ' . implode(',', array_keys($message)));
        return;
    }

    $jid = chat_store_normalize_jid(wa_wh_resolve_jid($key, $fromMe));
    $locked = chat_msg_lock($extId, 5);
    try {
        // Já gravada (pelo envio do sistema ou entrega repetida)
        $existingId = chat_msg_find_by_external_id($extId);
        if ($existingId > 0) {
            // Completa a mídia se a linha existente ficou sem arquivo
            if ($parsed['media_key'] !== null) {
                $st = db()->prepare("SELECT 1 FROM chat_messages WHERE id = ? AND (media_url IS NULL OR media_url = '')");
                $st->execute([$existingId]);
                if ($st->fetchColumn()) {
                    $url = wa_wh_store_media($instance, $msg, $message, $parsed);
                    if ($url) {
                        db()->prepare('UPDATE chat_messages SET media_url = ?, media_mime_type = COALESCE(media_mime_type, ?) WHERE id = ?')
                            ->execute([$url, $parsed['mime'], $existingId]);
                    }
                }
            }
            chat_webhook_trace($event, $key, 'já existia', $jid, $parsed['text']);
            return;
        }

        // Eco de texto enviado pelo sistema e gravado sem o id (ex.: follow-up)
        if ($fromMe && $parsed['type'] === 'text') {
            $st = db()->prepare("SELECT id FROM chat_messages WHERE remote_jid = ? AND from_me = 1 AND message_text = ?
                                 AND external_message_id IS NULL AND message_timestamp BETWEEN ? AND ? ORDER BY id ASC LIMIT 1");
            $st->execute([$jid, $parsed['text'], $ts - 60, $ts + 60]);
            $echoId = (int)($st->fetchColumn() ?: 0);
            if ($echoId > 0) {
                db()->prepare('UPDATE chat_messages SET external_message_id = ? WHERE id = ?')->execute([$extId, $echoId]);
                chat_webhook_trace($event, $key, 'eco do sistema (já existia)', $jid, $parsed['text']);
                return;
            }
        }

        $mediaUrl = $parsed['media_key'] !== null ? wa_wh_store_media($instance, $msg, $message, $parsed) : null;
        $ctx = wa_wh_context($message);
        $senderName = $fromMe ? null : ($pushName !== '' ? $pushName : null);

        db()->prepare("INSERT INTO chat_messages
                (remote_jid, instance_name, message_text, message_type, media_url, media_mime_type, media_filename, media_size,
                 from_me, message_timestamp, quoted_message_id, quoted_message_text, quoted_message_sender, mentioned_jids,
                 sender_name, participant_jid, external_message_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([
                $jid, $instance !== '' ? $instance : null, $parsed['text'], $parsed['type'], $mediaUrl, $parsed['mime'],
                $parsed['filename'], $parsed['size'], $fromMe ? 1 : 0, $ts,
                $ctx['quoted_id'], $ctx['quoted_text'], $ctx['quoted_sender'], $ctx['mentions'],
                $senderName, $participant !== '' ? $participant : null, $extId,
            ]);

        wa_wh_upsert_contact($jid, $instance, $isGroup, $fromMe, $pushName, $ts, $parsed);
        chat_webhook_trace($event, $key, 'gravada', $jid, $parsed['text']);
    } finally {
        if ($locked) {
            chat_msg_unlock($extId);
        }
    }
}

/** Conversa na lista: cria/atualiza com a última mensagem (também quando enviada). */
function wa_wh_upsert_contact(string $jid, string $instance, bool $isGroup, bool $fromMe, string $pushName, int $ts, array $parsed): void
{
    $number = str_replace(['@s.whatsapp.net', '@g.us', '@lid'], '', $jid);
    // Nome: pushName do contato em conversa privada recebida (nunca o nosso, nem em grupo)
    $name = (!$fromMe && !$isGroup && $pushName !== '') ? $pushName : $number;

    db()->prepare("
        INSERT INTO chat_contacts (remote_jid, instance_name, contact_name, is_group, last_message_timestamp, last_message_text, last_message_type)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            contact_name = CASE
                WHEN VALUES(contact_name) != '' AND VALUES(contact_name) != REPLACE(REPLACE(REPLACE(remote_jid, '@s.whatsapp.net', ''), '@g.us', ''), '@lid', '')
                THEN VALUES(contact_name)
                ELSE COALESCE(contact_name, VALUES(contact_name))
            END,
            instance_name = COALESCE(VALUES(instance_name), instance_name),
            last_message_timestamp = GREATEST(COALESCE(last_message_timestamp, 0), VALUES(last_message_timestamp)),
            last_message_text = IF(VALUES(last_message_timestamp) >= COALESCE(last_message_timestamp, 0), VALUES(last_message_text), last_message_text),
            last_message_type = IF(VALUES(last_message_timestamp) >= COALESCE(last_message_timestamp, 0), VALUES(last_message_type), last_message_type),
            updated_at = CURRENT_TIMESTAMP
    ")->execute([$jid, $instance !== '' ? $instance : null, $name, $isGroup ? 1 : 0, $ts, $parsed['text'], $parsed['type']]);

    // Resposta do contato reabre a conversa concluída
    if (!$fromMe) {
        db()->prepare("UPDATE chat_contacts SET status = 'aguardando', updated_at = CURRENT_TIMESTAMP WHERE remote_jid = ? AND status = 'resolvido'")
            ->execute([$jid]);
    }
}

/** Reação: grava em chat_reactions e, se for numa captação, registra o interesse. */
function wa_wh_handle_reaction(string $rawJid, array $reaction, bool $fromMe, string $participant, string $participantPn, string $pushName, int $ts, array $key): void
{
    $targetId = (string)($reaction['key']['id'] ?? '');
    $emoji = (string)($reaction['text'] ?? '');
    if ($targetId === '') {
        return;
    }
    $reactor = $fromMe ? 'me' : ($participantPn ?: ($participant ?: $rawJid));
    $chatJid = chat_store_normalize_jid($rawJid);

    if ($emoji === '') {
        db()->prepare('DELETE FROM chat_reactions WHERE message_id = ? AND reactor_jid = ?')->execute([$targetId, $reactor]);
        return;
    }
    db()->prepare("INSERT INTO chat_reactions (remote_jid, message_id, reactor_jid, emoji, reaction_timestamp)
                   VALUES (?, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE emoji = VALUES(emoji), reaction_timestamp = VALUES(reaction_timestamp)")
        ->execute([$chatJid, $targetId, $reactor, $emoji, $ts]);

    // Reação de profissional numa mensagem de CAPTAÇÃO (grupo) = interesse
    if ($fromMe || strpos($rawJid, '@g.us') === false) {
        return;
    }
    try {
        $st = db()->prepare("SELECT dl.id, dl.demand_id, d.status AS demand_status
                             FROM demand_dispatch_logs dl
                             LEFT JOIN demands d ON d.id = dl.demand_id
                             WHERE dl.external_message_id = ? LIMIT 1");
        $st->execute([$targetId]);
        $dispatch = $st->fetch(PDO::FETCH_ASSOC);
        if (!$dispatch || in_array((string)$dispatch['demand_status'], ['admitido', 'concluido', 'cancelado'], true)) {
            return;
        }
        // Telefone real de quem reagiu (participantPn/participantAlt; LID não é telefone)
        $phone = (string)preg_replace('/[^0-9]/', '', (string)preg_replace('/[:@].+$/', '', $participantPn ?: ($participant ?: $reactor)));
        if (strlen($phone) > 13) {
            $phone = (string)preg_replace('/[^0-9]/', '', (string)preg_replace('/[:@].+$/', '', (string)($key['participantAlt'] ?? '')));
        }
        $userId = null;
        if ($phone !== '') {
            $stU = db()->prepare("SELECT id FROM users
                                  WHERE REPLACE(REPLACE(REPLACE(REPLACE(phone, '-', ''), ' ', ''), '(', ''), ')', '') LIKE ? LIMIT 1");
            $stU->execute(['%' . substr($phone, -8) . '%']);
            $userId = ($u = $stU->fetchColumn()) ? (int)$u : null;
        }
        db()->prepare("INSERT IGNORE INTO demand_interested_professionals
                       (demand_id, dispatch_log_id, phone, phone_jid, push_name, user_id, emoji, reacted_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, NOW())")
            ->execute([(int)$dispatch['demand_id'], (int)$dispatch['id'], $phone, $phone !== '' ? $phone . '@s.whatsapp.net' : '',
                $pushName !== '' ? $pushName : null, $userId, $emoji]);
    } catch (Throwable $e) {
        error_log('[WEBHOOK] erro ao registrar interesse por reação: ' . $e->getMessage());
    }
}

/** Mensagens apagadas (messages.delete). */
function wa_wh_handle_delete($data): void
{
    if (!is_array($data)) {
        return;
    }
    $ids = [];
    foreach ([$data['key']['id'] ?? null, $data['id'] ?? null, $data['messageId'] ?? null, $data['message']['key']['id'] ?? null, $data['message']['id'] ?? null] as $id) {
        if (is_string($id) && $id !== '') $ids[] = $id;
    }
    $list = isset($data[0]) ? $data : (array)($data['messages'] ?? []);
    foreach ($list as $it) {
        if (is_string($it) && $it !== '') $ids[] = $it;
        elseif (is_array($it)) {
            $id = $it['key']['id'] ?? ($it['id'] ?? null);
            if (is_string($id) && $id !== '') $ids[] = $id;
        }
    }
    $ids = array_values(array_unique($ids));
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        db()->prepare("DELETE FROM chat_messages WHERE external_message_id IN ($ph)")->execute($ids);
    }
}

/** Nome e foto de contatos (contacts.upsert / contacts.update). */
function wa_wh_handle_contacts($data): void
{
    $list = is_array($data) && isset($data[0]) ? $data : [$data];
    foreach ($list as $c) {
        if (!is_array($c)) continue;
        $jid = (string)($c['id'] ?? ($c['remoteJid'] ?? ''));
        if ($jid === '' || substr($jid, -4) === '@lid') continue;
        $name = trim((string)($c['name'] ?? ($c['pushName'] ?? '')));
        $pic = $c['profilePictureUrl'] ?? ($c['profilePicUrl'] ?? null);
        try {
            db()->prepare("INSERT INTO chat_contacts (remote_jid, contact_name, profile_picture_url, is_group)
                           VALUES (?, ?, ?, ?)
                           ON DUPLICATE KEY UPDATE
                               contact_name = COALESCE(NULLIF(VALUES(contact_name), ''), contact_name),
                               profile_picture_url = COALESCE(VALUES(profile_picture_url), profile_picture_url),
                               updated_at = CURRENT_TIMESTAMP")
                ->execute([chat_store_normalize_jid($jid), $name, $pic, strpos($jid, '@g.us') !== false ? 1 : 0]);
        } catch (Throwable $e) {
            error_log('[WEBHOOK] erro ao atualizar contato: ' . $e->getMessage());
        }
    }
}

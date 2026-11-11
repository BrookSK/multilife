<?php

declare(strict_types=1);

/**
 * Sincroniza as últimas mensagens da conversa aberta direto da Evolution API.
 *
 * Reserva para quando o webhook não entrega (ou entrega com LID) as mensagens
 * enviadas pelo celular: o chat chama este endpoint ao abrir a conversa e a cada
 * ~15s. O que faltar no banco é gravado e aparece no próximo polling.
 */

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('chat.manage');
$userId = (int)(auth_user_id() ?? 0);
session_release(); // não travar as outras telas do usuário

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

$chatId = trim((string)($_GET['chat_id'] ?? ''));
$requested = trim((string)($_GET['instance'] ?? ''));

// Só conversas privadas (grupos têm muito volume e chegam bem pelo webhook)
if ($chatId === '' || strpos($chatId, '@g.us') !== false || strpos($chatId, '@') === false) {
    echo json_encode(['ok' => true, 'imported' => 0, 'skipped' => 'not_private']);
    exit;
}
$pnJid = chat_store_normalize_jid($chatId);

$allowed = whatsapp_user_instance_names($userId);
if (!$allowed) {
    echo json_encode(['ok' => true, 'imported' => 0, 'skipped' => 'no_instance']);
    exit;
}

// Instâncias a consultar: a pedida (WhatsApp da conversa) ou as do usuário que
// já conversaram com esse contato (no máximo 3).
$instances = [];
if ($requested !== '' && in_array($requested, $allowed, true)) {
    $instances = [$requested];
} else {
    try {
        $ph = implode(',', array_fill(0, count($allowed), '?'));
        $st = db()->prepare("SELECT DISTINCT instance_name FROM chat_messages WHERE remote_jid = ? AND instance_name IN ($ph)
                             ORDER BY instance_name LIMIT 3");
        $st->execute(array_merge([$pnJid], $allowed));
        $instances = array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    } catch (Throwable $e) {
        $instances = [];
    }
    if (!$instances) {
        $instances = [whatsapp_chat_instance_for_user($userId, $pnJid, '')];
    }
}
$instances = array_values(array_filter($instances, static fn($i) => $i !== ''));

// Limite de frequência por conversa+instância (vários atendentes com a mesma
// conversa aberta não multiplicam as chamadas à Evolution).
$throttleSeconds = 5;
$baseUrl = (string)admin_setting_get('evolution.base_url');
$apiKey = (string)admin_setting_get('evolution.api_key');
$imported = 0;
$errors = [];

foreach ($instances as $instanceName) {
    $throttleFile = sys_get_temp_dir() . '/chat_sync_' . md5($instanceName . '|' . $pnJid);
    $last = is_file($throttleFile) ? (int)@file_get_contents($throttleFile) : 0;
    if ($last > 0 && (time() - $last) < $throttleSeconds) {
        continue;
    }
    @file_put_contents($throttleFile, (string)time());

    try {
        $api = new EvolutionApiV1($baseUrl, $apiKey, $instanceName);

        // Mensagens enviadas pelo celular podem estar na Evolution só com o LID do
        // contato. Sem o LID conhecido, perguntamos à Evolution (whatsappNumbers)
        // no máximo 1x por hora por contato.
        if (!chat_lid_for_pn($pnJid)) {
            $lidCheckFile = sys_get_temp_dir() . '/chat_lidchk_' . md5($instanceName . '|' . $pnJid);
            $lastChk = is_file($lidCheckFile) ? (int)@file_get_contents($lidCheckFile) : 0;
            if ($lastChk <= 0 || (time() - $lastChk) > 3600) {
                @file_put_contents($lidCheckFile, (string)time());
                try {
                    $number = preg_replace('/\D+/', '', $pnJid);
                    $chk = $api->checkIsWhatsapp([$number]);
                    $items = is_array($chk['json'] ?? null) ? $chk['json'] : [];
                    foreach ($items as $item) {
                        if (!is_array($item)) {
                            continue;
                        }
                        foreach ($item as $v) {
                            if (is_string($v) && substr($v, -4) === '@lid') {
                                chat_lid_remember($v, $pnJid);
                            }
                        }
                    }
                } catch (Throwable $e) {
                    error_log('[CHAT_SYNC] whatsappNumbers: ' . $e->getMessage());
                }
            }
        }

        $jidsToQuery = array_values(array_unique(array_merge([$pnJid], chat_lid_for_pn($pnJid))));
        $records = [];
        foreach ($jidsToQuery as $qJid) {
            $res = $api->findRecentMessages($qJid, 30);
            if ((int)($res['status'] ?? 0) < 200 || (int)($res['status'] ?? 0) >= 300) {
                $errors[] = $instanceName . ': HTTP ' . (int)($res['status'] ?? 0);
                continue;
            }
            foreach (chat_evolution_records($res['json'] ?? null) as $rec) {
                $key = is_array($rec['key'] ?? null) ? $rec['key'] : [];
                $rj = (string)($key['remoteJid'] ?? '');
                $alt = (string)($key['remoteJidAlt'] ?? '');
                // A API pode ignorar o filtro: confere a conversa aqui
                $belongs = chat_store_normalize_jid($rj) === $pnJid
                    || in_array($rj, $jidsToQuery, true)
                    || ($alt !== '' && chat_store_normalize_jid($alt) === $pnJid);
                if (!$belongs || strpos($rj, '@g.us') !== false) {
                    continue;
                }
                if (substr($rj, -4) === '@lid' && $alt !== '' && strpos($alt, '@s.whatsapp.net') !== false) {
                    chat_lid_remember($rj, $alt);
                }
                $records[(string)($key['id'] ?? '')] = $rec;
            }
        }
        unset($records['']);
        // Grava da mais antiga para a mais nova (ids em ordem cronológica)
        usort($records, static fn($a, $b) => chat_evolution_record_ts($a) <=> chat_evolution_record_ts($b));
        foreach ($records as $rec) {
            // Recebidas dos últimos 20s ficam para o webhook (que também baixa a mídia).
            // Depois disso importamos: o webhook da Evolution chega a atrasar horas.
            $recFromMe = !empty($rec['key']['fromMe']);
            $recTs = chat_evolution_record_ts($rec);
            // Só as últimas 24h: histórico antigo pode ter linhas sem key.id que
            // virariam duplicadas.
            if ($recTs < time() - 86400) {
                continue;
            }
            if (!$recFromMe && $recTs > time() - 20) {
                continue;
            }
            if (chat_import_evolution_record($rec, $instanceName, $pnJid) > 0) {
                $imported++;
            }
        }
    } catch (Throwable $e) {
        $errors[] = $instanceName . ': ' . $e->getMessage();
        error_log('[CHAT_SYNC] ' . $instanceName . ' / ' . $pnJid . ': ' . $e->getMessage());
    }
}

if ($imported > 0) {
    error_log("[CHAT_SYNC] $imported mensagem(ns) importada(s) da Evolution para $pnJid");
}

echo json_encode(['ok' => true, 'imported' => $imported, 'errors' => $errors]);

<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

header('Content-Type: text/html; charset=utf-8');

$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$fmtTs = static fn($ts): string => (int)$ts > 0 ? date('d/m H:i:s', (int)$ts) : '-';

echo '<style>body{font-family:Arial,sans-serif;font-size:14px;margin:20px}table{border-collapse:collapse;font-size:12px;font-family:monospace}
td,th{border:1px solid #ccc;padding:4px 6px;vertical-align:top}th{background:#eee}.ok{color:#0a7a3b;font-weight:bold}.bad{color:#b00020;font-weight:bold}
.box{background:#f6f8fa;border:1px solid #ddd;border-radius:8px;padding:12px;margin:10px 0}</style>';

echo '<h2>Webhook Debug — WhatsApp</h2>';

// ---------------------------------------------------------------------------
// 1) Servidor: o webhook consegue responder na hora para a Evolution?
// ---------------------------------------------------------------------------
$earlyOk = function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request');
echo '<div class="box"><strong>Servidor:</strong> PHP ' . $e(PHP_VERSION) . ' (' . $e(PHP_SAPI) . ') — resposta antecipada ao webhook: '
    . ($earlyOk ? '<span class="ok">ATIVA</span>' : '<span class="bad">INDISPONÍVEL</span> (a Evolution espera o processamento terminar; pode gerar fila e atraso)')
    . '</div>';

// ---------------------------------------------------------------------------
// 2) Testar uma conversa direto na Evolution
// ---------------------------------------------------------------------------
$instances = [];
try {
    $instances = whatsapp_list_all_instances();
} catch (Throwable $ex) {
    $instances = [];
}
$testNumber = preg_replace('/\D+/', '', (string)($_REQUEST['number'] ?? ''));
$testInstance = trim((string)($_REQUEST['instance'] ?? ''));
$validInstance = in_array($testInstance, array_map(static fn($i) => (string)($i['instance_name'] ?? ''), $instances), true);

echo '<h3>Testar conversa na Evolution</h3>';
echo '<p>Mostra o que a Evolution tem guardado para um contato e o que já está no sistema. Use para saber se uma resposta chegou ao WhatsApp conectado.</p>';
echo '<form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">';
echo '<input name="number" value="' . $e($testNumber) . '" placeholder="Número com DDI, ex.: 553498225070" style="padding:6px;width:260px">';
echo '<select name="instance" style="padding:6px">';
foreach ($instances as $inst) {
    $n = (string)($inst['instance_name'] ?? '');
    $label = (string)($inst['display_name'] ?? '') !== '' ? $inst['display_name'] . ' (' . $n . ')' : $n;
    echo '<option value="' . $e($n) . '"' . ($n === $testInstance ? ' selected' : '') . '>' . $e($label) . '</option>';
}
echo '</select>';
echo '<button type="submit" style="padding:6px 12px">Testar</button>';
echo '</form>';

if ($testNumber !== '' && $validInstance) {
    $pnJid = chat_store_normalize_jid($testNumber . '@s.whatsapp.net');
    $api = new EvolutionApiV1((string)admin_setting_get('evolution.base_url'), (string)admin_setting_get('evolution.api_key'), $testInstance);

    // Conexão da instância
    try {
        $cs = $api->connectionState($testInstance, 8);
        $state = (string)($cs['json']['instance']['state'] ?? ($cs['json']['state'] ?? ''));
        echo '<div class="box"><strong>Conexão de ' . $e($testInstance) . ':</strong> '
            . ($state === 'open' ? '<span class="ok">open (conectada)</span>' : '<span class="bad">' . $e($state !== '' ? $state : 'HTTP ' . ($cs['status'] ?? '?')) . '</span>')
            . '</div>';
    } catch (Throwable $ex) {
        echo '<div class="box bad">Erro ao consultar conexão: ' . $e($ex->getMessage()) . '</div>';
    }

    $lids = chat_lid_for_pn($pnJid);
    echo '<p>LIDs conhecidos deste número: ' . ($lids ? $e(implode(', ', $lids)) : '<em>nenhum</em>') . '</p>';

    $records = [];
    foreach (array_values(array_unique(array_merge([$pnJid], $lids))) as $qJid) {
        try {
            $res = $api->findRecentMessages($qJid, 20, 10);
            $status = (int)($res['status'] ?? 0);
            $recs = chat_evolution_records($res['json'] ?? null);
            echo '<p>findMessages(' . $e($qJid) . '): HTTP ' . $status . ' — ' . count($recs) . ' registro(s)';
            if ($status < 200 || $status >= 300) {
                echo ' <span class="bad">' . $e(mb_substr((string)($res['body_raw'] ?? ''), 0, 300)) . '</span>';
            }
            echo '</p>';
            foreach ($recs as $r) {
                $records[(string)($r['key']['id'] ?? '')] = $r;
            }
        } catch (Throwable $ex) {
            echo '<p class="bad">findMessages(' . $e($qJid) . ') falhou: ' . $e($ex->getMessage()) . '</p>';
        }
    }
    unset($records['']);
    usort($records, static fn($a, $b) => chat_evolution_record_ts($b) <=> chat_evolution_record_ts($a));

    $imported = 0;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['do'] ?? '') === 'import') {
        foreach (array_reverse($records) as $r) {
            $rj = (string)($r['key']['remoteJid'] ?? '');
            if (strpos($rj, '@g.us') !== false) {
                continue;
            }
            if (chat_import_evolution_record($r, $testInstance, $pnJid) > 0) {
                $imported++;
            }
        }
        echo '<div class="box ok">' . $imported . ' mensagem(ns) importada(s) para o sistema.</div>';
    }

    if ($records) {
        echo '<table><tr><th>Horário (WhatsApp)</th><th>Dir.</th><th>remoteJid</th><th>remoteJidAlt</th><th>Texto</th><th>No sistema?</th></tr>';
        $missing = 0;
        foreach (array_slice($records, 0, 20) as $r) {
            $k = (array)($r['key'] ?? []);
            $m = (array)($r['message'] ?? []);
            $txt = (string)($m['conversation'] ?? ($m['extendedTextMessage']['text'] ?? ''));
            if ($txt === '') {
                $txt = '[' . implode(',', array_keys($m)) . ']';
            }
            $inDb = chat_msg_find_by_external_id((string)($k['id'] ?? '')) > 0;
            if (!$inDb) {
                $missing++;
            }
            echo '<tr><td>' . $e($fmtTs(chat_evolution_record_ts($r))) . '</td>'
                . '<td>' . (!empty($k['fromMe']) ? '→ enviada' : '← recebida') . '</td>'
                . '<td>' . $e($k['remoteJid'] ?? '') . '</td><td>' . $e($k['remoteJidAlt'] ?? '') . '</td>'
                . '<td>' . $e(mb_substr($txt, 0, 80)) . '</td>'
                . '<td>' . ($inDb ? '<span class="ok">sim</span>' : '<span class="bad">NÃO</span>') . '</td></tr>';
        }
        echo '</table>';
        if ($missing > 0) {
            echo '<form method="post" style="margin-top:8px"><input type="hidden" name="number" value="' . $e($testNumber) . '">'
                . '<input type="hidden" name="instance" value="' . $e($testInstance) . '"><input type="hidden" name="do" value="import">'
                . '<button type="submit" style="padding:6px 12px">Importar as que faltam no sistema</button></form>';
        }
    } else {
        echo '<p class="bad">A Evolution não devolveu nenhuma mensagem para este número nesta instância.</p>';
    }
} elseif ($testNumber !== '') {
    echo '<p class="bad">Escolha uma instância válida.</p>';
}

echo '<hr>';

// ---------------------------------------------------------------------------
// 3) Rastreio do webhook
// ---------------------------------------------------------------------------
$onlyFromMe = ($_GET['fromme'] ?? '') === '1';
$traceNumber = preg_replace('/\D+/', '', (string)($_GET['tnum'] ?? ''));
echo '<h3>Mensagens recebidas pelo webhook (rastreio)</h3>';
echo '<form method="get" style="display:flex;gap:8px;align-items:center">';
echo '<input name="tnum" value="' . $e($traceNumber) . '" placeholder="Filtrar por número" style="padding:6px">';
echo '<label><input type="checkbox" name="fromme" value="1"' . ($onlyFromMe ? ' checked' : '') . '> só enviadas</label>';
echo '<button type="submit" style="padding:6px 12px">Filtrar</button></form>';
try {
    $where = [];
    $params = [];
    if ($onlyFromMe) {
        $where[] = 'from_me = 1';
    }
    if ($traceNumber !== '') {
        $where[] = '(remote_jid LIKE ? OR remote_jid_alt LIKE ? OR resolved_jid LIKE ?)';
        array_push($params, '%' . $traceNumber . '%', '%' . $traceNumber . '%', '%' . $traceNumber . '%');
    }
    $st = db()->prepare("SELECT created_at, event, instance_name, from_me, remote_jid, remote_jid_alt, sender_pn, sender_lid,
                                resolved_jid, external_id, result, text_sample
                         FROM chat_webhook_trace" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . "
                         ORDER BY id DESC LIMIT 80");
    $st->execute($params);
    $traceRows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$traceRows) {
        echo '<p>Nenhum registro. Se você mandou mensagem e nada aparece aqui, a Evolution NÃO entregou o evento ao webhook do sistema (ainda).</p>';
    } else {
        echo '<div style="overflow:auto"><table>';
        echo '<tr><th>Chegou no sistema</th><th>Evento</th><th>WhatsApp</th><th>Dir.</th><th>remoteJid</th><th>remoteJidAlt</th><th>senderPn</th><th>senderLid</th><th>Gravada em</th><th>Resultado</th><th>Texto</th></tr>';
        foreach ($traceRows as $tr) {
            $isLidOnly = strpos((string)$tr['resolved_jid'], '@lid') !== false;
            echo '<tr' . ($isLidOnly ? ' style="background:#fff3cd"' : '') . '>';
            foreach (['created_at', 'event', 'instance_name'] as $c) {
                echo '<td>' . $e($tr[$c]) . '</td>';
            }
            echo '<td>' . ($tr['from_me'] ? '→ enviada' : '← recebida') . '</td>';
            foreach (['remote_jid', 'remote_jid_alt', 'sender_pn', 'sender_lid', 'resolved_jid', 'result', 'text_sample'] as $c) {
                echo '<td>' . $e($tr[$c]) . '</td>';
            }
            echo '</tr>';
        }
        echo '</table></div>';
    }
} catch (Throwable $ex) {
    echo '<p>O rastreio ainda não tem dados (a tabela é criada quando chega a primeira mensagem).</p>';
}

// Atraso de entrega: diferença entre a chegada no sistema e o horário da mensagem no WhatsApp
try {
    $lag = db()->query("SELECT instance_name, COUNT(*) AS n,
                               ROUND(AVG(UNIX_TIMESTAMP(created_at) - message_timestamp)) AS avg_lag,
                               MAX(UNIX_TIMESTAMP(created_at) - message_timestamp) AS max_lag
                        FROM chat_messages
                        WHERE created_at >= (NOW() - INTERVAL 6 HOUR) AND external_message_id IS NOT NULL
                        GROUP BY instance_name ORDER BY max_lag DESC")->fetchAll(PDO::FETCH_ASSOC);
    if ($lag) {
        $fmtLag = static function ($s): string {
            $s = (int)$s;
            if ($s < 120) return $s . ' s';
            if ($s < 7200) return round($s / 60) . ' min';
            return round($s / 3600, 1) . ' h';
        };
        echo '<h3>Atraso de entrega (últimas 6h)</h3><table><tr><th>WhatsApp</th><th>Mensagens</th><th>Atraso médio</th><th>Maior atraso</th></tr>';
        foreach ($lag as $l) {
            echo '<tr><td>' . $e($l['instance_name']) . '</td><td>' . (int)$l['n'] . '</td><td>' . $e($fmtLag($l['avg_lag'])) . '</td><td>' . $e($fmtLag($l['max_lag'])) . '</td></tr>';
        }
        echo '</table><p style="font-size:12px;color:#666">Atraso = hora em que a mensagem foi gravada no sistema menos a hora da mensagem no WhatsApp.</p>';
    }
} catch (Throwable $ex) {
    // sem dados
}

echo '<hr>';

// ---------------------------------------------------------------------------
// 4) Últimas chamadas à Evolution e últimas mensagens gravadas
// ---------------------------------------------------------------------------
echo '<h3>Últimas 20 chamadas à Evolution API</h3>';
echo '<pre style="max-height:400px;overflow:auto;font-size:12px;background:#1a1a1a;color:#0f0;padding:12px;border-radius:8px">';
try {
    $logs = db()->query("SELECT action, status, http_status, response_payload, error_message, created_at
                         FROM integration_logs WHERE provider = 'evolution' ORDER BY id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
    if (!$logs) {
        echo "Nenhum log.\n";
    }
    foreach ($logs as $log) {
        echo '[' . $e($log['created_at']) . '] ' . $e($log['action']) . ' | HTTP: ' . $e($log['http_status']) . ' | ' . $e($log['status']) . "\n";
        if (!empty($log['error_message'])) {
            echo '  ERRO: ' . $e($log['error_message']) . "\n";
        }
        $resp = (string)($log['response_payload'] ?? '');
        echo '  ' . $e(mb_strlen($resp) > 300 ? mb_substr($resp, 0, 300) . '...' : $resp) . "\n";
    }
} catch (Throwable $ex) {
    echo 'Erro ao consultar logs: ' . $e($ex->getMessage()) . "\n";
}
echo '</pre>';

echo '<h3>Últimas 15 mensagens gravadas</h3>';
echo '<pre style="max-height:400px;overflow:auto;font-size:12px;background:#1a1a1a;color:#fff;padding:12px;border-radius:8px">';
try {
    $msgs = db()->query("SELECT id, remote_jid, instance_name, LEFT(message_text, 60) AS msg, from_me, message_timestamp, message_type, created_at
                         FROM chat_messages ORDER BY id DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($msgs as $m) {
        echo '[gravada ' . $e($m['created_at']) . ' | WhatsApp ' . $e($fmtTs($m['message_timestamp'])) . '] '
            . ($m['from_me'] ? '→ ENVIADA' : '← RECEBIDA') . ' | ' . $e($m['instance_name']) . ' | ' . $e($m['remote_jid'])
            . ' | ' . $e($m['message_type']) . ' | "' . $e($m['msg']) . "\"\n";
    }
} catch (Throwable $ex) {
    echo 'Erro: ' . $e($ex->getMessage()) . "\n";
}
echo '</pre>';

echo '<br><a href="/admin_settings.php">Voltar</a>';

<?php

declare(strict_types=1);

/**
 * EVENTOS DO WHATSAPP — log detalhado de tudo o que acontece (e deixa de acontecer).
 *
 * Lê a tabela baileys_event_log, onde gravam:
 *  - o serviço WhatsApp (origem "serviço"): conexão, QR Code, quedas e o motivo,
 *    mensagens recebidas/enviadas, reações, falhas de criptografia, mídia,
 *    grupos, entrega de cada evento ao sistema (webhook), chamadas da API e
 *    erros internos do WhatsApp;
 *  - o sistema (origem "sistema"): webhook recebido, mensagem gravada ou
 *    ignorada (e por quê), reação que virou interesse em captação (ou não),
 *    disparos de captação, erros ao chamar o serviço, serviço fora do ar e
 *    erros fatais do PHP.
 *
 * Painel "Agora" no topo: por número, o que está parado (desconectado, mudo,
 * sem receber, chegando no serviço e não no sistema, envios sem confirmação).
 * Clique num id de mensagem para ver a viagem completa dela.
 */

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/whatsapp_webhook_inbox.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');
session_release();

try {
    wa_event_log_ensure_table();
} catch (Throwable $e) {
    view_header('Eventos do WhatsApp');
    echo '<div class="card col12"><div class="alertError">Não foi possível criar/abrir a tabela do log: ' . h($e->getMessage()) . '</div></div>';
    view_footer();
    exit;
}

// ---------------------------------------------------------------------------
// Filtros
// ---------------------------------------------------------------------------
$periods = ['1h' => 'Última 1 h', '6h' => 'Últimas 6 h', '24h' => 'Últimas 24 h', '72h' => 'Últimos 3 dias', '7d' => 'Últimos 7 dias', '30d' => 'Últimos 30 dias', 'custom' => 'Escolher datas'];
$periodSec = ['1h' => 3600, '6h' => 21600, '24h' => 86400, '72h' => 259200, '7d' => 604800, '30d' => 2592000];
$levels = ['' => 'Todos os níveis', 'info' => 'Info, avisos e erros', 'warn' => 'Avisos e erros', 'error' => 'Só erros'];
$catNames = [
    'conexao' => 'Conexão', 'recebida' => 'Recebidas', 'enviada' => 'Enviadas', 'reacao' => 'Reações', 'captacao' => 'Captação',
    'webhook' => 'Entrega ao sistema', 'processamento' => 'Processamento no sistema', 'midia' => 'Mídia', 'grupo' => 'Grupos',
    'contato' => 'Contatos', 'ligacao' => 'Ligações', 'api' => 'Chamadas da API', 'sistema' => 'Serviço / sistema',
    'baileys' => 'Interno do WhatsApp', 'criptografia' => 'Criptografia', 'seguranca' => 'Segurança', 'monitor' => 'Monitor',
];
$levelNames = ['error' => 'Erros', 'warn' => 'Avisos', 'info' => 'Info', 'debug' => 'Detalhe'];

$f = [
    'p' => (string)($_GET['p'] ?? '24h'),
    'de' => trim((string)($_GET['de'] ?? '')),
    'ate' => trim((string)($_GET['ate'] ?? '')),
    'inst' => trim((string)($_GET['inst'] ?? '')),
    'nivel' => (string)($_GET['nivel'] ?? ''),
    'cat' => trim((string)($_GET['cat'] ?? '')),
    'origem' => (string)($_GET['origem'] ?? ''),
    'ev' => trim((string)($_GET['ev'] ?? '')),
    'q' => trim((string)($_GET['q'] ?? '')),
    'msg' => trim((string)($_GET['msg'] ?? '')),
    'auto' => !empty($_GET['auto']) ? '1' : '',
];
if (!isset($periods[$f['p']])) {
    $f['p'] = '24h';
}
if (!isset($levels[$f['nivel']])) {
    $f['nivel'] = '';
}
if (!in_array($f['origem'], ['', 'servico', 'sistema'], true)) {
    $f['origem'] = '';
}
$offset = max(0, (int)($_GET['pag'] ?? 0));
$perPage = 100;
$isCsv = !empty($_GET['csv']);

$tz = new DateTimeZone(date_default_timezone_get());
$utc = new DateTimeZone('UTC');
/** Data/hora local digitada -> UTC "Y-m-d H:i:s". */
$toUtc = static function (string $local) use ($tz, $utc): ?string {
    if ($local === '') {
        return null;
    }
    try {
        return (new DateTime(str_replace('T', ' ', $local), $tz))->setTimezone($utc)->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return null;
    }
};
/** UTC do banco -> hora local para mostrar. */
$fmtLocal = static function (?string $utcStr, string $format = 'd/m H:i:s') use ($tz, $utc): string {
    if ($utcStr === null || $utcStr === '') {
        return '';
    }
    try {
        return (new DateTime($utcStr, $utc))->setTimezone($tz)->format($format);
    } catch (Throwable $e) {
        return $utcStr;
    }
};
$secAgo = static function (?string $utcStr): ?int {
    if ($utcStr === null || $utcStr === '') {
        return null;
    }
    $t = strtotime($utcStr . ' UTC');
    return $t ? max(0, time() - $t) : null;
};

// Viagem de uma mensagem: todos os eventos dela, sem limite de período
$timeline = $f['msg'] !== '';

$where = [];
$params = [];
if (!$timeline) {
    if ($f['p'] === 'custom') {
        $from = $toUtc($f['de']) ?? gmdate('Y-m-d H:i:s', time() - 86400);
        $where[] = 'created_at >= ?';
        $params[] = $from;
        if (($to = $toUtc($f['ate'])) !== null) {
            $where[] = 'created_at <= ?';
            $params[] = $to;
        }
    } else {
        $where[] = 'created_at >= ?';
        $params[] = gmdate('Y-m-d H:i:s', time() - $periodSec[$f['p']]);
    }
} else {
    $where[] = '(message_id = ? OR remote_jid = ?)';
    $params[] = $f['msg'];
    $params[] = $f['msg'];
}
if ($f['inst'] !== '') {
    $where[] = 'instance = ?';
    $params[] = $f['inst'];
}
if ($f['origem'] !== '') {
    $where[] = 'source = ?';
    $params[] = $f['origem'];
}
if ($f['ev'] !== '') {
    $where[] = 'event = ?';
    $params[] = $f['ev'];
}
if ($f['q'] !== '') {
    $where[] = '(summary LIKE ? OR error LIKE ? OR event LIKE ? OR remote_jid LIKE ? OR message_id LIKE ?)';
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $f['q']) . '%';
    array_push($params, $like, $like, $like, $like, $like);
}
// Contadores por nível/categoria usam os filtros acima (sem o próprio nível/categoria)
$baseWhere = $where;
$baseParams = $params;
$levelSets = ['info' => ['info', 'warn', 'error'], 'warn' => ['warn', 'error'], 'error' => ['error']];
if ($f['nivel'] !== '') {
    $set = $levelSets[$f['nivel']];
    $where[] = 'level IN (' . implode(',', array_fill(0, count($set), '?')) . ')';
    array_push($params, ...$set);
}
if ($f['cat'] !== '') {
    $where[] = 'category = ?';
    $params[] = $f['cat'];
}
$sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ---------------------------------------------------------------------------
// Exportar (CSV)
// ---------------------------------------------------------------------------
if ($isCsv) {
    $st = db()->prepare("SELECT created_at, source, level, category, event, instance, remote_jid, message_id, status, http_status, duration_ms, summary, error, details
                         FROM baileys_event_log $sqlWhere ORDER BY id DESC LIMIT 20000");
    $st->execute($params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="eventos-whatsapp-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['quando', 'origem', 'nivel', 'categoria', 'evento', 'whatsapp', 'conversa', 'mensagem', 'status', 'http', 'ms', 'resumo', 'erro', 'detalhes'], ';', '"', '');
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $r['created_at'] = $fmtLocal((string)$r['created_at'], 'd/m/Y H:i:s');
        fputcsv($out, array_values($r), ';', '"', '');
    }
    fclose($out);
    exit;
}

// ---------------------------------------------------------------------------
// Dados
// ---------------------------------------------------------------------------
$st = db()->prepare("SELECT id, created_at, source, level, category, event, instance, remote_jid, message_id, status, http_status, duration_ms, summary, error, details
                     FROM baileys_event_log $sqlWhere ORDER BY " . ($timeline ? 'created_at ASC, id ASC' : 'id DESC') . " LIMIT " . ($perPage + 1) . " OFFSET " . $offset);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
$hasMore = count($rows) > $perPage;
$rows = array_slice($rows, 0, $perPage);

$bw = $baseWhere ? 'WHERE ' . implode(' AND ', $baseWhere) : '';
$levelCount = ['error' => 0, 'warn' => 0, 'info' => 0, 'debug' => 0];
$st = db()->prepare("SELECT level, COUNT(*) n FROM baileys_event_log $bw GROUP BY level");
$st->execute($baseParams);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $levelCount[(string)$r['level']] = (int)$r['n'];
}
$catWhere = $baseWhere;
$catParams = $baseParams;
if ($f['nivel'] !== '') {
    $set = $levelSets[$f['nivel']];
    $catWhere[] = 'level IN (' . implode(',', array_fill(0, count($set), '?')) . ')';
    array_push($catParams, ...$set);
}
$st = db()->prepare('SELECT category, COUNT(*) n, SUM(level = \'error\') e FROM baileys_event_log ' . ($catWhere ? 'WHERE ' . implode(' AND ', $catWhere) : '') . ' GROUP BY category ORDER BY n DESC');
$st->execute($catParams);
$catCount = $st->fetchAll(PDO::FETCH_ASSOC);

// Erros mais frequentes do período (o que mais se repete)
$topErrors = [];
if (!$timeline) {
    $st = db()->prepare("SELECT category, event, COUNT(*) n, MAX(created_at) last_at, MAX(summary) sample FROM baileys_event_log "
        . ($baseWhere ? 'WHERE ' . implode(' AND ', $baseWhere) . ' AND ' : 'WHERE ') . "level IN ('error','warn')
        GROUP BY category, event ORDER BY SUM(level = 'error') DESC, n DESC LIMIT 12");
    $st->execute($baseParams);
    $topErrors = $st->fetchAll(PDO::FETCH_ASSOC);
}

// ---------------------------------------------------------------------------
// Painel "Agora": o que está acontecendo e o que deixou de acontecer
// ---------------------------------------------------------------------------
$sysInstances = wa_watchdog_system_instances();
$health = wa_service_health(null, 5);
$svcStatus = [];
$svcError = '';
if ($health['ok']) {
    $sr = wa_watchdog_request('GET', '/status', 8);
    if ($sr['ok']) {
        foreach ((array)($sr['json']['instances'] ?? []) as $s) {
            if (is_array($s) && !empty($s['name'])) {
                $svcStatus[(string)$s['name']] = $s;
            }
        }
    } else {
        $svcError = $sr['error'];
    }
}
$since24 = gmdate('Y-m-d H:i:s', time() - 86400);
$since1h = gmdate('Y-m-d H:i:s', time() - 3600);
$agg = [];
$aggQuery = static function (string $sql, array $p) use (&$agg): void {
    try {
        $st = db()->prepare($sql);
        $st->execute($p);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = (string)($r['instance'] ?? '');
            unset($r['instance']);
            $agg[$k] = array_merge($agg[$k] ?? [], array_filter($r, static fn($v) => $v !== null));
        }
    } catch (Throwable $e) {
    }
};
$aggQuery("SELECT instance, MAX(created_at) svc_last_event FROM baileys_event_log WHERE created_at >= ? AND source = 'servico' GROUP BY instance", [$since24]);
$aggQuery("SELECT instance, MAX(created_at) svc_last_in FROM baileys_event_log WHERE created_at >= ? AND source = 'servico' AND event IN ('recebida_privada','recebida_grupo','reacao_recebida') GROUP BY instance", [$since24]);
$aggQuery("SELECT instance, MAX(created_at) sys_last_in FROM baileys_event_log WHERE created_at >= ? AND source = 'sistema' AND category = 'webhook' AND event IN ('recebido','recebido_repetido') AND status = 'messages.upsert' GROUP BY instance", [$since24]);
$aggQuery("SELECT instance, MAX(created_at) last_sent FROM baileys_event_log WHERE created_at >= ? AND source = 'servico' AND event IN ('enviada_pelo_sistema','reacao_enviada') GROUP BY instance", [$since24]);
$aggQuery("SELECT instance, SUM(level = 'error') err_1h, SUM(level = 'warn') warn_1h FROM baileys_event_log WHERE created_at >= ? GROUP BY instance", [$since1h]);
$aggQuery("SELECT instance,
              SUM(event = 'sem_confirmacao') no_ack_24h,
              SUM(event = 'nao_entregue' AND category = 'webhook') wh_fail_24h,
              SUM(event = 'nao_descriptografada') cipher_24h,
              SUM(event = 'desconectado') disc_24h,
              SUM(event IN ('envio_falhou','envio_recusado','numero_sem_whatsapp')) send_fail_24h,
              SUM(event = 'mensagem_ignorada') ignored_24h,
              SUM(event = 'midia_perdida' OR event = 'download_falhou') media_fail_24h
           FROM baileys_event_log WHERE created_at >= ? GROUP BY instance", [$since24]);

$lastServiceEvent = null;
try {
    $lastServiceEvent = db()->query("SELECT MAX(created_at) FROM baileys_event_log WHERE source = 'servico'")->fetchColumn() ?: null;
} catch (Throwable $e) {
}
$inbox = [];
try {
    $inbox = wa_inbox_stats();
} catch (Throwable $e) {
}

$allNames = array_values(array_unique(array_merge(array_keys($sysInstances), array_keys($svcStatus))));
sort($allNames);
$panel = [];
foreach ($allNames as $name) {
    $s = $svcStatus[$name] ?? null;
    $a = $agg[$name] ?? [];
    $alerts = []; // [nível, texto]
    if (!$health['ok']) {
        $alerts[] = ['error', 'Serviço WhatsApp fora do ar: nada é enviado nem recebido.'];
    } elseif ($s === null) {
        $alerts[] = ['error', 'Este número não existe no serviço: em Configurações → WhatsApp Conexão, clique em Reconectar e leia o QR Code.'];
    } else {
        $state = (string)($s['state'] ?? '');
        $verdict = (string)($s['verdict'] ?? '');
        if ($state === 'close') {
            $code = isset($s['statusReason']) && $s['statusReason'] !== null ? (int)$s['statusReason'] : null;
            $why = [
                401 => ': o aparelho foi desconectado pelo celular. Precisa ler o QR Code de novo.',
                403 => ': o WhatsApp recusou a conexão (número possivelmente bloqueado). Não reconecta sozinho.',
                440 => ': o mesmo número abriu sessão em outro lugar (outra instância, Evolution antiga ou outro sistema). Não reconecta sozinho: desconecte o outro e clique em Reconectar.',
            ][$code] ?? (empty($s['registered']) ? ': precisa ler o QR Code.' : ': tentando reconectar sozinho.');
            $alerts[] = ['error', 'Desconectado' . ($code !== null ? ' (código ' . $code . ')' : '') . $why];
        } elseif ($state === 'connecting') {
            $alerts[] = ['warn', empty($s['registered']) ? 'Aguardando leitura do QR Code.' : 'Conectando (tentativa ' . (int)($s['reconnectAttempts'] ?? 0) . ').'];
        } elseif ($verdict === 'silent') {
            $alerts[] = ['error', 'Conexão "muda": aberta, mas sem nenhum evento ' . wa_watchdog_ago((int)($s['lastEventSecondsAgo'] ?? 0)) . '. O monitor reconecta sozinho.'];
        }
        $silentMin = $s['inboundSilentMinutes'] ?? null;
        if ($state === 'open' && $silentMin !== null && (int)$silentMin >= 120) {
            $alerts[] = ['warn', 'Sem RECEBER nenhuma mensagem há ' . (int)$silentMin . ' min.'];
        }
        $wh = is_array($s['webhook'] ?? null) ? $s['webhook'] : [];
        if ((int)($wh['queued'] ?? 0) >= 50) {
            $alerts[] = ['warn', (int)$wh['queued'] . ' evento(s) na fila esperando para ir ao sistema.'];
        }
        if ((int)($s['pendingAcks'] ?? 0) > 0) {
            $alerts[] = ['info', (int)$s['pendingAcks'] . ' envio(s) aguardando confirmação do WhatsApp.'];
        }
    }
    $svcIn = $a['svc_last_in'] ?? null;
    $sysIn = $a['sys_last_in'] ?? null;
    if ($svcIn !== null && ($sysIn === null || strtotime($svcIn . ' UTC') - strtotime($sysIn . ' UTC') > 180) && ($secAgo($svcIn) ?? 0) > 120) {
        $alerts[] = ['error', 'Mensagens chegam no serviço mas NÃO estão chegando no sistema (última no serviço ' . wa_watchdog_ago($secAgo($svcIn)) . ', no sistema ' . ($sysIn ? wa_watchdog_ago($secAgo($sysIn)) : 'nenhuma em 24 h') . '). Veja "Entrega ao sistema".'];
    }
    foreach ([
        ['wh_fail_24h', 'error', 'evento(s) não entregues ao sistema (webhook) em 24 h'],
        ['no_ack_24h', 'warn', 'envio(s) sem confirmação do WhatsApp em 24 h'],
        ['send_fail_24h', 'error', 'envio(s) que falharam em 24 h'],
        ['cipher_24h', 'warn', 'mensagem(ns) que chegaram sem poder ser lidas (criptografia) em 24 h'],
        ['media_fail_24h', 'warn', 'mídia(s) que não puderam ser baixadas em 24 h'],
        ['ignored_24h', 'info', 'mensagem(ns) ignoradas pelo sistema em 24 h'],
        ['disc_24h', 'warn', 'queda(s) de conexão em 24 h'],
    ] as [$k, $lvl, $txt]) {
        if ((int)($a[$k] ?? 0) > 0) {
            $alerts[] = [$lvl, (int)$a[$k] . ' ' . $txt . '.'];
        }
    }
    $worst = 'ok';
    foreach ($alerts as [$lvl]) {
        if ($lvl === 'error') {
            $worst = 'error';
            break;
        }
        if ($lvl === 'warn') {
            $worst = 'warn';
        }
    }
    $panel[$name] = ['s' => $s, 'a' => $a, 'alerts' => $alerts, 'worst' => $worst];
}

$globalAlerts = [];
if (!$health['ok']) {
    $globalAlerts[] = ['error', 'O serviço WhatsApp não respondeu em ' . ($health['url'] !== '' ? $health['url'] : '(endereço desconhecido)') . ': ' . $health['error']];
} elseif (empty($health['data']['ready'])) {
    $globalAlerts[] = ['warn', 'O serviço está no ar mas ainda não ficou pronto: ' . (string)($health['data']['phase'] ?? '')];
}
if ($svcError !== '') {
    $globalAlerts[] = ['warn', 'Não foi possível ler a situação dos números no serviço: ' . $svcError];
}
if ($lastServiceEvent === null) {
    $globalAlerts[] = ['warn', 'O serviço ainda não gravou nenhum evento neste banco (versão antiga do serviço ou outro banco).'];
} elseif (($ago = $secAgo((string)$lastServiceEvent)) !== null && $ago > 900 && wa_event_log_debug_enabled()) {
    $globalAlerts[] = ['warn', 'O serviço não grava nenhum evento ' . wa_watchdog_ago($ago) . ' (normal é a cada 5 min): ele pode estar desligado ou travado.'];
}
if ((int)($inbox['failed'] ?? 0) > 0) {
    $globalAlerts[] = ['error', (int)$inbox['failed'] . ' evento(s) recebidos nas últimas 24 h falharam no processamento do sistema (fila do webhook).'];
}
if ((int)($inbox['pending'] ?? 0) >= 20) {
    $globalAlerts[] = ['warn', (int)$inbox['pending'] . ' evento(s) recebidos esperando processamento (fila do webhook).'];
}

// ---------------------------------------------------------------------------
// Tela
// ---------------------------------------------------------------------------
$qs = static function (array $over = []) use ($f): string {
    $q = array_filter(array_merge($f, $over), static fn($v) => $v !== '' && $v !== null);
    return '/admin_whatsapp_eventos.php' . ($q ? '?' . http_build_query($q) : '');
};
$badge = static function (string $level): string {
    $c = ['error' => '#dc2626', 'warn' => '#d97706', 'info' => '#2563eb', 'debug' => '#6b7280'][$level] ?? '#6b7280';
    $t = ['error' => 'ERRO', 'warn' => 'AVISO', 'info' => 'INFO', 'debug' => 'detalhe'][$level] ?? $level;
    return '<span class="wevBadge" style="background:' . $c . '">' . $t . '</span>';
};

view_header('Eventos do WhatsApp');
?>
<style>
.wev{min-width:0}
.wev>.card{min-width:0;overflow-wrap:anywhere}
.wev .muted{color:hsl(var(--muted-foreground));font-size:13px}
.wevBadge{display:inline-block;color:#fff;font-size:10px;font-weight:800;padding:2px 6px;border-radius:4px;letter-spacing:.3px;white-space:nowrap}
.wevSrc{display:inline-block;font-size:10px;font-weight:700;padding:1px 6px;border-radius:4px;border:1px solid #cbd5e1;color:#334155;white-space:nowrap}
.wevGrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(300px,100%),1fr));gap:10px;margin-top:10px}
.wevInst{min-width:0}
.wevInst{border:1px solid #e5e7eb;border-radius:10px;padding:10px 12px;background:#fff}
.wevInst.ok{border-left:5px solid #22c55e}.wevInst.warn{border-left:5px solid #f59e0b}.wevInst.error{border-left:5px solid #dc2626}
.wevInst ul{margin:6px 0 0 0;padding-left:16px;font-size:13px;line-height:1.5}
.wevKv{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(0,1fr);gap:2px 10px;font-size:12px;margin-top:6px}
.wevKv span:nth-child(odd){color:hsl(var(--muted-foreground))}
.wevCounters{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.wevCounter{display:block;padding:8px 12px;border-radius:8px;border:1px solid #e5e7eb;text-decoration:none;color:inherit;min-width:92px}
.wevCounter b{display:block;font-size:20px}
.wevCounter.on{outline:2px solid #2563eb}
.wevChips{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}
.wevChip{font-size:12px;padding:3px 9px;border-radius:999px;border:1px solid #cbd5e1;text-decoration:none;color:inherit;white-space:nowrap}
.wevChip.on{background:#1e293b;color:#fff;border-color:#1e293b}
.wevForm{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;align-items:center}
.wevForm select,.wevForm input{padding:6px 10px;max-width:100%}
.wev .wevForm select,.wev .wevForm input:not([type=checkbox]){width:auto;flex:0 1 auto;font-size:14px;margin:0}
.wev .wevForm input[type=search]{min-width:200px}
.wev .wevAuto{display:inline-flex !important;flex-direction:row !important;gap:6px;align-items:center;font-size:13px;font-weight:600;margin:0;white-space:nowrap}
.wev .wevAuto input{width:18px !important;height:18px;flex:0 0 auto !important;margin:0;padding:0}
.wevList{margin-top:8px}
.wevRow{border-bottom:1px solid #f1f5f9;padding:7px 2px;display:grid;grid-template-columns:118px 64px minmax(0,1fr);gap:8px;align-items:start;font-size:13px}
.wevRow.error{background:#fef2f2}.wevRow.warn{background:#fffbeb}
.wevRow .when{font-variant-numeric:tabular-nums;color:#334155;font-size:12px}
.wevRow .meta{display:flex;gap:6px;flex-wrap:wrap;align-items:center;font-size:11px;color:hsl(var(--muted-foreground));margin-top:3px}
.wevRow .meta a{color:#2563eb;text-decoration:none;word-break:break-all}
.wevRow details summary{cursor:pointer;color:#2563eb;font-size:12px}
.wevRow pre{margin:6px 0 0 0;max-height:360px;overflow:auto;background:#0f172a;color:#e2e8f0;padding:8px;border-radius:6px;font-size:11px;white-space:pre-wrap;word-break:break-word}
.wevAlert{padding:8px 12px;border-radius:8px;margin-top:8px;font-size:13px}
.wevAlert.error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}.wevAlert.warn{background:#fffbeb;color:#92400e;border:1px solid #fde68a}.wevAlert.info{background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe}
.wevTop td{padding:4px 8px;border-bottom:1px solid #f1f5f9;font-size:12px;vertical-align:top}
@media (max-width:640px){
  .wevRow{grid-template-columns:minmax(0,1fr);gap:3px}
  .wevRow .when{display:flex;gap:8px;align-items:center}
  .wevGrid{grid-template-columns:minmax(0,1fr)}
  .wev .wevForm select,.wev .wevForm input:not([type=checkbox]),.wev .wevForm button,.wev .wevForm #wevCustom{flex:1 1 100%;width:100%;min-width:0 !important}
  .wev .wevForm #wevCustom input{flex:1 1 100%}
  .wevTop,.wevTop tbody,.wevTop tr{display:block;width:100%}
  .wevTop tr{border-bottom:1px solid #e5e7eb;padding:6px 0}
  .wevTop td{display:inline-block;border:0;padding:2px 6px 2px 0;white-space:normal !important}
  .wevTop td:nth-child(3){display:block}
  .wevCounter{flex:1 1 40%}
}
</style>
<?php
echo '<div class="grid wev">';

// Cabeçalho + painel "Agora"
echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div><div style="font-size:22px;font-weight:900">Eventos do WhatsApp</div>';
echo '<div class="muted" style="margin-top:6px;line-height:1.6;max-width:860px">Tudo o que acontece no WhatsApp e no sistema: conexões e quedas (com o motivo), mensagens recebidas e enviadas, reações, entregas ao sistema, captações, mídias, erros. '
    . 'Clique no id de uma mensagem para ver a viagem completa dela. Horários no fuso do sistema.</div></div>';
echo '<div style="display:flex;gap:6px;flex-wrap:wrap">';
echo '<a class="btn" href="/admin_whatsapp_monitor.php">Monitor</a>';
echo '<a class="btn" href="/admin_webhook_debug.php">Webhook Debug</a>';
echo '<a class="btn" href="/admin_settings.php?aba=whatsapp-instancias">Configurações</a>';
echo '</div></div>';

foreach ($globalAlerts as [$lvl, $txt]) {
    echo '<div class="wevAlert ' . h($lvl) . '">' . h($txt) . '</div>';
}

echo '<div style="font-weight:800;margin-top:14px">Agora, por número</div>';
if (!$panel) {
    echo '<div class="muted" style="margin-top:6px">Nenhum número cadastrado.</div>';
}
echo '<div class="wevGrid">';
foreach ($panel as $name => $p) {
    $s = $p['s'];
    $a = $p['a'];
    echo '<div class="wevInst ' . h($p['worst']) . '">';
    echo '<div style="display:flex;justify-content:space-between;gap:8px;align-items:baseline;flex-wrap:wrap">';
    echo '<a style="font-weight:800;text-decoration:none;color:inherit" href="' . h($qs(['inst' => $name, 'pag' => null])) . '">' . h(wa_instance_label($name, $sysInstances[$name] ?? null)) . '</a>';
    if ($s) {
        $stLabel = ['open' => 'conectado', 'connecting' => 'conectando', 'close' => 'desconectado'][(string)($s['state'] ?? '')] ?? (string)($s['state'] ?? '?');
        echo '<span class="muted">' . h($stLabel . (!empty($s['number']) ? ' · ' . $s['number'] : '')) . '</span>';
    }
    echo '</div>';
    if ($p['alerts']) {
        echo '<ul>';
        foreach ($p['alerts'] as [$lvl, $txt]) {
            $color = ['error' => '#b91c1c', 'warn' => '#b45309', 'info' => '#1d4ed8'][$lvl] ?? 'inherit';
            echo '<li style="color:' . $color . '">' . h($txt) . '</li>';
        }
        echo '</ul>';
    } else {
        echo '<div style="color:#15803d;font-size:13px;margin-top:6px">Tudo funcionando: sem falhas nas últimas 24 h.</div>';
    }
    echo '<div class="wevKv">';
    $kv = [
        'Última recebida no serviço' => isset($a['svc_last_in']) ? wa_watchdog_ago($secAgo($a['svc_last_in'])) : 'nenhuma em 24 h',
        'Última que chegou no sistema' => isset($a['sys_last_in']) ? wa_watchdog_ago($secAgo($a['sys_last_in'])) : 'nenhuma em 24 h',
        'Último envio pelo sistema' => isset($a['last_sent']) ? wa_watchdog_ago($secAgo($a['last_sent'])) : 'nenhum em 24 h',
        'Último evento do serviço' => isset($a['svc_last_event']) ? wa_watchdog_ago($secAgo($a['svc_last_event'])) : 'nenhum em 24 h',
        'Erros / avisos (1 h)' => (int)($a['err_1h'] ?? 0) . ' / ' . (int)($a['warn_1h'] ?? 0),
    ];
    if ($s && is_array($s['webhook'] ?? null)) {
        $kv['Entrega ao sistema'] = (int)($s['webhook']['delivered'] ?? 0) . ' ok · ' . (int)($s['webhook']['failed'] ?? 0) . ' falhas · ' . (int)($s['webhook']['queued'] ?? 0) . ' na fila (desde que o serviço ligou)';
    }
    if ($s && ($s['state'] ?? '') === 'open') {
        $kv['Conectado há'] = str_replace('há ', '', wa_watchdog_ago((int)($s['uptime'] ?? 0)));
    }
    foreach ($kv as $k => $v) {
        echo '<span>' . h($k) . '</span><span>' . h((string)$v) . '</span>';
    }
    echo '</div>';
    if ((int)($a['err_1h'] ?? 0) > 0) {
        echo '<div style="margin-top:6px"><a class="wevChip" href="' . h($qs(['inst' => $name, 'nivel' => 'error', 'p' => '1h', 'pag' => null])) . '">Ver os erros da última hora</a></div>';
    }
    echo '</div>';
}
echo '</div>';
if ($inbox) {
    echo '<div class="muted" style="margin-top:10px">Fila do webhook (24 h): ' . (int)($inbox['done'] ?? 0) . ' processados · ' . (int)($inbox['pending'] ?? 0) . ' pendentes · '
        . (int)($inbox['processing'] ?? 0) . ' em processamento · ' . (int)($inbox['failed'] ?? 0) . ' com falha.</div>';
}
echo '</section>';

// Filtros e contadores
echo '<section class="card col12">';
if ($timeline) {
    echo '<div style="font-weight:900;font-size:17px">Viagem da mensagem / conversa <code style="word-break:break-all">' . h($f['msg']) . '</code></div>';
    echo '<div class="muted" style="margin-top:4px">Todos os eventos dela, do mais antigo ao mais novo, no serviço e no sistema. <a href="' . h($qs(['msg' => null, 'pag' => null])) . '">Voltar para todos os eventos</a></div>';
}
echo '<form method="get" class="wevForm">';
if ($timeline) {
    echo '<input type="hidden" name="msg" value="' . h($f['msg']) . '">';
} else {
    echo '<select name="p" onchange="document.getElementById(\'wevCustom\').style.display=this.value===\'custom\'?\'flex\':\'none\'">';
    foreach ($periods as $k => $v) {
        echo '<option value="' . h($k) . '"' . ($k === $f['p'] ? ' selected' : '') . '>' . h($v) . '</option>';
    }
    echo '</select>';
    echo '<span id="wevCustom" style="display:' . ($f['p'] === 'custom' ? 'flex' : 'none') . ';gap:6px;flex-wrap:wrap;align-items:center">de <input type="datetime-local" name="de" value="' . h($f['de']) . '"> até <input type="datetime-local" name="ate" value="' . h($f['ate']) . '"></span>';
}
echo '<select name="inst"><option value="">Todos os números</option>';
foreach ($allNames as $n) {
    echo '<option value="' . h($n) . '"' . ($n === $f['inst'] ? ' selected' : '') . '>' . h(wa_instance_label($n, $sysInstances[$n] ?? null)) . '</option>';
}
echo '</select>';
echo '<select name="nivel">';
foreach ($levels as $k => $v) {
    echo '<option value="' . h($k) . '"' . ($k === $f['nivel'] ? ' selected' : '') . '>' . h($v) . '</option>';
}
echo '</select>';
echo '<select name="cat"><option value="">Todas as categorias</option>';
foreach ($catNames as $k => $v) {
    echo '<option value="' . h($k) . '"' . ($k === $f['cat'] ? ' selected' : '') . '>' . h($v) . '</option>';
}
echo '</select>';
echo '<select name="origem"><option value="">Serviço e sistema</option><option value="servico"' . ($f['origem'] === 'servico' ? ' selected' : '') . '>Só o serviço WhatsApp</option><option value="sistema"' . ($f['origem'] === 'sistema' ? ' selected' : '') . '>Só o sistema</option></select>';
echo '<input type="search" name="q" value="' . h($f['q']) . '" placeholder="Buscar texto, número, erro..." style="min-width:200px">';
if (!$timeline) {
    echo '<input type="search" name="msg" value="" placeholder="Id da mensagem ou conversa" style="min-width:180px">';
}
if ($f['ev'] !== '') {
    echo '<input type="hidden" name="ev" value="' . h($f['ev']) . '">';
}
echo '<label class="wevAuto"><input type="checkbox" name="auto" value="1"' . ($f['auto'] ? ' checked' : '') . '> Atualizar sozinho</label>';
echo '<button class="btn btnPrimary" type="submit">Filtrar</button>';
echo '<a class="btn" href="/admin_whatsapp_eventos.php">Limpar</a>';
echo '<a class="btn" href="' . h($qs(['csv' => '1', 'pag' => null])) . '">Baixar planilha</a>';
echo '</form>';
if ($f['ev'] !== '') {
    echo '<div class="wevChips"><a class="wevChip on" href="' . h($qs(['ev' => null, 'pag' => null])) . '">evento: ' . h($f['ev']) . ' ✕</a></div>';
}

echo '<div class="wevCounters">';
foreach ($levelNames as $lv => $label) {
    $target = $lv === 'debug' ? '' : $lv;
    $on = $f['nivel'] === $target && $lv !== 'debug';
    echo '<a class="wevCounter' . ($on ? ' on' : '') . '" href="' . h($qs(['nivel' => $target, 'pag' => null])) . '" style="border-left:4px solid '
        . (['error' => '#dc2626', 'warn' => '#d97706', 'info' => '#2563eb', 'debug' => '#9ca3af'][$lv]) . '"><b>' . number_format($levelCount[$lv], 0, ',', '.') . '</b><span class="muted">' . h($label) . '</span></a>';
}
echo '</div>';
echo '<div class="wevChips">';
echo '<a class="wevChip' . ($f['cat'] === '' ? ' on' : '') . '" href="' . h($qs(['cat' => null, 'pag' => null])) . '">Todas</a>';
foreach ($catCount as $c) {
    $k = (string)$c['category'];
    echo '<a class="wevChip' . ($f['cat'] === $k ? ' on' : '') . '" href="' . h($qs(['cat' => $k, 'pag' => null])) . '">' . h($catNames[$k] ?? $k) . ' · ' . (int)$c['n']
        . ((int)$c['e'] > 0 ? ' <span style="color:#dc2626">(' . (int)$c['e'] . ' erros)</span>' : '') . '</a>';
}
echo '</div>';
echo '</section>';

// O que mais deu problema no período
if ($topErrors) {
    echo '<section class="card col12"><div style="font-weight:800">O que mais deu problema no período</div>';
    echo '<div style="overflow-x:auto;margin-top:6px"><table class="wevTop" style="width:100%;border-collapse:collapse">';
    foreach ($topErrors as $t) {
        echo '<tr><td style="white-space:nowrap"><a class="wevChip" href="' . h($qs(['ev' => (string)$t['event'], 'cat' => (string)$t['category'], 'pag' => null])) . '">' . h((string)$t['event']) . '</a></td>'
            . '<td style="white-space:nowrap"><b>' . (int)$t['n'] . '×</b></td>'
            . '<td>' . h(mb_strimwidth((string)$t['sample'], 0, 180, '…')) . '</td>'
            . '<td class="muted" style="white-space:nowrap">última ' . h($fmtLocal((string)$t['last_at'], 'd/m H:i')) . '</td></tr>';
    }
    echo '</table></div></section>';
}

// Lista
echo '<section class="card col12">';
echo '<div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;align-items:baseline"><div style="font-weight:800">'
    . ($timeline ? 'Linha do tempo' : 'Eventos') . '</div><div class="muted">' . ($rows ? 'mostrando ' . ($offset + 1) . '–' . ($offset + count($rows)) : 'nenhum evento com estes filtros') . '</div></div>';
echo '<div class="wevList">';
foreach ($rows as $r) {
    $lvl = (string)$r['level'];
    echo '<div class="wevRow ' . h($lvl) . '">';
    echo '<div class="when" title="' . h($fmtLocal((string)$r['created_at'], 'd/m/Y H:i:s.v')) . '">' . h($fmtLocal((string)$r['created_at'])) . '</div>';
    echo '<div>' . $badge($lvl) . '</div>';
    echo '<div style="min-width:0">';
    echo '<div style="word-break:break-word">' . h((string)($r['summary'] ?? $r['event'])) . '</div>';
    echo '<div class="meta">';
    echo '<span class="wevSrc">' . ($r['source'] === 'servico' ? 'serviço' : 'sistema') . '</span>';
    echo '<a href="' . h($qs(['cat' => (string)$r['category'], 'pag' => null])) . '">' . h($catNames[(string)$r['category']] ?? (string)$r['category']) . '</a>';
    echo '<a href="' . h($qs(['ev' => (string)$r['event'], 'pag' => null])) . '">' . h((string)$r['event']) . '</a>';
    if (!empty($r['instance'])) {
        echo '<a href="' . h($qs(['inst' => (string)$r['instance'], 'pag' => null])) . '">' . h((string)$r['instance']) . '</a>';
    }
    if (!empty($r['message_id'])) {
        echo '<a title="Ver a viagem desta mensagem" href="' . h($qs(['msg' => (string)$r['message_id'], 'pag' => null, 'cat' => null, 'nivel' => null, 'ev' => null, 'q' => null])) . '">msg ' . h((string)$r['message_id']) . '</a>';
    }
    if (!empty($r['remote_jid'])) {
        echo '<a title="Ver todos os eventos desta conversa" href="' . h($qs(['msg' => (string)$r['remote_jid'], 'pag' => null, 'cat' => null, 'nivel' => null, 'ev' => null, 'q' => null])) . '">' . h((string)$r['remote_jid']) . '</a>';
    }
    if ($r['status'] !== null && $r['status'] !== '') {
        echo '<span>' . h((string)$r['status']) . '</span>';
    }
    if ($r['http_status'] !== null) {
        echo '<span>HTTP ' . (int)$r['http_status'] . '</span>';
    }
    if ($r['duration_ms'] !== null) {
        echo '<span>' . number_format((int)$r['duration_ms'], 0, ',', '.') . ' ms</span>';
    }
    echo '</div>';
    if (!empty($r['error']) || !empty($r['details'])) {
        echo '<details><summary>' . (!empty($r['error']) ? 'erro e detalhes' : 'detalhes') . '</summary>';
        if (!empty($r['error'])) {
            echo '<pre style="background:#450a0a">' . h((string)$r['error']) . '</pre>';
        }
        if (!empty($r['details'])) {
            $d = json_decode((string)$r['details'], true);
            echo '<pre>' . h($d !== null ? (string)json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string)$r['details']) . '</pre>';
        }
        echo '</details>';
    }
    echo '</div></div>';
}
echo '</div>';
echo '<div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap">';
if ($offset > 0) {
    echo '<a class="btn" href="' . h($qs(['pag' => (string)max(0, $offset - $perPage)])) . '">' . ($timeline ? '← Anteriores' : '← Mais novos') . '</a>';
}
if ($hasMore) {
    echo '<a class="btn" href="' . h($qs(['pag' => (string)($offset + $perPage)])) . '">' . ($timeline ? 'Seguintes →' : 'Mais antigos →') . '</a>';
}
echo '</div>';
$retention = wa_event_log_retention_days();
echo '<div class="muted" style="margin-top:10px">O log guarda ' . ($retention > 0 ? $retention . ' dias' : 'para sempre') . ' de eventos'
    . (wa_event_log_debug_enabled() ? ', incluindo os de detalhe' : ' (eventos de detalhe desligados)') . '. Ajuste em Configurações → WhatsApp Conexão → Credenciais.</div>';
echo '</section>';

echo '</div>';
if ($f['auto'] && $offset === 0) {
    // Não recarrega enquanto alguém lê os detalhes de um evento
    echo '<script>(function wevTick(){ setTimeout(function(){ if (document.querySelector(".wev details[open]")) { wevTick(); } else { location.reload(); } }, 15000); })();</script>';
}
view_footer();

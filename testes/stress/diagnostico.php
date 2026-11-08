<?php
/**
 * DIAGNÓSTICO PRÉ-APRESENTAÇÃO (somente leitura).
 *
 * Rodar NO SERVIDOR (onde está o banco real), de um destes jeitos:
 *   - Terminal:  php -d opcache.enable_cli=0 testes/stress/diagnostico.php [--wa-rounds=10] [--skip-wa] [--skip-email]
 *   - Navegador: https://SEU-DOMINIO/testes/stress/diagnostico.php?wa_rounds=10   (precisa estar logado como admin)
 *
 * O que verifica (não envia WhatsApp, não envia e-mail, não altera dados):
 *   1. Banco: tabelas e colunas novas do fluxo revisado.
 *   2. WhatsApp: estado de cada instância na Evolution, consultado N vezes seguidas
 *      (detecta instância oscilando / em loop e tempo de resposta da Evolution).
 *   3. Chat: para cada usuário com WhatsApp, quais WhatsApps ele vê e quantas
 *      conversas aparecem; roda a MESMA estrutura de consulta do chat_web.php para
 *      pegar erro de SQL/parâmetros.
 *   4. Captação: para cada card aberto, quantos profissionais existem na cidade/zona
 *      e no estado (mesma regra do botão "Realizar Captação"), no modo atual.
 *   5. Fila: captações presas em "queued/processing" e jobs de integração atrasados.
 *   6. E-mail: login no IMAP configurado (só abre e fecha a caixa).
 *
 * Observação: algumas funções do sistema criam colunas que faltarem (ALTER ADD),
 * como já acontece no uso normal. Nenhum dado é apagado ou modificado.
 */

declare(strict_types=1);

$isCli = PHP_SAPI === 'cli';
require_once __DIR__ . '/../../app/bootstrap.php';

if (!$isCli) {
    auth_require_login();
    if (!rbac_user_has_role((int)auth_user_id(), 'admin')) {
        http_response_code(403);
        exit('Somente admin.');
    }
    if (function_exists('session_release')) { session_release(); }
    header('Content-Type: text/plain; charset=utf-8');
    @set_time_limit(300);
}

$opt = static function (string $name, $default = null) use ($isCli, $argv) {
    if ($isCli) {
        foreach (($argv ?? []) as $a) {
            if ($a === "--$name") return true;
            if (strpos($a, "--$name=") === 0) return substr($a, strlen($name) + 3);
        }
        return $default;
    }
    $k = str_replace('-', '_', $name);
    return $_GET[$k] ?? $default;
};

$waRounds = max(1, min(60, (int)$opt('wa-rounds', 5)));
$skipWa = (bool)$opt('skip-wa', false);
$skipEmail = (bool)$opt('skip-email', false);

$problems = [];
$warnings = [];
$out = static function (string $s = ''): void { echo $s . PHP_EOL; @flush(); };
$section = static function (string $t) use ($out): void { $out(''); $out(str_repeat('=', 70)); $out($t); $out(str_repeat('=', 70)); };
$t0 = microtime(true);

$out('DIAGNÓSTICO MultiLife Care — ' . date('d/m/Y H:i:s') . ' — host do banco: ' . ($config['db']['host'] ?? '?') . ' / ' . ($config['db']['name'] ?? '?'));

// ---------------------------------------------------------------- 1) banco
$section('1) BANCO DE DADOS');
$need = [
    'demands' => ['location_city', 'location_state', 'specialty'],
    'demand_dispatch_runs' => ['status', 'phase', 'result_message', 'dispatch_scope', 'sub_request_id'],
    'chat_contacts' => ['remote_jid', 'instance_name', 'status'],
    'chat_messages' => ['remote_jid', 'instance_name', 'is_read'],
    'whatsapp_instances' => ['instance_name', 'display_name', 'connection_status', 'is_default', 'user_id'],
    'whatsapp_instance_users' => ['instance_id', 'user_id'],
    'users' => ['phone', 'specialty', 'city', 'state', 'is_test_professional'],
    'admin_settings' => ['setting_key', 'setting_value'],
];
$dbName = (string)db()->query('SELECT DATABASE()')->fetchColumn();
foreach ($need as $table => $cols) {
    $st = db()->prepare('SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = ? AND table_name = ?');
    $st->execute([$dbName, $table]);
    $have = $st->fetchAll(PDO::FETCH_COLUMN);
    if (!$have) {
        $problems[] = "Tabela ausente: $table";
        $out("  [FALTA] tabela $table");
        continue;
    }
    $missing = array_diff($cols, $have);
    if ($missing) {
        $warnings[] = "Colunas ausentes em $table: " . implode(', ', $missing) . ' (o sistema cria no primeiro uso; confira).';
        $out("  [AVISO] $table sem: " . implode(', ', $missing));
    } else {
        $cnt = (int)db()->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        $out("  [ok] $table ($cnt linhas)");
    }
}
$testMode = (string)admin_setting_get('feature.captacao_test_mode', '0') === '1';
$out('  Modo de teste da captação: ' . ($testMode ? 'LIGADO (só profissionais de teste)' : 'desligado'));
if ($testMode) {
    try {
        $n = (int)db()->query("SELECT COUNT(*) FROM users WHERE is_test_professional = 1 AND status = 'active'")->fetchColumn();
        $out("  Profissionais de teste ativos: $n");
        if ($n === 0) $problems[] = 'Modo de teste ligado sem nenhum profissional de teste ativo.';
    } catch (Throwable $e) {
        $problems[] = 'Não deu para contar profissionais de teste: ' . $e->getMessage();
    }
}

// ---------------------------------------------------------------- 2) WhatsApp
$section('2) WHATSAPP (Evolution) — ' . ($skipWa ? 'pulado' : "$waRounds rodada(s)"));
try {
$instances = whatsapp_list_all_instances();
$baseUrl = (string)admin_setting_get('evolution.base_url', '');
$apiKey = (string)admin_setting_get('evolution.api_key', '');
foreach ($instances as $i) {
    $out(sprintf('  - %s (apelido: %s) | status gravado: %s | padrão: %s | usuários: %s',
        $i['instance_name'], $i['display_name'] ?: '-', $i['connection_status'] ?: 'NULL',
        !empty($i['is_default']) ? 'sim' : 'não', $i['linked_user_names'] ?: ($i['user_name'] ?: '-')));
}
if (!$skipWa && $baseUrl !== '' && $apiKey !== '') {
    foreach ($instances as $i) {
        $name = (string)$i['instance_name'];
        $api = new EvolutionApiV1($baseUrl, $apiKey, $name);
        $states = []; $times = []; $fails = 0;
        for ($r = 0; $r < $waRounds; $r++) {
            $s = microtime(true);
            try {
                $res = $api->connectionState(null, 10);
                $st = (string)($res['json']['instance']['state'] ?? $res['json']['state'] ?? ('HTTP ' . ($res['status'] ?? 0)));
            } catch (Throwable $e) {
                $st = 'ERRO'; $fails++;
            }
            $times[] = (microtime(true) - $s) * 1000;
            $states[] = $st;
            if ($r < $waRounds - 1) usleep(700000);
        }
        $uniq = array_count_values($states);
        sort($times);
        $p95 = $times[(int)floor(0.95 * (count($times) - 1))];
        $out(sprintf('  %s: %s | resposta média %.0f ms, p95 %.0f ms', $name, json_encode($uniq), array_sum($times) / count($times), $p95));
        if (count($uniq) > 1) $problems[] = "WhatsApp '$name' oscilou entre estados: " . json_encode($uniq) . ' (possível loop de conexão).';
        elseif (!isset($uniq['open'])) $warnings[] = "WhatsApp '$name' não está conectado (estado: " . implode(',', array_keys($uniq)) . ').';
        if ($p95 > 5000) $warnings[] = "Evolution lenta para '$name' (p95 " . round($p95) . ' ms).';
        if ($fails > 0) $problems[] = "Falha ao consultar '$name' na Evolution ($fails de $waRounds).";
    }
} elseif (!$skipWa) {
    $problems[] = 'Evolution sem base_url/api_key configurados.';
}
} catch (Throwable $e) {
    $problems[] = 'Seção WhatsApp falhou: ' . $e->getMessage();
    $out('  ERRO ' . $e->getMessage());
}

// ---------------------------------------------------------------- 3) chat
$section('3) CHAT — o que cada usuário enxerga');
try {
$userIds = db()->query("SELECT DISTINCT u.id FROM users u
    LEFT JOIN whatsapp_instance_users wiu ON wiu.user_id = u.id
    LEFT JOIN whatsapp_instances wi ON wi.user_id = u.id
    LEFT JOIN user_roles ur ON ur.user_id = u.id LEFT JOIN roles r ON r.id = ur.role_id
    WHERE u.status = 'active' AND (wiu.user_id IS NOT NULL OR wi.id IS NOT NULL OR r.slug = 'admin')
    ORDER BY u.id")->fetchAll(PDO::FETCH_COLUMN);
$nullMsgs = (int)db()->query("SELECT COUNT(*) FROM chat_messages WHERE instance_name IS NULL AND remote_jid NOT LIKE '%@g.us'")->fetchColumn();
$out("  Mensagens privadas sem WhatsApp gravado (antigas, aparecem para todos que veem o contato): $nullMsgs");
foreach ($userIds as $uid) {
    $uid = (int)$uid;
    $name = (string)db()->query('SELECT name FROM users WHERE id = ' . $uid)->fetchColumn();
    $isAdmin = rbac_user_has_role($uid, 'admin');
    $names = whatsapp_user_instance_names($uid);
    if (!$names) {
        $warnings[] = "Usuário '$name' sem WhatsApp disponível.";
        $out("  - $name: nenhum WhatsApp");
        continue;
    }
    // Mesma estrutura da lista do chat_web.php (subconsultas + JOIN + WHERE).
    $ph = implode(',', array_fill(0, count($names), '?'));
    $sql = "SELECT cc.remote_jid,
              (SELECT MAX(cm2.message_timestamp) FROM chat_messages cm2 WHERE cm2.remote_jid = cc.remote_jid AND (cm2.instance_name IN ($ph) OR cm2.instance_name IS NULL)) AS ts,
              (SELECT cm3.message_text FROM chat_messages cm3 WHERE cm3.remote_jid = cc.remote_jid AND (cm3.instance_name IN ($ph) OR cm3.instance_name IS NULL) ORDER BY cm3.message_timestamp DESC, cm3.id DESC LIMIT 1) AS txt,
              COUNT(CASE WHEN cm.from_me = 0 AND cm.is_read = 0 THEN 1 END) AS unread
            FROM chat_contacts cc
            LEFT JOIN chat_messages cm ON cm.remote_jid = cc.remote_jid AND (cm.instance_name IN ($ph) OR cm.instance_name IS NULL)
            WHERE cc.is_group = 0
              AND (cc.instance_name IN ($ph) OR EXISTS (SELECT 1 FROM chat_messages cmx WHERE cmx.remote_jid = cc.remote_jid AND cmx.instance_name IN ($ph)))
              AND (cc.status != 'arquivado' OR cc.status IS NULL)
            GROUP BY cc.remote_jid ORDER BY ts DESC LIMIT 50";
    $s = microtime(true);
    try {
        $st = db()->prepare($sql);
        $st->execute(array_merge($names, $names, $names, $names, $names));
        $rows = $st->fetchAll();
        $ms = (microtime(true) - $s) * 1000;
        $sendInst = whatsapp_chat_instance_for_user($uid, (string)($rows[0]['remote_jid'] ?? ''), '');
        $out(sprintf('  - %s%s: vê %s | %d conversa(s) na 1ª página | consulta %.0f ms | resposta sai por: %s',
            $name, $isAdmin ? ' [admin]' : '', implode(', ', $names), count($rows), $ms, $sendInst ?: '?'));
        if ($ms > 3000) $warnings[] = "Lista do chat lenta para '$name' (" . round($ms) . ' ms).';
        if ($sendInst !== '' && !in_array($sendInst, $names, true)) $problems[] = "Resposta de '$name' sairia por WhatsApp que ele não vê ($sendInst).";
    } catch (Throwable $e) {
        $problems[] = "ERRO na consulta do chat para '$name': " . $e->getMessage();
        $out("  - $name: ERRO " . $e->getMessage());
    }
}
} catch (Throwable $e) {
    $problems[] = 'Seção Chat falhou: ' . $e->getMessage();
    $out('  ERRO ' . $e->getMessage());
}

// ---------------------------------------------------------------- 4) captação
$section('4) CAPTAÇÃO — profissionais por card aberto (' . ($testMode ? 'modo TESTE' : 'modo real') . ')');
try {
$cards = db()->query("SELECT * FROM demands WHERE status NOT IN ('cancelado','cancelada','concluido','concluida','admitido','arquivado')
    ORDER BY id DESC LIMIT 40")->fetchAll();
foreach ($cards as $d) {
    try {
        $loc = dispatch_resolve_location($d, null);
        if ($loc['specialty'] === '' || $loc['city'] === '') {
            $out(sprintf('  #%d %s: SEM especialidade/cidade — captação vai pedir para completar', $d['id'], mb_strimwidth((string)$d['title'], 0, 40, '…')));
            continue;
        }
        $s = microtime(true);
        $pre = dispatch_precheck_professionals($loc, $testMode);
        $ms = (microtime(true) - $s) * 1000;
        $what = $pre['ok'] ? $pre['city_count'] . ' na cidade/zona → envia'
            : ($pre['state_count'] > 0 ? '0 na cidade, ' . $pre['state_count'] . ' no estado → mostra botão AMPLIAR' : '0 na cidade e no estado → só avisa');
        $out(sprintf('  #%d %s | %s | %s | %.0f ms', $d['id'], $loc['specialty'],
            dispatch_group_location_label($loc['city'], $loc['state'], $loc['zone']), $what, $ms));
        if ($ms > 5000) $warnings[] = "Checagem de profissionais lenta no card #{$d['id']} (" . round($ms) . ' ms).';
    } catch (Throwable $e) {
        $problems[] = "ERRO ao checar card #{$d['id']}: " . $e->getMessage();
        $out("  #{$d['id']}: ERRO " . $e->getMessage());
    }
}
} catch (Throwable $e) {
    $problems[] = 'Seção Captação falhou: ' . $e->getMessage();
    $out('  ERRO ' . $e->getMessage());
}

// ---------------------------------------------------------------- 5) fila
$section('5) FILA DE CAPTAÇÃO / JOBS');
try {
    $stuck = db()->query("SELECT id, demand_id, status, phase, updated_at FROM demand_dispatch_runs
        WHERE status IN ('queued','processing') AND updated_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE) ORDER BY id DESC LIMIT 20")->fetchAll();
    $out('  Captações paradas há +10 min: ' . count($stuck));
    foreach ($stuck as $r) $out("    run #{$r['id']} card #{$r['demand_id']} {$r['status']}/{$r['phase']} desde {$r['updated_at']}");
    if ($stuck) $warnings[] = count($stuck) . ' captação(ões) parada(s) em queued/processing (o acompanhamento marca como falha; confira o cron do worker).';
    $last = db()->query("SELECT status, COUNT(*) c FROM demand_dispatch_runs WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY status")->fetchAll();
    $out('  Últimos 7 dias: ' . json_encode(array_column($last, 'c', 'status')));
} catch (Throwable $e) {
    $out('  demand_dispatch_runs: ' . $e->getMessage());
}
try {
    $jobs = db()->query("SELECT status, COUNT(*) c, MIN(created_at) oldest FROM integration_jobs
        WHERE status IN ('pending','running') GROUP BY status")->fetchAll();
    foreach ($jobs as $j) {
        $out("  integration_jobs {$j['status']}: {$j['c']} (mais antigo {$j['oldest']})");
        if (strtotime((string)$j['oldest']) < time() - 900) $warnings[] = "Jobs '{$j['status']}' esperando há +15 min: o cron do worker pode estar parado.";
    }
    if (!$jobs) $out('  integration_jobs: nenhum pendente');
} catch (Throwable $e) {
    $out('  integration_jobs: ' . $e->getMessage());
}

// ---------------------------------------------------------------- 6) e-mail
$section('6) E-MAIL (IMAP) — ' . ($skipEmail ? 'pulado' : 'login de teste'));
if (!$skipEmail) {
    $host = trim((string)admin_setting_get('smtp.in.host', ''));
    $user = trim((string)admin_setting_get('smtp.in.username', ''));
    $pass = (string)admin_setting_get('smtp.in.password', '');
    if (!function_exists('imap_open')) {
        $warnings[] = 'Extensão IMAP do PHP não instalada neste PHP (o recebimento de e-mail não funciona aqui).';
        $out('  extensão imap ausente');
    } elseif ($host === '' || $user === '' || $pass === '') {
        $warnings[] = 'IMAP de recebimento não configurado.';
        $out('  IMAP não configurado');
    } else {
        $port = (int)admin_setting_get('smtp.in.port', '993');
        $enc = strtolower(trim((string)admin_setting_get('smtp.in.encryption', 'ssl')));
        $flags = '/imap' . ($enc === 'ssl' ? '/ssl' : ($enc === 'tls' ? '/tls' : '/notls')) . '/novalidate-cert';
        $mb = trim((string)admin_setting_get('smtp.in.mailbox', 'INBOX')) ?: 'INBOX';
        @imap_timeout(IMAP_OPENTIMEOUT, 10);
        $s = microtime(true);
        $imap = @imap_open('{' . $host . ':' . $port . $flags . '}' . $mb, $user, $pass, OP_READONLY, 1);
        $ms = (microtime(true) - $s) * 1000;
        if ($imap) {
            $info = @imap_check($imap);
            $out(sprintf('  [ok] login em %s como %s (%.0f ms) — %d mensagem(ns) na caixa', $host, $user, $ms, (int)($info->Nmsgs ?? 0)));
            imap_close($imap);
        } else {
            $problems[] = 'Falha no login IMAP: ' . imap_last_error();
            $out('  FALHA: ' . imap_last_error());
        }
        @imap_errors();
    }
}

// ---------------------------------------------------------------- resumo
$section('RESUMO (' . round(microtime(true) - $t0, 1) . ' s)');
$out('PROBLEMAS (' . count($problems) . '):');
foreach ($problems as $p) $out('  ✗ ' . $p);
$out('AVISOS (' . count($warnings) . '):');
foreach ($warnings as $w) $out('  ! ' . $w);
if (!$problems) $out('Nenhum problema bloqueante encontrado.');
if ($isCli) exit($problems ? 1 : 0);

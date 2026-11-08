<?php
/**
 * TESTE DE CARGA HTTP (somente leitura: só faz GET em páginas e consultas).
 *
 * Simula vários usuários abrindo as telas ao mesmo tempo e mede tempo de resposta
 * e erros. Não clica em botões, não envia WhatsApp/e-mail, não grava nada
 * (só o registro normal de login/acesso que o sistema já faz).
 *
 * Uso (de qualquer computador com PHP + curl):
 *   set ML_EMAIL=admin@...& set ML_PASS=...
 *   php testes/stress/http_load.php https://multilife.onsolutionsbrasil.com.br [--users=10] [--rounds=5] [--paths=/a.php,/b.php]
 *
 * Credenciais SEMPRE por variável de ambiente (ML_EMAIL / ML_PASS), nunca no código.
 * Sugestão: comece com --users=5 e só depois aumente (10, 20). Fora do horário de uso.
 */

declare(strict_types=1);

if (!function_exists('curl_multi_init')) {
    fwrite(STDERR, "Extensão curl do PHP não habilitada.\n");
    exit(2);
}

$base = rtrim((string)($argv[1] ?? ''), '/');
if ($base === '' || !preg_match('#^https?://#', $base)) {
    fwrite(STDERR, "Uso: php http_load.php https://SEU-DOMINIO [--users=10] [--rounds=5] [--paths=/a.php,/b.php]\n");
    exit(2);
}
$arg = static function (string $n, string $def) use ($argv): string {
    foreach ($argv as $a) if (strpos($a, "--$n=") === 0) return substr($a, strlen($n) + 3);
    return $def;
};
$users = max(1, min(50, (int)$arg('users', '5')));
$rounds = max(1, min(50, (int)$arg('rounds', '3')));
$paths = array_values(array_filter(array_map('trim', explode(',', $arg('paths',
    '/dashboard.php,/demands_list.php,/chat_web.php,/chat_web.php?type=lista_espera,/chat_web.php?type=todos,'
    . '/notifications_get.php,/demands_dispatch_status.php?active=1,/monitoramento.php')))));

$email = (string)getenv('ML_EMAIL');
$pass = (string)getenv('ML_PASS');
if ($email === '' || $pass === '') {
    fwrite(STDERR, "Defina ML_EMAIL e ML_PASS (usuário de teste/admin) nas variáveis de ambiente.\n");
    exit(2);
}

$logDir = __DIR__ . '/_resultados';
@mkdir($logDir, 0777, true);
$report = $logDir . '/http_load_' . date('Ymd_His') . '.txt';
$say = static function (string $s) use ($report): void { echo $s . PHP_EOL; file_put_contents($report, $s . PHP_EOL, FILE_APPEND); };

// 1) login de cada "usuário virtual" (cada um com sua sessão/cookie)
$jars = [];
for ($u = 0; $u < $users; $u++) {
    $jar = tempnam(sys_get_temp_dir(), 'mlcj');
    $ch = curl_init($base . '/login_post.php');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['email' => $email, 'password' => $pass]),
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30,
    ]);
    curl_exec($ch);
    $loc = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    if (strpos($loc, 'login.php') !== false || $loc === '') {
        $say("Login falhou para o usuário virtual $u (redirect: $loc). Confira ML_EMAIL/ML_PASS.");
        exit(1);
    }
    $jars[] = $jar;
}
$say("Base: $base | usuários simultâneos: $users | rodadas: $rounds | páginas: " . count($paths));

// 2) rodadas: em cada rodada, todos os usuários pedem todas as páginas ao mesmo tempo
$stats = [];
for ($r = 1; $r <= $rounds; $r++) {
    $mh = curl_multi_init();
    $handles = [];
    foreach ($jars as $jar) {
        foreach ($paths as $p) {
            $ch = curl_init($base . $p);
            curl_setopt_array($ch, [
                CURLOPT_COOKIEFILE => $jar, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => ['X-Requested-With: XMLHttpRequest'],
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[] = [$ch, $p];
        }
    }
    $t = microtime(true);
    do {
        $st = curl_multi_exec($mh, $running);
        if ($running) curl_multi_select($mh, 1.0);
    } while ($running && $st === CURLM_OK);
    $wall = microtime(true) - $t;
    foreach ($handles as [$ch, $p]) {
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $ms = curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000;
        $body = (string)curl_multi_getcontent($ch);
        $redirect = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $err = '';
        if ($code === 0) $err = 'sem resposta: ' . curl_error($ch);
        elseif ($code >= 500) $err = "HTTP $code";
        elseif ($code >= 300 && strpos($redirect, 'login') !== false) $err = 'sessão caiu (redirect para login)';
        elseif (preg_match('/(Fatal error|Uncaught|Parse error|SQLSTATE\[)/', $body, $m)) $err = 'erro PHP na página: ' . $m[1];
        $stats[$p]['t'][] = $ms;
        if ($err !== '') $stats[$p]['e'][] = $err;
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    $say(sprintf('  rodada %d: %d requisições em %.1f s', $r, count($handles), $wall));
}

// 3) relatório
$say('');
$say(sprintf('%-45s %6s %8s %8s %8s %6s', 'página', 'req', 'média', 'p95', 'máx', 'erros'));
$bad = 0;
foreach ($stats as $p => $s) {
    $t = $s['t']; sort($t);
    $p95 = $t[(int)floor(0.95 * (count($t) - 1))];
    $errs = $s['e'] ?? [];
    $bad += count($errs);
    $say(sprintf('%-45s %6d %7.0fms %7.0fms %7.0fms %6d', mb_strimwidth($p, 0, 45), count($t), array_sum($t) / count($t), $p95, end($t), count($errs)));
    foreach (array_slice(array_count_values($errs), 0, 3, true) as $e => $c) $say("      ↳ $c× $e");
}
$say('');
$say($bad === 0 ? 'OK: nenhuma falha.' : "ATENÇÃO: $bad falha(s). Veja acima.");
$say("Relatório salvo em $report");
foreach ($jars as $j) @unlink($j);
exit($bad > 0 ? 1 : 0);

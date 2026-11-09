<?php
/**
 * TESTE DE CARGA pelo navegador (somente leitura: só abre páginas com GET).
 *
 * Acesse logado como admin: /testes/stress/carga.php
 * Simula N usuários abrindo as telas principais AO MESMO TEMPO e mede tempo de
 * resposta e erros. Os "usuários virtuais" usam sessões temporárias com o SEU login
 * (não pede senha) e são apagadas no fim. Não clica em botões, não envia nada.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

auth_require_login();
if (!rbac_user_has_role((int)auth_user_id(), 'admin')) {
    http_response_code(403);
    exit('Somente admin.');
}

$allPages = [
    '/dashboard.php' => 'Dashboard',
    '/demands_list.php' => 'Captação (lista de cards)',
    '/chat_web.php' => 'Chat ao Vivo',
    '/chat_web.php?type=lista_espera' => 'Chat: Lista de Espera',
    '/chat_web.php?type=todos' => 'Chat: Todos',
    '/notifications_get.php' => 'Notificações (sino)',
    '/demands_dispatch_status.php?active=1' => 'Acompanhamento da captação',
    '/monitoramento.php' => 'Monitoramento',
];

$run = isset($_GET['run']);
$users = max(1, min(20, (int)($_GET['users'] ?? 5)));
$rounds = max(1, min(8, (int)($_GET['rounds'] ?? 3)));
$mode = (string)($_GET['mode'] ?? 'realista') === 'pesado' ? 'pesado' : 'realista';
$picked = array_values(array_intersect(array_keys($allPages), (array)($_GET['p'] ?? array_keys($allPages))));
if (!$picked) {
    $picked = array_keys($allPages);
}

$results = null;
$error = '';

if ($run) {
    @set_time_limit(300);
    if (!function_exists('curl_multi_init')) {
        $error = 'A extensão curl do PHP não está habilitada no servidor.';
    } else {
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $base = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $cookieName = session_name();
        $origId = session_id();
        $sessionData = $_SESSION;
        session_write_close();

        // Sessões temporárias (uma por usuário virtual) com o mesmo login do admin.
        $tempIds = [];
        for ($u = 0; $u < $users; $u++) {
            $sid = bin2hex(random_bytes(16));
            session_id($sid);
            session_start();
            $_SESSION = $sessionData;
            $_SESSION['_last_activity'] = time();
            session_write_close();
            $tempIds[] = $sid;
        }

        $stats = [];
        $roundsInfo = [];
        try {
            for ($r = 1; $r <= $rounds; $r++) {
                $mh = curl_multi_init();
                $handles = [];
                foreach ($tempIds as $uIdx => $sid) {
                    // Realista: cada usuário abre UMA tela por vez (como uma pessoa).
                    // Pesado: cada usuário abre TODAS as telas no mesmo instante.
                    $pagesNow = $mode === 'realista' ? [$picked[($uIdx + $r) % count($picked)]] : $picked;
                    foreach ($pagesNow as $p) {
                        $ch = curl_init($base . $p);
                        curl_setopt_array($ch, [
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_FOLLOWLOCATION => false,
                            CURLOPT_TIMEOUT => 30,
                            CURLOPT_CONNECTTIMEOUT => 10,
                            CURLOPT_COOKIE => $cookieName . '=' . $sid,
                            CURLOPT_HTTPHEADER => ['X-Requested-With: XMLHttpRequest'],
                            // Requisição do servidor para ele mesmo: não depende da cadeia do certificado.
                            CURLOPT_SSL_VERIFYPEER => false,
                            CURLOPT_SSL_VERIFYHOST => 0,
                        ]);
                        curl_multi_add_handle($mh, $ch);
                        $handles[] = [$ch, $p];
                    }
                }
                $t = microtime(true);
                do {
                    $st = curl_multi_exec($mh, $running);
                    if ($running) {
                        curl_multi_select($mh, 1.0);
                    }
                } while ($running && $st === CURLM_OK);
                $roundsInfo[] = [count($handles), microtime(true) - $t];
                foreach ($handles as [$ch, $p]) {
                    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                    $ms = curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000;
                    $body = (string)curl_multi_getcontent($ch);
                    $redirect = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
                    $err = '';
                    if ($code === 0) {
                        $err = 'sem resposta (' . (curl_error($ch) ?: 'tempo esgotado') . ')';
                    } elseif ($code === 508) {
                        $err = 'limite de processos da hospedagem atingido (508)';
                    } elseif ($code >= 500) {
                        $err = "erro HTTP $code";
                    } elseif ($code >= 300 && stripos($redirect, 'login') !== false) {
                        $err = 'sessão caiu (mandou para o login)';
                    } elseif ($code === 403) {
                        $err = 'acesso negado (403)';
                    } elseif (preg_match('/(Fatal error|Uncaught|Parse error|SQLSTATE\[)/', $body, $m)) {
                        $err = 'erro de PHP na página (' . $m[1] . ')';
                    }
                    $stats[$p]['t'][] = $ms;
                    if ($err !== '') {
                        $stats[$p]['e'][] = $err;
                    }
                    curl_multi_remove_handle($mh, $ch);
                    curl_close($ch);
                }
                curl_multi_close($mh);
            }
        } finally {
            // Apaga as sessões temporárias e volta para a sessão do admin.
            foreach ($tempIds as $sid) {
                session_id($sid);
                session_start();
                $_SESSION = [];
                session_destroy();
            }
            session_id($origId);
            session_start();
            session_release();
        }
        $results = ['stats' => $stats, 'rounds' => $roundsInfo];
    }
}

view_header('Teste de carga');
echo '<div style="max-width:980px">';
echo '<p><a href="/testes/stress/">← Voltar aos testes</a></p>';
echo '<h1 style="margin:0 0 6px">Teste de carga</h1>';
echo '<p style="color:#667781;margin:0 0 16px">Simula vários usuários abrindo as telas ao mesmo tempo. Só abre páginas, não envia nada nem altera dados. Comece com 5 usuários e rode fora do horário de uso.</p>';

echo '<form method="get" class="card" style="padding:16px;margin-bottom:18px">';
echo '<input type="hidden" name="run" value="1">';
echo '<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:12px">';
echo '<label>Usuários ao mesmo tempo <select name="users">';
foreach ([1, 3, 5, 10, 15, 20] as $v) {
    echo '<option value="' . $v . '"' . ($v === $users ? ' selected' : '') . '>' . $v . '</option>';
}
echo '</select></label>';
echo '<label>Rodadas <select name="rounds">';
foreach ([1, 2, 3, 5, 8] as $v) {
    echo '<option value="' . $v . '"' . ($v === $rounds ? ' selected' : '') . '>' . $v . '</option>';
}
echo '</select></label>';
echo '<label>Tipo <select name="mode">';
echo '<option value="realista"' . ($mode === 'realista' ? ' selected' : '') . '>Realista (cada pessoa abre 1 tela por vez)</option>';
echo '<option value="pesado"' . ($mode === 'pesado' ? ' selected' : '') . '>Pesado (cada pessoa abre todas as telas de uma vez)</option>';
echo '</select></label>';
echo '</div>';
echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:6px;margin-bottom:12px">';
foreach ($allPages as $p => $label) {
    echo '<label style="font-weight:400"><input type="checkbox" name="p[]" value="' . h($p) . '"' . (in_array($p, $picked, true) ? ' checked' : '') . '> ' . h($label) . '</label>';
}
echo '</div>';
echo '<button class="btn btnPrimary" type="submit" onclick="this.disabled=true;this.textContent=\'Rodando... aguarde (até 2 min)\';this.form.submit()">Rodar teste</button>';
echo '</form>';

if ($error !== '') {
    echo '<div class="alert alertError">' . h($error) . '</div>';
}

if ($results) {
    $bad = 0;
    $slow = [];
    echo '<div class="card" style="padding:16px">';
    echo '<h2 style="margin:0 0 10px;font-size:18px">Resultado — ' . $users . ' usuário(s), ' . $rounds . ' rodada(s), modo ' . $mode . '</h2>';
    foreach ($results['rounds'] as $i => [$n, $s]) {
        echo '<div style="font-size:13px;color:#667781">Rodada ' . ($i + 1) . ': ' . $n . ' acessos simultâneos terminaram em ' . number_format($s, 1, ',', '.') . ' s</div>';
    }
    echo '<table style="width:100%;border-collapse:collapse;margin-top:12px;font-size:14px">';
    echo '<tr style="text-align:left;border-bottom:2px solid #e5e7eb"><th>Tela</th><th>Acessos</th><th>Média</th><th>95% abaixo de</th><th>Pior</th><th>Erros</th></tr>';
    foreach ($results['stats'] as $p => $s) {
        $t = $s['t'];
        sort($t);
        $avg = array_sum($t) / count($t);
        $p95 = $t[(int)floor(0.95 * (count($t) - 1))];
        $errs = $s['e'] ?? [];
        $bad += count($errs);
        if ($p95 > 6000) {
            $slow[] = $allPages[$p] ?? $p;
        }
        $color = $errs ? '#dc2626' : ($p95 > 6000 ? '#d97706' : '#16a34a');
        echo '<tr style="border-bottom:1px solid #f0f2f5">';
        echo '<td>' . h($allPages[$p] ?? $p) . '</td><td>' . count($t) . '</td>';
        echo '<td>' . number_format($avg / 1000, 2, ',', '.') . ' s</td>';
        echo '<td>' . number_format($p95 / 1000, 2, ',', '.') . ' s</td>';
        echo '<td>' . number_format(end($t) / 1000, 2, ',', '.') . ' s</td>';
        echo '<td style="color:' . $color . ';font-weight:700">' . count($errs) . '</td></tr>';
        foreach (array_count_values($errs) as $e => $c) {
            echo '<tr><td colspan="6" style="color:#dc2626;font-size:13px;padding-left:18px">↳ ' . $c . '× ' . h($e) . '</td></tr>';
        }
    }
    echo '</table>';
    if ($bad === 0 && !$slow) {
        echo '<div class="alert alertSuccess" style="margin-top:14px">Tudo certo: nenhuma falha e nenhuma tela lenta. Pode testar com mais usuários.</div>';
    } else {
        if ($bad > 0) {
            echo '<div class="alert alertError" style="margin-top:14px">' . $bad . ' falha(s). Tire um print desta tela e me envie.</div>';
        }
        if ($slow) {
            echo '<div class="alert alertError" style="margin-top:8px">Lentas com esta carga (mais de 6 s): ' . h(implode(', ', $slow)) . '.</div>';
        }
    }
    echo '</div>';
}
echo '</div>';
view_footer();

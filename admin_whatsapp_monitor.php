<?php

declare(strict_types=1);

/**
 * MONITOR DO WHATSAPP — histórico de conexão (piloto do Baileys).
 *
 * O serviço WhatsApp (ml-baileys-service) grava a cada 5 min, por número:
 * estado do socket, veredito e minutos sem RECEBER mensagens
 * (inboundSilentMinutes). Esta tela mostra as últimas 72 h (ou o período
 * escolhido) para decidir o piloto da doc multilife-baileys-integracao.md:
 *  - silêncio de entrada nunca passa de ~30 min com o socket aberto = a troca
 *    da Evolution resolveu (H1);
 *  - silêncio cresce sem parar com o socket aberto = o problema é o número/uso (H2).
 */

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');
session_release();

$hoursOptions = [24 => '24 h', 72 => '72 h', 168 => '7 dias'];
$hours = (int)($_GET['horas'] ?? 72);
if (!isset($hoursOptions[$hours])) {
    $hours = 72;
}
$only = trim((string)($_GET['instance'] ?? ''));

$instances = wa_watchdog_system_instances();
$names = array_keys($instances);
if ($only !== '' && isset($instances[$only])) {
    $names = [$only];
}

$history = [];
$errors = [];
if (wa_watchdog_enabled()) {
    foreach ($names as $name) {
        $res = wa_watchdog_request('GET', '/status/' . rawurlencode($name) . '/history?hours=' . $hours, 10);
        if ($res['ok'] && is_array($res['json']['points'] ?? null)) {
            $history[$name] = $res['json']['points'];
        } else {
            $errors[$name] = $res['error'] !== '' ? $res['error'] : 'HTTP ' . $res['status'];
        }
    }
}

/** Gráfico de barras (SVG): minutos sem receber, cor pelo estado do socket. */
$chart = static function (array $points, int $hours): string {
    $w = 900;
    $h = 160;
    $padL = 40;
    $padB = 20;
    $plotW = $w - $padL - 10;
    $plotH = $h - $padB - 10;
    $end = time();
    $start = $end - $hours * 3600;
    $cap = 240; // escala até 4 h de silêncio
    $svg = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" style="width:100%;height:auto;background:#fff;border:1px solid #e5e7eb;border-radius:8px" role="img" aria-label="Minutos sem receber mensagens">';
    foreach ([0, 30, 120, 240] as $y) {
        $py = 10 + $plotH - ($y / $cap) * $plotH;
        $svg .= '<line x1="' . $padL . '" x2="' . ($w - 10) . '" y1="' . $py . '" y2="' . $py . '" stroke="' . ($y === 30 ? '#f59e0b' : '#e5e7eb') . '" stroke-dasharray="' . ($y === 30 ? '4 3' : '0') . '"/>';
        $svg .= '<text x="' . ($padL - 4) . '" y="' . ($py + 4) . '" font-size="10" text-anchor="end" fill="#6b7280">' . $y . '</text>';
    }
    $barW = max(1.0, $plotW / max(1, $hours * 12));
    foreach ($points as $p) {
        $t = strtotime((string)($p['at'] ?? ''));
        if (!$t || $t < $start) {
            continue;
        }
        $x = $padL + (($t - $start) / ($end - $start)) * $plotW;
        $socket = (string)($p['socket'] ?? '');
        $min = $p['inboundSilentMinutes'];
        if ($socket !== 'open' || $min === null) {
            $color = $socket === 'connecting' ? '#eab308' : '#9ca3af';
            $svg .= '<rect x="' . round($x, 1) . '" y="' . (10 + $plotH - 6) . '" width="' . round($barW, 1) . '" height="6" fill="' . $color . '"><title>' . h(date('d/m H:i', $t) . ' — ' . ($socket ?: '?')) . '</title></rect>';
            continue;
        }
        $v = min($cap, max(0, (int)$min));
        $bh = max(1, ($v / $cap) * $plotH);
        $color = $v > 30 ? ($v >= 120 ? '#dc2626' : '#f97316') : '#22c55e';
        $svg .= '<rect x="' . round($x, 1) . '" y="' . round(10 + $plotH - $bh, 1) . '" width="' . round($barW, 1) . '" height="' . round($bh, 1) . '" fill="' . $color . '"><title>' . h(date('d/m H:i', $t) . ' — ' . (int)$min . ' min sem receber') . '</title></rect>';
    }
    for ($i = 0; $i <= 6; $i++) {
        $t = $start + (int)(($end - $start) * $i / 6);
        $x = $padL + $plotW * $i / 6;
        $svg .= '<text x="' . round($x, 1) . '" y="' . ($h - 5) . '" font-size="10" text-anchor="middle" fill="#6b7280">' . h(date('d/m H:i', $t)) . '</text>';
    }
    return $svg . '</svg>';
};

view_header('Monitor do WhatsApp');

echo '<div class="grid">';
echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div><div style="font-size:22px;font-weight:900">Monitor do WhatsApp</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6;max-width:820px">'
    . 'Minutos sem receber nenhuma mensagem (registro a cada 5 min pelo serviço WhatsApp). '
    . 'Verde até 30 min; laranja acima de 30 min; vermelho acima de 2 h; cinza = desconectado; amarelo = aguardando QR Code. '
    . 'Socket aberto com barra subindo sem parar = o número parou de receber.</div></div>';
echo '<a class="btn" href="/admin_settings.php?aba=whatsapp-instancias">Voltar</a>';
echo '</div>';
echo '<form method="get" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;align-items:center">';
echo '<select name="instance" style="padding:6px 10px"><option value="">Todos os números</option>';
foreach ($instances as $n => $row) {
    echo '<option value="' . h($n) . '"' . ($n === $only ? ' selected' : '') . '>' . h(wa_instance_label($n, $row)) . '</option>';
}
echo '</select><select name="horas" style="padding:6px 10px">';
foreach ($hoursOptions as $hv => $hl) {
    echo '<option value="' . $hv . '"' . ($hv === $hours ? ' selected' : '') . '>' . h($hl) . '</option>';
}
echo '</select><button class="btn btnPrimary" type="submit">Ver</button></form>';
if (!wa_watchdog_enabled()) {
    echo '<div class="alertError" style="margin-top:12px">Serviço WhatsApp (Baileys) não configurado. Preencha a URL e a API Key em Configurações → WhatsApp Conexão → Credenciais.</div>';
}
echo '</section>';

foreach ($names as $name) {
    $points = $history[$name] ?? [];
    $open = 0;
    $over30 = 0;
    $maxSilent = 0;
    foreach ($points as $p) {
        if (($p['socket'] ?? '') === 'open' && $p['inboundSilentMinutes'] !== null) {
            $open++;
            $m = (int)$p['inboundSilentMinutes'];
            $maxSilent = max($maxSilent, $m);
            if ($m > 30) {
                $over30++;
            }
        }
    }
    echo '<section class="card col12">';
    echo '<div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:baseline">';
    echo '<div style="font-weight:900">' . h(wa_instance_label($name, $instances[$name] ?? null)) . '</div>';
    if ($points) {
        $pctOpen = (int)round(100 * $open / max(1, count($points)));
        echo '<div style="font-size:13px;color:hsl(var(--muted-foreground))">'
            . 'Conectado em ' . $pctOpen . '% das leituras · maior silêncio com conexão aberta: <strong>' . $maxSilent . ' min</strong>'
            . ' · leituras acima de 30 min: <strong>' . $over30 . '</strong></div>';
    }
    echo '</div>';
    if (isset($errors[$name])) {
        echo '<div class="alertError" style="margin-top:8px">Sem histórico: ' . h($errors[$name]) . '</div>';
    } elseif (!$points) {
        echo '<div style="margin-top:8px;font-size:13px;color:hsl(var(--muted-foreground))">Ainda não há registros para este número no período.</div>';
    } else {
        echo '<div style="margin-top:10px;overflow-x:auto">' . $chart($points, $hours) . '</div>';
    }
    echo '</section>';
}

echo '</div>';
view_footer();

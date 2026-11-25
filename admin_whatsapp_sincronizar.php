<?php

declare(strict_types=1);

/**
 * SINCRONIZAR WHATSAPP (redispatch do serviço WhatsApp / Baileys).
 *
 * Pede ao serviço WhatsApp (POST /redispatch/{instância}) que reenvie ao webhook
 * do sistema as mensagens que ele JÁ RECEBEU e guardou (tabela baileys_messages,
 * 15 dias) e que não chegaram aqui (queda do webhook, sistema fora do ar,
 * "Suspeita de queda"...). O que nunca chegou ao serviço não existe para reenviar.
 *
 * Fluxo: escolhe a janela -> prévia (dryRun: quantos eventos) -> confirma -> reenvia.
 * Só libera WA_REDISPATCH_WAIT_SEC (15 min) depois da última reconexão/QR Code,
 * para o WhatsApp terminar de entregar ao serviço o que ficou para trás.
 * O webhook deduplica pelo id da mensagem (reenvio repetido não duplica nada).
 *
 * Quem pode: administrador de WhatsApp/configurações ou usuário vinculado à instância.
 */

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();

$userId = (int)auth_user_id();
$instance = trim((string)($_POST['instance'] ?? ($_GET['instance'] ?? '')));
if ($instance === '' || !wa_watchdog_user_can_heal($userId, $instance)) {
    http_response_code(403);
    exit('Você não tem acesso a esta instância.');
}

$tz = new DateTimeZone('America/Sao_Paulo');
$utc = new DateTimeZone('UTC');
$typeOptions = [
    'reacoes' => ['label' => 'Só reações (interesse nas captações)', 'types' => ['reactionMessage']],
    'tudo' => ['label' => 'Tudo (reações, mensagens, áudios, imagens...)', 'types' => []],
];

/** "Y-m-d\TH:i" (horário de Brasília) -> DateTimeImmutable ou null. */
$parseLocal = static function (string $v) use ($tz): ?DateTimeImmutable {
    $v = trim(str_replace(' ', 'T', $v));
    $d = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', substr($v, 0, 16), $tz);
    return $d ?: null;
};

$now = new DateTimeImmutable('now', $tz);
$fromIn = (string)($_POST['from'] ?? ($_GET['from'] ?? ''));
$toIn = (string)($_POST['to'] ?? ($_GET['to'] ?? ''));
$from = $fromIn !== '' ? $parseLocal($fromIn) : $now->modify('-24 hours');
$to = $toIn !== '' ? $parseLocal($toIn) : $now;
$typeKey = (string)($_POST['tipo'] ?? ($_GET['tipo'] ?? 'reacoes'));
if (!isset($typeOptions[$typeKey])) {
    $typeKey = 'reacoes';
}

$action = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? (string)($_POST['acao'] ?? '') : '';
$error = '';
$preview = null;
$result = null;

if ($action !== '') {
    if (!hash_equals((string)($_SESSION['wa_sync_nonce'] ?? ''), (string)($_POST['nonce'] ?? ''))) {
        $error = 'Página expirada. Confira a janela e clique de novo.';
        $action = '';
    }
}
$nonce = bin2hex(random_bytes(16));
$_SESSION['wa_sync_nonce'] = $nonce;
session_release();
@set_time_limit(240);

$status = wa_redispatch_status($instance);
$blocked = !wa_watchdog_enabled() ? 'Serviço WhatsApp (Baileys) não configurado. Preencha a URL e a API Key em Configurações → WhatsApp Conexão → Credenciais.'
    : (!$status['connected'] || $status['wait_seconds'] > 0 ? $status['message'] : '');

if ($action !== '' && $error === '') {
    if ($blocked !== '') {
        $error = $blocked;
    } elseif (!$from || !$to) {
        $error = 'Informe o início e o fim da janela.';
    } elseif ($from >= $to) {
        $error = 'O início da janela precisa ser antes do fim.';
    } elseif ($to->getTimestamp() - $from->getTimestamp() > 7 * 86400) {
        $error = 'A janela pode ter no máximo 7 dias. Divida em partes menores.';
    } else {
        $fromUtc = $from->setTimezone($utc)->format('Y-m-d H:i:s');
        $toUtc = $to->setTimezone($utc)->format('Y-m-d H:i:s');
        $types = $typeOptions[$typeKey]['types'];
        $res = wa_watchdog_redispatch($instance, $fromUtc, $toUtc, $action === 'previa', $types);
        if (!$res['ok'] || !is_array($res['json'])) {
            $error = 'O monitor de conexão não respondeu como esperado: ' . ($res['error'] !== '' ? $res['error'] : 'HTTP ' . $res['status']) . '. Tente de novo em alguns minutos.';
        } elseif ($action === 'previa') {
            $preview = $res['json'];
        } else {
            $result = $res['json'];
            audit_log('update', 'whatsapp_instances', null, null, [
                'action' => 'watchdog_redispatch', 'instance' => $instance, 'from_utc' => $fromUtc, 'to_utc' => $toUtc,
                'types' => $types, 'redispatched' => (int)($result['redispatched'] ?? 0), 'failed' => (int)($result['failed'] ?? 0),
            ]);
        }
    }
}

$label = wa_instance_label($instance, wa_watchdog_system_instances()[$instance] ?? null);
$fmtIn = static fn(?DateTimeImmutable $d): string => $d ? $d->format('Y-m-d\TH:i') : '';
$typeName = static function (string $t): string {
    $map = ['reactionMessage' => 'reações', 'conversation' => 'mensagens de texto', 'extendedTextMessage' => 'mensagens de texto',
        'imageMessage' => 'imagens', 'audioMessage' => 'áudios', 'videoMessage' => 'vídeos', 'documentMessage' => 'documentos',
        'documentWithCaptionMessage' => 'documentos', 'stickerMessage' => 'figurinhas', 'contactMessage' => 'contatos'];
    return $map[$t] ?? $t;
};

/** Campos escondidos com a janela atual (para os botões de confirmar/continuar). */
$hidden = static function (string $acao, ?DateTimeImmutable $f, ?DateTimeImmutable $t) use ($instance, $typeKey, $nonce, $fmtIn): string {
    return '<input type="hidden" name="instance" value="' . h($instance) . '">'
        . '<input type="hidden" name="tipo" value="' . h($typeKey) . '">'
        . '<input type="hidden" name="nonce" value="' . h($nonce) . '">'
        . '<input type="hidden" name="acao" value="' . h($acao) . '">'
        . '<input type="hidden" name="from" value="' . h($fmtIn($f)) . '">'
        . '<input type="hidden" name="to" value="' . h($fmtIn($t)) . '">';
};

view_header('Sincronizar WhatsApp');

echo '<style>
.wsync-row{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end}
.wsync-row label{display:flex;flex-direction:column;gap:4px;font-size:13px;font-weight:600;flex:1 1 200px;min-width:0}
.wsync-row input,.wsync-row select{width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid hsl(var(--border));border-radius:8px;font-size:14px}
.wsync-quick{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px}
.wsync-quick a{font-size:12px;padding:4px 10px;border:1px solid hsl(var(--border));border-radius:14px;text-decoration:none;color:inherit}
.wsync-box{padding:12px 14px;border-radius:10px;margin-top:14px;font-size:14px;line-height:1.5}
.wsync-ok{background:#f0fdf4;border:1px solid #bbf7d0}.wsync-warn{background:#fffbeb;border:1px solid #fde68a}.wsync-err{background:#fef2f2;border:1px solid #fecaca}
.wsync-table{width:100%;border-collapse:collapse;font-size:12px;margin-top:8px}.wsync-table td,.wsync-table th{border-bottom:1px solid hsl(var(--border));padding:4px 6px;text-align:left}
</style>';

echo '<div class="grid">';
echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div><div style="font-size:22px;font-weight:900">Sincronizar WhatsApp</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;overflow-wrap:anywhere">' . h($label) . '</div></div>';
echo '<a class="btn" href="/admin_settings.php?aba=whatsapp-instancias">Voltar</a>';
echo '</div>';
echo '<div style="margin-top:10px;font-size:13px;color:hsl(var(--muted-foreground));line-height:1.6">'
    . 'Reenvia ao sistema o que o WhatsApp já recebeu e que não apareceu aqui (ex.: reações às captações durante uma "Suspeita de queda"). '
    . 'Use depois de reconectar. O que nunca chegou ao WhatsApp do sistema não tem como ser recuperado: nesse caso peça para a pessoa reagir de novo. '
    . 'Reenviar a mesma janela mais de uma vez não duplica nada.</div>';

if ($blocked !== '') {
    echo '<div class="wsync-box wsync-warn">⏳ ' . h($blocked) . '</div>';
} elseif ($status['reconnect_ago'] !== null) {
    echo '<div class="wsync-box wsync-ok">Conectado. Última reconexão ' . h(wa_watchdog_ago($status['reconnect_ago'])) . '.</div>';
}
if ($error !== '' && $error !== $blocked) {
    echo '<div class="wsync-box wsync-err">' . h($error) . '</div>';
}
echo '</section>';

// Formulário da janela
echo '<section class="card col12">';
echo '<form method="post">';
echo '<input type="hidden" name="instance" value="' . h($instance) . '"><input type="hidden" name="nonce" value="' . h($nonce) . '"><input type="hidden" name="acao" value="previa">';
echo '<div class="wsync-row">';
echo '<label>Desde (horário de Brasília)<input type="datetime-local" name="from" value="' . h($fmtIn($from)) . '" required></label>';
echo '<label>Até<input type="datetime-local" name="to" value="' . h($fmtIn($to)) . '" required></label>';
echo '<label>O que reenviar<select name="tipo">';
foreach ($typeOptions as $k => $o) {
    echo '<option value="' . h($k) . '"' . ($k === $typeKey ? ' selected' : '') . '>' . h($o['label']) . '</option>';
}
echo '</select></label>';
echo '</div>';
echo '<div class="wsync-quick">';
foreach (['Últimas 6 h' => '-6 hours', 'Últimas 24 h' => '-24 hours', 'Últimos 3 dias' => '-3 days'] as $txt => $mod) {
    $q = http_build_query(['instance' => $instance, 'tipo' => $typeKey, 'from' => $now->modify($mod)->format('Y-m-d\TH:i'), 'to' => $now->format('Y-m-d\TH:i')]);
    echo '<a href="/admin_whatsapp_sincronizar.php?' . h($q) . '">' . h($txt) . '</a>';
}
echo '</div>';
echo '<div style="margin-top:14px"><button type="submit" class="btn btnPrimary"' . ($blocked !== '' ? ' disabled' : '') . '>Ver quantos eventos serão reenviados</button></div>';
echo '</form>';

// Prévia
if (is_array($preview)) {
    $total = (int)($preview['wouldRedispatch'] ?? 0);
    $sample = is_array($preview['sample'] ?? null) ? $preview['sample'] : [];
    $byType = [];
    foreach ($sample as $s) {
        $t = $typeName((string)($s['type'] ?? '?'));
        $byType[$t] = ($byType[$t] ?? 0) + 1;
    }
    echo '<div class="wsync-box ' . ($total > 0 ? 'wsync-ok' : 'wsync-warn') . '">';
    if ($total <= 0) {
        echo 'Nenhum evento para reenviar nessa janela. Se a reação não aparece, ela não chegou ao WhatsApp do sistema: peça para a pessoa reagir de novo.';
    } else {
        echo '<strong>Serão reenviados ' . $total . ' evento(s)</strong>';
        if ($byType) {
            $parts = [];
            foreach ($byType as $t => $n) {
                $parts[] = $t . ': ' . $n;
            }
            echo ' (' . (count($sample) >= $total ? '' : 'na amostra de ' . count($sample) . ' — ') . h(implode(', ', $parts)) . ')';
        }
        echo '.';
        if (!empty($preview['moreAvailable'])) {
            echo '<br>A janela tem mais eventos do que cabem numa rodada: depois de reenviar, rode de novo com uma janela menor.';
        }
        echo '<form method="post" style="margin-top:10px">' . $hidden('reenviar', $from, $to)
            . '<button type="submit" class="btn btnPrimary" onclick="this.disabled=true;this.textContent=\'Reenviando... (pode levar até 2 minutos)\';this.form.submit();">Reenviar ' . $total . ' evento(s)</button></form>';
    }
    echo '</div>';
}

// Resultado
if (is_array($result)) {
    $sent = (int)($result['redispatched'] ?? 0);
    $failed = (int)($result['failed'] ?? 0);
    $failures = is_array($result['failures'] ?? null) ? $result['failures'] : [];
    echo '<div class="wsync-box ' . ($failed > 0 ? 'wsync-warn' : 'wsync-ok') . '">';
    echo '<strong>' . $sent . ' evento(s) reenviado(s)' . ($failed > 0 ? ', ' . $failed . ' com falha' : '') . '.</strong> ';
    echo 'As reações às captações já devem aparecer na aba Lista de Espera.';
    if ($failures) {
        echo '<table class="wsync-table"><tr><th>Evento</th><th>Motivo</th></tr>';
        foreach (array_slice($failures, 0, 50) as $f) {
            $fid = is_array($f) ? (string)($f['id'] ?? ($f['key'] ?? '')) : (string)$f;
            $fwhy = is_array($f) ? (string)($f['status'] ?? ($f['error'] ?? '')) : '';
            echo '<tr><td>' . h($fid) . '</td><td>' . h($fwhy) . '</td></tr>';
        }
        echo '</table>';
        echo '<form method="post" style="margin-top:10px">' . $hidden('reenviar', $from, $to)
            . '<button type="submit" class="btn">Tentar de novo esta janela</button></form>';
    }
    if (!empty($result['moreAvailable']) && $from && $to) {
        // Envio em ordem cronológica: a 1ª parte já foi. Sugere a metade final.
        $mid = $from->setTimestamp(intdiv($from->getTimestamp() + $to->getTimestamp(), 2));
        echo '<div style="margin-top:10px">A janela tinha mais eventos do que cabem numa rodada (os mais antigos foram primeiro). Rode o resto em partes menores:</div>';
        echo '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">';
        echo '<form method="post">' . $hidden('previa', $mid, $to) . '<button type="submit" class="btn">Ver a 2ª metade (' . h($mid->format('d/m H:i')) . ' até ' . h($to->format('d/m H:i')) . ')</button></form>';
        echo '</div>';
    }
    echo '</div>';
}
echo '</section>';
echo '</div>';
view_footer();

<?php

declare(strict_types=1);

/**
 * Diagnóstico ISOLADO de envio para grupo (sem o fluxo de captação).
 * Executa, com cronômetro, cada chamada que o envio depende e mostra a
 * resposta crua da Evolution — para saber exatamente onde trava/falha.
 */

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

@set_time_limit(400);
header('Content-Type: text/html; charset=utf-8');

$baseUrl = rtrim((string)admin_setting_get('evolution.base_url', ''), '/');
$apiKey = (string)admin_setting_get('evolution.api_key', '');

$groupsList = db()->query("SELECT name, evolution_group_jid, instance_name FROM whatsapp_groups WHERE status = 'active' AND evolution_group_jid LIKE '%@g.us' ORDER BY id DESC LIMIT 200")->fetchAll();
$instList = db()->query("SELECT instance_name, owner_phone_formatted, owner_number FROM whatsapp_instances WHERE status = 'active' ORDER BY is_default DESC, id ASC")->fetchAll();

$jid = trim((string)($_POST['jid'] ?? ''));
$inst = trim((string)($_POST['instance'] ?? ''));
$privNumber = preg_replace('/\D+/', '', (string)($_POST['private_number'] ?? ''));
$sendGroup = !empty($_POST['send_group']);

/** Chamada crua com cronômetro. */
function dbg_call(string $method, string $url, string $apiKey, $body = null, int $timeout = 30): array
{
    $t0 = microtime(true);
    try {
        $r = http_json_request($method, $url, ['apikey' => $apiKey], $body, $timeout);
        $r['error'] = null;
    } catch (Throwable $e) {
        $r = ['status' => 0, 'body_raw' => '', 'json' => null, 'error' => $e->getMessage()];
    }
    $r['secs'] = round(microtime(true) - $t0, 2);
    return $r;
}

function dbg_print(string $title, array $r, int $maxBody = 1500): void
{
    $ok = $r['status'] >= 200 && $r['status'] < 300;
    echo '<h3>' . h($title) . '</h3><pre>';
    echo ($ok ? '✅' : '❌') . ' HTTP ' . (int)$r['status'] . ' em ' . $r['secs'] . "s\n";
    if ($r['error']) {
        echo 'ERRO: ' . h((string)$r['error']) . "\n";
    }
    $body = (string)($r['body_raw'] ?? '');
    if ($body !== '') {
        echo h(mb_strimwidth($body, 0, $maxBody, '...')) . "\n";
    }
    echo '</pre>';
    @ob_flush(); @flush();
}
?>
<h2>Diagnóstico de envio para grupo</h2>
<form method="post">
    <p>
        <label>Grupo:
            <select name="jid" required>
                <?php foreach ($groupsList as $g): ?>
                    <option value="<?= h((string)$g['evolution_group_jid']) ?>" <?= $jid === $g['evolution_group_jid'] ? 'selected' : '' ?>>
                        <?= h((string)$g['name']) ?> (<?= h((string)$g['evolution_group_jid']) ?>) [<?= h((string)($g['instance_name'] ?? '-')) ?>]
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
    </p>
    <p>
        <label>Instância:
            <select name="instance" required>
                <?php foreach ($instList as $i): ?>
                    <option value="<?= h((string)$i['instance_name']) ?>" <?= $inst === $i['instance_name'] ? 'selected' : '' ?>>
                        <?= h((string)$i['instance_name']) ?> (<?= h((string)($i['owner_phone_formatted'] ?: $i['owner_number'] ?: '-')) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
    </p>
    <p><label>Número para teste no privado (opcional, ex. 5511999999999): <input name="private_number" value="<?= h($privNumber) ?>"></label></p>
    <p><label><input type="checkbox" name="send_group" value="1" <?= $sendGroup ? 'checked' : '' ?>> Enviar mensagem de teste NO GRUPO</label></p>
    <button type="submit">Rodar diagnóstico</button>
</form>
<?php
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $jid === '' || $inst === '') {
    exit;
}
if ($baseUrl === '' || $apiKey === '') {
    echo '<p>Evolution não configurada (base_url/api_key).</p>';
    exit;
}

$ei = urlencode($inst);
echo '<hr><p>Instância: <b>' . h($inst) . '</b> | Grupo: <b>' . h($jid) . '</b></p>';

// 1. Estado da conexão
dbg_print('1. connectionState', dbg_call('GET', "$baseUrl/instance/connectionState/$ei", $apiKey));

// 2. Dados da instância (ownerJid vazio = sessão sem login, gera o TypeError 'id')
$fi = dbg_call('GET', "$baseUrl/instance/fetchInstances?instanceName=$ei", $apiKey);
dbg_print('2. fetchInstances (procure ownerJid / connectionStatus)', $fi, 1200);

// 3. Metadados do grupo ao vivo
$gi = dbg_call('GET', "$baseUrl/group/findGroupInfos/$ei?groupJid=" . urlencode($jid), $apiKey, null, 60);
dbg_print('3. findGroupInfos', $gi, 600);
if (is_array($gi['json'] ?? null)) {
    $parts = is_array($gi['json']['participants'] ?? null) ? $gi['json']['participants'] : [];
    $admins = array_filter($parts, fn($p) => !empty($p['admin']));
    echo '<pre>Participantes: ' . count($parts) . ' | Admins: ' . count($admins)
        . ' | addressingMode: ' . h((string)($gi['json']['addressingMode'] ?? '?'))
        . ' | announce: ' . h(var_export($gi['json']['announce'] ?? null, true)) . "\n";
    $selfDigits = '';
    foreach ($instList as $i) {
        if ($i['instance_name'] === $inst) {
            $selfDigits = preg_replace('/\D+/', '', (string)($i['owner_phone_formatted'] ?: $i['owner_number']));
        }
    }
    $selfKey = $selfDigits !== '' ? dispatch_member_key($selfDigits) : '';
    $selfAdmin = null;
    foreach ($parts as $p) {
        foreach (['id', 'jid', 'phoneNumber'] as $f) {
            if (!empty($p[$f]) && is_string($p[$f]) && dispatch_member_key($p[$f]) === $selfKey) {
                $selfAdmin = (string)($p['admin'] ?? '');
            }
        }
    }
    echo 'Número da instância no grupo: ' . ($selfAdmin === null ? 'NÃO ENCONTRADO' : ('sim, admin=' . ($selfAdmin !== '' ? $selfAdmin : 'não'))) . "</pre>";
}

// 4. Envio no privado (isola: problema é do grupo ou da sessão?)
if ($privNumber !== '') {
    dbg_print('4. sendText PRIVADO', dbg_call('POST', "$baseUrl/message/sendText/$ei", $apiKey, [
        'number' => $privNumber,
        'text' => 'Teste diagnóstico (privado) ' . date('H:i:s'),
    ], 180));
}

// 5. Envio no grupo (corpo mínimo, timeout longo para medir o tempo real)
if ($sendGroup) {
    dbg_print('5. sendText GRUPO', dbg_call('POST', "$baseUrl/message/sendText/$ei", $apiKey, [
        'number' => $jid,
        'text' => 'Teste diagnóstico (grupo) ' . date('H:i:s'),
    ], 300));
    // Estado depois do envio (detecta se o envio derrubou a sessão)
    dbg_print('6. connectionState após envio', dbg_call('GET', "$baseUrl/instance/connectionState/$ei", $apiKey));
}

echo '<br><a href="/chat_web.php?type=grupos">Voltar ao Chat</a>';

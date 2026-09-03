<?php
/**
 * DIAGNÓSTICO: por que a mensagem "enviada" não chega no grupo WhatsApp.
 * Mostra estado real de cada instância + resposta crua da Evolution ao enviar.
 * Acesse logado como admin: /diag_group_send.php?jid=120363430130484913@g.us
 * DELETE após uso!
 */
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
auth_require_login();
rbac_require_permission('demands.manage');

header('Content-Type: text/plain; charset=utf-8');

$jid = isset($_GET['jid']) ? trim((string)$_GET['jid']) : '';
$doSend = isset($_GET['send']) && $_GET['send'] === '1';

echo "=== DIAGNÓSTICO DE ENVIO PARA GRUPO ===\n\n";

$baseUrl = rtrim((string)admin_setting_get('evolution.base_url', ''), '/');
$apiKey = (string)admin_setting_get('evolution.api_key', '');
echo "Base URL: " . ($baseUrl ?: '(vazio)') . "\n";
echo "API Key configurada: " . ($apiKey !== '' ? 'sim' : 'NÃO') . "\n\n";

// 1. Estado real de cada instância
echo "--- INSTÂNCIAS (estado REAL na Evolution) ---\n";
$insts = db()->query("SELECT instance_name, connection_status, is_default FROM whatsapp_instances WHERE status = 'active' ORDER BY is_default DESC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$connectedInstance = null;
foreach ($insts as $inst) {
    $name = (string)$inst['instance_name'];
    if ($name === '') continue;
    $bancoStatus = (string)$inst['connection_status'];
    $realState = '?';
    try {
        $api = new EvolutionApiV1($baseUrl, $apiKey, $name);
        $cs = $api->connectionState();
        $json = $cs['json'] ?? [];
        $realState = (string)($json['instance']['state'] ?? ($json['state'] ?? 'desconhecido'));
        $httpCs = (int)($cs['status'] ?? 0);
        echo "  • $name → banco='$bancoStatus' | Evolution='$realState' (HTTP $httpCs)\n";
        if (in_array(strtolower(trim($realState)), ['open', 'connected'], true) && $connectedInstance === null) {
            $connectedInstance = $name;
        }
    } catch (Throwable $e) {
        echo "  • $name → ERRO: " . $e->getMessage() . "\n";
    }
}
echo "\nInstância realmente conectada: " . ($connectedInstance ?: 'NENHUMA ⚠️') . "\n\n";

if ($connectedInstance === null) {
    echo "⚠️ CAUSA PROVÁVEL: nenhuma instância está REALMENTE conectada na Evolution,\n";
    echo "   mesmo que o banco diga 'connected'. Reconecte escaneando o QR Code.\n";
    exit;
}

if ($jid === '') {
    echo "Informe o grupo: /diag_group_send.php?jid=SEU_JID@g.us\n";
    exit;
}

// 2. Verificar se o grupo existe NA instância conectada
echo "--- VERIFICAÇÃO DO GRUPO $jid ---\n";
$api = new EvolutionApiV1($baseUrl, $apiKey, $connectedInstance);
try {
    $groupsRes = $api->fetchAllGroups(false);
    $groupsJson = $groupsRes['json'] ?? [];
    $found = false;
    $foundJid = '';
    if (is_array($groupsJson)) {
        foreach ($groupsJson as $g) {
            $gid = (string)($g['id'] ?? ($g['jid'] ?? ''));
            $gsubject = (string)($g['subject'] ?? ($g['name'] ?? ''));
            if ($gid === $jid || str_replace('@g.us','',$gid) === str_replace('@g.us','',$jid)) {
                $found = true;
                $foundJid = $gid;
                echo "  ✓ Grupo encontrado na instância '$connectedInstance': '$gsubject' (JID real: $gid)\n";
                break;
            }
        }
    }
    if (!$found) {
        echo "  ✗ GRUPO NÃO ENCONTRADO na instância '$connectedInstance'.\n";
        echo "  ⚠️ CAUSA PROVÁVEL: o grupo foi criado por OUTRA instância/conta que agora está desconectada.\n";
        echo "     A instância conectada atual NÃO participa desse grupo, então a mensagem é aceita (HTTP 200) mas não entregue.\n";
        echo "  Total de grupos que a instância conectada vê: " . (is_array($groupsJson) ? count($groupsJson) : 0) . "\n";
    }
} catch (Throwable $e) {
    echo "  ERRO ao buscar grupos: " . $e->getMessage() . "\n";
}
echo "\n";

// 2b. Inspecionar participantes CRUS do grupo + contatos (para decodificar @lid)
echo "--- PARTICIPANTES CRUS DO GRUPO (para entender o formato do @lid) ---\n";
try {
    $partRes = $api->request('GET', '/group/participants/' . urlencode($connectedInstance), ['groupJid' => $jid]);
} catch (Throwable $e) {
    // request é privado; usar cURL direto
    $ch = curl_init($baseUrl . '/group/participants/' . urlencode($connectedInstance) . '?groupJid=' . urlencode($jid));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['apikey: ' . $apiKey]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $resp = curl_exec($ch);
    curl_close($ch);
    echo "Resposta participantes:\n" . substr((string)$resp, 0, 2000) . "\n\n";
}

echo "--- AMOSTRA DE CONTATOS (findContacts) para ver se há mapeamento lid→número ---\n";
try {
    $cRes = $api->findContacts();
    $cJson = $cRes['json'] ?? [];
    $sample = is_array($cJson) ? array_slice($cJson, 0, 5) : $cJson;
    echo json_encode($sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    echo "Total de contatos: " . (is_array($cJson) ? count($cJson) : 0) . "\n\n";
} catch (Throwable $e) {
    echo "Erro findContacts: " . $e->getMessage() . "\n\n";
}

// 3. Envio de teste (só se ?send=1) mostrando resposta CRUA
if ($doSend) {
    echo "--- ENVIO DE TESTE (resposta crua) ---\n";
    $res = $api->sendTextToGroup($jid, '[TESTE MULTILIFE] Diagnóstico de entrega - ' . date('H:i:s'));
    echo "HTTP: " . (int)($res['status'] ?? 0) . "\n";
    echo "Resposta JSON:\n" . json_encode($res['json'] ?? $res['body_raw'] ?? '', JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
    echo "Analise: se 'status' for 'PENDING'/'ERROR' ou 'key.remoteJid' diferente do grupo, a entrega falhou apesar do HTTP 200.\n";
} else {
    echo "Para testar o envio real, acesse: /diag_group_send.php?jid=" . urlencode($jid) . "&send=1\n";
}

<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

header('Content-Type: text/html; charset=utf-8');

echo '<h2>Webhook Debug — Últimos eventos recebidos</h2>';
echo '<p>Verifique o log de erros do PHP para ver os payloads do webhook.</p>';
echo '<p><strong>Teste:</strong> Mande uma mensagem no WhatsApp agora e recarregue esta página.</p>';
echo '<hr>';

// Verificar se existe a tabela de log de webhook
try {
    $stmt = db()->query("SELECT COUNT(*) FROM integration_logs WHERE provider = 'evolution' ORDER BY id DESC LIMIT 1");
    $count = (int)$stmt->fetchColumn();
    
    echo "<h3>Últimos 20 logs da Evolution API:</h3>";
    echo '<pre style="max-height:600px;overflow:auto;font-size:12px;background:#1a1a1a;color:#0f0;padding:12px;border-radius:8px">';
    
    $stmt = db()->query("
        SELECT id, action, status, http_code, response_data, error_message, created_at
        FROM integration_logs 
        WHERE provider = 'evolution'
        ORDER BY id DESC 
        LIMIT 20
    ");
    $logs = $stmt->fetchAll();
    
    if (empty($logs)) {
        echo "Nenhum log encontrado na tabela integration_logs.\n";
    }
    
    foreach ($logs as $log) {
        echo "---\n";
        echo "[" . $log['created_at'] . "] " . $log['action'] . " | HTTP: " . $log['http_code'] . " | Status: " . $log['status'] . "\n";
        if ($log['error_message']) {
            echo "  ERROR: " . $log['error_message'] . "\n";
        }
        $resp = $log['response_data'] ?? '';
        if (strlen($resp) > 300) $resp = substr($resp, 0, 300) . '...';
        echo "  Response: " . $resp . "\n";
    }
    
    echo '</pre>';
} catch (Exception $e) {
    echo "<p>Erro ao consultar logs: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// Verificar últimas mensagens no chat
echo "<h3>Últimas 10 mensagens no chat_messages:</h3>";
echo '<pre style="max-height:400px;overflow:auto;font-size:12px;background:#1a1a1a;color:#fff;padding:12px;border-radius:8px">';

try {
    $stmt = db()->query("
        SELECT id, remote_jid, LEFT(message_text, 50) as msg, from_me, message_timestamp, message_type, 
               DATE_FORMAT(FROM_UNIXTIME(message_timestamp), '%d/%m %H:%i') as dt
        FROM chat_messages 
        ORDER BY id DESC 
        LIMIT 10
    ");
    $msgs = $stmt->fetchAll();
    
    foreach ($msgs as $m) {
        $dir = $m['from_me'] ? '→ ENVIADA' : '← RECEBIDA';
        echo "[{$m['dt']}] {$dir} | JID: {$m['remote_jid']} | Type: {$m['message_type']} | \"{$m['msg']}\"\n";
    }
    
    if (empty($msgs)) {
        echo "Nenhuma mensagem encontrada.\n";
    }
} catch (Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
}

echo '</pre>';

// Link para ver o error_log
echo '<hr>';
echo '<p><strong>Para ver os payloads completos do webhook:</strong></p>';
echo '<ul>';
echo '<li>Acesse o error_log do PHP no servidor</li>';
echo '<li>Procure por <code>[WEBHOOK]</code> nos logs</li>';
echo '<li>Cada mensagem recebida gera log com <code>[WEBHOOK] event:\'messages.upsert\'</code></li>';
echo '</ul>';

echo '<br><a href="/admin_settings.php">Voltar</a>';

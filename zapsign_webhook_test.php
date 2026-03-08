<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

view_header('Testar Webhook ZapSign');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">🧪 Testar Webhook ZapSign</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Simule eventos do ZapSign para testar o webhook.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/zapsign_config.php">← Voltar</a>';
echo '</div>';
echo '</div>';
echo '</section>';

// Buscar contratos para teste
$contracts = db()->query('SELECT c.*, e.full_name FROM hr_employee_contracts c LEFT JOIN hr_employees e ON e.id = c.employee_id ORDER BY c.created_at DESC LIMIT 10')->fetchAll();

echo '<section class="card col12">';
echo '<h3 style="font-size:18px;font-weight:700;margin-bottom:16px">Simular Evento de Assinatura</h3>';

if (count($contracts) > 0) {
    echo '<form method="post" action="/zapsign_webhook_simulate_post.php" style="display:grid;gap:12px;max-width:600px">';
    
    echo '<label>Contrato<select name="contract_id" required>';
    echo '<option value="">Selecione um contrato...</option>';
    foreach ($contracts as $contract) {
        $statusLabels = ['pending' => 'Pendente', 'signed' => 'Assinado', 'expired' => 'Expirado', 'cancelled' => 'Cancelado'];
        $statusLabel = $statusLabels[$contract['zapsign_status']] ?? $contract['zapsign_status'];
        echo '<option value="' . (int)$contract['id'] . '">';
        echo 'ID ' . (int)$contract['id'] . ' - ' . h((string)$contract['full_name']) . ' (' . $statusLabel . ')';
        echo '</option>';
    }
    echo '</select></label>';
    
    echo '<label>Evento<select name="event" required>';
    echo '<option value="doc_signed">✅ Documento Assinado</option>';
    echo '<option value="doc_expired">⏰ Documento Expirado</option>';
    echo '<option value="doc_cancelled">❌ Documento Cancelado</option>';
    echo '</select></label>';
    
    echo '<div style="padding:12px;background:#dbeafe;border-left:4px solid #3b82f6;border-radius:4px">';
    echo '<div style="font-size:13px;color:#1e40af">';
    echo '<strong>ℹ️ Como funciona:</strong><br>';
    echo 'Este teste simula uma chamada do ZapSign ao webhook, atualizando o status do contrato e criando notificações.';
    echo '</div>';
    echo '</div>';
    
    echo '<div style="display:flex;gap:10px;justify-content:flex-end">';
    echo '<button class="btn btnPrimary" type="submit">🚀 Simular Evento</button>';
    echo '</div>';
    
    echo '</form>';
} else {
    echo '<div style="text-align:center;padding:40px;background:hsl(var(--muted));border-radius:12px">';
    echo '<div style="font-size:48px;margin-bottom:12px">📄</div>';
    echo '<div style="font-size:16px;font-weight:600;margin-bottom:8px">Nenhum contrato encontrado</div>';
    echo '<div style="font-size:14px;color:hsl(var(--muted-foreground))">Envie um contrato primeiro para testar o webhook.</div>';
    echo '</div>';
}

echo '</section>';

// Logs recentes
$logFile = __DIR__ . '/logs/zapsign_webhook.log';
if (file_exists($logFile)) {
    echo '<section class="card col12">';
    echo '<h3 style="font-size:18px;font-weight:700;margin-bottom:16px">📋 Logs Recentes do Webhook</h3>';
    
    $logs = file($logFile);
    $recentLogs = array_slice(array_reverse($logs), 0, 50);
    
    if (count($recentLogs) > 0) {
        echo '<div style="background:#1e1e1e;color:#d4d4d4;padding:16px;border-radius:8px;font-family:monospace;font-size:12px;max-height:400px;overflow-y:auto">';
        foreach ($recentLogs as $log) {
            echo h($log) . '<br>';
        }
        echo '</div>';
    } else {
        echo '<div style="padding:20px;background:hsl(var(--muted));border-radius:8px;text-align:center;color:hsl(var(--muted-foreground))">Nenhum log encontrado</div>';
    }
    
    echo '</section>';
}

echo '</div>';

view_footer();

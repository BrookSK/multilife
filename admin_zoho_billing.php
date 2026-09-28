<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

$webhookToken = (string)admin_setting_get('zoho_billing.webhook_token', '');
$testMode = admin_setting_get('zoho_billing.test_mode', '0') === '1';
$testPhone = (string)admin_setting_get('zoho_billing.test_phone', '');
$sendIntervalMs = (int)admin_setting_get('zoho_billing.send_interval_ms', '1200');
$preferredInstance = (string)admin_setting_get('zoho_billing.preferred_instance', 'financeiro');
$template = zoho_billing_get_template();

// Monta a URL pública do webhook.
$baseUrl = rtrim((string)admin_setting_get('app.public_base_url', ''), '/');
if ($baseUrl === '') {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $baseUrl = $scheme . '://' . $host;
}
$webhookUrl = $baseUrl . '/zoho_billing_webhook.php';

// Pré-visualização da mensagem com dados de exemplo.
$sample = zoho_billing_sample_payload();
$previewMessage = zoho_billing_build_message($sample, $template);

view_header('Zoho Faturamento → WhatsApp');

echo '<div class="grid">';

// Cabeçalho
echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Zoho Faturamento → WhatsApp</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Recebe o webhook do formulário de faturamento do Zoho Forms e envia uma mensagem de WhatsApp para o telefone informado. Os envios são enfileirados e processados em segundo plano, suportando muitos formulários ao mesmo tempo sem perder mensagens.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/admin_settings.php">Voltar para Configurações</a>';
echo '</div>';
echo '</div>';
echo '</section>';

// URL do webhook
echo '<section class="card col12">';
echo '<div class="formSectionTitle" style="font-weight:700;margin-bottom:10px">URL do Webhook</div>';
echo '<div style="color:hsl(var(--muted-foreground));font-size:14px;margin-bottom:10px">Configure esta URL no Zoho Forms (Integrações → Webhooks) usando o método <strong>POST</strong>.</div>';
echo '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">';
echo '<input type="text" readonly value="' . h($webhookUrl) . '" id="zohoWebhookUrl" style="flex:1;min-width:280px;font-family:monospace">';
echo '<button type="button" class="btn" onclick="zohoCopyUrl()">Copiar</button>';
echo '</div>';
if ($webhookToken !== '') {
    echo '<div style="margin-top:10px;color:hsl(var(--muted-foreground));font-size:13px">Token de segurança ativo. Envie no header <code>X-Webhook-Token</code>, ou como <code>?token=</code> na URL, ou no campo <code>token</code> do formulário.</div>';
}
echo '</section>';

// Formulário de configurações
echo '<section class="card col12">';
echo '<form method="post" action="/admin_zoho_billing_post.php" style="display:grid;gap:16px">';

echo '<div class="formSectionTitle" style="font-weight:700">Configurações</div>';

// Token
echo '<label>Token de segurança do webhook (opcional)';
echo '<input type="text" name="webhook_token" value="' . h($webhookToken) . '" placeholder="deixe em branco para não exigir token" style="font-family:monospace">';
echo '<span class="helpText">Se preenchido, o webhook só aceita requisições que enviem este token. Recomendado para produção.</span>';
echo '</label>';

// Modo teste
echo '<label style="display:flex;align-items:center;gap:10px;cursor:pointer">';
echo '<input type="checkbox" name="test_mode" value="1"' . ($testMode ? ' checked' : '') . '>';
echo '<span><strong>Modo teste</strong> — ignora o telefone recebido no webhook e envia sempre para o telefone de teste abaixo.</span>';
echo '</label>';

// Telefone de teste
echo '<label>Telefone de teste (com DDD)';
echo '<input type="text" name="test_phone" value="' . h($testPhone) . '" placeholder="(17) 99999-9999">';
echo '<span class="helpText">Usado quando o modo teste está ativo, ou ao clicar em "Testar webhook". Aceita formatos com ou sem DDI (o 55 é adicionado automaticamente).</span>';
echo '</label>';

// Intervalo entre envios (proteção contra rajada)
echo '<label>Intervalo entre envios (ms)';
echo '<input type="number" name="send_interval_ms" value="' . h((string)$sendIntervalMs) . '" min="0" step="100" placeholder="1200">';
echo '<span class="helpText">Espaçamento aplicado quando vários formulários chegam ao mesmo tempo, para não sobrecarregar o WhatsApp. Padrão: 1200ms. Use 0 para enviar sem espaçamento.</span>';
echo '</label>';

// Instância preferida
echo '<label>Instância preferida para envio';
echo '<input type="text" name="preferred_instance" value="' . h($preferredInstance) . '" placeholder="financeiro">';
echo '<span class="helpText">Termo do apelido/nome da instância WhatsApp preferida para enviar (ex.: <code>financeiro</code>). Se ela estiver desconectada, o sistema usa automaticamente outra instância que esteja conectada.</span>';
echo '</label>';

// Template
echo '<label>Template da mensagem';
echo '<textarea name="template" rows="12" style="font-family:monospace;line-height:1.5">' . h($template) . '</textarea>';
echo '<span class="helpText">Placeholders disponíveis: <code>{professional_name}</code>, <code>{patient_name}</code>, <code>{specialty}</code>, <code>{sessions_count}</code>, <code>{phone}</code>. Use <code>*texto*</code> para <strong>negrito</strong> no WhatsApp.</span>';
echo '</label>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end">';
echo '<button class="btn btnPrimary" type="submit">Salvar Configurações</button>';
echo '</div>';

echo '</form>';
echo '</section>';

// Pré-visualização
echo '<section class="card col12">';
echo '<div class="formSectionTitle" style="font-weight:700;margin-bottom:10px">Pré-visualização (dados de exemplo)</div>';
echo '<div style="background:#e5ddd5;padding:16px;border-radius:10px">';
echo '<div style="background:#dcf8c6;padding:12px 14px;border-radius:8px;max-width:520px;white-space:pre-wrap;font-size:14px;line-height:1.5;box-shadow:0 1px 1px rgba(0,0,0,.1)">' . h($previewMessage) . '</div>';
echo '</div>';
echo '</section>';

// Status das instâncias WhatsApp (diagnóstico)
echo '<section class="card col12">';
echo '<div class="formSectionTitle" style="font-weight:700;margin-bottom:10px">Instâncias WhatsApp</div>';
echo '<div style="color:hsl(var(--muted-foreground));font-size:14px;margin-bottom:12px">Ordem de preferência que o sistema usa para enviar. A primeira <strong>conectada</strong> é escolhida automaticamente.</div>';

$candidates = zoho_billing_candidate_instances();
if (count($candidates) === 0) {
    echo '<div style="padding:14px;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;color:#991b1b;font-size:14px">Nenhuma instância WhatsApp ativa encontrada. Configure/conecte uma instância na aba WhatsApp Conexão.</div>';
} else {
    echo '<table style="width:100%;border-collapse:collapse;font-size:14px">';
    echo '<thead><tr style="text-align:left;border-bottom:1px solid hsl(var(--border))">';
    echo '<th style="padding:8px">#</th><th style="padding:8px">Instância</th><th style="padding:8px">Status (tempo real)</th></tr></thead><tbody>';
    $pos = 1;
    foreach ($candidates as $cand) {
        $connected = zoho_billing_instance_is_connected($cand);
        $badge = $connected
            ? '<span style="padding:2px 10px;background:#dcfce7;color:#166534;border-radius:12px;font-weight:600">Conectada</span>'
            : '<span style="padding:2px 10px;background:#fee2e2;color:#991b1b;border-radius:12px;font-weight:600">Desconectada</span>';
        echo '<tr style="border-bottom:1px solid hsl(var(--border))">';
        echo '<td style="padding:8px">' . $pos . '</td>';
        echo '<td style="padding:8px;font-family:monospace">' . h($cand) . '</td>';
        echo '<td style="padding:8px">' . $badge . '</td>';
        echo '</tr>';
        $pos++;
    }
    echo '</tbody></table>';
}
echo '</section>';

// Testar webhook
echo '<section class="card col12">';
echo '<div class="formSectionTitle" style="font-weight:700;margin-bottom:10px">Testar Webhook</div>';
echo '<div style="color:hsl(var(--muted-foreground));font-size:14px;margin-bottom:12px">Envia a mensagem de exemplo acima para o <strong>telefone de teste</strong> configurado (o telefone recebido é sempre descartado no teste). Confirme que o telefone de teste está preenchido e salvo antes de testar.</div>';
echo '<form method="post" action="/admin_zoho_billing_test_post.php">';
echo '<button class="btn btnPrimary" type="submit">Enviar mensagem de teste</button>';
echo '</form>';
echo '</section>';

echo '</div>';

echo '<script>';
echo 'function zohoCopyUrl(){';
echo '  var el = document.getElementById("zohoWebhookUrl");';
echo '  el.select(); el.setSelectionRange(0, 99999);';
echo '  navigator.clipboard.writeText(el.value).then(function(){}, function(){ document.execCommand("copy"); });';
echo '}';
echo '</script>';

view_footer();

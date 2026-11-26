<?php

declare(strict_types=1);

/**
 * API OFICIAL DO WHATSAPP (Meta / Cloud API) — configuração completa:
 * credenciais, webhook, números, modelos (templates), captação e teste.
 * Ações em admin_whatsapp_cloud_post.php.
 */

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

wa_cloud_ensure_schema();

$isCloud = wa_is_cloud();
$configured = wa_cloud_configured();
$health = $configured ? wa_cloud_health(8) : ['ok' => false, 'error' => 'Preencha o token e o ID da conta.', 'data' => null];
$metaNumbers = ($configured && $health['ok']) ? wa_cloud_list_phone_numbers() : ['ok' => false, 'error' => '', 'items' => []];
$subscribed = ($configured && $health['ok']) ? wa_cloud_subscribed_apps() : ['ok' => false, 'items' => [], 'error' => ''];
$instances = wa_cloud_instances();
$byPnid = [];
foreach ($instances as $r) {
    $byPnid[(string)$r['cloud_phone_number_id']] = $r;
}
$templates = wa_cloud_templates_list();
$defaultInstance = wa_default_instance();
$lastWebhook = (int)wa_cloud_setting('last_webhook_at', '0');
$syncedAt = (int)wa_cloud_setting('templates_synced_at', '0');
$testResult = $_SESSION['wa_cloud_test_result'] ?? null;
unset($_SESSION['wa_cloud_test_result']);

$statusLabel = static function (string $s): array {
    $s = strtoupper($s);
    $map = [
        'APPROVED' => ['Aprovado', '#166534', '#dcfce7'],
        'PENDING' => ['Em análise', '#854d0e', '#fef9c3'],
        'IN_APPEAL' => ['Em recurso', '#854d0e', '#fef9c3'],
        'REJECTED' => ['Recusado', '#991b1b', '#fee2e2'],
        'PAUSED' => ['Pausado', '#9a3412', '#ffedd5'],
        'DISABLED' => ['Desativado', '#991b1b', '#fee2e2'],
        'CONNECTED' => ['Conectado', '#166534', '#dcfce7'],
        'PENDING_REVIEW' => ['Em análise', '#854d0e', '#fef9c3'],
        'FLAGGED' => ['Sinalizado', '#9a3412', '#ffedd5'],
        'RESTRICTED' => ['Restrito', '#991b1b', '#fee2e2'],
        'BANNED' => ['Banido', '#991b1b', '#fee2e2'],
        'DISCONNECTED' => ['Desconectado', '#991b1b', '#fee2e2'],
        'GREEN' => ['Alta', '#166534', '#dcfce7'],
        'YELLOW' => ['Média', '#854d0e', '#fef9c3'],
        'RED' => ['Baixa', '#991b1b', '#fee2e2'],
    ];
    return $map[$s] ?? [$s !== '' ? $s : '—', '#334155', '#f1f5f9'];
};
$pill = static function (string $s) use ($statusLabel): string {
    [$t, $fg, $bg] = $statusLabel($s);
    return '<span style="display:inline-block;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:700;color:' . $fg . ';background:' . $bg . '">' . h($t) . '</span>';
};

view_header('API oficial do WhatsApp');

echo '<style>
.wacWrap{max-width:1100px;margin:0 auto;display:grid;gap:16px}
.wacCard{background:hsl(var(--card));border:1px solid hsl(var(--border));border-radius:16px;padding:18px;box-shadow:var(--shadow-card)}
.wacCard h2{margin:0 0 4px;font-size:18px;font-weight:800;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.wacStep{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:999px;background:hsl(var(--primary));color:#fff;font-size:13px;font-weight:800}
.wacHelp{font-size:13px;color:hsl(var(--muted-foreground));line-height:1.55;margin:0 0 12px}
.wacGrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px}
.wacRow{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.wacCopy{display:flex;gap:8px;align-items:center;background:hsl(var(--secondary));border:1px solid hsl(var(--border));border-radius:10px;padding:8px 10px;font-family:ui-monospace,Consolas,monospace;font-size:13px;word-break:break-all}
.wacCopy span{flex:1}
.wacTable{width:100%;border-collapse:collapse;font-size:14px}
.wacTable th,.wacTable td{padding:9px 8px;border-bottom:1px solid hsl(var(--border));text-align:left;vertical-align:top}
.wacTableWrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
.wacBanner{padding:12px 14px;border-radius:12px;font-size:14px;line-height:1.5}
.wacOk{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534}
.wacWarn{background:#fffbeb;border:1px solid #fde68a;color:#854d0e}
.wacErr{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
.wacMuted{font-size:12px;color:hsl(var(--muted-foreground))}
.wacCard form{gap:10px}
@media(max-width:640px){.wacCard{padding:14px;border-radius:14px}.wacTable{font-size:13px}.wacHideSm{display:none}}
</style>';

echo '<div class="wacWrap">';
echo '<div class="wacRow" style="justify-content:space-between">';
echo '<div><h1 style="margin:0;font-size:24px;font-weight:900">API oficial do WhatsApp</h1>';
echo '<div class="wacMuted">Integração direta com a Meta (Cloud API): sem QR Code e sem servidor intermediário.</div></div>';
echo '<a class="btn" href="/admin_settings.php">← Configurações</a>';
echo '</div>';

// Integração em uso
if (!$isCloud) {
    echo '<div class="wacBanner wacWarn"><strong>A integração em uso agora é: ' . h(wa_provider_label()) . '.</strong> '
        . 'Configure tudo abaixo e, quando estiver pronto, ative a API oficial (o sistema passa a enviar e receber por ela).';
    echo '<form method="post" action="/admin_whatsapp_cloud_post.php" style="margin-top:10px" onsubmit="return confirm(\'Passar a usar a API oficial do WhatsApp em todo o sistema?\')">';
    echo '<input type="hidden" name="action" value="set_provider"><input type="hidden" name="provider" value="cloud">';
    echo '<button class="btn btnPrimary" type="submit"' . ($configured && $instances ? '' : ' disabled title="Cadastre as credenciais e pelo menos um número primeiro"') . '>Usar a API oficial agora</button>';
    echo '</form></div>';
} else {
    echo '<div class="wacBanner wacOk"><strong>✅ A API oficial está em uso.</strong> Chat, captação, avisos automáticos e grupos saem pela Meta. '
        . 'Para voltar ao Baileys ou à Evolution: Configurações → WhatsApp Conexão → Integração.</div>';
}

// Resultado do teste de envio
if (is_array($testResult)) {
    echo '<div class="wacBanner ' . (!empty($testResult['ok']) ? 'wacOk' : 'wacErr') . '">' . h((string)$testResult['message']) . '</div>';
}

// ---------- 1. Credenciais ----------
$hasToken = wa_cloud_access_token() !== '';
$hasSecret = wa_cloud_secret('app_secret') !== '';
echo '<section class="wacCard">';
echo '<h2><span class="wacStep">1</span> Credenciais da Meta ' . ($health['ok'] ? $pill('CONNECTED') : '') . '</h2>';
echo '<p class="wacHelp">No <a href="https://business.facebook.com/settings/system-users" target="_blank" rel="noopener">Gerenciador de Negócios → Usuários do sistema</a>, gere um <strong>token permanente</strong> com as permissões <code>whatsapp_business_messaging</code> e <code>whatsapp_business_management</code>. '
    . 'O ID da conta (WABA) e a chave secreta do app ficam em <a href="https://developers.facebook.com/apps" target="_blank" rel="noopener">developers.facebook.com → seu app</a> (WhatsApp → Configuração da API e Configurações → Básico). Guia completo: <code>CONFIGURAR_WHATSAPP_API_OFICIAL.md</code>.</p>';
if ($configured) {
    echo '<div class="wacBanner ' . ($health['ok'] ? 'wacOk' : 'wacErr') . '" style="margin-bottom:12px">'
        . ($health['ok'] ? 'Conta conectada: <strong>' . h((string)($health['data']['name'] ?? '')) . '</strong> (WABA ' . h(wa_cloud_waba_id()) . ')' : 'A Meta recusou: ' . h((string)$health['error']))
        . '</div>';
}
echo '<form method="post" action="/admin_whatsapp_cloud_post.php">';
echo '<input type="hidden" name="action" value="save_credentials">';
echo '<div class="wacGrid">';
echo '<label>ID da conta do WhatsApp Business (WABA)<input name="waba_id" inputmode="numeric" value="' . h(wa_cloud_waba_id()) . '" placeholder="ex.: 102290129340398"></label>';
echo '<label>Versão da Graph API<input name="graph_version" value="' . h(wa_cloud_graph_version()) . '" placeholder="' . h(WA_CLOUD_DEFAULT_VERSION) . '"></label>';
echo '</div>';
echo '<label>Token de acesso permanente<input type="password" name="access_token" autocomplete="off" placeholder="' . ($hasToken ? '•••••••• (guardado — deixe vazio para manter)' : 'EAAG...') . '"></label>';
echo '<label>Chave secreta do app (App Secret)<input type="password" name="app_secret" autocomplete="off" placeholder="' . ($hasSecret ? '•••••••• (guardada — deixe vazio para manter)' : 'confere a assinatura do webhook') . '">'
    . '<span class="helpText">Com ela o sistema só aceita webhooks assinados pela Meta.' . ($hasSecret ? '' : ' <strong>Sem ela, qualquer um que souber o endereço pode mandar eventos falsos.</strong>') . '</span></label>';
echo '<div class="wacRow"><button class="btn btnPrimary" type="submit">Salvar credenciais</button></div>';
echo '</form>';
echo '</section>';

// ---------- 2. Webhook ----------
$whUrl = wa_cloud_webhook_url();
$verify = wa_cloud_verify_token();
echo '<section class="wacCard">';
echo '<h2><span class="wacStep">2</span> Webhook (mensagens recebidas)</h2>';
echo '<p class="wacHelp">No app da Meta: <strong>WhatsApp → Configuração → Webhook → Editar</strong>. Cole o endereço e o token abaixo, clique em "Verificar e salvar" e, em "Campos do webhook", assine: '
    . '<code>messages</code>, <code>smb_message_echoes</code>, <code>message_template_status_update</code>, <code>group_lifecycle_update</code>, <code>group_participants_update</code>, <code>group_settings_update</code>, <code>group_status_update</code>, <code>account_update</code> e <code>phone_number_quality_update</code>.</p>';
echo '<div style="display:grid;gap:8px">';
echo '<div class="wacMuted">URL de retorno de chamada</div><div class="wacCopy"><span id="wacUrl">' . h($whUrl !== '' ? $whUrl : '(preencha "App - Base URL pública" em Configurações → Operacional)') . '</span><button class="btn" type="button" onclick="wacCopy(\'wacUrl\')">Copiar</button></div>';
echo '<div class="wacMuted">Token de verificação</div><div class="wacCopy"><span id="wacVerify">' . h($verify) . '</span><button class="btn" type="button" onclick="wacCopy(\'wacVerify\')">Copiar</button></div>';
echo '</div>';
$appSubscribed = $subscribed['ok'] && count($subscribed['items']) > 0;
echo '<div class="wacRow" style="margin-top:12px">';
echo '<span class="wacMuted">Último evento recebido da Meta: <strong>' . ($lastWebhook > 0 ? h(date('d/m/Y H:i:s', $lastWebhook)) : 'nenhum ainda') . '</strong></span>';
if ($configured && $health['ok']) {
    echo '<span class="wacMuted">· App inscrito na conta: <strong>' . ($appSubscribed ? 'sim' : 'não') . '</strong></span>';
    echo '<form method="post" action="/admin_whatsapp_cloud_post.php" style="display:inline"><input type="hidden" name="action" value="subscribe"><button class="btn" type="submit">' . ($appSubscribed ? 'Inscrever de novo' : 'Inscrever o app na conta') . '</button></form>';
}
echo '</div>';
echo '<details style="margin-top:10px"><summary class="wacMuted" style="cursor:pointer">Usar outro endereço de webhook</summary>';
echo '<form method="post" action="/admin_whatsapp_cloud_post.php" style="margin-top:8px"><input type="hidden" name="action" value="save_webhook_url">';
echo '<label>Endereço (vazio = automático)<input name="webhook_url" value="' . h(wa_cloud_setting('webhook_url', '')) . '" placeholder="https://seu-sistema/whatsapp_cloud_webhook.php"></label>';
echo '<div><button class="btn" type="submit">Salvar endereço</button></div></form></details>';
echo '</section>';

// ---------- 3. Números ----------
echo '<section class="wacCard">';
echo '<h2><span class="wacStep">3</span> Números</h2>';
echo '<p class="wacHelp">Cada número da conta da Meta vira um "WhatsApp" do sistema: dá para vincular aos usuários (Configurações → WhatsApp Conexão → Instâncias), filtrar no chat e escolher na Nova Conversa, como antes. '
    . 'O WhatsApp <strong>padrão</strong> envia as mensagens automáticas (aprovação de cadastro, avisos, cron).</p>';
if (!$configured || !$health['ok']) {
    echo '<div class="wacBanner wacWarn">Salve as credenciais (passo 1) para listar os números da conta.</div>';
} elseif (!$metaNumbers['ok']) {
    echo '<div class="wacBanner wacErr">Não foi possível listar os números: ' . h($metaNumbers['error']) . '</div>';
} elseif (!$metaNumbers['items']) {
    echo '<div class="wacBanner wacWarn">A conta ainda não tem números. Adicione um número no Gerenciador do WhatsApp da Meta.</div>';
} else {
    echo '<div class="wacTableWrap"><table class="wacTable"><thead><tr><th>Número</th><th>Situação na Meta</th><th class="wacHideSm">Qualidade · limite</th><th>No sistema</th></tr></thead><tbody>';
    foreach ($metaNumbers['items'] as $n) {
        $pnid = (string)($n['id'] ?? '');
        $inst = $byPnid[$pnid] ?? null;
        $display = (string)($n['display_phone_number'] ?? '');
        $digits = (string)preg_replace('/\D+/', '', $display);
        echo '<tr>';
        echo '<td><strong>' . h($display) . '</strong><div class="wacMuted">' . h((string)($n['verified_name'] ?? '')) . ' · ID ' . h($pnid) . '</div></td>';
        echo '<td>' . $pill((string)($n['status'] ?? '')) . (($n['code_verification_status'] ?? '') !== '' ? '<div class="wacMuted">verificação: ' . h((string)$n['code_verification_status']) . '</div>' : '') . '</td>';
        echo '<td class="wacHideSm">' . $pill((string)($n['quality_rating'] ?? '')) . '<div class="wacMuted">' . h((string)($n['messaging_limit_tier'] ?? '')) . '</div></td>';
        echo '<td>';
        if ($inst) {
            $isDef = (string)$inst['instance_name'] === $defaultInstance || (int)($inst['is_default'] ?? 0) === 1;
            echo '<div><strong>' . h((string)$inst['instance_name']) . '</strong>' . ($isDef ? ' <span class="wacMuted">(padrão)</span>' : '') . '</div>';
            echo '<div class="wacRow" style="margin-top:6px">';
            if (!$isDef) {
                echo '<form method="post" action="/admin_whatsapp_cloud_post.php"><input type="hidden" name="action" value="set_default"><input type="hidden" name="instance" value="' . h((string)$inst['instance_name']) . '"><button class="btn" type="submit">Tornar padrão</button></form>';
            }
            echo '<form method="post" action="/admin_whatsapp_cloud_post.php" onsubmit="return confirm(\'Tirar este número do sistema? As conversas continuam guardadas.\')"><input type="hidden" name="action" value="unlink_number"><input type="hidden" name="instance" value="' . h((string)$inst['instance_name']) . '"><button class="btn" type="submit">Tirar do sistema</button></form>';
            echo '</div>';
        } else {
            echo '<form method="post" action="/admin_whatsapp_cloud_post.php" class="wacRow" style="display:flex">';
            echo '<input type="hidden" name="action" value="link_number"><input type="hidden" name="phone_number_id" value="' . h($pnid) . '">';
            echo '<input name="instance_name" value="' . h('ml-oficial-' . substr($digits, -4)) . '" style="max-width:190px" aria-label="Nome interno">';
            echo '<button class="btn btnPrimary" type="submit">Usar no sistema</button></form>';
        }
        if (strtoupper((string)($n['status'] ?? '')) !== 'CONNECTED') {
            echo '<details style="margin-top:6px"><summary class="wacMuted" style="cursor:pointer">Registrar na Cloud API (PIN)</summary>';
            echo '<form method="post" action="/admin_whatsapp_cloud_post.php" style="margin-top:6px"><input type="hidden" name="action" value="register_phone"><input type="hidden" name="phone_number_id" value="' . h($pnid) . '">';
            echo '<label>PIN de 6 dígitos (verificação em duas etapas)<input name="pin" inputmode="numeric" maxlength="6" pattern="\d{6}" required></label><button class="btn" type="submit">Registrar</button></form></details>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
}
echo '</section>';

// ---------- 4. Modelos ----------
$approved = array_values(array_filter($templates, static fn($t) => strtoupper((string)$t['status']) === 'APPROVED'));
echo '<section class="wacCard" id="modelos">';
echo '<h2><span class="wacStep">4</span> Modelos de mensagem (templates)</h2>';
echo '<p class="wacHelp">Pela regra da Meta, <strong>mensagem livre só pode ser enviada até 24 h depois da última mensagem da pessoa</strong>. Fora desse prazo, só sai mensagem com modelo aprovado. '
    . 'O sistema escolhe sozinho: dentro das 24 h manda o texto normal; fora, usa o modelo de cada uso abaixo. A aprovação de um modelo novo leva de minutos a 24 h.</p>';
echo '<div class="wacRow" style="margin-bottom:12px">';
echo '<form method="post" action="/admin_whatsapp_cloud_post.php"><input type="hidden" name="action" value="sync_templates"><button class="btn" type="submit"' . ($configured ? '' : ' disabled') . '>Atualizar modelos da Meta</button></form>';
echo '<span class="wacMuted">' . count($templates) . ' modelo(s) na conta' . ($syncedAt ? ' · atualizado em ' . h(date('d/m H:i', $syncedAt)) : '') . '</span>';
echo '</div>';

echo '<form method="post" action="/admin_whatsapp_cloud_post.php">';
echo '<input type="hidden" name="action" value="save_purposes">';
foreach (WA_CLOUD_PURPOSES as $purpose => $def) {
    $sel = wa_cloud_purpose_template($purpose);
    $current = $sel ? $sel['name'] . '|' . $sel['lang'] : '';
    echo '<div style="border:1px solid hsl(var(--border));border-radius:12px;padding:12px;display:grid;gap:8px">';
    echo '<div style="font-weight:800">' . h($def['label']) . '</div>';
    echo '<div class="wacMuted">' . h($def['help']) . '</div>';
    echo '<div class="wacMuted">Variáveis esperadas, nesta ordem: ' . h(implode(' · ', array_map(static fn($k, $v) => '{{' . ($k + 1) . '}} ' . $v, array_keys(array_values($def['params'])), array_values($def['params'])))) . ($def['buttons'] ? ' · botão "' . h($def['buttons'][0]['text']) . '"' : '') . '</div>';
    echo '<select name="tpl[' . h($purpose) . ']">';
    echo '<option value="">— nenhum (fora das 24 h esta mensagem não sai) —</option>';
    $found = false;
    foreach ($templates as $t) {
        $val = $t['name'] . '|' . $t['language'];
        $shape = wa_cloud_template_shape($t);
        $fits = $shape['body_vars'] + $shape['header_vars'] === count($def['params']);
        $isSel = $val === $current;
        $found = $found || $isSel;
        echo '<option value="' . h($val) . '"' . ($isSel ? ' selected' : '') . '>' . h($t['name'] . ' (' . $t['language'] . ') · ' . $statusLabel((string)$t['status'])[0]
            . ($fits ? '' : ' · ' . ($shape['body_vars'] + $shape['header_vars']) . ' variável(is) — não confere')) . '</option>';
    }
    if ($current !== '' && !$found) {
        echo '<option value="' . h($current) . '" selected>' . h(str_replace('|', ' (', $current) . ') · não encontrado na conta') . '</option>';
    }
    echo '</select>';
    if ($sel && $sel['row'] !== null) {
        echo '<div>' . $pill((string)$sel['row']['status']) . ($sel['row']['rejected_reason'] ? ' <span class="wacMuted">motivo: ' . h((string)$sel['row']['rejected_reason']) . '</span>' : '') . '</div>';
    }
    echo '</div>';
}
echo '<div class="wacRow"><button class="btn btnPrimary" type="submit">Salvar modelos escolhidos</button></div>';
echo '</form>';

echo '<details style="margin-top:12px"><summary style="cursor:pointer;font-weight:700">Criar os modelos padrão do sistema na Meta</summary>';
echo '<p class="wacHelp" style="margin-top:8px">Envia para aprovação da Meta o texto sugerido de cada uso (dá para editar o texto depois no Gerenciador do WhatsApp). Depois de aprovado, ele já fica escolhido acima.</p>';
foreach (WA_CLOUD_PURPOSES as $purpose => $def) {
    echo '<form method="post" action="/admin_whatsapp_cloud_post.php" style="border:1px dashed hsl(var(--border));border-radius:12px;padding:12px;margin-bottom:10px">';
    echo '<input type="hidden" name="action" value="create_template"><input type="hidden" name="purpose" value="' . h($purpose) . '">';
    echo '<div style="font-weight:700">' . h($def['label']) . '</div>';
    echo '<pre style="white-space:pre-wrap;background:hsl(var(--secondary));padding:10px;border-radius:8px;font-size:13px;margin:6px 0">' . h($def['body']) . ($def['buttons'] ? "\n\n[ " . h($def['buttons'][0]['text']) . ' ]' : '') . '</pre>';
    echo '<div class="wacGrid"><label>Nome do modelo<input name="name" value="' . h($def['default_name']) . '" pattern="[a-z0-9_]+" title="só letras minúsculas, números e _"></label>';
    echo '<label>Categoria<select name="category"><option value="UTILITY"' . ($def['category'] === 'UTILITY' ? ' selected' : '') . '>Utilidade</option><option value="MARKETING"' . ($def['category'] === 'MARKETING' ? ' selected' : '') . '>Marketing</option></select></label></div>';
    echo '<div style="margin-top:8px"><button class="btn" type="submit"' . ($configured && $health['ok'] ? '' : ' disabled') . '>Enviar para aprovação</button></div>';
    echo '</form>';
}
echo '</details>';

if ($templates) {
    echo '<details style="margin-top:8px"><summary style="cursor:pointer;font-weight:700">Todos os modelos da conta (' . count($templates) . ')</summary><div class="wacTableWrap" style="margin-top:8px"><table class="wacTable"><thead><tr><th>Nome</th><th>Idioma</th><th>Categoria</th><th>Situação</th><th class="wacHideSm">Texto</th></tr></thead><tbody>';
    foreach ($templates as $t) {
        $shape = wa_cloud_template_shape($t);
        echo '<tr><td>' . h((string)$t['name']) . '</td><td>' . h((string)$t['language']) . '</td><td>' . h((string)$t['category']) . '</td><td>' . $pill((string)$t['status']) . '</td><td class="wacHideSm wacMuted">' . h(mb_strimwidth($shape['body'], 0, 160, '…')) . '</td></tr>';
    }
    echo '</tbody></table></div></details>';
}
echo '</section>';

// ---------- 5. Captação ----------
$mode = wa_cloud_captacao_mode();
echo '<section class="wacCard">';
echo '<h2><span class="wacStep">5</span> Como a captação sai pela API oficial</h2>';
echo '<form method="post" action="/admin_whatsapp_cloud_post.php"><input type="hidden" name="action" value="save_captacao_mode">';
echo '<label style="display:flex !important;gap:10px;align-items:flex-start;font-weight:600"><input type="radio" name="captacao_mode" value="direct"' . ($mode === 'direct' ? ' checked' : '') . ' style="margin-top:4px">'
    . '<span>Direto para cada profissional (recomendado)<span class="helpText" style="display:block;font-weight:400">A mesma lista de profissionais da especialidade/região que entrava no grupo recebe a captação numa conversa privada, com o botão <strong>"Tenho interesse"</strong>. Tocar no botão ou reagir à mensagem coloca o profissional na Lista de Espera. Fora das 24 h sai o modelo "Captação".</span></span></label>';
echo '<label style="display:flex !important;gap:10px;align-items:flex-start;font-weight:600"><input type="radio" name="captacao_mode" value="group"' . ($mode === 'group' ? ' checked' : '') . ' style="margin-top:4px">'
    . '<span>Em grupo (limitado)<span class="helpText" style="display:block;font-weight:400">Grupo da API oficial tem no máximo <strong>' . WA_CLOUD_GROUP_MAX_PARTICIPANTS . ' participantes</strong> e ninguém é adicionado direto: cada profissional recebe o link de convite e só vê a captação depois de entrar. Serve só para grupos pequenos.</span></span></label>';
echo '<div><button class="btn btnPrimary" type="submit">Salvar</button></div></form>';
echo '</section>';

// ---------- 6. Teste ----------
echo '<section class="wacCard">';
echo '<h2><span class="wacStep">6</span> Teste de envio</h2>';
echo '<p class="wacHelp">Manda uma mensagem de verdade. Se a pessoa não falou com o número nas últimas 24 h, sai pelo modelo de Aviso (precisa estar aprovado).</p>';
echo '<form method="post" action="/admin_whatsapp_cloud_post.php"><input type="hidden" name="action" value="send_test">';
echo '<div class="wacGrid">';
echo '<label>Enviar pelo número<select name="instance">';
foreach ($instances as $r) {
    echo '<option value="' . h((string)$r['instance_name']) . '"' . ((string)$r['instance_name'] === $defaultInstance ? ' selected' : '') . '>' . h((string)$r['instance_name'] . ' · ' . (string)($r['cloud_display_phone'] ?? '')) . '</option>';
}
echo '</select></label>';
echo '<label>Para (com DDD)<input name="to" inputmode="tel" placeholder="11999998888" required></label>';
echo '</div>';
echo '<label>Mensagem<textarea name="text" rows="3">Teste da API oficial do WhatsApp — MultiLife Care</textarea></label>';
echo '<div><button class="btn btnPrimary" type="submit"' . ($instances ? '' : ' disabled') . '>Enviar teste</button></div></form>';
echo '</section>';

echo '</div>';
echo '<script>function wacCopy(id){var t=document.getElementById(id).textContent;if(navigator.clipboard){navigator.clipboard.writeText(t).then(function(){alert("Copiado")});}else{var r=document.createRange();r.selectNode(document.getElementById(id));getSelection().removeAllRanges();getSelection().addRange(r);document.execCommand("copy");}}</script>';

view_footer();

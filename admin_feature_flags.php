<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
auth_require_login();
rbac_require_permission('admin.settings.manage');

$db = db();

// POST: salvar configuracoes
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flags = [
        'feature.portal_profissional_ativo' => ($_POST['portal_profissional_ativo'] ?? '0'),
        'feature.enviar_link_portal_notificacoes' => ($_POST['enviar_link_portal_notificacoes'] ?? '0'),
        'feature.profissional_envio_documentos' => ($_POST['profissional_envio_documentos'] ?? '0'),
        'feature.profissional_acesso_agendamentos' => ($_POST['profissional_acesso_agendamentos'] ?? '0'),
        'feature.profissional_acesso_recebimentos' => ($_POST['profissional_acesso_recebimentos'] ?? '0'),
        'feature.enviar_credenciais_profissional' => ($_POST['enviar_credenciais_profissional'] ?? '0'),
        'feature.auto_send_authorization' => ($_POST['auto_send_authorization'] ?? '0'),
    ];
    admin_settings_set_many($flags, auth_user_id());
    flash_set('success', 'Configuracoes de implantacao atualizadas!');
    header('Location: /admin_feature_flags.php');
    exit;
}

// Buscar valores atuais
$flags = [
    'feature.portal_profissional_ativo' => (string)admin_setting_get('feature.portal_profissional_ativo', '0'),
    'feature.enviar_link_portal_notificacoes' => (string)admin_setting_get('feature.enviar_link_portal_notificacoes', '0'),
    'feature.profissional_envio_documentos' => (string)admin_setting_get('feature.profissional_envio_documentos', '0'),
    'feature.profissional_acesso_agendamentos' => (string)admin_setting_get('feature.profissional_acesso_agendamentos', '0'),
    'feature.profissional_acesso_recebimentos' => (string)admin_setting_get('feature.profissional_acesso_recebimentos', '0'),
    'feature.enviar_credenciais_profissional' => (string)admin_setting_get('feature.enviar_credenciais_profissional', '0'),
    'feature.auto_send_authorization' => (string)admin_setting_get('feature.auto_send_authorization', '1'),
];

view_header('Implantacao - Funcionalidades');

$fl = flash_get('success');
if ($fl) { echo '<div class="alert alertSuccess">' . htmlspecialchars($fl) . '</div>'; }

echo '<div class="grid">';

// Header
echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Gerenciamento de Funcionalidades</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Controle a implantacao gradual do sistema. Habilite ou desabilite funcionalidades conforme a etapa de implantacao.</div>';
echo '</div>';
echo '<a class="btn" href="/admin_settings.php">Voltar</a>';
echo '</div>';
echo '</section>';

// Info do modulo atual
$portalAtivo = $flags['feature.portal_profissional_ativo'] === '1';
$moduloAtual = $portalAtivo ? 'Modulo Completo (Portal liberado)' : 'Modulo 1 - Adaptacao Interna';
$moduloCor = $portalAtivo ? '#10b981' : '#f59e0b';

echo '<section class="card col12" style="border-left:4px solid ' . $moduloCor . '">';
echo '<div style="display:flex;align-items:center;gap:12px">';
echo '<div style="width:40px;height:40px;border-radius:10px;background:' . $moduloCor . '20;display:flex;align-items:center;justify-content:center;font-size:20px">' . ($portalAtivo ? '🚀' : '🔧') . '</div>';
echo '<div>';
echo '<div style="font-size:16px;font-weight:700;color:' . $moduloCor . '">' . $moduloAtual . '</div>';
echo '<div style="font-size:13px;color:hsl(var(--muted-foreground))">' . ($portalAtivo ? 'Profissionais com acesso completo ao portal' : 'Equipe interna operando o sistema. Profissionais recebem notificacoes mas nao acessam o portal.') . '</div>';
echo '</div>';
echo '</div>';
echo '</section>';

// Formulario
echo '<section class="card col12">';
echo '<form method="post">';
echo '<div style="font-size:16px;font-weight:700;margin-bottom:20px">Funcionalidades do Portal do Profissional</div>';

echo '<div style="display:flex;flex-direction:column;gap:16px">';

// Cada flag como toggle
$flagsConfig = [
    ['key' => 'portal_profissional_ativo', 'label' => 'Liberar acesso ao Portal do Profissional', 'desc' => 'Quando ativado, os profissionais poderao fazer login e acessar o portal. Quando desativado, o login dos profissionais sera bloqueado.'],
    ['key' => 'enviar_link_portal_notificacoes', 'label' => 'Enviar link de acesso ao portal nas notificacoes', 'desc' => 'Inclui o link de acesso ao portal nos e-mails e mensagens de WhatsApp enviados ao profissional (ex: na aprovacao do atendimento).'],
    ['key' => 'enviar_credenciais_profissional', 'label' => 'Enviar credenciais de acesso ao profissional', 'desc' => 'Ao criar ou aprovar um profissional, envia automaticamente o login e senha para acesso ao portal.'],
    ['key' => 'profissional_envio_documentos', 'label' => 'Permitir envio de documentos pelo portal', 'desc' => 'Os profissionais poderao enviar Fichas de Evolucao e Produtividade diretamente pelo portal. Quando desativado, a equipe interna cadastra manualmente.'],
    ['key' => 'profissional_acesso_agendamentos', 'label' => 'Permitir acesso a Agendamentos', 'desc' => 'Exibe a aba de Agendamentos no portal do profissional (calendario de sessoes).'],
    ['key' => 'profissional_acesso_recebimentos', 'label' => 'Permitir acesso a Recebimentos', 'desc' => 'Exibe a aba de Recebimentos no portal do profissional (valores pagos e pendentes).'],
    ['key' => 'auto_send_authorization', 'label' => 'Envio automatico da autorizacao de proposta por e-mail', 'desc' => 'Quando ativado, o sistema envia automaticamente o e-mail de autorizacao a operadora/cliente (fluxo: Captacao -> Autorizacao -> Envio -> Resposta -> Pre-Admissao). Quando desativado, nao envia e-mail e o usuario registra manualmente que a autorizacao foi respondida.'],
];

foreach ($flagsConfig as $fc) {
    $val = $flags['feature.' . $fc['key']] ?? '0';
    $isOn = $val === '1';
    $toggleColor = $isOn ? 'hsl(var(--primary))' : '#d1d5db';
    $toggleBg = $isOn ? 'hsl(var(--primary))' : '#e5e7eb';

    echo '<div style="display:flex;align-items:flex-start;gap:14px;padding:16px;border:1px solid hsl(var(--border));border-radius:10px">';
    echo '<label style="position:relative;display:inline-block;width:48px;height:26px;flex-shrink:0;cursor:pointer">';
    echo '<input type="hidden" name="' . $fc['key'] . '" value="0">';
    echo '<input type="checkbox" name="' . $fc['key'] . '" value="1"' . ($isOn ? ' checked' : '') . ' style="opacity:0;width:0;height:0" onchange="this.parentElement.querySelector(\'span\').style.background=this.checked?\'hsl(180,65%,46%)\':\'#e5e7eb\';this.parentElement.querySelector(\'span span\').style.transform=this.checked?\'translateX(22px)\':\'translateX(0)\'">';
    echo '<span style="position:absolute;inset:0;background:' . $toggleBg . ';border-radius:26px;transition:background .3s"><span style="position:absolute;top:3px;left:3px;width:20px;height:20px;background:white;border-radius:50%;transition:transform .3s;transform:' . ($isOn ? 'translateX(22px)' : 'translateX(0)') . '"></span></span>';
    echo '</label>';
    echo '<div>';
    echo '<div style="font-size:14px;font-weight:700">' . $fc['label'] . '</div>';
    echo '<div style="font-size:12px;color:hsl(var(--muted-foreground));margin-top:4px">' . $fc['desc'] . '</div>';
    echo '</div>';
    echo '</div>';
}

echo '</div>';

echo '<div style="margin-top:24px;display:flex;gap:10px">';
echo '<button type="submit" class="btn btnPrimary">Salvar Configuracoes</button>';
echo '<a class="btn" href="/admin_settings.php">Cancelar</a>';
echo '</div>';

echo '</form>';
echo '</section>';

// Instrucoes
echo '<section class="card col12" style="background:hsl(var(--muted))">';
echo '<div style="font-size:15px;font-weight:700;margin-bottom:12px">Como funciona a implantacao gradual</div>';
echo '<div style="font-size:13px;color:hsl(var(--muted-foreground));line-height:1.8">';
echo '<p><strong>Modulo 1 - Adaptacao Interna:</strong> Mantenha todas as opcoes desativadas. Os profissionais recebem notificacoes normais, mas nao acessam o portal. A equipe interna cadastra documentos manualmente.</p>';
echo '<p style="margin-top:8px"><strong>Modulo 2 - Portal Liberado:</strong> Ative "Liberar acesso ao Portal" e "Enviar link nas notificacoes". Os profissionais passam a acessar o portal e visualizar seus atendimentos.</p>';
echo '<p style="margin-top:8px"><strong>Modulo 3 - Operacao Completa:</strong> Ative todas as opcoes. Os profissionais enviam documentos pelo portal, acompanham agendamentos e recebimentos.</p>';
echo '</div>';
echo '</section>';

echo '</div>';
view_footer();

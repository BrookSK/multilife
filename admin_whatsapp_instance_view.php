<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('whatsapp.manage');

$instance = isset($_GET['instance']) ? trim((string)$_GET['instance']) : '';
if ($instance === '') {
    flash_set('error', 'Informe a instance.');
    header('Location: /admin_whatsapp_instances.php');
    exit;
}

$evo = new EvolutionApiV1();
$stateRes = $evo->connectionState($instance);

$state = '';
if (is_array($stateRes['json']) && isset($stateRes['json']['instance']['state'])) {
    $state = (string)$stateRes['json']['instance']['state'];
}

view_header('WhatsApp - ' . $instance);

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:800">Instância: ' . h($instance) . '</div>';
echo '<div style="margin-top:6px;color:rgba(234,240,255,.72);font-size:14px;line-height:1.6"><strong>Status conexão:</strong> ' . h($state) . '</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/admin_whatsapp_console.php?instance=' . urlencode($instance) . '">Console</a>';
echo '<a class="btn" href="/tech_logs_list.php?provider=evolution">Logs TI</a>';
echo '<a class="btn" href="/admin_whatsapp_instances.php">Voltar</a>';
echo '</div>';
echo '</div>';
echo '</section>';

// Connect / QR

echo '<section class="card col12">';
echo '<div style="font-weight:800;margin-bottom:8px">Conectar (QR/Pairing)</div>';
echo '<form method="post" action="/admin_whatsapp_instance_connect_post.php" style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<input type="hidden" name="instance" value="' . h($instance) . '">';
echo '<input name="number" placeholder="Número (opcional)" style="flex:1;min-width:220px;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:10px 12px;outline:none;font-size:14px">';
echo '<button class="btn btnPrimary" type="submit">Gerar QR/Pairing</button>';
echo '</form>';
echo '</section>';

// Ações

echo '<section class="card col12">';
echo '<div style="font-weight:800;margin-bottom:8px">Ações</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';

echo '<form method="post" action="/admin_whatsapp_instance_restart_post.php" style="display:inline">';
echo '<input type="hidden" name="instance" value="' . h($instance) . '">';
echo '<button class="btn" type="submit">Restart</button>';
echo '</form>';

echo '<form method="post" action="/admin_whatsapp_instance_logout_post.php" style="display:inline">';
echo '<input type="hidden" name="instance" value="' . h($instance) . '">';
echo '<button class="btn" type="submit" onclick="return confirm(\'Fazer logout desta instância?\')">Logout</button>';
echo '</form>';

echo '<form method="post" action="/admin_whatsapp_instance_delete_post.php" style="display:inline">';
echo '<input type="hidden" name="instance" value="' . h($instance) . '">';
echo '<button class="btn" type="submit" onclick="return confirm(\'Deletar esta instância?\')">Delete</button>';
echo '</form>';

echo '</div>';
echo '</section>';

// Teste envio

echo '<section class="card col12">';
echo '<div style="font-weight:800;margin-bottom:8px">Teste - Enviar mensagem</div>';
echo '<form method="post" action="/admin_whatsapp_send_text_post.php" style="display:grid;gap:10px;max-width:720px">';
echo '<input type="hidden" name="instance" value="' . h($instance) . '">';
echo '<input name="number" required placeholder="Número destino (DDI)" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:10px 12px;outline:none;font-size:14px">';
echo '<textarea name="text" required rows="3" placeholder="Mensagem" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:10px 12px;outline:none;font-size:14px"></textarea>';
echo '<button class="btn btnPrimary" type="submit">Enviar</button>';
echo '</form>';
echo '</section>';

echo '</div>';

view_footer();

<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('whatsapp.manage');

// Verificar se Evolution API está configurada
$baseUrl = admin_setting_get('evolution.base_url', '');
$apiKey = admin_setting_get('evolution.api_key', '');

if ($baseUrl === '' || $apiKey === '') {
    flash_set('error', 'Evolution API não configurada. Configure em Configurações.');
    header('Location: /admin_settings.php');
    exit;
}

$instance = trim((string)($_POST['instance'] ?? ''));
$number = trim((string)($_POST['number'] ?? ''));

if ($instance === '') {
    flash_set('error', 'Instance inválida.');
    header('Location: /admin_whatsapp_instances.php');
    exit;
}

$evo = new EvolutionApiV1();
$res = $evo->connectInstance($instance, $number !== '' ? $number : null);

$pairingCode = '';
$code = '';
$count = '';
if (is_array($res['json'])) {
    $pairingCode = (string)($res['json']['pairingCode'] ?? '');
    $code = (string)($res['json']['code'] ?? '');
    $count = (string)($res['json']['count'] ?? '');
}

view_header('QR / Pairing');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="font-size:22px;font-weight:800">Conectar: ' . h($instance) . '</div>';
echo '<div style="margin-top:8px;color:rgba(234,240,255,.72);font-size:14px;line-height:1.6">';
echo '<strong>Pairing code:</strong> ' . h($pairingCode) . ' &nbsp; <strong>Count:</strong> ' . h($count);
echo '</div>';

echo '<div style="margin-top:12px">';
if ($code !== '') {
    // A doc retorna "code" (geralmente string do QR). Tentamos renderizar como imagem base64; se falhar, mostramos o texto.
    echo '<div class="pill" style="display:block;padding:12px">';
    echo '<div style="font-weight:800;margin-bottom:8px">QR (code)</div>';
    echo '<img alt="qr" style="max-width:320px;border-radius:14px;background:#fff;padding:10px" src="data:image/png;base64,' . h($code) . '">';
    echo '<div style="margin-top:10px;color:rgba(234,240,255,.72);font-size:12px">Se não aparecer como imagem, copie o valor abaixo:</div>';
    echo '<textarea rows="4" style="width:100%;margin-top:8px;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:10px 12px;outline:none;font-size:13px">' . h($code) . '</textarea>';
    echo '</div>';
} else {
    echo '<div class="pill" style="display:block">Nenhum QR retornado.</div>';
}

echo '</div>';

echo '<div style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/admin_whatsapp_instance_view.php?instance=' . urlencode($instance) . '">Voltar</a>';
echo '</div>';

echo '</section>';

echo '</div>';

view_footer();

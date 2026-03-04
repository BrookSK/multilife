<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('zapsign.manage');

$docToken = trim((string)($_POST['doc_token'] ?? ''));
if ($docToken === '') {
    flash_set('error', 'Informe doc_token.');
    header('Location: /admin_zapsign_console.php');
    exit;
}

$api = new ZapSignApi();
$res = $api->detailDoc($docToken);

view_header('ZapSign - Detalhe');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="font-size:22px;font-weight:800">Detalhe do documento</div>';
echo '<div style="margin-top:8px;color:rgba(234,240,255,.72);font-size:14px;line-height:1.6"><strong>doc_token:</strong> ' . h($docToken) . ' &nbsp; <strong>HTTP:</strong> ' . h((string)($res['status'] ?? '')) . '</div>';

echo '<div style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/admin_zapsign_console.php">Voltar</a>';
echo '<a class="btn" href="/tech_logs_list.php?provider=zapsign">Ver Logs TI</a>';
echo '</div>';

echo '</section>';

$pretty = '';
if (is_array($res['json'])) {
    $pretty = (string)json_encode($res['json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

echo '<section class="card col12">';
echo '<div style="font-weight:800;margin-bottom:8px">Resposta (JSON)</div>';
echo '<pre style="white-space:pre-wrap;background:rgba(10,14,28,.35);border:1px solid rgba(255,255,255,.12);border-radius:14px;padding:12px;overflow:auto">' . h($pretty !== '' ? $pretty : (string)($res['body_raw'] ?? '')) . '</pre>';
echo '</section>';

echo '</div>';

view_footer();

<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('tech_logs.view');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = db()->prepare('SELECT * FROM integration_logs WHERE id = :id');
$stmt->execute(['id' => $id]);
$l = $stmt->fetch();

if (!$l) {
    flash_set('error', 'Log não encontrado.');
    header('Location: /tech_logs_list.php');
    exit;
}

view_header('Log #' . (string)$l['id']);

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="font-size:22px;font-weight:800">Log #' . (int)$l['id'] . '</div>';
echo '<div style="margin-top:8px;color:rgba(234,240,255,.72);font-size:14px;line-height:1.6">';
echo '<strong>Provider:</strong> ' . h((string)$l['provider']) . ' &nbsp; <strong>Ação:</strong> ' . h((string)$l['action']) . ' &nbsp; <strong>Status:</strong> ' . h((string)$l['status']);
echo '<br><strong>HTTP:</strong> ' . h((string)($l['http_status'] ?? '')) . ' &nbsp; <strong>Tentativas:</strong> ' . h((string)$l['attempts']);
echo '</div>';
if (!empty($l['error_message'])) {
    echo '<div class="alert alertError" style="margin-top:12px">' . h((string)$l['error_message']) . '</div>';
}
echo '<div style="margin-top:12px"><a class="btn" href="/tech_logs_list.php">Voltar</a></div>';
echo '</section>';

echo '<section class="card col12">';
echo '<div style="font-weight:800;margin-bottom:8px">Request payload</div>';
echo '<pre style="white-space:pre-wrap;background:rgba(10,14,28,.35);border:1px solid rgba(255,255,255,.12);border-radius:14px;padding:12px;overflow:auto">' . h((string)($l['request_payload'] ?? '')) . '</pre>';
echo '</section>';

echo '<section class="card col12">';
echo '<div style="font-weight:800;margin-bottom:8px">Response payload</div>';
echo '<pre style="white-space:pre-wrap;background:rgba(10,14,28,.35);border:1px solid rgba(255,255,255,.12);border-radius:14px;padding:12px;overflow:auto">' . h((string)($l['response_payload'] ?? '')) . '</pre>';
echo '</section>';

echo '</div>';

view_footer();

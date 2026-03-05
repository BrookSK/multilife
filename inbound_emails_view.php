<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = db()->prepare('SELECT * FROM inbound_emails WHERE id = :id');
$stmt->execute(['id' => $id]);
$e = $stmt->fetch();

if (!$e) {
    flash_set('error', 'E-mail não encontrado.');
    header('Location: /inbound_emails_list.php');
    exit;
}

view_header('E-mail #' . (string)$e['id']);

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">E-mail #' . (int)$e['id'] . '</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Detalhe do e-mail capturado.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/inbound_emails_list.php">Voltar</a>';
if ($e['linked_demand_id'] !== null) {
    echo '<a class="btn btnPrimary" href="/demands_view.php?id=' . (int)$e['linked_demand_id'] . '">Abrir demanda</a>';
}
echo '</div>';
echo '</div>';
echo '</section>';

echo '<section class="card col12">';
echo '<div class="grid">';

echo '<div class="col6"><div class="pill" style="display:block"><strong>Status:</strong> ' . h((string)$e['status']) . '</div></div>';
echo '<div class="col6"><div class="pill" style="display:block"><strong>Recebido em:</strong> ' . h((string)$e['received_at']) . '</div></div>';
echo '<div class="col6"><div class="pill" style="display:block"><strong>Remetente:</strong> ' . h((string)($e['from_email'] ?? '-')) . '</div></div>';
echo '<div class="col6"><div class="pill" style="display:block"><strong>Nome:</strong> ' . h((string)($e['from_name'] ?? '')) . '</div></div>';
echo '<div class="col12"><div class="pill" style="display:block"><strong>Assunto:</strong> ' . h((string)($e['subject'] ?? '')) . '</div></div>';

if ((string)($e['error_message'] ?? '') !== '') {
    echo '<div class="col12"><div class="pill" style="display:block"><strong>Erro:</strong> ' . h((string)$e['error_message']) . '</div></div>';
}

echo '</div>';
echo '</section>';

$bodyText = (string)($e['body_text'] ?? '');
$bodyHtml = (string)($e['body_html'] ?? '');

if (trim($bodyText) !== '') {
    echo '<section class="card col12">';
    echo '<div style="font-weight:900;margin-bottom:10px">Corpo (texto)</div>';
    echo '<pre class="pill" style="white-space:pre-wrap;display:block;margin:0">' . h($bodyText) . '</pre>';
    echo '</section>';
}

if (trim($bodyHtml) !== '') {
    echo '<section class="card col12">';
    echo '<div style="font-weight:900;margin-bottom:10px">Corpo (html - bruto)</div>';
    echo '<pre class="pill" style="white-space:pre-wrap;display:block;margin:0">' . h($bodyHtml) . '</pre>';
    echo '</section>';
}

echo '</div>';

view_footer();

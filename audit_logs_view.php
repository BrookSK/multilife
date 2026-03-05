<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('audit.view');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = db()->prepare(
    'SELECT a.*, u.name AS user_name, u.email AS user_email
     FROM audit_logs a
     LEFT JOIN users u ON u.id = a.user_id
     WHERE a.id = :id'
);
$stmt->execute(['id' => $id]);
$r = $stmt->fetch();

if (!$r) {
    flash_set('error', 'Registro de auditoria não encontrado.');
    header('Location: /audit_logs_list.php');
    exit;
}

view_header('Auditoria #' . (string)$r['id']);

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Auditoria #' . (int)$r['id'] . '</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Detalhe do log.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/audit_logs_list.php">Voltar</a>';
echo '</div>';
echo '</div>';
echo '</section>';

echo '<section class="card col12">';
echo '<div class="grid">';

echo '<div class="col6"><div class="pill" style="display:block"><strong>Data:</strong> ' . h((string)$r['created_at']) . '</div></div>';
echo '<div class="col6"><div class="pill" style="display:block"><strong>IP:</strong> ' . h((string)($r['ip'] ?? '')) . '</div></div>';
echo '<div class="col6"><div class="pill" style="display:block"><strong>Ação:</strong> ' . h((string)$r['action']) . '</div></div>';
echo '<div class="col6"><div class="pill" style="display:block"><strong>Módulo:</strong> ' . h((string)$r['module']) . '</div></div>';
echo '<div class="col6"><div class="pill" style="display:block"><strong>Registro:</strong> ' . h((string)($r['record_id'] ?? '')) . '</div></div>';

$userTxt = '-';
if ($r['user_id'] !== null) {
    $userTxt = (string)($r['user_name'] ?? 'Usuário') . ' — ' . (string)($r['user_email'] ?? '') . ' (#' . (int)$r['user_id'] . ')';
}

echo '<div class="col6"><div class="pill" style="display:block"><strong>Usuário:</strong> ' . h($userTxt) . '</div></div>';

echo '</div>';
echo '</section>';

$oldRaw = $r['old_data'] !== null ? (string)$r['old_data'] : '';
$newRaw = $r['new_data'] !== null ? (string)$r['new_data'] : '';

$oldPretty = $oldRaw;
$newPretty = $newRaw;

if ($oldRaw !== '') {
    $decoded = json_decode($oldRaw, true);
    if (is_array($decoded) || is_object($decoded)) {
        $oldPretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}
if ($newRaw !== '') {
    $decoded = json_decode($newRaw, true);
    if (is_array($decoded) || is_object($decoded)) {
        $newPretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}

echo '<section class="card col12">';
echo '<div style="font-weight:900;margin-bottom:10px">Dados</div>';
echo '<div class="grid">';
echo '<div class="col6"><div style="font-weight:900;margin-bottom:6px">Antes</div><pre class="pill" style="white-space:pre-wrap;display:block;margin:0">' . h($oldPretty) . '</pre></div>';
echo '<div class="col6"><div style="font-weight:900;margin-bottom:6px">Depois</div><pre class="pill" style="white-space:pre-wrap;display:block;margin:0">' . h($newPretty) . '</pre></div>';
echo '</div>';
echo '</section>';

echo '</div>';

view_footer();

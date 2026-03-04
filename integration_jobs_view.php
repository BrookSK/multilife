<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('integration_jobs.manage');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = db()->prepare('SELECT * FROM integration_jobs WHERE id = :id');
$stmt->execute(['id' => $id]);
$j = $stmt->fetch();

if (!$j) {
    flash_set('error', 'Job não encontrado.');
    header('Location: /integration_jobs_list.php');
    exit;
}

view_header('Job #' . (string)$j['id']);

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="font-size:22px;font-weight:900">Job #' . (int)$j['id'] . '</div>';
echo '<div style="margin-top:8px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">';
echo '<strong>Provider:</strong> ' . h((string)$j['provider']) . ' &nbsp; <strong>Ação:</strong> ' . h((string)$j['action']) . ' &nbsp; <strong>Status:</strong> ' . h((string)$j['status']);
echo '<br><strong>Tentativas:</strong> ' . h((string)$j['attempts']) . '/' . h((string)$j['max_attempts']);
echo '<br><strong>Próx. execução:</strong> ' . h((string)($j['next_run_at'] ?? '')) . ' &nbsp; <strong>Última execução:</strong> ' . h((string)($j['last_run_at'] ?? ''));
echo '</div>';
if (!empty($j['last_error'])) {
    echo '<div class="alert alertError" style="margin-top:12px">' . h((string)$j['last_error']) . '</div>';
}

echo '<div style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end">';
echo '<a class="btn" href="/integration_jobs_list.php">Voltar</a>';
echo '<form method="post" action="/integration_jobs_run_post.php" style="display:inline">';
echo '<input type="hidden" name="id" value="' . (int)$j['id'] . '">';
echo '<button class="btn btnPrimary" type="submit">Rodar agora</button>';
echo '</form>';
echo '</div>';

echo '</section>';

echo '<section class="card col12">';
echo '<div style="font-weight:900;margin-bottom:8px">Payload</div>';
echo '<pre style="white-space:pre-wrap;background:hsl(var(--muted)/.25);border:1px solid hsl(var(--border));border-radius:14px;padding:12px;overflow:auto">' . h((string)($j['payload'] ?? '')) . '</pre>';
echo '</section>';

echo '</div>';

view_footer();

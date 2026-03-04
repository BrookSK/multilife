<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('integration_jobs.manage');

$provider = isset($_POST['provider']) ? trim((string)$_POST['provider']) : '';
$action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';
$forceError = isset($_POST['force_error']) ? (string)$_POST['force_error'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($provider === '' || $action === '') {
        flash_set('error', 'Informe provider e action.');
        header('Location: /integration_jobs_enqueue_demo.php');
        exit;
    }

    $payload = ['demo' => true, 'force_error' => ($forceError === '1')];
    $id = integration_job_enqueue($provider, $action, $payload, (new DateTime())->format('Y-m-d H:i:s'));
    flash_set('success', 'Job criado: #' . $id);
    header('Location: /integration_jobs_view.php?id=' . $id);
    exit;
}

view_header('Criar job (demo)');

echo '<div class="card">';
echo '<div style="font-size:22px;font-weight:800;margin-bottom:6px">Criar job (demo)</div>';
echo '<div style="color:rgba(234,240,255,.72);font-size:14px;line-height:1.6;margin-bottom:14px">Usado para testar logs e retentativas sem chamar APIs externas.</div>';

echo '<form method="post" action="/integration_jobs_enqueue_demo.php" style="display:grid;gap:12px;max-width:560px">';
echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Provider<input name="provider" required placeholder="OpenAI/Evolution/ZapSign/SMTP" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label>';
echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Action<input name="action" required placeholder="send_message / parse_email" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label>';

echo '<label class="pill" style="display:flex;align-items:center;gap:10px;padding:12px">';
echo '<input type="checkbox" name="force_error" value="1"> Simular erro (force_error)';
echo '</label>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<button class="btn btnPrimary" type="submit">Criar job</button>';
echo '<a class="btn" href="/integration_jobs_list.php">Voltar</a>';
echo '</div>';

echo '</form>';

echo '</div>';

view_footer();

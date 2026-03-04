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
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900;margin-bottom:6px">Criar job (demo)</div>';
echo '<div style="color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Usado para testar logs e retentativas sem chamar APIs externas.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/integration_jobs_list.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<div style="height:14px"></div>';

echo '<form method="post" action="/integration_jobs_enqueue_demo.php" style="display:grid;gap:12px;max-width:820px">';
echo '<div class="grid" style="gap:12px">';
echo '<div class="col6"><label>Provider<input name="provider" required placeholder="OpenAI/Evolution/ZapSign/SMTP"></label></div>';
echo '<div class="col6"><label>Action<input name="action" required placeholder="send_message / parse_email"></label></div>';
echo '</div>';

echo '<label class="pill" style="display:flex;align-items:center;gap:10px;padding:12px">';
echo '<input type="checkbox" name="force_error" value="1"> Simular erro (force_error)';
echo '</label>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end">';
echo '<a class="btn" href="/integration_jobs_list.php">Cancelar</a>';
echo '<button class="btn btnPrimary" type="submit">Criar job</button>';
echo '</div>';

echo '</form>';

echo '</div>';

view_footer();

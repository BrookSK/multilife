<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.dashboard');

$db = db();

$metrics = [];

$metrics['demands_open'] = (int)$db->query("SELECT COUNT(*) c FROM demands WHERE status IN ('aguardando_captacao','tratamento_manual','em_captacao')")->fetch()['c'];
$metrics['docs_submitted'] = (int)$db->query("SELECT COUNT(*) c FROM professional_documentations WHERE status = 'submitted'")->fetch()['c'];
$metrics['appointments_pending'] = (int)$db->query("SELECT COUNT(*) c FROM appointments WHERE status = 'pendente_formulario'")->fetch()['c'];
$metrics['ar_pending'] = (int)$db->query("SELECT COUNT(*) c FROM finance_accounts_receivable WHERE status = 'pendente'")->fetch()['c'];
$metrics['ap_pending'] = (int)$db->query("SELECT COUNT(*) c FROM finance_accounts_payable WHERE status = 'pendente'")->fetch()['c'];

view_header('Admin - Dashboard');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Dashboard Administrativo</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Indicadores operacionais e atalhos do Admin.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/admin_settings.php">Configurações</a>';
echo '<a class="btn" href="/hr_employees_list.php">RH</a>';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';
echo '</section>';

$cards = [
    ['Demandas em aberto', $metrics['demands_open'], '/demands_list.php'],
    ['Docs pendentes de revisão', $metrics['docs_submitted'], '/professional_docs_review_list.php'],
    ['Agendamentos pendentes', $metrics['appointments_pending'], '/appointments_list.php?status=pendente_formulario'],
    ['Contas a receber pendentes', $metrics['ar_pending'], '/finance_receivable_list.php?status=pendente'],
    ['Repasses pendentes', $metrics['ap_pending'], '/finance_payable_list.php?status=pendente'],
];

echo '<section class="card col12">';
echo '<div class="grid">';
foreach ($cards as $c) {
    echo '<div class="col6">';
    echo '<div class="pill" style="display:block;padding:14px">';
    echo '<div style="font-size:12px;color:hsl(var(--muted-foreground))">' . h((string)$c[0]) . '</div>';
    echo '<div style="font-size:28px;font-weight:900;margin-top:6px">' . h((string)$c[1]) . '</div>';
    echo '<div style="margin-top:10px"><a class="btn" href="' . h((string)$c[2]) . '">Abrir</a></div>';
    echo '</div>';
    echo '</div>';
}
echo '</div>';
echo '</section>';

echo '</div>';

view_footer();

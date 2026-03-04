<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();

view_header('Dashboard');

$user = auth_user();

echo '<div class="grid">';
echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:12px;color:rgba(234,240,255,.72);margin-bottom:6px">Etapa 0</div>';
echo '<div style="font-size:22px;font-weight:800">Base do sistema</div>';
echo '<div style="margin-top:6px;color:rgba(234,240,255,.72);font-size:14px;line-height:1.5">Bem-vindo(a), ' . h($user ? (string)$user['name'] : '') . '.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/demands_list.php">Captação</a>';
echo '<a class="btn" href="/chat_list.php">Chat</a>';
echo '<a class="btn" href="/professional_docs_list.php">Minhas Docs</a>';
echo '<a class="btn" href="/professional_docs_review_list.php">Revisão Docs</a>';
echo '<a class="btn" href="/patients_list.php">Pacientes</a>';
echo '<a class="btn" href="/professional_my_patients_list.php">Meus Pacientes</a>';
echo '<a class="btn" href="/appointments_list.php">Agendamentos</a>';
echo '<a class="btn" href="/professional_my_appointments_list.php">Meus Agendamentos</a>';
echo '<a class="btn" href="/finance_receivable_list.php">Financeiro (Receber)</a>';
echo '<a class="btn" href="/finance_payable_list.php">Financeiro (Pagar)</a>';
echo '<a class="btn" href="/documents_list.php">Documentos</a>';
echo '<a class="btn" href="/admin_dashboard.php">Admin</a>';
echo '<a class="btn" href="/admin_settings.php">Config</a>';
echo '<a class="btn" href="/admin_integrations.php">Integrações</a>';
echo '<a class="btn" href="/admin_whatsapp_instances.php">WhatsApp</a>';
echo '<a class="btn" href="/admin_whatsapp_console.php">WhatsApp Console</a>';
echo '<a class="btn" href="/admin_evolution_instances.php">Evolution</a>';
echo '<a class="btn" href="/admin_evolution_console.php">Evolution Console API v1</a>';
echo '<a class="btn" href="/admin_openai_console.php">OpenAI Console</a>';
echo '<a class="btn" href="/admin_zapsign_console.php">ZapSign Console</a>';
echo '<a class="btn" href="/hr_employees_list.php">RH</a>';
echo '<a class="btn" href="/tech_logs_list.php">Logs TI</a>';
echo '<a class="btn" href="/integration_jobs_list.php">Jobs</a>';
echo '<a class="btn" href="/patient_access_logs_list.php">Acessos</a>';
echo '<a class="btn" href="/backup_runs_list.php">Backups</a>';
echo '<a class="btn" href="/reports_dashboard.php">Relatórios</a>';
echo '<a class="btn" href="/users_list.php">Usuários</a>';
echo '<a class="btn" href="/roles_list.php">Perfis</a>';
echo '<a class="btn" href="/permissions_list.php">Permissões</a>';
echo '<a class="btn" href="/professional_applications_list.php">Candidaturas</a>';
echo '</div>';
echo '</div>';
echo '</section>';

echo '<section class="card col12">';
echo '<div style="font-weight:800;margin-bottom:6px">Próximos passos</div>';
echo '<div style="color:rgba(234,240,255,.72);font-size:14px;line-height:1.6">';
echo '1) Rodar a migration .SQL no phpMyAdmin (RBAC + auditoria).<br>'; 
echo '2) Criar o usuário Admin inicial.<br>';
echo '3) Ativar o CRUD de Perfis/Permissões.';
echo '</div>';
echo '</section>';

echo '</div>';

view_footer();

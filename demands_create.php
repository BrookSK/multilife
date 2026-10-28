<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

demand_form_ensure_schema();

// Se o POST voltou com erro, reaproveita o que foi digitado (não perde os dados).
$old = $_SESSION['demand_form_old'] ?? [];
unset($_SESSION['demand_form_old']);

view_header('Novo card');

echo '<div class="card">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900;margin-bottom:6px">Novo card de captação</div>';
echo '<div style="color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Preencha os dados do atendimento. Rua, bairro, cidade/UF, especialidade, frequência e quadro clínico são usados na mensagem enviada aos grupos de WhatsApp. Sem especialidade, cidade e UF (e zona em São Paulo/Rio) o card fica em Tratamento Manual.</div>';
echo '</div>';
echo '<a class="btn" href="/demands_list.php">Voltar</a>';
echo '</div>';
echo '<div style="height:14px"></div>';

demand_form_render(is_array($old) ? $old : [], '/demands_create_post.php', [
    'submit' => 'Salvar card',
    'cancel_url' => '/demands_list.php',
]);

echo '</div>';

view_footer();

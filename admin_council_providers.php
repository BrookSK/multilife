<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/council_validator.php';

auth_require_login();
rbac_require_permission('professional_applications.manage');

$stats     = council_validation_stats();
$providers = council_get_providers_info();

view_header('Validação de Registros — Provedores');

echo '<div class="grid">';

// Cabeçalho
echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Validação de Registros Profissionais</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.5">Configuração de provedores de API e estatísticas de consultas.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/admin_settings.php">Configurações</a>';
echo '<a class="btn" href="/admin_council_logs.php">Ver Logs</a>';
echo '</div>';
echo '</div>';
echo '</section>';

// Estatísticas gerais
echo '<section class="card col4">';
echo '<div style="font-size:13px;color:hsl(var(--muted-foreground))">Total de Consultas</div>';
echo '<div style="font-size:28px;font-weight:900;margin-top:4px">' . number_format($stats['total_queries']) . '</div>';
echo '</section>';

echo '<section class="card col4">';
echo '<div style="font-size:13px;color:hsl(var(--muted-foreground))">Registros Válidos</div>';
echo '<div style="font-size:28px;font-weight:900;margin-top:4px;color:hsl(142 71% 45%)">' . number_format($stats['valid_results']) . '</div>';
echo '</section>';

echo '<section class="card col4">';
echo '<div style="font-size:13px;color:hsl(var(--muted-foreground))">Erros</div>';
echo '<div style="font-size:28px;font-weight:900;margin-top:4px;color:hsl(0 72% 51%)">' . number_format($stats['error_results']) . '</div>';
echo '</section>';

// Provedores configurados
echo '<section class="card col12">';
echo '<div style="font-weight:900;margin-bottom:12px">Provedores Configurados</div>';
echo '<div style="overflow:auto">';
echo '<table>';
echo '<thead><tr>';
echo '<th>Provedor</th><th>Status</th><th>Prioridade</th><th>Conselhos</th>';
echo '</tr></thead><tbody>';

foreach ($providers as $p) {
    $statusBadge = $p['configured']
        ? '<span style="background:hsl(142 71% 45%);color:#fff;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:700">Ativo</span>'
        : '<span style="background:hsl(var(--muted));color:hsl(var(--muted-foreground));padding:3px 10px;border-radius:12px;font-size:12px;font-weight:700">Inativo</span>';

    echo '<tr>';
    echo '<td style="font-weight:700">' . h($p['name']) . '</td>';
    echo '<td>' . $statusBadge . '</td>';
    echo '<td>' . (int)$p['priority'] . '</td>';
    echo '<td>' . h(implode(', ', $p['councils'])) . '</td>';
    echo '</tr>';
}

echo '</tbody></table>';
echo '</div>';
echo '<div style="margin-top:12px;font-size:13px;color:hsl(var(--muted-foreground))">';
echo 'O sistema tenta os provedores em ordem de prioridade (menor = primeiro). Se um provedor falhar, o próximo é tentado automaticamente.';
echo '</div>';
echo '</section>';

// Estatísticas por provedor
if (!empty($stats['by_provider'])) {
    echo '<section class="card col12">';
    echo '<div style="font-weight:900;margin-bottom:12px">Desempenho por Provedor</div>';
    echo '<div style="overflow:auto">';
    echo '<table>';
    echo '<thead><tr>';
    echo '<th>Provedor</th><th>Consultas</th><th>Válidos</th><th>Tempo Médio</th>';
    echo '</tr></thead><tbody>';

    foreach ($stats['by_provider'] as $bp) {
        $avgTime = $bp['avg_time_ms'] !== null ? number_format((float)$bp['avg_time_ms'], 0) . 'ms' : '-';
        echo '<tr>';
        echo '<td style="font-weight:700">' . h((string)($bp['provider_used'] ?? '-')) . '</td>';
        echo '<td>' . number_format((int)$bp['total']) . '</td>';
        echo '<td>' . number_format((int)($bp['valid_count'] ?? 0)) . '</td>';
        echo '<td>' . h($avgTime) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
    echo '</section>';
}

// Estatísticas por conselho
if (!empty($stats['by_council'])) {
    echo '<section class="card col12">';
    echo '<div style="font-weight:900;margin-bottom:12px">Consultas por Conselho</div>';
    echo '<div style="overflow:auto">';
    echo '<table>';
    echo '<thead><tr>';
    echo '<th>Conselho</th><th>Total</th><th>Válidos</th><th>Erros</th>';
    echo '</tr></thead><tbody>';

    foreach ($stats['by_council'] as $bc) {
        echo '<tr>';
        echo '<td style="font-weight:700">' . h((string)$bc['council_abbr']) . '</td>';
        echo '<td>' . number_format((int)$bc['total']) . '</td>';
        echo '<td style="color:hsl(142 71% 45%)">' . number_format((int)($bc['valid_count'] ?? 0)) . '</td>';
        echo '<td style="color:hsl(0 72% 51%)">' . number_format((int)($bc['error_count'] ?? 0)) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
    echo '</section>';
}

// Última consulta
if ($stats['last_query'] !== null) {
    $last = $stats['last_query'];
    echo '<section class="card col12">';
    echo '<div style="font-weight:900;margin-bottom:12px">Última Consulta Realizada</div>';
    echo '<div style="display:grid;gap:6px;font-size:14px">';
    echo '<div><strong>Conselho:</strong> ' . h((string)($last['council_abbr'] ?? '')) . ' ' . h((string)($last['registry_number'] ?? '')) . '/' . h((string)($last['council_state'] ?? '')) . '</div>';
    echo '<div><strong>Resultado:</strong> ' . ((int)($last['valid'] ?? 0) ? '✓ Válido' : '✗ Não válido') . '</div>';
    echo '<div><strong>Nome:</strong> ' . h((string)($last['name_found'] ?? '-')) . '</div>';
    echo '<div><strong>Provedor:</strong> ' . h((string)($last['provider_used'] ?? $last['source'] ?? '-')) . '</div>';
    echo '<div><strong>Tempo:</strong> ' . ((int)($last['response_time_ms'] ?? 0) > 0 ? (int)$last['response_time_ms'] . 'ms' : '-') . '</div>';
    echo '<div><strong>Data:</strong> ' . h((string)($last['created_at'] ?? '-')) . '</div>';
    if (!empty($last['error_message'])) {
        echo '<div><strong>Erro:</strong> ' . h((string)$last['error_message']) . '</div>';
    }
    echo '</div>';
    echo '</section>';
}

// Instruções de configuração
echo '<section class="card col12">';
echo '<div style="font-weight:900;margin-bottom:12px">Como Configurar Provedores</div>';
echo '<div style="font-size:14px;line-height:1.8;color:hsl(var(--muted-foreground))">';
echo '<p>Para ativar um provedor de API, configure as seguintes chaves em <strong>Configurações &gt; Admin Settings</strong>:</p>';
echo '<div style="margin:12px 0;padding:12px;background:hsl(var(--muted));border-radius:8px;font-family:monospace;font-size:13px">';
echo '<strong>Consultar.IO:</strong><br>';
echo '&nbsp;&nbsp;council_provider.consultario.api_key = SUA_CHAVE<br>';
echo '&nbsp;&nbsp;council_provider.consultario.enabled = 1<br>';
echo '&nbsp;&nbsp;council_provider.consultario.priority = 10<br>';
echo '<br>';
echo '<strong>Infosimples:</strong><br>';
echo '&nbsp;&nbsp;council_provider.infosimples.api_token = SEU_TOKEN<br>';
echo '&nbsp;&nbsp;council_provider.infosimples.enabled = 1<br>';
echo '&nbsp;&nbsp;council_provider.infosimples.priority = 20<br>';
echo '<br>';
echo '<strong>Portal Direto (fallback):</strong><br>';
echo '&nbsp;&nbsp;council_provider.portal_direct.enabled = 1<br>';
echo '&nbsp;&nbsp;council_provider.portal_direct.priority = 99<br>';
echo '</div>';
echo '<p>O sistema tentará os provedores em ordem de prioridade. Se o primeiro falhar, tenta o próximo automaticamente.</p>';
echo '</div>';
echo '</section>';

echo '</div>';

view_footer();

<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

$stmt = db()->query('SELECT * FROM specialties ORDER BY name ASC');
$rows = $stmt->fetchAll();

view_header('Especialidades');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Especialidades</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Gerenciar especialidades disponíveis no sistema.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn btnPrimary" href="/specialties_create.php">Nova especialidade</a>';
echo '<a class="btn" href="/admin_settings.php">Voltar</a>';
echo '</div>';
echo '</div>';
echo '</section>';

echo '<section class="card col12">';
echo '<div style="overflow:auto">';
echo '<table>';
echo '<thead><tr>';
echo '<th>ID</th><th>Nome</th><th>Valor Mínimo (R$)</th><th>Status</th><th style="text-align:right">Ações</th>';
echo '</tr></thead><tbody>';

foreach ($rows as $r) {
    echo '<tr>';
    echo '<td>' . (int)$r['id'] . '</td>';
    echo '<td style="font-weight:700">' . h((string)$r['name']) . '</td>';
    echo '<td>R$ ' . number_format((float)($r['minimum_value'] ?? 0), 2, ',', '.') . '</td>';
    echo '<td>' . h((string)$r['status']) . '</td>';
    echo '<td style="text-align:right">';
    echo '<a class="btn btnPrimary" href="/specialty_services.php?id=' . (int)$r['id'] . '" title="Gerenciar tipos de serviço e valores">⚙️ Serviços</a> ';
    echo '<a class="btn" href="/specialties_edit.php?id=' . (int)$r['id'] . '">Editar</a> ';
    echo '<form method="post" action="/specialties_delete_post.php" style="display:inline">';
    echo '<input type="hidden" name="id" value="' . (int)$r['id'] . '">';
    echo '<button class="btn" type="submit" onclick="return confirm(\'Excluir esta especialidade?\')">Excluir</button>';
    echo '</form>';
    echo '</td>';
    echo '</tr>';
}

if (count($rows) === 0) {
    echo '<tr><td colspan="5" class="pill" style="display:table-cell;padding:12px">Nenhuma especialidade cadastrada.</td></tr>';
}

echo '</tbody></table>';
echo '</div>';
echo '</section>';

echo '</div>';

view_footer();

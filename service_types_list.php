<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

// Buscar todos os tipos de serviço
$stmt = db()->query("SELECT * FROM service_types ORDER BY display_order, name ASC");
$serviceTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);

view_header('Tipos de Serviço');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Tipos de Serviço</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Gerenciar tipos de serviço disponíveis no sistema.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn btnPrimary" href="/service_type_create_form.php">Novo Tipo de Serviço</a>';
echo '<a class="btn" href="/admin_settings.php">Voltar</a>';
echo '</div>';
echo '</div>';
echo '</section>';

echo '<section class="card col12">';
echo '<div style="overflow:auto">';
echo '<table>';
echo '<thead><tr>';
echo '<th>ID</th><th>Nome</th><th>Descrição</th><th>Ordem</th><th>Status</th><th style="text-align:right">Ações</th>';
echo '</tr></thead><tbody>';

foreach ($serviceTypes as $st) {
    echo '<tr>';
    echo '<td>' . (int)$st['id'] . '</td>';
    echo '<td style="font-weight:700">' . h((string)$st['name']) . '</td>';
    echo '<td>' . h((string)$st['description']) . '</td>';
    echo '<td>' . (int)$st['display_order'] . '</td>';
    echo '<td>' . h((string)$st['status']) . '</td>';
    echo '<td style="text-align:right">';
    echo '<a class="btn" href="/service_type_edit.php?id=' . (int)$st['id'] . '">Editar</a> ';
    echo '<form method="post" action="/service_type_delete.php" style="display:inline">';
    echo '<input type="hidden" name="id" value="' . (int)$st['id'] . '">';
    echo '<button class="btn" type="submit" onclick="return confirm(\'Excluir este tipo de serviço?\')">Excluir</button>';
    echo '</form>';
    echo '</td>';
    echo '</tr>';
}

if (count($serviceTypes) === 0) {
    echo '<tr><td colspan="6" class="pill" style="display:table-cell;padding:12px">Nenhum tipo de serviço cadastrado.</td></tr>';
}

echo '</tbody></table>';
echo '</div>';
echo '</section>';

echo '</div>';

view_footer();

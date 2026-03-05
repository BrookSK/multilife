<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('appointments.manage');

$status = isset($_GET['status']) ? (string)$_GET['status'] : 'pending';
if (!in_array($status, ['pending','approved','rejected','all'], true)) {
    $status = 'pending';
}

$sql = 'SELECT a.*, u.name AS requested_by_name, ur.name AS reviewed_by_name
        FROM appointment_value_authorizations a
        LEFT JOIN users u ON u.id = a.requested_by_user_id
        LEFT JOIN users ur ON ur.id = a.reviewed_by_user_id
        WHERE 1=1';
$params = [];

if ($status !== 'all') {
    $sql .= ' AND a.status = :st';
    $params['st'] = $status;
}

$sql .= ' ORDER BY a.requested_at DESC, a.id DESC LIMIT 300';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

view_header('Autorizações - Valor mínimo');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Autorizações de valor</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Solicitações quando o valor por sessão está abaixo do mínimo por especialidade.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/specialty_minimums_list.php">Mínimos</a>';
echo '<a class="btn" href="/admin_settings.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<form method="get" action="/appointment_value_authorizations_list.php" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">';
echo '<select name="status" style="min-width:220px">';
foreach (['pending' => 'Pendentes', 'approved' => 'Aprovadas', 'rejected' => 'Rejeitadas', 'all' => 'Todas'] as $k => $lab) {
    echo '<option value="' . h($k) . '"' . ($status === $k ? ' selected' : '') . '>' . h($lab) . '</option>';
}

echo '</select>';
echo '<button class="btn" type="submit">Filtrar</button>';
echo '</form>';

echo '</section>';

echo '<section class="card col12">';
echo '<div style="overflow:auto">';
echo '<table>';
echo '<thead><tr><th>ID</th><th>Status</th><th>Especialidade</th><th>Solicitado</th><th>Mínimo</th><th>Paciente</th><th>Profissional</th><th>1º atendimento</th><th>Solicitado por</th><th style="text-align:right">Ações</th></tr></thead><tbody>';
foreach ($rows as $r) {
    echo '<tr>';
    echo '<td>' . (int)$r['id'] . '</td>';
    echo '<td>' . h((string)$r['status']) . '</td>';
    echo '<td>' . h((string)$r['specialty']) . '</td>';
    echo '<td>' . h((string)$r['requested_value']) . '</td>';
    echo '<td>' . h((string)$r['minimum_value']) . '</td>';
    echo '<td>#' . (int)$r['patient_id'] . '</td>';
    echo '<td>#' . (int)$r['professional_user_id'] . '</td>';
    echo '<td>' . h((string)$r['first_at']) . '</td>';
    echo '<td>' . h((string)($r['requested_by_name'] ?? '-')) . '</td>';
    echo '<td style="text-align:right">';
    echo '<a class="btn" href="/appointment_value_authorizations_view.php?id=' . (int)$r['id'] . '">Ver</a>';
    echo '</td>';
    echo '</tr>';
}

echo '</tbody></table>';
echo '</div>';
echo '</section>';

echo '</div>';

view_footer();

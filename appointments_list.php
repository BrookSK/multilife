<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('appointments.manage');

$status = isset($_GET['status']) ? (string)$_GET['status'] : '';
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

$allowed = ['', 'agendado','pendente_formulario','realizado','atrasado','cancelado','revisao_admin'];
if (!in_array($status, $allowed, true)) {
    $status = '';
}

$sql = 'SELECT a.id, a.first_at, a.recurrence_type, a.value_per_session, a.status,
               p.full_name AS patient_name,
               u.name AS professional_name
        FROM appointments a
        INNER JOIN patients p ON p.id = a.patient_id
        INNER JOIN users u ON u.id = a.professional_user_id
        WHERE p.deleted_at IS NULL';
$params = [];

if ($status !== '') {
    $sql .= ' AND a.status = :status';
    $params['status'] = $status;
}

if ($q !== '') {
    $sql .= ' AND (p.full_name LIKE :q OR u.name LIKE :q OR a.id LIKE :q)';
    $params['q'] = '%' . $q . '%';
}

$sql .= ' ORDER BY a.first_at DESC, a.id DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

view_header('Agendamentos');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Agendamentos</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Criação pelo Captador após confirmação via chat.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn btnPrimary" href="/appointments_create.php">Novo agendamento</a>';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<form method="get" action="/appointments_list.php" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">';
echo '<select name="status" style="min-width:220px">';
$labels = [
    '' => 'Todos',
    'agendado' => 'Agendado',
    'pendente_formulario' => 'Pendente de Formulário',
    'realizado' => 'Realizado',
    'atrasado' => 'Atrasado',
    'cancelado' => 'Cancelado',
    'revisao_admin' => 'Revisão Admin',
];
foreach ($labels as $k => $label) {
    $sel = ($status === $k) ? ' selected' : '';
    echo '<option value="' . h($k) . '"' . $sel . '>' . h($label) . '</option>';
}
echo '</select>';
echo '<input name="q" value="' . h($q) . '" placeholder="Buscar (paciente/profissional)" style="flex:1;min-width:240px">';
echo '<button class="btn" type="submit">Filtrar</button>';
echo '</form>';

echo '</section>';


echo '<section class="card col12">';
echo '<div style="overflow:auto">';
echo '<table>';
echo '<thead><tr>';
echo '<th>ID</th><th>Data/hora</th><th>Paciente</th><th>Profissional</th><th>Recorrência</th><th>Valor</th><th>Status</th><th style="text-align:right">Ações</th>';
echo '</tr></thead><tbody>';
foreach ($rows as $r) {
    echo '<tr>';
    echo '<td>' . (int)$r['id'] . '</td>';
    echo '<td>' . h((string)$r['first_at']) . '</td>';
    echo '<td style="font-weight:700">' . h((string)$r['patient_name']) . '</td>';
    echo '<td>' . h((string)$r['professional_name']) . '</td>';
    echo '<td>' . h((string)$r['recurrence_type']) . '</td>';
    echo '<td>' . h((string)$r['value_per_session']) . '</td>';
    echo '<td>' . h((string)$r['status']) . '</td>';
    echo '<td style="text-align:right">';
    echo '<a class="btn" href="/appointments_view.php?id=' . (int)$r['id'] . '">Abrir</a>';
    echo '</td>';
    echo '</tr>';
}
if (count($rows) === 0) {
    echo '<tr><td colspan="8" class="pill" style="display:table-cell;padding:12px">Sem registros.</td></tr>';
}

echo '</tbody></table>';
echo '</div>';
echo '</section>';

echo '</div>';

view_footer();

<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('appointments.view_linked');

$uid = (int)auth_user_id();
$status = isset($_GET['status']) ? (string)$_GET['status'] : '';

$allowed = ['', 'agendado','pendente_formulario','realizado','atrasado','cancelado','revisao_admin'];
if (!in_array($status, $allowed, true)) {
    $status = '';
}

$sql = 'SELECT a.id, a.first_at, a.recurrence_type, a.status, a.value_per_session,
               p.full_name AS patient_name
        FROM appointments a
        INNER JOIN patients p ON p.id = a.patient_id
        WHERE p.deleted_at IS NULL AND a.professional_user_id = :uid';
$params = ['uid' => $uid];

if ($status !== '') {
    $sql .= ' AND a.status = :status';
    $params['status'] = $status;
}

$sql .= ' ORDER BY a.first_at DESC, a.id DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

view_header('Meus agendamentos');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:800">Meus agendamentos</div>';
echo '<div style="margin-top:6px;color:rgba(234,240,255,.72);font-size:14px;line-height:1.6">Apenas seus agendamentos.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<form method="get" action="/professional_my_appointments_list.php" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">';
echo '<select name="status" style="border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:10px 12px;outline:none;font-size:14px">';
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
echo '<button class="btn" type="submit">Filtrar</button>';
echo '</form>';

echo '</section>';

echo '<section class="card col12">';
echo '<div style="overflow:auto">';
echo '<table style="width:100%;border-collapse:separate;border-spacing:0 10px">';
echo '<thead><tr style="text-align:left;color:rgba(234,240,255,.72);font-size:12px">';
echo '<th>ID</th><th>Data/hora</th><th>Paciente</th><th>Recorrência</th><th>Status</th><th style="text-align:right">Ações</th>';
echo '</tr></thead><tbody>';
foreach ($rows as $r) {
    echo '<tr style="background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.10)">';
    echo '<td style="padding:12px;border-top-left-radius:14px;border-bottom-left-radius:14px">' . (int)$r['id'] . '</td>';
    echo '<td style="padding:12px">' . h((string)$r['first_at']) . '</td>';
    echo '<td style="padding:12px">' . h((string)$r['patient_name']) . '</td>';
    echo '<td style="padding:12px">' . h((string)$r['recurrence_type']) . '</td>';
    echo '<td style="padding:12px">' . h((string)$r['status']) . '</td>';
    echo '<td style="padding:12px;border-top-right-radius:14px;border-bottom-right-radius:14px;text-align:right">';
    echo '<a class="btn" href="/professional_docs_list.php">Abrir Docs</a>';
    echo '</td>';
    echo '</tr>';
}
if (count($rows) === 0) {
    echo '<tr><td colspan="6" class="pill" style="display:table-cell;padding:12px">Sem registros.</td></tr>';
}

echo '</tbody></table>';
echo '</div>';
echo '</section>';

echo '</div>';

view_footer();

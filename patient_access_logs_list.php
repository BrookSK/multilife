<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('patient_access_logs.view');

$patientId = isset($_GET['patient_id']) ? trim((string)$_GET['patient_id']) : '';
$userId = isset($_GET['user_id']) ? trim((string)$_GET['user_id']) : '';

$sql = 'SELECT l.id, l.patient_id, l.user_id, l.action, l.ip_address, l.created_at,
               p.full_name AS patient_name,
               u.name AS user_name
        FROM patient_access_logs l
        INNER JOIN patients p ON p.id = l.patient_id
        INNER JOIN users u ON u.id = l.user_id
        WHERE p.deleted_at IS NULL';
$params = [];

if ($patientId !== '' && ctype_digit($patientId)) {
    $sql .= ' AND l.patient_id = :pid';
    $params['pid'] = (int)$patientId;
}

if ($userId !== '' && ctype_digit($userId)) {
    $sql .= ' AND l.user_id = :uid';
    $params['uid'] = (int)$userId;
}

$sql .= ' ORDER BY l.id DESC LIMIT 500';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

view_header('Acessos a prontuário');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:800">Logs de Acesso a Prontuário</div>';
echo '<div style="margin-top:6px;color:rgba(234,240,255,.72);font-size:14px;line-height:1.6">Registra abertura do prontuário por usuário.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/backup_runs_list.php">Backups</a>';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';

echo '<form method="get" action="/patient_access_logs_list.php" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">';
echo '<input name="patient_id" value="' . h($patientId) . '" placeholder="Patient ID" style="width:160px;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:10px 12px;outline:none;font-size:14px">';
echo '<input name="user_id" value="' . h($userId) . '" placeholder="User ID" style="width:160px;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:10px 12px;outline:none;font-size:14px">';
echo '<button class="btn" type="submit">Filtrar</button>';
echo '</form>';

echo '</section>';

echo '<section class="card col12">';
echo '<div style="overflow:auto">';
echo '<table style="width:100%;border-collapse:separate;border-spacing:0 10px">';
echo '<thead><tr style="text-align:left;color:rgba(234,240,255,.72);font-size:12px">';
echo '<th>ID</th><th>Quando</th><th>Paciente</th><th>Usuário</th><th>Ação</th><th>IP</th>';
echo '</tr></thead><tbody>';
foreach ($rows as $r) {
    echo '<tr style="background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.10)">';
    echo '<td style="padding:12px;border-top-left-radius:14px;border-bottom-left-radius:14px">' . (int)$r['id'] . '</td>';
    echo '<td style="padding:12px">' . h((string)$r['created_at']) . '</td>';
    echo '<td style="padding:12px">' . h((string)$r['patient_name']) . ' (#' . (int)$r['patient_id'] . ')</td>';
    echo '<td style="padding:12px">' . h((string)$r['user_name']) . ' (#' . (int)$r['user_id'] . ')</td>';
    echo '<td style="padding:12px">' . h((string)$r['action']) . '</td>';
    echo '<td style="padding:12px;border-top-right-radius:14px;border-bottom-right-radius:14px">' . h((string)($r['ip_address'] ?? '')) . '</td>';
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

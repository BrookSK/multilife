<?php
/**
 * DIAGNÓSTICO: mostra as roles do usuário logado e os assignments dele.
 * Acesse logado: https://multilife.onsolutionsbrasil.com.br/check_my_roles.php
 * DELETE após uso!
 */
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
auth_require_login();

header('Content-Type: text/html; charset=utf-8');

$uid = (int)(auth_user_id() ?? 0);
$user = auth_user();

echo '<h2>Diagnóstico de acesso — Captação</h2>';
echo '<p><strong>Usuário:</strong> ' . htmlspecialchars($user['name'] ?? '') . ' (ID ' . $uid . ')</p>';
echo '<p><strong>E-mail:</strong> ' . htmlspecialchars($user['email'] ?? '') . '</p>';

$roles = rbac_user_roles($uid);
echo '<p><strong>Roles:</strong> ' . htmlspecialchars(implode(', ', $roles) ?: '(nenhuma)') . '</p>';

$hasFullAccess = !empty(array_intersect($roles, ['admin', 'ti']));
$isProfessional = in_array('profissional', $roles, true);
echo '<p><strong>Tem visão total (admin/ti)?</strong> ' . ($hasFullAccess ? 'SIM' : 'NÃO') . '</p>';
echo '<p><strong>É profissional?</strong> ' . ($isProfessional ? 'SIM' : 'NÃO') . '</p>';
echo '<p><strong>Filtro de profissional será aplicado?</strong> ' . (($isProfessional && !$hasFullAccess) ? 'SIM (verá só os dele)' : 'NÃO (verá tudo)') . '</p>';

echo '<hr><h3>Assignments atribuídos a este usuário (professional_user_id = ' . $uid . ')</h3>';
$stmt = db()->prepare("
    SELECT pa.id, pa.demand_id, p.full_name AS paciente, pa.specialty, pa.status
    FROM patient_assignments pa
    LEFT JOIN patients p ON p.id = pa.patient_id
    WHERE pa.professional_user_id = ?
    ORDER BY pa.id DESC
");
$stmt->execute([$uid]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) {
    echo '<p style="color:red">Nenhum assignment atribuído a este usuário. Por isso o kanban ficaria vazio se o filtro fosse aplicado.</p>';
} else {
    echo '<table border="1" cellpadding="4"><tr><th>Assignment ID</th><th>Demand ID</th><th>Paciente</th><th>Especialidade</th><th>Status</th></tr>';
    foreach ($rows as $r) {
        echo '<tr><td>' . $r['id'] . '</td><td>' . $r['demand_id'] . '</td><td>' . htmlspecialchars($r['paciente'] ?? '') . '</td><td>' . htmlspecialchars($r['specialty'] ?? '') . '</td><td>' . htmlspecialchars($r['status'] ?? '') . '</td></tr>';
    }
    echo '</table>';
}

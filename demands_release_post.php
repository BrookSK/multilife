<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

$id = (int)($_POST['id'] ?? 0);
$uid = auth_user_id();

$stmt = db()->prepare('SELECT id, status, assumed_by_user_id FROM demands WHERE id = :id');
$stmt->execute(['id' => $id]);
$d = $stmt->fetch();

if (!$d) {
    flash_set('error', 'Demanda não encontrada.');
    header('Location: /demands_list.php');
    exit;
}

if ($d['assumed_by_user_id'] === null) {
    page_history_log(
        '/demands_view.php?id=' . $id,
        'Demanda',
        'release',
        'Liberou a demanda',
        'demand',
        $id
    );
    flash_set('success', 'Demanda já estava no pool.');
    header('Location: /demands_view.php?id=' . $id);
    exit;
}

if ((int)$d['assumed_by_user_id'] !== (int)$uid && !rbac_user_has_role((int)$uid, 'admin')) {
    flash_set('error', 'Apenas o responsável ou Admin pode devolver.');
    header('Location: /demands_view.php?id=' . $id);
    exit;
}

$stmt = db()->prepare('UPDATE demands SET assumed_by_user_id = NULL, assumed_at = NULL, status = IF(status = \'em_captacao\', \'aguardando_captacao\', status) WHERE id = :id');
$stmt->execute(['id' => $id]);

audit_log('update', 'demands_release', (string)$id, ['assumed_by_user_id' => $d['assumed_by_user_id']], ['assumed_by_user_id' => null]);

flash_set('success', 'Demanda devolvida ao pool.');
header('Location: /demands_view.php?id=' . $id);
exit;

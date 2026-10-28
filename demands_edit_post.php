<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

demand_form_ensure_schema();

$id = (int)($_POST['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM demands WHERE id = :id');
$stmt->execute(['id' => $id]);
$old = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$old) {
    flash_set('error', 'Demanda não encontrada.');
    header('Location: /demands_list.php');
    exit;
}

$parsed = demand_form_from_post($_POST);
$data = $parsed['data'];
$errors = $parsed['errors'];
if ($data['patient_name'] === '') {
    $errors[] = 'Informe o nome do paciente.';
}
if ($errors) {
    $_SESSION['demand_form_old'] = $data;
    flash_set('error', implode(' ', $errors));
    header('Location: /demands_edit.php?id=' . $id);
    exit;
}

// Transição automática entre "Recebimento" e "Tratamento Manual" conforme o que falta.
$oldStatus = (string)$old['status'];
$missing = demand_missing_fields($data);
$newStatus = null;
if ($oldStatus === 'tratamento_manual' && !$missing['blocking']) {
    $newStatus = 'aguardando_captacao';
} elseif ($oldStatus === 'aguardando_captacao' && $missing['blocking']) {
    $newStatus = 'tratamento_manual';
}

$db = db();
$db->beginTransaction();
try {
    demand_form_persist($data, $id, $newStatus);
    if ($newStatus !== null) {
        $note = $newStatus === 'aguardando_captacao'
            ? 'dados completados na edição — liberado para captação'
            : 'edição deixou o card incompleto (faltando ' . implode(', ', $missing['blocking']) . ')';
        $db->prepare('INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (:did, :os, :ns, :uid, :note)')
            ->execute(['did' => $id, 'os' => $oldStatus, 'ns' => $newStatus, 'uid' => auth_user_id(), 'note' => mb_strimwidth($note, 0, 255, '…')]);
    }
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[DEMANDS_EDIT] ' . $e->getMessage());
    $_SESSION['demand_form_old'] = $data;
    flash_set('error', 'Erro ao salvar: ' . $e->getMessage());
    header('Location: /demands_edit.php?id=' . $id);
    exit;
}

$changed = [];
foreach ($data as $k => $v) {
    if (array_key_exists($k, $old) && (string)($old[$k] ?? '') !== (string)($v ?? '')) {
        $changed[$k] = $v;
    }
}
audit_log('update', 'demands', (string)$id, array_intersect_key($old, $changed), $changed);
page_history_log('/demands_view.php?id=' . $id, 'Demanda', 'update', 'Atualizou demanda: ' . $data['title'], 'demand', $id);

if ($newStatus === 'aguardando_captacao') {
    flash_set('success', 'Card atualizado e liberado para captação.');
} elseif ($missing['blocking']) {
    flash_set('success', 'Card atualizado. Ainda falta: ' . implode(', ', $missing['blocking']) . '.');
} else {
    flash_set('success', 'Card atualizado.');
}
header('Location: /demands_view.php?id=' . $id);
exit;

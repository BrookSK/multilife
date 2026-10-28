<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

demand_form_ensure_schema();

$parsed = demand_form_from_post($_POST);
$data = $parsed['data'];
$errors = $parsed['errors'];

if ($data['patient_name'] === '') {
    $errors[] = 'Informe o nome do paciente.';
}

if ($errors) {
    $_SESSION['demand_form_old'] = $data;
    flash_set('error', implode(' ', $errors));
    header('Location: /demands_create.php');
    exit;
}

// Status inicial: só vai para captação se tiver o mínimo para disparar.
$missing = demand_missing_fields($data);
$status = $missing['blocking'] ? 'tratamento_manual' : 'aguardando_captacao';

$db = db();
$db->beginTransaction();
try {
    $id = demand_form_persist($data, null, $status);

    $note = 'criação manual';
    if ($missing['blocking']) {
        $note .= ' (tratamento_manual: faltando ' . implode(', ', $missing['blocking']) . ')';
    }
    $db->prepare('INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (:did, NULL, :ns, :uid, :note)')
        ->execute(['did' => $id, 'ns' => $status, 'uid' => auth_user_id(), 'note' => mb_strimwidth($note, 0, 255, '…')]);

    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[DEMANDS_CREATE] ' . $e->getMessage());
    $_SESSION['demand_form_old'] = $data;
    flash_set('error', 'Erro ao salvar o card: ' . $e->getMessage());
    header('Location: /demands_create.php');
    exit;
}

audit_log('create', 'demands', (string)$id, null, ['title' => $data['title'], 'status' => $status]);
page_history_log('/demands_list.php', 'Captação', 'create', 'Criou nova demanda: ' . $data['title'], 'demand', $id);

if ($missing['blocking']) {
    flash_set('success', 'Card criado em Tratamento Manual. Falta: ' . implode(', ', $missing['blocking']) . '.');
} else {
    flash_set('success', 'Card criado e pronto para captação.');
}
header('Location: /demands_view.php?id=' . $id);
exit;

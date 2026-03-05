<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('professional_docs.submit');

$uid = (int)auth_user_id();
$id = (int)($_POST['id'] ?? 0);

$stmt = db()->prepare('SELECT * FROM professional_documentations WHERE id = :id AND professional_user_id = :uid');
$stmt->execute(['id' => $id, 'uid' => $uid]);
$d = $stmt->fetch();

if (!$d) {
    flash_set('error', 'Formulário não encontrado.');
    header('Location: /professional_docs_list.php');
    exit;
}

if ((string)$d['status'] !== 'draft') {
    flash_set('error', 'Apenas rascunhos podem ser enviados.');
    header('Location: /professional_docs_edit.php?id=' . $id);
    exit;
}

$db = db();
$db->beginTransaction();
try {
    $stmt = $db->prepare('UPDATE professional_documentations SET status = \'submitted\', submitted_at = NOW() WHERE id = :id');
    $stmt->execute(['id' => $id]);

    // Pendência imediata para revisão (Admin/Financeiro)
    $stmt = $db->prepare(
        "SELECT id FROM pending_items\n"
        . "WHERE status = 'open' AND type = 'professional_docs_review'\n"
        . "  AND related_table = 'professional_documentations' AND related_id = :rid\n"
        . "LIMIT 1"
    );
    $stmt->execute(['rid' => $id]);
    if (!$stmt->fetch()) {
        $title = 'Revisar documentação (Doc #' . $id . ')';
        $detail = 'Paciente: ' . (string)($d['patient_ref'] ?? '') . ' | Sessões: ' . (string)($d['sessions_count'] ?? '1');
        $stmt = $db->prepare(
            "INSERT INTO pending_items (type, status, title, detail, related_table, related_id, assigned_user_id)"
            . " VALUES ('professional_docs_review','open',:title,:detail,'professional_documentations',:rid,NULL)"
        );
        $stmt->execute([
            'title' => $title,
            'detail' => mb_strimwidth($detail, 0, 240, '...'),
            'rid' => $id,
        ]);
    }

    audit_log('update', 'professional_documentations_submit', (string)$id, ['status' => (string)$d['status']], ['status' => 'submitted']);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

flash_set('success', 'Formulário enviado para revisão.');
header('Location: /professional_docs_list.php?status=submitted');
exit;

<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('chat.manage');

$id = (int)($_POST['id'] ?? 0);
$kind = trim((string)($_POST['kind'] ?? ''));
$refId = (int)($_POST['ref_id'] ?? 0);

if (!in_array($kind, ['patient', 'professional'], true) || $refId <= 0) {
    flash_set('error', 'Vinculação inválida.');
    header('Location: /chat_web.php?id=' . $id);
    exit;
}

$stmt = db()->prepare('SELECT id, external_phone, contact_kind, contact_ref_id FROM chat_conversations WHERE id = :id');
$stmt->execute(['id' => $id]);
$c = $stmt->fetch();

if (!$c) {
    flash_set('error', 'Conversa não encontrada.');
    header('Location: /chat_web.php');
    exit;
}

if ($kind === 'patient') {
    $stmt = db()->prepare('SELECT id FROM patients WHERE id = :id');
    $stmt->execute(['id' => $refId]);
    if (!$stmt->fetch()) {
        flash_set('error', 'Paciente não encontrado.');
        header('Location: /chat_web.php?id=' . $id);
        exit;
    }
}

if ($kind === 'professional') {
    $stmt = db()->prepare('SELECT id FROM professional_applications WHERE id = :id');
    $stmt->execute(['id' => $refId]);
    if (!$stmt->fetch()) {
        flash_set('error', 'Candidatura não encontrada.');
        header('Location: /chat_web.php?id=' . $id);
        exit;
    }
}

$db = db();
$db->beginTransaction();
try {
    $stmt = $db->prepare('UPDATE chat_conversations SET contact_kind = :k, contact_ref_id = :rid WHERE id = :id');
    $stmt->execute(['k' => $kind, 'rid' => $refId, 'id' => $id]);

    audit_log('update', 'chat_conversations', (string)$id, $c, ['contact_kind' => $kind, 'contact_ref_id' => $refId]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

flash_set('success', 'Contato vinculado.');
header('Location: /chat_web.php?id=' . $id);
exit;

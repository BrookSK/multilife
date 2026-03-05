<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('chat.manage');

$demandId = (int)($_POST['demand_id'] ?? 0);
$professionalUserId = (int)($_POST['professional_user_id'] ?? 0);

if ($professionalUserId <= 0) {
    flash_set('error', 'Profissional inválido.');
    header('Location: /demands_list.php');
    exit;
}

$stmt = db()->prepare(
    "SELECT u.id, u.phone\n"
    . "FROM users u\n"
    . "INNER JOIN user_roles ur ON ur.user_id = u.id\n"
    . "INNER JOIN roles r ON r.id = ur.role_id\n"
    . "WHERE u.id = :id AND u.status='active' AND r.slug='profissional'\n"
    . "LIMIT 1"
);
$stmt->execute(['id' => $professionalUserId]);
$u = $stmt->fetch();
if (!$u) {
    flash_set('error', 'Profissional não encontrado/ativo.');
    header('Location: /demands_view.php?id=' . $demandId);
    exit;
}

$phone = preg_replace('/\D+/', '', (string)($u['phone'] ?? ''));
if ($phone === '') {
    flash_set('error', 'Profissional sem telefone cadastrado.');
    header('Location: /demands_view.php?id=' . $demandId);
    exit;
}

$db = db();
$db->beginTransaction();
try {
    // Try find open conversation by phone
    $stmt = $db->prepare('SELECT * FROM chat_conversations WHERE external_phone = :p AND status = \'open\' LIMIT 1');
    $stmt->execute(['p' => $phone]);
    $c = $stmt->fetch();

    if (!$c) {
        $stmt = $db->prepare(
            'INSERT INTO chat_conversations (external_phone, contact_kind, contact_ref_id, status, assigned_user_id) '
            . 'VALUES (:p, :k, :rid, \'open\', :uid)'
        );
        $stmt->execute([
            'p' => $phone,
            'k' => 'professional',
            'rid' => $professionalUserId,
            'uid' => auth_user_id(),
        ]);
        $chatId = (int)$db->lastInsertId();
    } else {
        $chatId = (int)$c['id'];
        // Ensure it is linked to this professional
        $stmt = $db->prepare('UPDATE chat_conversations SET contact_kind = \'professional\', contact_ref_id = :rid WHERE id = :id');
        $stmt->execute(['rid' => $professionalUserId, 'id' => $chatId]);
    }

    audit_log('update', 'chat_open_professional', (string)$chatId, null, ['professional_user_id' => $professionalUserId, 'demand_id' => $demandId]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

$qs = 'id=' . urlencode((string)$chatId);
if ($demandId > 0) {
    $qs .= '&demand_id=' . urlencode((string)$demandId);
}

header('Location: /chat_web.php?' . $qs);
exit;

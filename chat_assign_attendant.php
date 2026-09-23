<?php
/**
 * Atribui (ou remove) o atendente responsável por uma conversa do Chat ao Vivo.
 * Grava chat_contacts.assigned_to_user_id, que é usado pelo filtro "por atendente".
 *
 * Somente administradores podem alterar o atendente de uma conversa.
 *
 * POST:
 *   - chat_id      : JID da conversa (obrigatório)
 *   - attendant_id : ID do usuário atendente (0/vazio = remover atribuição)
 */

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

auth_require_login();
rbac_require_permission('chat.manage');

// Apenas admin pode (re)atribuir atendente.
$currentUserId = auth_user_id();
if (!rbac_user_has_role((int)$currentUserId, 'admin')) {
    echo json_encode(['success' => false, 'error' => 'Apenas administradores podem atribuir atendentes.']);
    exit;
}

$chatId = isset($_POST['chat_id']) ? trim((string)$_POST['chat_id']) : '';
$attendantId = isset($_POST['attendant_id']) ? (int)$_POST['attendant_id'] : 0;

if ($chatId === '') {
    echo json_encode(['success' => false, 'error' => 'Chat ID não informado.']);
    exit;
}

// Validar que o atendente informado realmente pode atender o chat.
if ($attendantId > 0) {
    $validIds = array_map(static fn($a) => (int)$a['id'], chat_list_attendants());
    if (!in_array($attendantId, $validIds, true)) {
        echo json_encode(['success' => false, 'error' => 'Atendente inválido.']);
        exit;
    }
}

// Normalizar JID (mesma lógica do chat_web.php / webhook)
function attendantNormalizeJid(string $jid): string
{
    $numberOnly = preg_replace('/@(s\.whatsapp\.net|g\.us|lid|c\.us|broadcast)$/', '', $jid);
    if (strpos($jid, '@g.us') !== false) {
        return $numberOnly . '@g.us';
    }
    return $numberOnly . '@s.whatsapp.net';
}

try {
    $db = db();

    // Garantir coluna assigned_to_user_id
    try {
        $col = $db->query("SHOW COLUMNS FROM chat_contacts LIKE 'assigned_to_user_id'")->fetch();
        if (!$col) {
            $db->exec("ALTER TABLE chat_contacts ADD COLUMN assigned_to_user_id INT UNSIGNED DEFAULT NULL");
        }
    } catch (Throwable $e) {
        // ignora - o UPDATE abaixo reporta se falhar
    }

    $normalizedJid = attendantNormalizeJid($chatId);
    $valueToSave = $attendantId > 0 ? $attendantId : null;

    $stmt = $db->prepare("
        UPDATE chat_contacts
        SET assigned_to_user_id = :uid, updated_at = CURRENT_TIMESTAMP
        WHERE remote_jid = :jid1 OR remote_jid = :jid2
    ");
    $stmt->execute([
        'uid' => $valueToSave,
        'jid1' => $normalizedJid,
        'jid2' => $chatId,
    ]);

    // Criar o contato se ainda não existir
    if ($stmt->rowCount() === 0) {
        $stmtInsert = $db->prepare("
            INSERT INTO chat_contacts (remote_jid, assigned_to_user_id)
            VALUES (:jid, :uid)
            ON DUPLICATE KEY UPDATE assigned_to_user_id = VALUES(assigned_to_user_id), updated_at = CURRENT_TIMESTAMP
        ");
        $stmtInsert->execute([
            'jid' => $normalizedJid,
            'uid' => $valueToSave,
        ]);
    }

    audit_log('update', 'chat_contacts', $normalizedJid, null, ['assigned_to_user_id' => $valueToSave]);

    echo json_encode(['success' => true, 'attendant_id' => $valueToSave ?? 0]);
} catch (Throwable $e) {
    error_log('[CHAT_ASSIGN_ATTENDANT] Erro: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

<?php
/**
 * Define/edita um nome personalizado ("apelido de agenda") para um contato do
 * Chat ao Vivo. O nome fica salvo em chat_contacts.custom_name e passa a ter
 * prioridade na exibição e na busca, funcionando como uma agenda de contatos.
 *
 * POST:
 *   - chat_id      : JID da conversa (obrigatório)
 *   - custom_name  : nome desejado (vazio = remover o nome personalizado)
 */

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

auth_require_login();
rbac_require_permission('chat.manage');

$chatId = isset($_POST['chat_id']) ? trim((string)$_POST['chat_id']) : '';
$customName = isset($_POST['custom_name']) ? trim((string)$_POST['custom_name']) : '';

if ($chatId === '') {
    echo json_encode(['success' => false, 'error' => 'Chat ID não informado.']);
    exit;
}

// Limitar tamanho para caber na coluna e evitar abuso
if (mb_strlen($customName) > 100) {
    $customName = mb_substr($customName, 0, 100);
}

// Normalizar JID (mesma lógica do chat_web.php / webhook)
function contactNameNormalizeJid(string $jid): string
{
    $numberOnly = preg_replace('/@(s\.whatsapp\.net|g\.us|lid|c\.us|broadcast)$/', '', $jid);
    if (strpos($jid, '@g.us') !== false) {
        return $numberOnly . '@g.us';
    }
    return $numberOnly . '@s.whatsapp.net';
}

try {
    $db = db();

    // Garantir coluna custom_name
    try {
        $col = $db->query("SHOW COLUMNS FROM chat_contacts LIKE 'custom_name'")->fetch();
        if (!$col) {
            $db->exec("ALTER TABLE chat_contacts ADD COLUMN custom_name VARCHAR(120) DEFAULT NULL AFTER contact_name");
        }
    } catch (Throwable $e) {
        // ignora - se falhar, o UPDATE abaixo vai reportar o erro
    }

    $normalizedJid = contactNameNormalizeJid($chatId);
    $valueToSave = $customName !== '' ? $customName : null;

    // Atualizar tentando tanto o JID normalizado quanto o original (compatibilidade)
    $stmt = $db->prepare("
        UPDATE chat_contacts
        SET custom_name = :name, updated_at = CURRENT_TIMESTAMP
        WHERE remote_jid = :jid1 OR remote_jid = :jid2
    ");
    $stmt->execute([
        'name' => $valueToSave,
        'jid1' => $normalizedJid,
        'jid2' => $chatId,
    ]);

    // Se não existe ainda, criar o contato com o nome personalizado
    if ($stmt->rowCount() === 0) {
        $stmtInsert = $db->prepare("
            INSERT INTO chat_contacts (remote_jid, custom_name)
            VALUES (:jid, :name)
            ON DUPLICATE KEY UPDATE custom_name = VALUES(custom_name), updated_at = CURRENT_TIMESTAMP
        ");
        $stmtInsert->execute([
            'jid' => $normalizedJid,
            'name' => $valueToSave,
        ]);
    }

    audit_log('update', 'chat_contacts', $normalizedJid, null, ['custom_name' => $valueToSave]);

    echo json_encode(['success' => true, 'custom_name' => $valueToSave ?? '']);
} catch (Throwable $e) {
    error_log('[CHAT_CONTACT_NAME] Erro: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

<?php
/**
 * Marca uma conversa do Chat ao Vivo como concluída (resolvido) ou reabre.
 *
 * POST:
 *   - chat_id : JID da conversa (obrigatório)
 *   - status  : 'resolvido' para concluir, 'aguardando' para reabrir (default: resolvido)
 *
 * Regras:
 *   - Ao concluir (resolvido), a conversa sai das abas "Em Captação" e some
 *     dos pendentes, indo para a aba "Concluídos".
 *   - Quando o cliente enviar nova mensagem, o webhook reativa (resolvido -> aguardando).
 */

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

auth_require_login();
rbac_require_permission('chat.manage');

$chatId = isset($_POST['chat_id']) ? trim((string)$_POST['chat_id']) : '';
$status = isset($_POST['status']) ? trim((string)$_POST['status']) : 'resolvido';

if ($chatId === '') {
    echo json_encode(['success' => false, 'error' => 'Chat ID não informado.']);
    exit;
}

// Aceitar apenas status válidos
$allowed = ['aguardando', 'atendendo', 'resolvido'];
if (!in_array($status, $allowed, true)) {
    $status = 'resolvido';
}

// Normalizar JID (mesma lógica do chat_web.php / webhook)
function concludeNormalizeJid(string $jid): string
{
    $numberOnly = preg_replace('/@(s\.whatsapp\.net|g\.us|lid|c\.us|broadcast)$/', '', $jid);
    // Determinar sufixo padrão
    if (strpos($jid, '@g.us') !== false) {
        return $numberOnly . '@g.us';
    }
    return $numberOnly . '@s.whatsapp.net';
}

try {
    $db = db();

    // Garantir coluna status
    try {
        $statusCol = $db->query("SHOW COLUMNS FROM chat_contacts LIKE 'status'")->fetch();
        if (!$statusCol) {
            $db->exec("ALTER TABLE chat_contacts ADD COLUMN status VARCHAR(20) DEFAULT 'aguardando'");
        }
    } catch (Throwable $e) {
        // ignora
    }

    $normalizedJid = concludeNormalizeJid($chatId);

    // Atualizar tentando tanto o JID normalizado quanto o original (compatibilidade)
    $stmt = $db->prepare("
        UPDATE chat_contacts
        SET status = :status, updated_at = CURRENT_TIMESTAMP
        WHERE remote_jid = :jid1 OR remote_jid = :jid2
    ");
    $stmt->execute([
        'status' => $status,
        'jid1' => $normalizedJid,
        'jid2' => $chatId,
    ]);

    $affected = $stmt->rowCount();

    // Se não atualizou nenhuma linha, o contato pode não existir ainda; criar
    if ($affected === 0) {
        $stmtInsert = $db->prepare("
            INSERT INTO chat_contacts (remote_jid, status)
            VALUES (:jid, :status)
            ON DUPLICATE KEY UPDATE status = VALUES(status), updated_at = CURRENT_TIMESTAMP
        ");
        $stmtInsert->execute([
            'jid' => $normalizedJid,
            'status' => $status,
        ]);
    }

    audit_log('update', 'chat_contacts', $normalizedJid, null, ['status' => $status]);

    echo json_encode(['success' => true, 'status' => $status]);
} catch (Throwable $e) {
    error_log('[CHAT_CONCLUDE] Erro: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

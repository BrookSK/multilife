<?php

declare(strict_types=1);

/**
 * Marca manualmente uma autorização como RECUSADA (operadora/cliente não autorizou).
 *
 * Move o card para a coluna "Negativas" (status = 'autorizacao_negada'), registra o
 * motivo (opcional) e o histórico. NÃO cria patient_assignment nem lançamentos
 * financeiros — é o oposto da aprovação.
 *
 * A partir das Negativas, a equipe pode reenviar com novo valor ou finalizar.
 *
 * POST:
 *   - auth_id (obrigatório)
 *   - denial_reason (opcional)
 */

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

$authId = (int)($_POST['auth_id'] ?? 0);
$denialReason = trim((string)($_POST['denial_reason'] ?? ''));

if ($authId <= 0) {
    flash_set('error', 'Autorização inválida.');
    header('Location: /authorization_list.php');
    exit;
}

$db = db();
$userId = (int)(auth_user_id() ?? 1);

try {
    // Só recusa quem está aguardando resposta.
    $stmt = $db->prepare("
        SELECT ar.id, ar.demand_id, ar.status
        FROM authorization_requests ar
        WHERE ar.id = :id AND ar.status = 'aguardando_autorizacao'
        LIMIT 1
    ");
    $stmt->execute(['id' => $authId]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        flash_set('error', 'Autorização não encontrada ou já processada.');
        header('Location: /authorization_view.php?id=' . $authId);
        exit;
    }

    $demandId = (int)$request['demand_id'];

    // Garantir coluna de motivo (idempotente; a tela já exibe denial_reason).
    try { $db->exec("ALTER TABLE authorization_requests ADD COLUMN denial_reason TEXT NULL"); } catch (Throwable $e) {}

    $db->beginTransaction();

    // Marca a autorização como negada (coluna "Negativas").
    $db->prepare(
        "UPDATE authorization_requests
         SET status = 'autorizacao_negada', response_received_at = NOW(), denial_reason = :reason
         WHERE id = :id"
    )->execute([
        'reason' => $denialReason !== '' ? $denialReason : null,
        'id' => $authId,
    ]);

    // Reflete na demanda (status de captação usado no kanban).
    try {
        $db->prepare("UPDATE demands SET status = 'autorizacao_negada' WHERE id = :id")->execute(['id' => $demandId]);
    } catch (Throwable $e) {
        error_log('[AUTH_MARK_DENIED] Falha ao atualizar status da demanda: ' . $e->getMessage());
    }

    // Histórico.
    try {
        $db->prepare(
            "INSERT INTO authorization_request_history (authorization_request_id, action, notes, user_id)
             VALUES (:auth_id, 'denied', :notes, :uid)"
        )->execute([
            'auth_id' => $authId,
            'notes' => 'Autorização recusada manualmente.' . ($denialReason !== '' ? ' Motivo: ' . $denialReason : ''),
            'uid' => $userId,
        ]);
    } catch (Throwable $e) {}

    $db->commit();

    audit_log('update', 'authorization_requests', (string)$authId, null, [
        'action' => 'marcada_recusada_manual',
        'denial_reason' => $denialReason,
    ]);

    flash_set('success', 'Autorização marcada como recusada. O card foi movido para Negativas.');
    header('Location: /authorization_list.php');
    exit;
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[AUTH_MARK_DENIED] ' . $e->getMessage());
    flash_set('error', 'Erro ao marcar como recusada: ' . $e->getMessage());
    header('Location: /authorization_view.php?id=' . $authId);
    exit;
}

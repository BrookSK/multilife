<?php

declare(strict_types=1);

/**
 * Feedback de classificação a partir do CARD do Kanban de captação.
 * A equipe marca "É captação/prospecção" ou "Não é captação".
 *
 * - Registra o feedback (aprende por remetente/domínio do origin_email).
 * - Se "não é captação": cancela o card.
 */

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/email_classification_helper.php';

auth_require_login();
rbac_require_permission('demands.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /demands_list.php');
    exit;
}

$id = (int)($_POST['id'] ?? 0);
$label = (string)($_POST['label'] ?? '');
$label = $label === 'captation' ? 'captation' : 'not_captation';

if ($id <= 0) {
    flash_set('error', 'Card inválido.');
    header('Location: /demands_list.php');
    exit;
}

$db = db();
$stmt = $db->prepare('SELECT id, title, origin_email, status FROM demands WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $id]);
$d = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$d) {
    flash_set('error', 'Card não encontrado.');
    header('Location: /demands_list.php');
    exit;
}

$fromEmail = (string)($d['origin_email'] ?? '');
$subject = (string)($d['title'] ?? '');

// Tenta localizar o inbound_email de origem (se a coluna existir) para vincular o feedback.
$inboundId = null;
try {
    $colChk = $db->query("SHOW COLUMNS FROM demands LIKE 'source_email_id'")->fetch();
    if ($colChk) {
        $seid = $db->prepare("SELECT source_email_id FROM demands WHERE id = :id");
        $seid->execute(['id' => $id]);
        $v = (int)($seid->fetchColumn() ?: 0);
        if ($v > 0) { $inboundId = $v; }
    }
} catch (Throwable $e) {}

// 1) Registrar feedback + aprender (por remetente/domínio do origin_email).
email_classification_record_feedback(
    $label,
    $fromEmail,
    $subject,
    $inboundId,
    $id,
    (int)(auth_user_id() ?? 0)
);

if ($label === 'not_captation') {
    // Cancela o card (saiu da fila de prospecção) — exceto se já admitido/concluído.
    try {
        $upd = $db->prepare("UPDATE demands SET status = 'cancelado', cancellation_reason = :r, cancelled_at = NOW(), cancelled_by_user_id = :uid WHERE id = :id AND status NOT IN ('admitido','concluido','cancelado')");
        $upd->execute(['r' => 'Reclassificado como não-captação', 'uid' => auth_user_id(), 'id' => $id]);
        if ($upd->rowCount() > 0) {
            $db->prepare("INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (:did, :old, 'cancelado', :uid, 'Reclassificado como não-captação (feedback no card)')")
                ->execute(['did' => $id, 'old' => (string)$d['status'], 'uid' => auth_user_id()]);
        }
        // Também marca o inbound como não-captação, se houver.
        if ($inboundId) {
            try { $db->prepare("UPDATE inbound_emails SET status = 'not_captation' WHERE id = :id")->execute(['id' => $inboundId]); } catch (Throwable $e) {}
        }
    } catch (Throwable $e) {
        error_log('[DEMANDS_CLASSIFY] Erro ao cancelar card: ' . $e->getMessage());
    }
    flash_set('success', 'Card marcado como NÃO captação e cancelado. O sistema aprendeu com este remetente/domínio.');
} else {
    flash_set('success', 'Confirmado como captação. O sistema aprendeu com este remetente/domínio.');
}

header('Location: /demands_list.php');
exit;

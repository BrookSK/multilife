<?php

declare(strict_types=1);

/**
 * Feedback de classificação de e-mail (aprendizado):
 * a equipe marca "É captação" ou "Não é captação".
 *
 * - Registra o feedback e consolida as regras aprendidas (por remetente/domínio).
 * - Se marcar NÃO-CAPTAÇÃO e existir card vinculado, cancela o card.
 * - Se marcar É CAPTAÇÃO e o e-mail estava descartado (not_captation/needs_review),
 *   reenfileira para o cron criar o card.
 */

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/email_classification_helper.php';

auth_require_login();
rbac_require_permission('demands.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /inbound_emails_list.php');
    exit;
}

$id = (int)($_POST['id'] ?? 0);
$label = (string)($_POST['label'] ?? '');
$label = $label === 'captation' ? 'captation' : 'not_captation';

if ($id <= 0) {
    flash_set('error', 'E-mail inválido.');
    header('Location: /inbound_emails_list.php');
    exit;
}

$db = db();
$stmt = $db->prepare('SELECT * FROM inbound_emails WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $id]);
$e = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$e) {
    flash_set('error', 'E-mail não encontrado.');
    header('Location: /inbound_emails_list.php');
    exit;
}

$fromEmail = (string)($e['from_email'] ?? $e['from_address'] ?? '');
$subject = (string)($e['subject'] ?? '');
$linkedDemandId = isset($e['linked_demand_id']) ? (int)$e['linked_demand_id'] : 0;

// 1) Registrar o feedback + aprender regras.
email_classification_record_feedback(
    $label,
    $fromEmail,
    $subject,
    $id,
    $linkedDemandId > 0 ? $linkedDemandId : null,
    (int)(auth_user_id() ?? 0)
);

if ($label === 'not_captation') {
    // Marca o e-mail como não-captação.
    try {
        $db->prepare("UPDATE inbound_emails SET status = 'not_captation', error_message = :m WHERE id = :id")
            ->execute(['m' => 'Marcado manualmente como não-captação', 'id' => $id]);
    } catch (Throwable $ex) {
        $db->prepare("UPDATE inbound_emails SET status = 'not_captation' WHERE id = :id")->execute(['id' => $id]);
    }
    // Se tinha card vinculado, cancela (saiu da fila de prospecção).
    if ($linkedDemandId > 0) {
        try {
            $db->prepare("UPDATE demands SET status = 'cancelado', cancellation_reason = :r, cancelled_at = NOW(), cancelled_by_user_id = :uid WHERE id = :id AND status NOT IN ('admitido','concluido','cancelado')")
                ->execute(['r' => 'Reclassificado como não-captação', 'uid' => auth_user_id(), 'id' => $linkedDemandId]);
            $db->prepare("INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note) VALUES (:did, NULL, 'cancelado', :uid, 'Reclassificado como não-captação')")
                ->execute(['did' => $linkedDemandId, 'uid' => auth_user_id()]);
        } catch (Throwable $ex) {
            error_log('[CLASSIFY] Erro ao cancelar card vinculado: ' . $ex->getMessage());
        }
    }
    flash_set('success', 'Marcado como NÃO captação. O sistema aprendeu com remetentes/domínios parecidos.');
    header('Location: /inbound_emails_list.php');
    exit;
}

// label === 'captation'
// Se estava descartado, reenfileira para o cron criar o card.
$currentStatus = (string)($e['status'] ?? '');
if (in_array($currentStatus, ['not_captation', 'needs_review', 'error'], true)) {
    try {
        $db->prepare("UPDATE inbound_emails SET status = 'received', error_message = NULL, processed_at = NULL WHERE id = :id")
            ->execute(['id' => $id]);
    } catch (Throwable $ex) {}

    // Dispara o cron para este e-mail (fire-and-forget).
    $cronToken = trim((string)admin_setting_get('cron.token', ''));
    if ($cronToken !== '' && function_exists('curl_init')) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        $url = $scheme . '://' . $host . '/cron/openai_extract_email_to_demand.php?'
            . http_build_query(['token' => $cronToken, 'id' => $id, 'force' => '1', 'retry_errors' => '1']);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 800);
        curl_setopt($ch, CURLOPT_NOSIGNAL, true);
        curl_exec($ch);
        curl_close($ch);
    }
    flash_set('success', 'Marcado como CAPTAÇÃO e enviado para criar o card. O sistema aprendeu com este remetente/domínio.');
} else {
    flash_set('success', 'Marcado como CAPTAÇÃO. O sistema aprendeu com este remetente/domínio.');
}

header('Location: /inbound_emails_list.php');
exit;

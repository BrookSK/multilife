<?php
/**
 * Manutenção da tabela integration_logs (Logs Técnicos):
 *   action=clean     arquiva (.jsonl.gz no servidor) e apaga os registros antigos (até ~20 s por chamada)
 *   action=optimize  devolve ao disco o espaço liberado (OPTIMIZE TABLE)
 *   action=settings  salva prazos de retenção
 * Responde JSON quando chamado via fetch (X-Requested-With), senão redireciona.
 */

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /tech_logs_list.php');
    exit;
}

$action = (string)($_POST['action'] ?? '');
$isAjax = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
session_release();
@set_time_limit(120);

$reply = static function (array $data) use ($isAjax): void {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
    flash_set(!empty($data['ok']) ? 'success' : 'error', (string)($data['message'] ?? ''));
    header('Location: /tech_logs_list.php');
    exit;
};

if ($action === 'settings') {
    $ok = max(1, min(365, (int)($_POST['days_success'] ?? 7)));
    $err = max(1, min(730, (int)($_POST['days_error'] ?? 30)));
    $months = max(1, min(60, (int)($_POST['archive_months'] ?? 12)));
    admin_settings_set_many([
        'logs.integration_retention_days_success' => (string)$ok,
        'logs.integration_retention_days_error' => (string)$err,
        'logs.integration_archive' => !empty($_POST['archive']) ? '1' : '0',
        'logs.integration_archive_months' => (string)$months,
    ], (int)auth_user_id());
    $reply(['ok' => true, 'message' => 'Prazos de retenção salvos.']);
}

if ($action === 'clean') {
    $r = integration_logs_maintain(20);
    if ($r['error'] !== '' && $r['deleted'] === 0) {
        $reply(['ok' => false, 'message' => $r['error']] + $r);
    }
    $msg = number_format($r['deleted'], 0, ',', '.') . ' registro(s) removido(s) da tabela'
        . ($r['archived'] > 0 ? ' (' . number_format($r['archived'], 0, ',', '.') . ' arquivado(s) em arquivo)' : '') . '.';
    if ($r['more']) {
        $msg .= ' Ainda há registros antigos: clique de novo.';
    }
    audit_log('cleanup', 'integration_logs', null, null, ['deleted' => $r['deleted'], 'archived' => $r['archived']]);
    $reply(['ok' => true, 'message' => $msg] + $r);
}

if ($action === 'optimize') {
    try {
        db()->query('OPTIMIZE TABLE integration_logs')->fetchAll();
        $reply(['ok' => true, 'message' => 'Tabela compactada: o espaço liberado voltou para o disco.']);
    } catch (Throwable $e) {
        $reply(['ok' => false, 'message' => 'Falha ao compactar: ' . $e->getMessage()]);
    }
}

$reply(['ok' => false, 'message' => 'Ação inválida.']);

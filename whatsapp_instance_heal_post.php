<?php

declare(strict_types=1);

/**
 * Botão "Reconectar" da suspeita de queda (veredito "silent" do watchdog).
 * Pede ao watchdog o restart da instância (refaz a conexão sem QR Code).
 * A chave do serviço WhatsApp fica só no servidor.
 *
 * Quem pode: administrador de WhatsApp/configurações ou usuário vinculado à
 * instância. Só instâncias ativas do sistema.
 */

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
wa_cloud_page_guard('A reconexão sem QR Code');
session_release();

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'Use POST.']);
    exit;
}

$instance = trim((string)($_POST['instance'] ?? ''));
$userId = (int)auth_user_id();

if ($instance === '' || !wa_watchdog_user_can_heal($userId, $instance)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Você não tem acesso a esta instância.']);
    exit;
}
if (!wa_watchdog_enabled()) {
    echo json_encode(['ok' => false, 'msg' => 'Serviço WhatsApp (Baileys) não configurado. Preencha a URL e a API Key em Configurações → WhatsApp Conexão → Credenciais.']);
    exit;
}

$res = wa_watchdog_heal($instance);
if ($res['ok']) {
    // Conta como a reconexão deste episódio: se continuar sem eventos, o alerta sai em 5 min.
    // Até a próxima leitura do watchdog a tela mostra "Monitorando".
    wa_monitor_set($instance, ['auto_heal_at' => date('Y-m-d H:i:s'), 'verdict' => 'warming_up', 'verdict_since' => date('Y-m-d H:i:s')]);
}
audit_log('update', 'whatsapp_instances', null, null, ['action' => 'watchdog_heal', 'instance' => $instance, 'ok' => $res['ok'], 'reason' => $res['message']]);

if ($res['ok']) {
    echo json_encode(['ok' => true, 'msg' => 'Reconexão disparada. Confira de novo em 2 a 5 minutos.']);
    exit;
}

// Motivos de recusa do watchdog em linguagem do usuário
$reason = mb_strtolower($res['message']);
if (str_contains($reason, 'saudável') || str_contains($reason, 'saudavel') || str_contains($reason, 'healthy')) {
    $msg = 'A conexão já está recebendo eventos normalmente. Não foi preciso reconectar.';
} elseif (str_contains($reason, 'qr') || str_contains($reason, 'dormente') || str_contains($reason, 'close') || str_contains($reason, 'connecting')) {
    $msg = 'Esta conexão precisa ler o QR Code de novo: use "Reconectar" em Meu WhatsApp (ou em Configurações → WhatsApp Conexão → Instâncias).';
} else {
    $msg = 'O monitor não conseguiu reconectar: ' . ($res['message'] !== '' ? $res['message'] : 'falha desconhecida') . '.';
}
echo json_encode(['ok' => false, 'msg' => $msg]);

<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

// Dispara o processamento com o payload de exemplo, forçando o modo teste:
// o telefone recebido é sempre descartado e usamos o telefone de teste.
$payload = zoho_billing_sample_payload();
$result = zoho_billing_process($payload, true);

zoho_billing_log(
    'Teste manual (admin). Destino=' . ($result['phone'] ?? 'null')
    . ' | Sucesso=' . ($result['success'] ? 'sim' : 'não')
    . ' | ' . $result['message']
);

if ($result['success']) {
    flash_set('success', 'Teste enviado: ' . $result['message']);
} else {
    flash_set('error', 'Falha no teste: ' . $result['message']);
}

header('Location: /admin_zoho_billing.php');
exit;

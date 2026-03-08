<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

$contractId = (int)($_POST['contract_id'] ?? 0);
$event = trim((string)($_POST['event'] ?? ''));

if ($contractId === 0 || $event === '') {
    flash_set('error', 'Preencha todos os campos.');
    header('Location: /zapsign_webhook_test.php');
    exit;
}

// Buscar contrato
$stmt = db()->prepare('SELECT * FROM hr_employee_contracts WHERE id = :id');
$stmt->execute(['id' => $contractId]);
$contract = $stmt->fetch();

if (!$contract) {
    flash_set('error', 'Contrato não encontrado.');
    header('Location: /zapsign_webhook_test.php');
    exit;
}

// Simular payload do ZapSign
$payload = [
    'event' => $event,
    'doc_token' => $contract['zapsign_doc_token'],
    'signed_file_url' => 'https://example.com/signed.pdf',
    'timestamp' => date('Y-m-d H:i:s'),
];

// Chamar o webhook internamente
$webhookUrl = 'http://' . $_SERVER['HTTP_HOST'] . '/zapsign_webhook.php';

$ch = curl_init($webhookUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200) {
    flash_set('success', 'Evento simulado com sucesso! Verifique os logs e o status do contrato.');
} else {
    flash_set('error', 'Erro ao simular evento. Código HTTP: ' . $httpCode . ' | Resposta: ' . $response);
}

header('Location: /zapsign_webhook_test.php');
exit;

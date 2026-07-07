<?php
/**
 * Teste simples para verificar se a Evolution consegue alcançar este servidor.
 * Loga qualquer coisa que receber.
 */
$logFile = __DIR__ . '/logs/webhook_test.log';
$dir = dirname($logFile);
if (!is_dir($dir)) @mkdir($dir, 0755, true);

$timestamp = date('Y-m-d H:i:s');
$method = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
$payload = file_get_contents('php://input');
$ip = $_SERVER['REMOTE_ADDR'] ?? '?';

$log = "[$timestamp] $method from $ip | payload_len=" . strlen($payload) . " | sample=" . substr($payload, 0, 200) . "\n";
file_put_contents($logFile, $log, FILE_APPEND);

http_response_code(200);
echo json_encode(['status' => 'ok', 'received_at' => $timestamp]);

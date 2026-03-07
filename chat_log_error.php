<?php
// Script simples para registrar erros JavaScript no log do servidor

// Receber dados JSON
$json = file_get_contents('php://input');
$data = json_decode($json, true);

if ($data) {
    $message = $data['message'] ?? 'Unknown error';
    $url = $data['url'] ?? 'Unknown URL';
    $line = $data['line'] ?? 0;
    $col = $data['col'] ?? 0;
    $stack = $data['stack'] ?? 'No stack trace';
    
    // Formatar mensagem de log
    $logMessage = sprintf(
        "[JS ERROR] %s | URL: %s | Line: %d | Col: %d | Stack: %s",
        $message,
        $url,
        $line,
        $col,
        $stack
    );
    
    // Registrar no error_log do PHP
    error_log($logMessage);
    
    // Retornar sucesso
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
} else {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
}
?>

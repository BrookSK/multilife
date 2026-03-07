<?php
// Diagnóstico simples para identificar erro de sintaxe JavaScript

require_once __DIR__ . '/app/bootstrap.php';
auth_require_login();

$selectedChat = isset($_GET['chat']) ? trim((string)$_GET['chat']) : '';
$chatName = '';

?>
<!DOCTYPE html>
<html>
<head>
    <title>Diagnóstico JavaScript</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #1e1e1e; color: #00ff00; }
        .error { color: #ff0000; }
        .success { color: #00ffff; }
        .info { color: #ffff00; }
    </style>
</head>
<body>
    <h1>🔍 DIAGNÓSTICO JAVASCRIPT - CHAT WEB</h1>
    <div id="logs"></div>
    
    <script>
        const logs = document.getElementById('logs');
        
        function log(msg, type = 'info') {
            const div = document.createElement('div');
            div.className = type;
            div.textContent = '[' + new Date().toLocaleTimeString() + '] ' + msg;
            logs.appendChild(div);
            console.log(msg);
        }
        
        window.onerror = function(msg, url, line, col, error) {
            log('❌ ERRO: ' + msg + ' (linha ' + line + ')', 'error');
            return false;
        };
        
        log('========================================', 'success');
        log('TESTE DE SINTAXE JAVASCRIPT', 'success');
        log('========================================', 'success');
        log('Chat: <?php echo addslashes($selectedChat); ?>');
        log('ChatName: <?php echo addslashes($chatName); ?>');
        
        // Testar se funções básicas funcionam
        log('Testando funções básicas...');
        
        function teste1() {
            log('✓ Função teste1 OK', 'success');
        }
        
        async function teste2() {
            log('✓ Função async teste2 OK', 'success');
        }
        
        teste1();
        teste2();
        
        log('========================================', 'success');
        log('TODOS OS TESTES PASSARAM!', 'success');
        log('========================================', 'success');
        log('O problema NÃO é sintaxe JavaScript básica', 'info');
        log('O problema está em algum código específico do chat_web.php', 'info');
    </script>
</body>
</html>

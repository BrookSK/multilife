<?php
require_once __DIR__ . '/app/bootstrap.php';
auth_require_login();

$selectedChat = isset($_GET['chat']) ? trim((string)$_GET['chat']) : '';
$chatName = '';

view_header('Chat Test');
?>

<!-- PAINEL DE DEBUG -->
<div id="debugPanel" style="position:fixed;bottom:0;left:0;right:0;max-height:300px;overflow-y:auto;background:#1e1e1e;color:#00ff00;font-family:monospace;font-size:11px;padding:10px;z-index:99998;border-top:3px solid #00ff00">
    <strong style="color:#00ff00">🔍 DEBUG PANEL - TESTE MINIMAL</strong>
    <div id="debugLogs"></div>
</div>

<script>
const debugPanel = document.getElementById('debugLogs');
function addDebugLog(msg, type) {
    if(!type) type = 'info';
    const colors = {info: '#00ff00', error: '#ff0000', warn: '#ffff00', success: '#00ffff'};
    const line = document.createElement('div');
    line.style.color = colors[type] || colors.info;
    line.textContent = '[' + new Date().toLocaleTimeString() + '] ' + msg;
    debugPanel.appendChild(line);
    console.log(msg);
}

window.onerror = function(msg, url, line, col, error) {
    addDebugLog('ERRO JS: ' + msg + ' (linha ' + line + ')', 'error');
    return false;
};

addDebugLog('========================================', 'success');
addDebugLog('TESTE MINIMAL - CHAT WEB', 'success');
addDebugLog('========================================', 'success');
addDebugLog('selectedChat: <?php echo addslashes($selectedChat); ?>');
addDebugLog('chatName: <?php echo addslashes($chatName); ?>');

// Testar função básica
function openAssignmentModal() {
    addDebugLog('openAssignmentModal chamada', 'success');
    alert('Modal funcionando!');
}

addDebugLog('Função openAssignmentModal definida', 'success');
addDebugLog('Tipo: ' + typeof openAssignmentModal, 'success');
addDebugLog('========================================', 'success');
addDebugLog('TESTE CONCLUÍDO - SEM ERROS', 'success');
</script>

<div style="padding:20px">
    <h1>Teste Minimal - Chat Web</h1>
    <button onclick="openAssignmentModal()" style="padding:10px 20px;background:#00a884;color:#fff;border:none;border-radius:8px;cursor:pointer">
        Testar openAssignmentModal
    </button>
</div>

<?php view_footer(); ?>

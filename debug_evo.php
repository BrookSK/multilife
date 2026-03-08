<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('chat.manage');

header('Content-Type: text/html; charset=utf-8');

// Função para ler últimas linhas do error_log
function getRecentLogs(int $lines = 100): array {
    $logFile = ini_get('error_log');
    if (empty($logFile) || !file_exists($logFile)) {
        // Tentar localização padrão do Plesk
        $possibleLogs = [
            '/var/log/apache2/error.log',
            '/var/log/httpd/error_log',
            '/var/www/vhosts/system/festive-darwin.186-209-113-140.plesk.page/logs/error_log',
            __DIR__ . '/error_log',
        ];
        
        foreach ($possibleLogs as $log) {
            if (file_exists($log)) {
                $logFile = $log;
                break;
            }
        }
    }
    
    if (empty($logFile) || !file_exists($logFile)) {
        return ['error' => 'Log file not found. Checked: ' . implode(', ', $possibleLogs ?? [])];
    }
    
    $command = "tail -n $lines " . escapeshellarg($logFile);
    $output = shell_exec($command);
    
    if ($output === null) {
        return ['error' => 'Could not read log file'];
    }
    
    $logLines = explode("\n", trim($output));
    
    // Filtrar apenas logs relacionados ao webhook
    $webhookLogs = array_filter($logLines, function($line) {
        return strpos($line, '[WEBHOOK]') !== false 
            || strpos($line, '[DOWNLOAD_MEDIA]') !== false
            || strpos($line, '[SAVE_MSG]') !== false;
    });
    
    return array_values($webhookLogs);
}

// Tentar buscar últimos webhooks recebidos do integration_log (se existir)
$webhooks = [];
try {
    $stmt = db()->prepare("
        SELECT 
            id,
            service,
            direction,
            status,
            http_code,
            request_data,
            response_data,
            error_message,
            created_at
        FROM integration_log
        WHERE service = 'evolution_webhook'
        ORDER BY id DESC
        LIMIT 20
    ");
    $stmt->execute();
    $webhooks = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Tabela não existe, continuar sem webhooks
    $webhooks = [];
}

// Buscar últimas mensagens de mídia
$stmt = db()->prepare("
    SELECT 
        id,
        remote_jid,
        message_type,
        message_text,
        media_url,
        media_mime_type,
        media_filename,
        media_size,
        from_me,
        message_timestamp,
        created_at
    FROM chat_messages
    WHERE message_type != 'text'
    ORDER BY id DESC
    LIMIT 20
");
$stmt->execute();
$mediaMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Debug Evolution API</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 20px;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        h1 {
            color: #4ec9b0;
            margin-bottom: 10px;
            font-size: 28px;
        }
        h2 {
            color: #569cd6;
            margin: 30px 0 15px 0;
            font-size: 20px;
            border-bottom: 2px solid #569cd6;
            padding-bottom: 5px;
        }
        .info {
            background: #252526;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #4ec9b0;
        }
        .section {
            background: #252526;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .log-entry {
            background: #1e1e1e;
            padding: 15px;
            margin-bottom: 15px;
            border-radius: 6px;
            border-left: 4px solid #569cd6;
            font-family: 'Courier New', monospace;
            font-size: 13px;
        }
        .log-entry.error {
            border-left-color: #f48771;
        }
        .log-entry.success {
            border-left-color: #4ec9b0;
        }
        .log-entry.warning {
            border-left-color: #dcdcaa;
        }
        .webhook-item {
            background: #1e1e1e;
            padding: 15px;
            margin-bottom: 15px;
            border-radius: 6px;
            border-left: 4px solid #c586c0;
        }
        .media-item {
            background: #1e1e1e;
            padding: 15px;
            margin-bottom: 15px;
            border-radius: 6px;
            border-left: 4px solid #4ec9b0;
        }
        .label {
            color: #569cd6;
            font-weight: bold;
            display: inline-block;
            min-width: 150px;
        }
        .value {
            color: #ce9178;
        }
        pre {
            background: #0d0d0d;
            padding: 15px;
            border-radius: 4px;
            overflow-x: auto;
            margin-top: 10px;
            font-size: 12px;
            line-height: 1.5;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
        }
        .status-success { background: #4ec9b0; color: #000; }
        .status-error { background: #f48771; color: #000; }
        .status-warning { background: #dcdcaa; color: #000; }
        .btn {
            background: #569cd6;
            color: #fff;
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            margin-right: 10px;
        }
        .btn:hover {
            background: #4a8bc2;
        }
        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        @media (max-width: 1024px) {
            .grid { grid-template-columns: 1fr; }
        }
        .timestamp {
            color: #858585;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Debug Evolution API - Sistema de Mídias</h1>
        
        <div class="info">
            <p><strong>Objetivo:</strong> Diagnosticar por que mídias (imagens, áudios, vídeos) não estão sendo baixadas corretamente do WhatsApp.</p>
            <p><strong>Última atualização:</strong> <?= date('d/m/Y H:i:s') ?></p>
            <div style="margin-top: 15px;">
                <a href="?refresh=1" class="btn">🔄 Atualizar Página</a>
                <a href="chat_web.php" class="btn">← Voltar ao Chat</a>
            </div>
        </div>

        <div class="grid">
            <div>
                <h2>📥 Últimos Webhooks Recebidos (<?= count($webhooks) ?>)</h2>
                <div class="section">
                    <?php if (empty($webhooks)): ?>
                        <p style="color: #858585;">Nenhum webhook registrado ainda.</p>
                    <?php else: ?>
                        <?php foreach ($webhooks as $webhook): ?>
                            <div class="webhook-item">
                                <div>
                                    <span class="label">ID:</span>
                                    <span class="value">#<?= $webhook['id'] ?></span>
                                    <span class="timestamp" style="float: right;"><?= $webhook['created_at'] ?></span>
                                </div>
                                <div>
                                    <span class="label">Status:</span>
                                    <?php
                                    $statusClass = 'status-success';
                                    if ($webhook['status'] === 'error') $statusClass = 'status-error';
                                    elseif ($webhook['http_code'] >= 400) $statusClass = 'status-error';
                                    ?>
                                    <span class="status-badge <?= $statusClass ?>">
                                        <?= strtoupper($webhook['status']) ?> (<?= $webhook['http_code'] ?>)
                                    </span>
                                </div>
                                <div>
                                    <span class="label">Direction:</span>
                                    <span class="value"><?= htmlspecialchars($webhook['direction']) ?></span>
                                </div>
                                <?php if (!empty($webhook['error_message'])): ?>
                                    <div>
                                        <span class="label">Erro:</span>
                                        <span style="color: #f48771;"><?= htmlspecialchars($webhook['error_message']) ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($webhook['request_data'])): ?>
                                    <details style="margin-top: 10px;">
                                        <summary style="cursor: pointer; color: #569cd6;">📄 Request Data</summary>
                                        <pre><?= htmlspecialchars(json_encode(json_decode($webhook['request_data'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                                    </details>
                                <?php endif; ?>
                                <?php if (!empty($webhook['response_data'])): ?>
                                    <details style="margin-top: 10px;">
                                        <summary style="cursor: pointer; color: #569cd6;">📤 Response Data</summary>
                                        <pre><?= htmlspecialchars(json_encode(json_decode($webhook['response_data'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                                    </details>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div>
                <h2>🎬 Últimas Mensagens de Mídia (<?= count($mediaMessages) ?>)</h2>
                <div class="section">
                    <?php if (empty($mediaMessages)): ?>
                        <p style="color: #858585;">Nenhuma mensagem de mídia registrada ainda.</p>
                    <?php else: ?>
                        <?php foreach ($mediaMessages as $msg): ?>
                            <div class="media-item">
                                <div>
                                    <span class="label">ID:</span>
                                    <span class="value">#<?= $msg['id'] ?></span>
                                    <span class="timestamp" style="float: right;"><?= $msg['created_at'] ?></span>
                                </div>
                                <div>
                                    <span class="label">Tipo:</span>
                                    <span class="status-badge status-success"><?= strtoupper($msg['message_type']) ?></span>
                                </div>
                                <div>
                                    <span class="label">JID:</span>
                                    <span class="value"><?= htmlspecialchars(substr($msg['remote_jid'], 0, 30)) ?></span>
                                </div>
                                <div>
                                    <span class="label">Texto:</span>
                                    <span class="value"><?= htmlspecialchars($msg['message_text']) ?></span>
                                </div>
                                <div>
                                    <span class="label">URL:</span>
                                    <?php if (!empty($msg['media_url'])): ?>
                                        <?php if (strpos($msg['media_url'], 'http') === 0): ?>
                                            <span style="color: #f48771;">❌ URL EXTERNA (não baixada)</span>
                                        <?php else: ?>
                                            <span style="color: #4ec9b0;">✅ URL LOCAL</span>
                                        <?php endif; ?>
                                        <br>
                                        <span class="value" style="font-size: 11px; word-break: break-all;">
                                            <?= htmlspecialchars(substr($msg['media_url'], 0, 100)) ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #858585;">NULL (mídia não disponível)</span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($msg['media_filename'])): ?>
                                    <div>
                                        <span class="label">Filename:</span>
                                        <span class="value"><?= htmlspecialchars($msg['media_filename']) ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($msg['media_size'])): ?>
                                    <div>
                                        <span class="label">Size:</span>
                                        <span class="value"><?= number_format($msg['media_size'] / 1024, 2) ?> KB</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <h2>📋 Logs Recentes do Webhook (últimas 100 linhas)</h2>
        <div class="section">
            <?php
            $logs = getRecentLogs(100);
            if (isset($logs['error'])):
            ?>
                <p style="color: #f48771;">❌ <?= htmlspecialchars($logs['error']) ?></p>
                <p style="color: #858585; margin-top: 10px;">
                    <strong>Dica:</strong> Os logs podem estar em outro local. Verifique:
                    <br>- Plesk: Logs → Error Log
                    <br>- SSH: tail -f /var/log/apache2/error.log
                </p>
            <?php else: ?>
                <?php if (empty($logs)): ?>
                    <p style="color: #858585;">Nenhum log de webhook encontrado nos últimos registros.</p>
                <?php else: ?>
                    <?php foreach (array_reverse($logs) as $log): ?>
                        <?php
                        $logClass = 'log-entry';
                        if (strpos($log, 'ERRO') !== false || strpos($log, 'ERROR') !== false) {
                            $logClass .= ' error';
                        } elseif (strpos($log, 'SUCESSO') !== false || strpos($log, '✅') !== false) {
                            $logClass .= ' success';
                        } elseif (strpos($log, 'AVISO') !== false || strpos($log, 'WARNING') !== false) {
                            $logClass .= ' warning';
                        }
                        ?>
                        <div class="<?= $logClass ?>">
                            <?= htmlspecialchars($log) ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <h2>📖 Como Usar Esta Página</h2>
        <div class="section">
            <ol style="line-height: 2;">
                <li><strong>Envie uma nova mídia</strong> do WhatsApp (imagem, áudio, vídeo)</li>
                <li><strong>Atualize esta página</strong> (botão "🔄 Atualizar Página")</li>
                <li><strong>Verifique os logs</strong> para ver:
                    <ul style="margin-left: 30px; margin-top: 10px;">
                        <li>Se o webhook foi recebido</li>
                        <li>Qual URL foi extraída da mídia</li>
                        <li>Se o download foi tentado</li>
                        <li>Qual erro ocorreu (se houver)</li>
                    </ul>
                </li>
                <li><strong>Analise as mensagens de mídia</strong> para ver se foram salvas com URL local ou externa</li>
            </ol>
        </div>

        <h2>🔍 O Que Procurar</h2>
        <div class="section">
            <div style="line-height: 2;">
                <p><strong style="color: #4ec9b0;">✅ Sucesso:</strong></p>
                <ul style="margin-left: 30px;">
                    <li><code>[DOWNLOAD_MEDIA] ✅ SUCESSO - Arquivo salvo</code></li>
                    <li><code>[SAVE_MSG] Mídia salva localmente: /uploads/...</code></li>
                    <li>URL na mensagem começa com <code>/uploads/</code></li>
                </ul>

                <p style="margin-top: 20px;"><strong style="color: #f48771;">❌ Falha:</strong></p>
                <ul style="margin-left: 30px;">
                    <li><code>[DOWNLOAD_MEDIA] ERRO: Falha ao baixar arquivo</code></li>
                    <li><code>[DOWNLOAD_MEDIA] ERRO: Arquivo não é uma imagem válida</code></li>
                    <li><code>[SAVE_MSG] AVISO: Falha ao baixar mídia</code></li>
                    <li>URL na mensagem começa com <code>https://mmg.whatsapp.net</code></li>
                </ul>

                <p style="margin-top: 20px;"><strong style="color: #dcdcaa;">⚠️ Possíveis Causas:</strong></p>
                <ul style="margin-left: 30px;">
                    <li>URL do WhatsApp já expirou quando webhook tentou baixar</li>
                    <li>Arquivo corrompido ou HTML de erro sendo retornado</li>
                    <li>Permissões do diretório /uploads/chat_media/</li>
                    <li>Timeout na conexão com servidor do WhatsApp</li>
                </ul>
            </div>
        </div>
    </div>

    <script>
        // Auto-refresh a cada 10 segundos se houver parâmetro na URL
        if (window.location.search.includes('auto=1')) {
            setTimeout(() => {
                window.location.reload();
            }, 10000);
        }
    </script>
</body>
</html>

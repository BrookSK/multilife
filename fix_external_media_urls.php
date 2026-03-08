<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('chat.manage');

set_time_limit(300); // 5 minutos

header('Content-Type: text/html; charset=utf-8');

echo '<h1>Corrigir URLs Externas de Mídia</h1>';
echo '<p>Este script vai baixar todas as mídias que ainda têm URLs externas (WhatsApp, Evolution API, etc.)</p>';

// Buscar mensagens com URLs externas
$stmt = db()->prepare("
    SELECT 
        id,
        remote_jid,
        message_type,
        media_url,
        media_mime_type,
        media_filename
    FROM chat_messages
    WHERE message_type != 'text'
      AND media_url IS NOT NULL
      AND media_url != ''
      AND (
          media_url LIKE 'http://%'
          OR media_url LIKE 'https://%'
      )
    ORDER BY id DESC
");
$stmt->execute();
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo '<p><strong>Encontradas ' . count($messages) . ' mensagens com URLs externas</strong></p>';

if (empty($messages)) {
    echo '<p style="color:green">✅ Nenhuma URL externa encontrada! Todas as mídias já estão locais.</p>';
    exit;
}

$success = 0;
$failed = 0;
$skipped = 0;

echo '<ul style="font-family:monospace;font-size:12px">';

foreach ($messages as $msg) {
    $id = $msg['id'];
    $externalUrl = $msg['media_url'];
    $mimeType = $msg['media_mime_type'] ?? 'application/octet-stream';
    $filename = $msg['media_filename'] ?? 'media';
    
    echo '<li>';
    echo '<strong>ID ' . $id . ':</strong> ';
    
    // Verificar se é URL do servidor local (já salva mas com domínio completo)
    if (strpos($externalUrl, 'festive-darwin.186-209-113-140.plesk.page') !== false) {
        // Extrair path relativo
        $path = parse_url($externalUrl, PHP_URL_PATH);
        
        // Atualizar no banco
        $updateStmt = db()->prepare("UPDATE chat_messages SET media_url = ? WHERE id = ?");
        $updateStmt->execute([$path, $id]);
        
        echo '<span style="color:blue">CORRIGIDO</span> - URL local convertida para path relativo: ' . htmlspecialchars($path);
        $success++;
    } else {
        // Tentar fazer download
        try {
            // Criar diretório
            $uploadDir = __DIR__ . '/uploads/chat_media/' . date('Y-m');
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            // Gerar nome único
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            if (empty($extension)) {
                $mimeToExt = [
                    'audio/ogg' => 'ogg',
                    'audio/mpeg' => 'mp3',
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'video/mp4' => 'mp4',
                    'application/pdf' => 'pdf',
                ];
                $extension = $mimeToExt[$mimeType] ?? 'bin';
            }
            
            $uniqueFilename = uniqid('chat_', true) . '.' . $extension;
            $localPath = $uploadDir . '/' . $uniqueFilename;
            
            // Fazer download
            $content = @file_get_contents($externalUrl);
            
            if ($content === false || empty($content)) {
                echo '<span style="color:orange">FALHOU</span> - Não foi possível baixar: ' . htmlspecialchars(substr($externalUrl, 0, 80)) . '...';
                $failed++;
            } else {
                // Salvar arquivo
                file_put_contents($localPath, $content);
                
                $localUrl = '/uploads/chat_media/' . date('Y-m') . '/' . $uniqueFilename;
                
                // Atualizar no banco
                $updateStmt = db()->prepare("UPDATE chat_messages SET media_url = ? WHERE id = ?");
                $updateStmt->execute([$localUrl, $id]);
                
                echo '<span style="color:green">SUCESSO</span> - Baixado e salvo: ' . htmlspecialchars($localUrl) . ' (' . number_format(strlen($content) / 1024, 2) . ' KB)';
                $success++;
            }
        } catch (Exception $e) {
            echo '<span style="color:red">ERRO</span> - ' . htmlspecialchars($e->getMessage());
            $failed++;
        }
    }
    
    echo '</li>';
    flush();
}

echo '</ul>';

echo '<hr>';
echo '<h2>Resumo:</h2>';
echo '<p><strong style="color:green">✅ Sucesso:</strong> ' . $success . '</p>';
echo '<p><strong style="color:red">❌ Falhou:</strong> ' . $failed . '</p>';
echo '<p><strong style="color:orange">⏭️ Ignorado:</strong> ' . $skipped . '</p>';

echo '<hr>';
echo '<p><a href="chat_web.php">← Voltar para o Chat</a></p>';

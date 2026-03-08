<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('chat.manage');

set_time_limit(300);

header('Content-Type: text/html; charset=utf-8');

echo '<h1>Limpar Mídias Corrompidas</h1>';
echo '<p>Este script vai verificar e deletar arquivos de mídia corrompidos.</p>';

$uploadDir = __DIR__ . '/uploads/chat_media/' . date('Y-m');

if (!is_dir($uploadDir)) {
    echo '<p style="color:red">❌ Diretório não existe: ' . htmlspecialchars($uploadDir) . '</p>';
    exit;
}

$files = scandir($uploadDir);
$files = array_filter($files, function($f) { return $f !== '.' && $f !== '..'; });

echo '<p><strong>Encontrados ' . count($files) . ' arquivos no diretório</strong></p>';

$deleted = 0;
$valid = 0;
$errors = 0;

echo '<ul style="font-family:monospace;font-size:12px">';

foreach ($files as $file) {
    $filePath = $uploadDir . '/' . $file;
    $fileSize = filesize($filePath);
    
    echo '<li>';
    echo '<strong>' . htmlspecialchars($file) . '</strong> (' . number_format($fileSize / 1024, 2) . ' KB) - ';
    
    // Verificar se é muito pequeno
    if ($fileSize < 100) {
        unlink($filePath);
        echo '<span style="color:red">DELETADO</span> - Arquivo muito pequeno';
        $deleted++;
    } else {
        // Verificar magic bytes para imagens
        $content = file_get_contents($filePath, false, null, 0, 100);
        $magicBytes = substr($content, 0, 4);
        $isValid = false;
        
        // Verificar se é HTML
        if (stripos($content, '<!DOCTYPE') !== false || stripos($content, '<html') !== false) {
            unlink($filePath);
            echo '<span style="color:red">DELETADO</span> - Arquivo é HTML, não mídia';
            $deleted++;
        }
        // JPEG
        elseif (substr($magicBytes, 0, 3) === "\xFF\xD8\xFF") {
            echo '<span style="color:green">OK</span> - JPEG válido';
            $valid++;
            $isValid = true;
        }
        // PNG
        elseif ($magicBytes === "\x89\x50\x4E\x47") {
            echo '<span style="color:green">OK</span> - PNG válido';
            $valid++;
            $isValid = true;
        }
        // GIF
        elseif (substr($magicBytes, 0, 3) === "\x47\x49\x46") {
            echo '<span style="color:green">OK</span> - GIF válido';
            $valid++;
            $isValid = true;
        }
        // OGG (áudio)
        elseif ($magicBytes === "\x4F\x67\x67\x53") {
            echo '<span style="color:green">OK</span> - OGG válido';
            $valid++;
            $isValid = true;
        }
        // MP4/M4A
        elseif (substr($content, 4, 4) === "ftyp") {
            echo '<span style="color:green">OK</span> - MP4/M4A válido';
            $valid++;
            $isValid = true;
        }
        // PDF
        elseif (substr($content, 0, 4) === "%PDF") {
            echo '<span style="color:green">OK</span> - PDF válido';
            $valid++;
            $isValid = true;
        }
        else {
            unlink($filePath);
            echo '<span style="color:red">DELETADO</span> - Formato desconhecido ou corrompido (magic: ' . bin2hex($magicBytes) . ')';
            $deleted++;
        }
    }
    
    echo '</li>';
    flush();
}

echo '</ul>';

// Limpar entradas do banco que apontam para arquivos que não existem mais
echo '<hr>';
echo '<h2>Limpando banco de dados...</h2>';

$stmt = db()->prepare("
    SELECT id, media_url 
    FROM chat_messages 
    WHERE message_type != 'text' 
      AND media_url IS NOT NULL
      AND media_url LIKE '/uploads/chat_media/%'
");
$stmt->execute();
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

$cleaned = 0;

foreach ($messages as $msg) {
    $url = $msg['media_url'];
    $path = __DIR__ . $url;
    
    if (!file_exists($path)) {
        // Deletar mensagem do banco
        $deleteStmt = db()->prepare("DELETE FROM chat_messages WHERE id = ?");
        $deleteStmt->execute([$msg['id']]);
        $cleaned++;
    }
}

echo '<p><strong>Mensagens deletadas do banco:</strong> ' . $cleaned . '</p>';

echo '<hr>';
echo '<h2>Resumo:</h2>';
echo '<p><strong style="color:green">✅ Arquivos válidos:</strong> ' . $valid . '</p>';
echo '<p><strong style="color:red">❌ Arquivos deletados:</strong> ' . $deleted . '</p>';
echo '<p><strong style="color:blue">🗑️ Mensagens limpas do banco:</strong> ' . $cleaned . '</p>';

echo '<hr>';
echo '<p><a href="chat_web.php">← Voltar para o Chat</a></p>';

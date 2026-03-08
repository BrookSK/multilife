<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('chat.manage');

header('Content-Type: text/html; charset=utf-8');

echo '<h1>Debug Chat Media</h1>';

// Buscar últimas mensagens com mídia
$stmt = db()->prepare("
    SELECT 
        id,
        remote_jid,
        message_text,
        message_type,
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
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo '<h2>Últimas 20 mensagens com mídia:</h2>';
echo '<table border="1" cellpadding="5" style="border-collapse:collapse;font-size:12px">';
echo '<tr>';
echo '<th>ID</th>';
echo '<th>JID</th>';
echo '<th>Tipo</th>';
echo '<th>URL</th>';
echo '<th>MIME</th>';
echo '<th>Filename</th>';
echo '<th>Size</th>';
echo '<th>From Me</th>';
echo '<th>Timestamp</th>';
echo '</tr>';

foreach ($messages as $msg) {
    echo '<tr>';
    echo '<td>' . htmlspecialchars((string)$msg['id']) . '</td>';
    echo '<td>' . htmlspecialchars($msg['remote_jid']) . '</td>';
    echo '<td>' . htmlspecialchars($msg['message_type']) . '</td>';
    echo '<td style="max-width:300px;overflow:hidden;text-overflow:ellipsis">';
    if (!empty($msg['media_url'])) {
        echo '<a href="' . htmlspecialchars($msg['media_url']) . '" target="_blank">' . htmlspecialchars($msg['media_url']) . '</a>';
    } else {
        echo '<span style="color:red">NULL ou VAZIO</span>';
    }
    echo '</td>';
    echo '<td>' . htmlspecialchars($msg['media_mime_type'] ?? 'NULL') . '</td>';
    echo '<td>' . htmlspecialchars($msg['media_filename'] ?? 'NULL') . '</td>';
    echo '<td>' . htmlspecialchars((string)($msg['media_size'] ?? 'NULL')) . '</td>';
    echo '<td>' . ($msg['from_me'] ? 'SIM' : 'NÃO') . '</td>';
    echo '<td>' . date('Y-m-d H:i:s', $msg['message_timestamp']) . '</td>';
    echo '</tr>';
}

echo '</table>';

// Verificar se diretório de uploads existe
$uploadDir = __DIR__ . '/uploads/chat_media/' . date('Y-m');
echo '<h2>Diretório de uploads:</h2>';
echo '<p><strong>Path:</strong> ' . htmlspecialchars($uploadDir) . '</p>';
echo '<p><strong>Existe:</strong> ' . (is_dir($uploadDir) ? 'SIM' : 'NÃO') . '</p>';
echo '<p><strong>Permissões:</strong> ' . (is_dir($uploadDir) ? substr(sprintf('%o', fileperms($uploadDir)), -4) : 'N/A') . '</p>';

if (is_dir($uploadDir)) {
    $files = scandir($uploadDir);
    $files = array_filter($files, function($f) { return $f !== '.' && $f !== '..'; });
    echo '<p><strong>Arquivos no diretório:</strong> ' . count($files) . '</p>';
    
    if (count($files) > 0) {
        echo '<ul>';
        foreach (array_slice($files, 0, 10) as $file) {
            $filePath = $uploadDir . '/' . $file;
            $fileSize = filesize($filePath);
            echo '<li>' . htmlspecialchars($file) . ' (' . number_format($fileSize / 1024, 2) . ' KB)</li>';
        }
        echo '</ul>';
    }
}

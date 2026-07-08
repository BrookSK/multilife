<?php
/**
 * Cria a pasta de mídia do chat. Execute uma vez e delete.
 */
require_once __DIR__ . '/app/bootstrap.php';
auth_require_login();

$dirs = [
    __DIR__ . '/uploads',
    __DIR__ . '/uploads/chat_media',
    __DIR__ . '/uploads/chat_media/' . date('Y-m'),
];

echo '<pre>';
foreach ($dirs as $dir) {
    if (is_dir($dir)) {
        echo "✅ $dir — já existe\n";
    } else {
        if (mkdir($dir, 0777, true)) {
            chmod($dir, 0777);
            echo "✅ $dir — criada\n";
        } else {
            echo "❌ $dir — FALHA ao criar\n";
        }
    }
}

// Testar escrita
$testFile = __DIR__ . '/uploads/chat_media/' . date('Y-m') . '/test.txt';
if (file_put_contents($testFile, 'ok') !== false) {
    echo "\n✅ Escrita na pasta OK\n";
    unlink($testFile);
} else {
    echo "\n❌ Falha ao escrever na pasta\n";
}

echo '</pre>';
echo '<br><a href="/admin_settings.php">Voltar</a>';
echo '<br><br><strong>Delete este arquivo após usar!</strong>';

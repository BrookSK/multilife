<?php
// Script para validar sintaxe JavaScript do chat_web.php

$_GET['chat'] = '5517981628213@s.whatsapp.net';
$_GET['type'] = 'all';

ob_start();
try {
    include __DIR__ . '/chat_web.php';
    $output = ob_get_clean();
} catch (Exception $e) {
    $output = ob_get_clean();
}

// Extrair todos os blocos JavaScript
preg_match_all('/<script[^>]*>(.*?)<\/script>/s', $output, $scripts);

header('Content-Type: text/html; charset=utf-8');
echo "<!DOCTYPE html><html><head><title>Validação JS</title>";
echo "<style>body{font-family:monospace;padding:20px;background:#1e1e1e;color:#00ff00;font-size:12px;} .error{color:#ff0000;} .warn{color:#ffff00;} .success{color:#00ffff;}</style>";
echo "</head><body>";

echo "<h1>🔍 VALIDAÇÃO DE SINTAXE JAVASCRIPT</h1>";
echo "<div class='success'>Total de blocos &lt;script&gt;: " . count($scripts[0]) . "</div>";
echo "<hr>";

foreach ($scripts[1] as $index => $script) {
    $blockNum = $index + 1;
    echo "<h2>BLOCO #$blockNum</h2>";
    
    // Contar chaves
    $openBraces = substr_count($script, '{');
    $closeBraces = substr_count($script, '}');
    $balance = $openBraces - $closeBraces;
    
    $color = $balance === 0 ? 'success' : 'error';
    echo "<div class='$color'>Chaves { : $openBraces | Chaves } : $closeBraces | Balanço: $balance</div>";
    
    if ($balance !== 0) {
        echo "<div class='error'>❌ ERRO: Chaves desbalanceadas!</div>";
        
        // Mostrar primeiras e últimas linhas
        $lines = explode("\n", $script);
        echo "<div class='warn'>Primeiras 5 linhas:</div>";
        for ($i = 0; $i < min(5, count($lines)); $i++) {
            echo "<div>" . htmlspecialchars(substr($lines[$i], 0, 100)) . "</div>";
        }
        
        echo "<div class='warn'>Últimas 5 linhas:</div>";
        for ($i = max(0, count($lines) - 5); $i < count($lines); $i++) {
            echo "<div>" . htmlspecialchars(substr($lines[$i], 0, 100)) . "</div>";
        }
    }
    
    // Contar parênteses
    $openParens = substr_count($script, '(');
    $closeParens = substr_count($script, ')');
    $parenBalance = $openParens - $closeParens;
    
    $parenColor = $parenBalance === 0 ? 'success' : 'error';
    echo "<div class='$parenColor'>Parênteses ( : $openParens | Parênteses ) : $closeParens | Balanço: $parenBalance</div>";
    
    echo "<div>Tamanho: " . strlen($script) . " caracteres</div>";
    echo "<hr>";
}

echo "</body></html>";
?>

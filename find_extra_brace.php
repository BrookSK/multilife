<?php
// Script para encontrar exatamente onde está a chave extra

$_GET['chat'] = '5517981628213@s.whatsapp.net';
$_GET['type'] = 'all';

ob_start();
try {
    include __DIR__ . '/chat_web.php';
    $output = ob_get_clean();
} catch (Exception $e) {
    $output = ob_get_clean();
}

// Extrair apenas o bloco JavaScript principal (script #4)
preg_match_all('/<script>(.*?)<\/script>/s', $output, $scripts);

echo "<!DOCTYPE html><html><head><title>Encontrar Chave Extra</title>";
echo "<style>body{font-family:monospace;padding:20px;background:#1e1e1e;color:#00ff00;font-size:12px;} .error{color:#ff0000;} .warn{color:#ffff00;} .success{color:#00ffff;} .line{margin:2px 0;}</style>";
echo "</head><body>";

echo "<h1>🔍 ENCONTRAR CHAVE EXTRA - BLOCO SCRIPT #4</h1>";

if (isset($scripts[1][3])) { // Script #4 (índice 3)
    $script = $scripts[1][3];
    $lines = explode("\n", $script);
    
    echo "<div class='success'>Total de linhas no script: " . count($lines) . "</div>";
    echo "<div class='success'>========================================</div>";
    
    $openCount = 0;
    $closeCount = 0;
    $balance = 0;
    $maxBalance = 0;
    $minBalance = 0;
    
    echo "<h2>ANÁLISE LINHA POR LINHA:</h2>";
    
    for ($i = 0; $i < count($lines); $i++) {
        $line = $lines[$i];
        $lineNum = $i + 1;
        
        $opensInLine = substr_count($line, '{');
        $closesInLine = substr_count($line, '}');
        
        $openCount += $opensInLine;
        $closeCount += $closesInLine;
        $balance += ($opensInLine - $closesInLine);
        
        if ($balance > $maxBalance) $maxBalance = $balance;
        if ($balance < $minBalance) $minBalance = $balance;
        
        // Mostrar apenas linhas com chaves ou linhas com balanço negativo
        if ($opensInLine > 0 || $closesInLine > 0 || $balance < 0) {
            $color = 'success';
            if ($balance < 0) $color = 'error';
            elseif ($opensInLine != $closesInLine) $color = 'warn';
            
            $linePreview = htmlspecialchars(substr($line, 0, 80));
            echo "<div class='line $color'>";
            echo sprintf("Linha %4d | { +%d } -%d | Balanço: %+3d | %s", 
                $lineNum, $opensInLine, $closesInLine, $balance, $linePreview);
            echo "</div>";
            
            // Se balanço ficou negativo, destacar
            if ($balance < 0) {
                echo "<div class='error'>  ^^^ CHAVE EXTRA AQUI! Balanço ficou negativo!</div>";
            }
        }
    }
    
    echo "<div class='success'>========================================</div>";
    echo "<div>Total de { abertos: $openCount</div>";
    echo "<div>Total de } fechados: $closeCount</div>";
    echo "<div>Balanço final: " . ($openCount - $closeCount) . "</div>";
    echo "<div>Balanço máximo: $maxBalance</div>";
    echo "<div>Balanço mínimo: $minBalance</div>";
    
    if ($minBalance < 0) {
        echo "<div class='error'>========================================</div>";
        echo "<div class='error'>❌ PROBLEMA ENCONTRADO!</div>";
        echo "<div class='error'>O balanço ficou negativo em algum ponto,</div>";
        echo "<div class='error'>indicando que há } fechando antes de { abrir.</div>";
        echo "<div class='error'>Verifique as linhas marcadas acima.</div>";
    }
    
    if ($openCount < $closeCount) {
        echo "<div class='error'>========================================</div>";
        echo "<div class='error'>❌ HÁ " . ($closeCount - $openCount) . " CHAVE(S) } A MAIS!</div>";
        echo "<div class='error'>Procure por } duplicado ou } sem { correspondente.</div>";
    }
    
} else {
    echo "<div class='error'>Bloco script #4 não encontrado!</div>";
}

echo "</body></html>";
?>

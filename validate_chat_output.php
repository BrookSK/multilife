<?php
// Script para validar o output HTML/JavaScript do chat_web.php

// Capturar output do chat_web.php
$_GET['chat'] = '5517981628213@s.whatsapp.net';
$_GET['type'] = 'all';

ob_start();
try {
    include __DIR__ . '/chat_web.php';
    $output = ob_get_clean();
} catch (Exception $e) {
    $output = ob_get_clean();
    echo "ERRO PHP: " . $e->getMessage();
    exit;
}

// Analisar output
$lines = explode("\n", $output);
$totalLines = count($lines);
$totalChars = strlen($output);

echo "<!DOCTYPE html><html><head><title>Validação Chat Output</title>";
echo "<style>body{font-family:monospace;padding:20px;background:#1e1e1e;color:#00ff00;} .error{color:#ff0000;} .warn{color:#ffff00;} .success{color:#00ffff;}</style>";
echo "</head><body>";

echo "<h1>🔍 VALIDAÇÃO DO OUTPUT - CHAT_WEB.PHP</h1>";
echo "<div class='success'>========================================</div>";
echo "<div>Total de linhas: $totalLines</div>";
echo "<div>Total de caracteres: $totalChars</div>";
echo "<div class='success'>========================================</div>";

// Contar tags script
$scriptOpen = substr_count($output, '<script>');
$scriptClose = substr_count($output, '</script>');
echo "<div>Tags &lt;script&gt; abertas: $scriptOpen</div>";
echo "<div>Tags &lt;/script&gt; fechadas: $scriptClose</div>";
if ($scriptOpen !== $scriptClose) {
    echo "<div class='error'>❌ ERRO: Tags &lt;script&gt; desbalanceadas!</div>";
    echo "<div class='error'>ESPERADO: $scriptOpen fechadas | RECEBIDO: $scriptClose fechadas</div>";
} else {
    echo "<div class='success'>✅ Tags &lt;script&gt; balanceadas</div>";
}

// Contar chaves JavaScript
preg_match_all('/<script>(.*?)<\/script>/s', $output, $scripts);
$totalOpenBraces = 0;
$totalCloseBraces = 0;
$totalOpenParens = 0;
$totalCloseParens = 0;

foreach ($scripts[1] as $idx => $script) {
    $openBraces = substr_count($script, '{');
    $closeBraces = substr_count($script, '}');
    $openParens = substr_count($script, '(');
    $closeParens = substr_count($script, ')');
    
    $totalOpenBraces += $openBraces;
    $totalCloseBraces += $closeBraces;
    $totalOpenParens += $openParens;
    $totalCloseParens += $closeParens;
    
    if ($openBraces !== $closeBraces) {
        echo "<div class='error'>❌ BLOCO SCRIPT #" . ($idx + 1) . ": Chaves desbalanceadas</div>";
        echo "<div class='error'>  Abertos: $openBraces | Fechados: $closeBraces | FALTAM: " . ($openBraces - $closeBraces) . "</div>";
        
        // Mostrar primeiras e últimas linhas do script problemático
        $scriptLines = explode("\n", $script);
        echo "<div class='warn'>  Primeiras 5 linhas:</div>";
        for ($i = 0; $i < min(5, count($scriptLines)); $i++) {
            echo "<div class='warn'>    " . htmlspecialchars(substr($scriptLines[$i], 0, 100)) . "</div>";
        }
        echo "<div class='warn'>  Últimas 5 linhas:</div>";
        for ($i = max(0, count($scriptLines) - 5); $i < count($scriptLines); $i++) {
            echo "<div class='warn'>    " . htmlspecialchars(substr($scriptLines[$i], 0, 100)) . "</div>";
        }
    }
}

echo "<div class='success'>========================================</div>";
echo "<div>Total de chaves { no JavaScript: $totalOpenBraces</div>";
echo "<div>Total de chaves } no JavaScript: $totalCloseBraces</div>";
if ($totalOpenBraces !== $totalCloseBraces) {
    echo "<div class='error'>❌ ERRO: Chaves JavaScript desbalanceadas!</div>";
    echo "<div class='error'>ESPERADO: $totalOpenBraces fechadas | RECEBIDO: $totalCloseBraces fechadas</div>";
    echo "<div class='error'>FALTAM: " . ($totalOpenBraces - $totalCloseBraces) . " chaves }</div>";
} else {
    echo "<div class='success'>✅ Chaves JavaScript balanceadas</div>";
}

echo "<div>Total de parênteses ( no JavaScript: $totalOpenParens</div>";
echo "<div>Total de parênteses ) no JavaScript: $totalCloseParens</div>";
if ($totalOpenParens !== $totalCloseParens) {
    echo "<div class='error'>❌ ERRO: Parênteses JavaScript desbalanceados!</div>";
    echo "<div class='error'>ESPERADO: $totalOpenParens fechados | RECEBIDO: $totalCloseParens fechados</div>";
} else {
    echo "<div class='success'>✅ Parênteses JavaScript balanceados</div>";
}

echo "<div class='success'>========================================</div>";

// Procurar por caracteres problemáticos
$problematicChars = [
    "'" => substr_count($output, "'"),
    '"' => substr_count($output, '"'),
    '`' => substr_count($output, '`'),
];

echo "<div>Aspas simples ': " . $problematicChars["'"] . "</div>";
echo "<div>Aspas duplas \": " . $problematicChars['"'] . "</div>";
echo "<div>Template literals `: " . $problematicChars['`'] . "</div>";

echo "<div class='success'>========================================</div>";
echo "<h2>CONCLUSÃO:</h2>";

if ($scriptOpen === $scriptClose && $totalOpenBraces === $totalCloseBraces && $totalOpenParens === $totalCloseParens) {
    echo "<div class='success'>✅ ESTRUTURA HTML/JavaScript VÁLIDA</div>";
    echo "<div class='warn'>Se ainda há erro no navegador, o problema pode ser:</div>";
    echo "<div class='warn'>1. Cache do navegador</div>";
    echo "<div class='warn'>2. Caracteres especiais em variáveis PHP</div>";
    echo "<div class='warn'>3. Código JavaScript semanticamente incorreto (mas sintaticamente válido)</div>";
} else {
    echo "<div class='error'>❌ ESTRUTURA HTML/JavaScript INVÁLIDA</div>";
    echo "<div class='error'>Corrija os erros acima antes de testar no navegador</div>";
}

echo "</body></html>";
?>

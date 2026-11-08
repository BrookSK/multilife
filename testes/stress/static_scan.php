<?php
/**
 * Varredura estática (não acessa banco nem rede).
 *
 * Procura em todos os .php chamadas prepare(...) cujo SQL usa o MESMO placeholder
 * nomeado mais de uma vez (ex.: ":msg" duas vezes). Com PDO::ATTR_EMULATE_PREPARES
 * desligado (app/db.php), isso gera "Invalid parameter number" e a gravação falha.
 *
 * Uso: php -d opcache.enable_cli=0 testes/stress/static_scan.php
 * Saída: lista "arquivo:linha  placeholders repetidos". Código de saída 1 se achar algo.
 */

declare(strict_types=1);

$root = realpath(__DIR__ . '/../..');
$skipDirs = ['vendor', 'node_modules', '.git', 'uploads'];
$found = 0;
$files = 0;

$it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    static function (SplFileInfo $f) use ($skipDirs): bool {
        return !($f->isDir() && in_array($f->getFilename(), $skipDirs, true));
    }
));

foreach ($it as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $files++;
    $tokens = @token_get_all((string)file_get_contents($file->getPathname()));
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (!is_array($t) || $t[0] !== T_STRING || strtolower($t[1]) !== 'prepare') {
            continue;
        }
        // Próximo token não-espaço deve ser "("
        $j = $i + 1;
        while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
        if ($j >= $n || $tokens[$j] !== '(') {
            continue;
        }
        $line = $t[2];
        $depth = 0;
        $sql = '';
        for ($k = $j; $k < $n; $k++) {
            $tk = $tokens[$k];
            if ($tk === '(') { $depth++; continue; }
            if ($tk === ')') { $depth--; if ($depth === 0) break; continue; }
            if (is_array($tk) && in_array($tk[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $sql .= ' ' . $tk[1];
            }
        }
        // Remove literais SQL internos simples ('...') que podem conter "HH:MM".
        $clean = preg_replace("/\\\\'[^']*\\\\'/", ' ', $sql);
        if (!preg_match_all('/(?<![:\w]):([a-zA-Z_]\w*)/', (string)$clean, $m)) {
            continue;
        }
        $counts = array_count_values($m[1]);
        $dups = array_keys(array_filter($counts, static fn($c) => $c > 1));
        if ($dups) {
            $found++;
            $rel = substr($file->getPathname(), strlen($root) + 1);
            echo $rel . ':' . $line . '  repetidos: :' . implode(', :', $dups) . PHP_EOL;
        }
    }
}

echo PHP_EOL . "Arquivos analisados: $files. Queries com placeholder repetido: $found." . PHP_EOL;
exit($found > 0 ? 1 : 0);

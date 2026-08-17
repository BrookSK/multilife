<?php
$json = file_get_contents(__DIR__ . '/extracted_data.json');
$data = json_decode($json, true);

// Mostrar primeiras linhas de dados de algumas planilhas
foreach (array_slice($data, 0, 5) as $file) {
    echo '=== ' . $file['operator'] . ' / ' . $file['specialty'] . " ===\n";
    foreach (array_slice($file['sheets'], 0, 2) as $sheet) {
        echo '  Aba: ' . $sheet['sheet_name'] . "\n";
        // Mostrar header + primeiras linhas de dados
        foreach (array_slice($sheet['rows'], 0, 5) as $i => $row) {
            echo '    Linha ' . $i . ': ' . implode(' | ', array_slice($row, 0, 10)) . "\n";
        }
        echo "\n";
    }
}

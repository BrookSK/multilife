<?php
$json = file_get_contents(__DIR__ . '/extracted_data.json');
$data = json_decode($json, true);

// Pegar uma planilha com bastante dados - ANERY FISIOTERAPIA, aba JULHO26
foreach ($data as $file) {
    if ($file['operator'] === 'ANERY' && strpos($file['specialty'], 'FISIOTERAPIA') !== false) {
        foreach ($file['sheets'] as $sheet) {
            if ($sheet['sheet_name'] === 'JULHO26') {
                // Mostrar header completo
                echo "HEADER completo:\n";
                echo json_encode($sheet['rows'][0], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
                // Mostrar primeiras linhas com dados completos
                for ($i = 1; $i <= min(6, count($sheet['rows'])-1); $i++) {
                    $row = $sheet['rows'][$i];
                    echo "--- Linha $i ---\n";
                    echo "Colunas 0-10 (metadata): " . json_encode(array_slice($row, 0, 11), JSON_UNESCAPED_UNICODE) . "\n";
                    // Colunas de dias (11 em diante para JULHO26 que tem GER.05 e GER.16)
                    $dayCols = array_slice($row, 11, 31);
                    echo "Colunas dias (11-41): " . json_encode($dayCols, JSON_UNESCAPED_UNICODE) . "\n";
                    // Total
                    $total = $row[42] ?? $row[43] ?? '';
                    echo "Total: $total\n\n";
                }
                break 2;
            }
        }
    }
}

echo "\n\n=== APAS FISIOTERAPIA - JULHO26 ===\n";
foreach ($data as $file) {
    if ($file['operator'] === 'APAS' && strpos($file['specialty'], 'FISIOTERAPIA') !== false) {
        foreach ($file['sheets'] as $sheet) {
            if ($sheet['sheet_name'] === 'JULHO26') {
                echo "HEADER:\n";
                echo json_encode($sheet['rows'][0], JSON_UNESCAPED_UNICODE) . "\n\n";
                for ($i = 1; $i <= min(5, count($sheet['rows'])-1); $i++) {
                    $row = $sheet['rows'][$i];
                    echo "--- Linha $i ---\n";
                    echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n\n";
                }
                break 2;
            }
        }
    }
}

echo "\n\n=== GANEP LAR ENFERMAGEM - JULHO26 ===\n";
foreach ($data as $file) {
    if ($file['operator'] === 'GANEP LAR' && strpos($file['specialty'], 'ENFERMAGEM') !== false) {
        foreach ($file['sheets'] as $sheet) {
            if ($sheet['sheet_name'] === 'JULHO26') {
                echo "HEADER:\n";
                echo json_encode($sheet['rows'][0], JSON_UNESCAPED_UNICODE) . "\n\n";
                for ($i = 1; $i <= min(5, count($sheet['rows'])-1); $i++) {
                    $row = $sheet['rows'][$i];
                    echo "--- Linha $i ---\n";
                    echo json_encode($row, JSON_UNESCAPED_UNICODE) . "\n\n";
                }
                break 2;
            }
        }
    }
}

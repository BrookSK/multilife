<?php
/**
 * Script para ler todas as planilhas .xlsx da pasta imports/planilhas
 * e extrair os dados em formato JSON para posterior geração de SQL.
 * 
 * Parser de XLSX nativo (sem dependências externas).
 * XLSX é um ZIP contendo XMLs.
 */

declare(strict_types=1);

$baseDir = __DIR__ . '/planilhas';

/**
 * Lê um arquivo .xlsx e retorna array de abas com seus dados.
 */
function readXlsx(string $filePath): array
{
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        echo "ERRO: Nao foi possivel abrir: $filePath\n";
        return [];
    }

    // 1. Ler shared strings
    $sharedStrings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $xml = new SimpleXMLElement($ssXml);
        $xml->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        foreach ($xml->si as $si) {
            // String pode estar em <t> direto ou em <r><t>
            $text = '';
            if (isset($si->t)) {
                $text = (string)$si->t;
            } elseif (isset($si->r)) {
                foreach ($si->r as $r) {
                    $text .= (string)$r->t;
                }
            }
            $sharedStrings[] = $text;
        }
    }

    // 2. Ler workbook.xml para pegar nomes das abas
    $wbXml = $zip->getFromName('xl/workbook.xml');
    $sheetNames = [];
    if ($wbXml !== false) {
        $xml = new SimpleXMLElement($wbXml);
        $xml->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        foreach ($xml->sheets->sheet as $sheet) {
            $sheetNames[] = (string)$sheet['name'];
        }
    }

    // 3. Ler relationships para mapear sheet names a arquivos
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    $sheetFiles = [];
    if ($relsXml !== false) {
        $xml = new SimpleXMLElement($relsXml);
        foreach ($xml->Relationship as $rel) {
            $type = (string)$rel['Type'];
            if (strpos($type, 'worksheet') !== false) {
                $rId = (string)$rel['Id'];
                $target = (string)$rel['Target'];
                $sheetFiles[$rId] = $target;
            }
        }
    }

    // Mapear sheet index -> rId
    $wbXml2 = $zip->getFromName('xl/workbook.xml');
    $sheetRIds = [];
    if ($wbXml2 !== false) {
        $xml = new SimpleXMLElement($wbXml2);
        $xml->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $xml->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $i = 0;
        foreach ($xml->sheets->sheet as $sheet) {
            $attrs = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $rId = (string)$attrs['id'];
            $sheetRIds[$i] = $rId;
            $i++;
        }
    }

    // 4. Ler cada sheet
    $result = [];
    foreach ($sheetNames as $idx => $sheetName) {
        $rId = $sheetRIds[$idx] ?? null;
        if (!$rId || !isset($sheetFiles[$rId])) {
            continue;
        }
        $target = $sheetFiles[$rId];
        // target pode ser "worksheets/sheet1.xml" ou "xl/worksheets/sheet1.xml"
        $sheetPath = 'xl/' . ltrim($target, '/');
        $sheetXml = $zip->getFromName($sheetPath);
        if ($sheetXml === false) {
            // Tentar sem "xl/"
            $sheetXml = $zip->getFromName($target);
            if ($sheetXml === false) {
                continue;
            }
        }

        $xml = new SimpleXMLElement($sheetXml);
        $xml->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $rows = [];
        if (isset($xml->sheetData->row)) {
            foreach ($xml->sheetData->row as $row) {
                $rowData = [];
                $maxCol = 0;
                foreach ($row->c as $cell) {
                    $ref = (string)$cell['r']; // ex: "A1", "B2"
                    $colIndex = cellColumnIndex($ref);
                    $maxCol = max($maxCol, $colIndex);

                    $type = (string)$cell['t'];
                    $value = '';

                    if ($type === 's') {
                        // shared string
                        $ssIdx = (int)(string)$cell->v;
                        $value = $sharedStrings[$ssIdx] ?? '';
                    } elseif ($type === 'inlineStr') {
                        $value = (string)$cell->is->t;
                    } elseif ($type === 'b') {
                        $value = (string)$cell->v;
                    } else {
                        $value = (string)$cell->v;
                    }

                    $rowData[$colIndex] = $value;
                }
                // Preencher buracos
                if (!empty($rowData)) {
                    $filledRow = [];
                    for ($c = 0; $c <= $maxCol; $c++) {
                        $filledRow[$c] = $rowData[$c] ?? '';
                    }
                    $rows[] = $filledRow;
                }
            }
        }

        $result[] = [
            'sheet_name' => $sheetName,
            'rows' => $rows,
        ];
    }

    $zip->close();
    return $result;
}

/**
 * Converte referência de célula (ex: "A1", "AB3") em índice de coluna (0-based).
 */
function cellColumnIndex(string $cellRef): int
{
    $letters = '';
    for ($i = 0; $i < strlen($cellRef); $i++) {
        if (ctype_alpha($cellRef[$i])) {
            $letters .= $cellRef[$i];
        } else {
            break;
        }
    }
    $letters = strtoupper($letters);
    $index = 0;
    $len = strlen($letters);
    for ($i = 0; $i < $len; $i++) {
        $index = $index * 26 + (ord($letters[$i]) - ord('A') + 1);
    }
    return $index - 1; // 0-based
}

// =====================================================
// MAIN: Percorrer todas as pastas e planilhas
// =====================================================

$allData = [];

$operators = glob($baseDir . '/*', GLOB_ONLYDIR);
foreach ($operators as $opDir) {
    $operatorName = basename($opDir);
    $files = glob($opDir . '/*.xlsx');
    
    foreach ($files as $file) {
        $fileName = basename($file, '.xlsx');
        // Extrair especialidade do nome do arquivo (remover nome da operadora)
        $specialty = trim(str_ireplace($operatorName, '', $fileName));
        $specialty = trim($specialty, ' _.-');
        // Remover trailing underscore que pode aparecer em nomes como "FISIOTERAPIA GANEP LAR_.xlsx"
        $specialty = rtrim($specialty, '_');
        
        echo "Lendo: $operatorName / $fileName\n";
        
        $sheets = readXlsx($file);
        
        $allData[] = [
            'operator' => $operatorName,
            'specialty' => $specialty,
            'file' => $fileName,
            'sheets' => $sheets,
        ];
    }
}

// Salvar como JSON para análise
$outputPath = __DIR__ . '/extracted_data.json';
file_put_contents($outputPath, json_encode($allData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "\nDados extraidos para: $outputPath\n";
echo "Total de arquivos processados: " . count($allData) . "\n";

// Mostrar resumo
foreach ($allData as $item) {
    echo "\n--- {$item['operator']} / {$item['specialty']} ---\n";
    foreach ($item['sheets'] as $sheet) {
        $rowCount = count($sheet['rows']);
        echo "  Aba: {$sheet['sheet_name']} ({$rowCount} linhas)\n";
        if ($rowCount > 0) {
            // Mostrar header (primeira linha)
            echo "  Header: " . implode(' | ', $sheet['rows'][0]) . "\n";
        }
    }
}

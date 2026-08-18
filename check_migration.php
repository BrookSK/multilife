<?php
/**
 * DIAGNÓSTICO: verifica se as migrations de cidade/estado foram aplicadas
 * Acesse: https://multilife.onsolutionsbrasil.com.br/check_migration.php
 * DELETE após uso!
 */
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
auth_require_login();
rbac_require_permission('users.manage');

header('Content-Type: text/html; charset=utf-8');

echo '<h2>Diagnóstico - Pacientes importados</h2>';
echo '<table border="1" cellpadding="4" cellspacing="0">';
echo '<tr><th>ID</th><th>Nome</th><th>address_city</th><th>address_state</th></tr>';

$stmt = db()->query("SELECT id, full_name, address_city, address_state FROM patients WHERE deleted_at IS NULL ORDER BY id ASC LIMIT 60");
$rows = $stmt->fetchAll();

foreach ($rows as $r) {
    $cityColor = empty($r['address_city']) ? 'red' : 'green';
    $stateColor = empty($r['address_state']) ? 'red' : 'green';
    echo '<tr>';
    echo '<td>' . $r['id'] . '</td>';
    echo '<td>' . htmlspecialchars($r['full_name']) . '</td>';
    echo '<td style="color:' . $cityColor . '">' . htmlspecialchars($r['address_city'] ?? 'NULL') . '</td>';
    echo '<td style="color:' . $stateColor . '">' . htmlspecialchars($r['address_state'] ?? 'NULL') . '</td>';
    echo '</tr>';
}
echo '</table>';

echo '<hr>';
echo '<h3>Rodar migration agora:</h3>';
echo '<form method="post"><button type="submit" name="run" value="1" style="padding:10px 20px;font-size:16px;cursor:pointer;background:#4CAF50;color:white;border:none;border-radius:4px">RODAR MIGRATION DE CIDADES AGORA</button></form>';
echo '<form method="post" style="margin-top:10px"><button type="submit" name="run_country" value="1" style="padding:10px 20px;font-size:16px;cursor:pointer;background:#2196F3;color:white;border:none;border-radius:4px">DEFINIR PAÍS = BRASIL PARA TODOS</button></form>';

if (isset($_POST['run_country'])) {
    echo '<hr><h3>Definindo país...</h3><pre>';
    $affected = db()->exec("UPDATE patients SET address_country = 'Brasil' WHERE deleted_at IS NULL AND (address_country IS NULL OR address_country = '')");
    echo "País 'Brasil' definido para {$affected} pacientes.\n";
    echo '</pre>';
    echo '<p style="color:green;font-weight:bold">Pronto!</p>';
}

if (isset($_POST['run'])) {
    echo '<hr><h3>Executando...</h3><pre>';
    
    $updates = [
        ['EIKO HIRATA SHIMABUKURO', 'Sorocaba', 'SP'],
        ['FERNANDA CRISTINA MEIKEN MONTEIRO', 'Sorocaba', 'SP'],
        ['JOSE CLOVIS LORENA', 'Votorantim', 'SP'],
        ['JOAO PEDRO CORREA GUIGUER', 'Sorocaba', 'SP'],
        ['LUIZ CARLOS GONÇALVES ANJA', 'Porto Feliz', 'SP'],
        ['LIVIA PACIFICO SILVA', 'Sorocaba', 'SP'],
        ['MARIA DE FATIMA COSSERMELLI SANTOS', 'Sorocaba', 'SP'],
        ['MARIA JOSE BAZZO CUCHERA', 'Sorocaba', 'SP'],
        ['ROMEU ANTONIO SGARIBOLDI DA SILVA', 'Porto Feliz', 'SP'],
        ['TALITA NABAS DE CARVALHO', 'Porto Feliz', 'SP'],
        ['RONILDO PAULO PEROTTI', 'Jundiaí', 'SP'],
        ['ISAURA DE MORAES LEONEL FERREIRA', 'Itapetininga', 'SP'],
        ['LUIZA CASERTA SCATENA', 'São Paulo', 'SP'],
        ['MARIA DO ROSARIO MARTINS HERMACULA', 'São Paulo', 'SP'],
        ['DIRCEU DOMINGUES DE OLIVEIRA', 'Sorocaba', 'SP'],
        ['DAVI LUCAS ZUCKER', 'Araçoiaba da Serra', 'SP'],
        ['DAVI LUIZ GOMES VIEIRA', 'Capela do Alto', 'SP'],
        ['DANIEL SANCHES', 'Sorocaba', 'SP'],
        ['ELVIRA RAMOS VIEIRA', 'Sorocaba', 'SP'],
        ['EDGAR STEFFEN', 'Sorocaba', 'SP'],
        ['HERMELINDA ROSA ALBERTINI', 'Sorocaba', 'SP'],
        ['ISABELLA FERNANDA PASSARO', 'Sorocaba', 'SP'],
        ['LUIS ANTONIO MACHADO PIMENTEL', 'Sorocaba', 'SP'],
        ['MARIANA CRISTINA RODRIGUES', 'Sorocaba', 'SP'],
        ['NELSON GUTIERREZ', 'Araçoiaba da Serra', 'SP'],
        ['SEBASTIÃO SANTOS DA SILVA', 'Sorocaba', 'SP'],
        ['SUELI APARECIDA TRINDADE RODRIGUES GARCIA', 'Sorocaba', 'SP'],
        ['VIRGINIA VERCELINO PRIMO', 'Boituva', 'SP'],
        ['LUCAS HENRIQUE OLIVEIRO CAMARGO', 'Sorocaba', 'SP'],
        ['IRENE MACAGNAN GALVAO DE MOURA', 'Bauru', 'SP'],
        ['CLAUDIONOR RIBEIRO AGUIAR', 'Bauru', 'SP'],
        ['NIRDE ROSALIN BARBIERI', 'Bauru', 'SP'],
        ['FRANCISCO JOAQUIM DA SILVA', 'Mauá', 'SP'],
        ['GILDA ROSANA LEONEL', 'Guarulhos', 'SP'],
        ['JOSE CARLOS PERES', 'Bauru', 'SP'],
        ['SYLVIA MARIA CANOAS MIZIARA', 'Barretos', 'SP'],
        ['THELMA LOPES ONOFRE DE FREITAS RIBEIRO', 'Campinas', 'SP'],
        ['HELENA KLEIN ALMEIDA', 'São Carlos', 'SP'],
        ['LIGIA DA PENHA CAMPOS', 'Ipatinga', 'MG'],
        ['OTTO FERREIRA', 'Jundiaí', 'SP'],
        ['SHIRLEI DA CUNHA OLIVEIRA', 'Suzano', 'SP'],
        ['ELCO APPARECIDO FORNAZALI', 'Rio Claro', 'SP'],
        ['GRACI LUIZA DE GODOI FORTES', 'São Paulo', 'SP'],
        ['NAELCIO FERREIRA', 'Campinas', 'SP'],
        ['ANTONIO BUENO DE CAMARGO', 'Joanópolis', 'SP'],
        ['EDINAMARA APARECIDA BISPO DE SOUZA', 'Buritama', 'SP'],
        ['THEREZA VICENTIM CHERONE', 'José Bonifácio', 'SP'],
        ['NATHALY ROCHA DE SOUZA', 'Itaquaquecetuba', 'SP'],
    ];

    $stmt = db()->prepare("UPDATE patients SET address_city = :city, address_state = :state WHERE LOWER(full_name) = LOWER(:name) AND deleted_at IS NULL");
    
    $updated = 0;
    foreach ($updates as [$name, $city, $state]) {
        $stmt->execute(['name' => $name, 'city' => $city, 'state' => $state]);
        $affected = $stmt->rowCount();
        $updated += $affected;
        echo "  {$name} => {$city}/{$state} (linhas afetadas: {$affected})\n";
    }
    
    // Atualizar país para Brasil em todos os pacientes importados
    $stmtCountry = db()->exec("UPDATE patients SET address_country = 'Brasil' WHERE deleted_at IS NULL AND (address_country IS NULL OR address_country = '')");
    echo "\nPaís 'Brasil' definido para todos os pacientes sem país.\n";
    
    echo "\n\nTotal atualizado: {$updated} pacientes\n";
    echo '</pre>';
    echo '<p style="color:green;font-weight:bold">Pronto! Recarregue a página do paciente para ver a cidade.</p>';
}

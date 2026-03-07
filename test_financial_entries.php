<?php
// Teste para verificar lançamentos financeiros criados

require_once __DIR__ . '/app/bootstrap.php';
auth_require_login();

echo "<h1>Teste de Lançamentos Financeiros</h1>";

// Buscar todos os lançamentos
$stmt = db()->prepare("
    SELECT 
        fe.*,
        p.full_name as patient_name,
        u.name as professional_name
    FROM financial_entries fe
    LEFT JOIN patients p ON p.id = fe.patient_id
    LEFT JOIN users u ON u.id = fe.professional_user_id
    ORDER BY fe.created_at DESC
    LIMIT 20
");
$stmt->execute();
$entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<h2>Total de lançamentos: " . count($entries) . "</h2>";

if (count($entries) > 0) {
    echo "<table border='1' style='border-collapse:collapse;width:100%'>";
    echo "<tr>";
    echo "<th>ID</th>";
    echo "<th>Tipo</th>";
    echo "<th>Categoria</th>";
    echo "<th>Invoice ID</th>";
    echo "<th>Assignment ID</th>";
    echo "<th>Paciente</th>";
    echo "<th>Profissional</th>";
    echo "<th>Valor</th>";
    echo "<th>Data</th>";
    echo "<th>Status</th>";
    echo "<th>Criado em</th>";
    echo "</tr>";
    
    foreach ($entries as $entry) {
        echo "<tr>";
        echo "<td>" . $entry['id'] . "</td>";
        echo "<td>" . $entry['entry_type'] . "</td>";
        echo "<td>" . $entry['category'] . "</td>";
        echo "<td>" . ($entry['invoice_id'] ?? '-') . "</td>";
        echo "<td>" . ($entry['assignment_id'] ?? '-') . "</td>";
        echo "<td>" . ($entry['patient_name'] ?? '-') . "</td>";
        echo "<td>" . ($entry['professional_name'] ?? '-') . "</td>";
        echo "<td>R$ " . number_format((float)$entry['amount'], 2, ',', '.') . "</td>";
        echo "<td>" . $entry['entry_date'] . "</td>";
        echo "<td>" . $entry['status'] . "</td>";
        echo "<td>" . $entry['created_at'] . "</td>";
        echo "</tr>";
    }
    
    echo "</table>";
} else {
    echo "<p style='color:red;font-weight:bold'>NENHUM LANÇAMENTO ENCONTRADO!</p>";
}

// Buscar especificamente os lançamentos do assignment 5
echo "<hr>";
echo "<h2>Lançamentos do Atendimento #5</h2>";

$stmt2 = db()->prepare("
    SELECT * FROM financial_entries
    WHERE assignment_id = 5
    ORDER BY created_at DESC
");
$stmt2->execute();
$entries5 = $stmt2->fetchAll(PDO::FETCH_ASSOC);

echo "<p>Total: " . count($entries5) . " lançamentos</p>";

if (count($entries5) > 0) {
    echo "<pre>";
    print_r($entries5);
    echo "</pre>";
}

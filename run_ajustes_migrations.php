<?php
/**
 * ENDPOINT para aplicar as migrations dos Ajustes Operacionais Globais.
 * Acesse logado como admin: /run_ajustes_migrations.php
 *
 * É idempotente: pode ser rodado várias vezes. Erros de "já existe"/"duplicate"
 * são ignorados. Lista as migrations deste pacote de ajustes.
 *
 * IMPORTANTE: considere remover este arquivo do servidor após aplicar tudo.
 */

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('users.manage');

header('Content-Type: text/plain; charset=utf-8');

// Migrations deste pacote de ajustes, na ordem de aplicação.
$migrations = [
    'migrations/2026-10-03_0001_neutralize_substitution_templates.sql',
    'migrations/2026-10-03_0002_demands_location_raw.sql',
    'migrations/2026-10-03_0003_assignment_coverages.sql',
    'migrations/2026-10-03_0004_demand_confidential.sql',
    'migrations/2026-10-03_0005_finance_invoices.sql',
    'migrations/2026-10-03_0006_imported_contacts.sql',
    'migrations/2026-10-03_0007_insurer_orientations.sql',
    'migrations/2026-10-03_0008_preadmission_orientation_placeholders.sql',
    'migrations/2026-10-03_0009_seed_insurer_orientations.sql',
];

$pdo = db();

echo "=== MIGRATIONS - AJUSTES OPERACIONAIS GLOBAIS ===\n\n";

foreach ($migrations as $file) {
    $path = __DIR__ . '/' . $file;
    if (!file_exists($path)) {
        echo "[!] Arquivo não encontrado: {$file}\n\n";
        continue;
    }

    echo "--- Executando: {$file} ---\n";
    $sql = (string)file_get_contents($path);

    // Separa por ';' (statements simples; os .sql deste pacote não usam DELIMITER).
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        static fn($s) => $s !== ''
    );

    $executed = 0;
    $skips = 0;

    foreach ($statements as $stmt) {
        // Remove linhas de comentário "--" para não enviar statement vazio.
        $clean = trim(preg_replace('/^\s*--[^\n]*$/m', '', $stmt));
        if ($clean === '') {
            continue;
        }

        try {
            $pdo->exec($clean);
            $executed++;
        } catch (\PDOException $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'Duplicate') !== false || stripos($msg, 'already exists') !== false) {
                echo "  [skip] já aplicado (ok)\n";
                $skips++;
            } else {
                echo "  [ERRO] {$msg}\n";
                echo "  SQL: " . substr($clean, 0, 160) . "...\n\n";
            }
        }
    }

    echo "  => {$executed} statements executados, {$skips} skips\n\n";
}

echo "=== CONCLUÍDO ===\n";
echo "\nDica: remova este arquivo (run_ajustes_migrations.php) do servidor após aplicar.\n";

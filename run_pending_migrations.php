<?php
/**
 * ENDPOINT TEMPORÁRIO - Roda migrations pendentes
 * Acesse: https://multilife.onsolutionsbrasil.com.br/run_pending_migrations.php
 * 
 * IMPORTANTE: Delete este arquivo após uso!
 */

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

// Verificação de segurança: só admin logado pode rodar
auth_require_login();
rbac_require_permission('users.manage');

header('Content-Type: text/plain; charset=utf-8');

$migrations = [
    'migrations/2026-08-14_0002_fix_duplicate_professionals_and_specialties.sql',
    'migrations/2026-08-14_0003_update_patients_city.sql',
    'migrations/2026-08-14_0004_update_patients_city_and_state.sql',
];

$pdo = db();

echo "=== EXECUTANDO MIGRATIONS PENDENTES ===\n\n";

foreach ($migrations as $file) {
    $path = __DIR__ . '/' . $file;
    if (!file_exists($path)) {
        echo "[!] Arquivo não encontrado: {$file}\n";
        continue;
    }

    echo "--- Executando: {$file} ---\n";
    $sql = file_get_contents($path);

    // Separar por statements (split por ;)
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        fn($s) => $s !== '' && !str_starts_with($s, '--')
    );

    $executed = 0;
    $errors = 0;

    foreach ($statements as $stmt) {
        // Pular comentários puros
        $clean = trim(preg_replace('/--[^\n]*/', '', $stmt));
        if ($clean === '') continue;

        try {
            $pdo->exec($stmt);
            $executed++;
        } catch (\PDOException $e) {
            $errors++;
            $msg = $e->getMessage();
            // Ignorar erros de "já existe" ou "duplicate"
            if (str_contains($msg, 'Duplicate') || str_contains($msg, 'already exists')) {
                echo "  [skip] Registro já existe (ok)\n";
            } else {
                echo "  [ERRO] {$msg}\n";
                echo "  SQL: " . substr($stmt, 0, 120) . "...\n\n";
            }
        }
    }

    echo "  => {$executed} statements executados, {$errors} erros/skips\n\n";
}

echo "\n=== MIGRATIONS CONCLUÍDAS ===\n";
echo "\n⚠️  DELETE este arquivo (run_pending_migrations.php) do servidor após uso!\n";

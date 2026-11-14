<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

// Arquiva (storage/log_archive/integration_logs/*.jsonl.gz) e apaga da tabela
// integration_logs os registros mais antigos que o prazo de retenção.
// Sugestão de cron: 1x por dia, ex.: /cron/integration_logs_cleanup.php?token=SEU_TOKEN
// (a limpeza também roda sozinha a cada 10 min, em lotes pequenos).

@set_time_limit(120);
$r = integration_logs_maintain(90);
echo 'archived=' . $r['archived'] . ' deleted=' . $r['deleted'] . ' more=' . ($r['more'] ? '1' : '0')
    . ($r['error'] !== '' ? ' error=' . $r['error'] : '') . "\n";

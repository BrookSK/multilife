<?php

declare(strict_types=1);

/**
 * Council Validator — Serviço centralizado de validação de registros profissionais.
 *
 * Arquitetura com camada de abstração de provedores:
 *  - ConsultarIoProvider (API especializada — prioridade 1)
 *  - InfosimplesProvider (API especializada — prioridade 2)
 *  - PortalDirectProvider (scraping direto — fallback final)
 *
 * Sistema de fallback automático: tenta provedores em ordem de prioridade.
 * Cache MySQL com validade de 24h.
 * Log detalhado de cada consulta.
 *
 * Conselhos suportados: CRP, CRN, COREN, CREFITO, CRM, CRO, CREA, OAB
 */

require_once __DIR__ . '/council_providers/CouncilProviderInterface.php';
require_once __DIR__ . '/council_providers/AbstractProvider.php';
require_once __DIR__ . '/council_providers/ConsultarIoProvider.php';
require_once __DIR__ . '/council_providers/InfosimplesProvider.php';
require_once __DIR__ . '/council_providers/PortalDirectProvider.php';

// ---------------------------------------------------------------------------
// Constantes
// ---------------------------------------------------------------------------

const COUNCIL_SUPPORTED = ['CRP', 'CRN', 'COREN', 'CREFITO', 'CRM', 'CRO', 'CREA', 'OAB'];
const COUNCIL_CACHE_TTL_HOURS = 24;

// ---------------------------------------------------------------------------
// Função pública principal
// ---------------------------------------------------------------------------

/**
 * Valida um registro profissional utilizando provedores de API com fallback.
 *
 * @param string $councilAbbr  Sigla do conselho (CRP, CRN, COREN, CREFITO, CRM, CRO, CREA, OAB)
 * @param string $number       Número do registro
 * @param string $state        UF do conselho regional (ex: SP, RJ)
 * @param bool   $forceRefresh Ignora cache e força nova consulta
 * @return array Resultado padronizado
 */
function council_validate(string $councilAbbr, string $number, string $state, bool $forceRefresh = false): array
{
    $abbr  = strtoupper(trim($councilAbbr));
    $num   = trim($number);
    $uf    = strtoupper(trim($state));
    $start = date('Y-m-d H:i:s');
    $startMicro = microtime(true);

    // Validação de entrada
    if (!in_array($abbr, COUNCIL_SUPPORTED, true)) {
        return council_build_result([
            'success' => false,
            'valid'   => false,
            'name'    => null,
            'status'  => 'CONSELHO NÃO SUPORTADO',
            'source'  => 'Sistema',
            'error'   => "Conselho '$abbr' não é suportado. Suportados: " . implode(', ', COUNCIL_SUPPORTED),
        ], $abbr, $num, $uf, $start);
    }

    // Verifica cache (ignorado quando forceRefresh=true)
    if (!$forceRefresh) {
        $cached = council_cache_get($abbr, $num, $uf);
        if ($cached !== null) {
            // Não serve cache de resultados de erro
            $cachedSuccess = $cached['success'] ?? false;
            if ($cachedSuccess) {
                return $cached;
            }
            // Limpa cache de erro para forçar nova consulta
            council_cache_delete($abbr, $num, $uf);
        }
    } else {
        council_cache_delete($abbr, $num, $uf);
    }

    // Obtém provedores ordenados por prioridade
    $providers = council_get_providers($abbr);
    $errors    = [];

    // Sistema de fallback: tenta cada provedor em ordem
    foreach ($providers as $provider) {
        /** @var CouncilProviderInterface $provider */
        $providerName = $provider->getName();

        try {
            $result = $provider->validate($abbr, $num, $uf);

            // Se o provedor retornou sucesso (mesmo que registro não encontrado), aceita
            if (!empty($result['success'])) {
                $result = council_build_result($result, $abbr, $num, $uf, $start);
                $result['provider_used'] = $providerName;

                // Persiste cache e log
                council_cache_set($abbr, $num, $uf, $result);
                council_log($abbr, $num, $uf, $result, $providerName, $startMicro);

                return $result;
            }

            // Provedor retornou erro — registra e tenta próximo
            $errorMsg = $result['error'] ?? 'Erro desconhecido';
            $errors[] = $providerName . ': ' . $errorMsg;

            // Se o erro indica "consulta manual necessária" (CAPTCHA, SPA, etc.)
            // e não há mais provedores de API, retorna este resultado
            $isManualRequired = !empty($result['requires_manual']) || !empty($result['has_captcha']);

            // Continua para o próximo provedor...

        } catch (\Throwable $e) {
            $errors[] = $providerName . ': Exceção — ' . $e->getMessage();
            error_log('[CouncilValidator] Exceção no provedor ' . $providerName . ': ' . $e->getMessage());
        }
    }

    // Todos os provedores falharam
    $lastProviderResult = null;
    if (!empty($providers)) {
        // Tenta retornar o resultado do último provedor (pode ter info útil como manual_url)
        try {
            $lastProvider = end($providers);
            $lastProviderResult = $lastProvider->validate($abbr, $num, $uf);
        } catch (\Throwable) {
            // ignora
        }
    }

    $fallbackResult = $lastProviderResult ?? [
        'success' => false,
        'valid'   => false,
        'name'    => null,
        'status'  => 'ERRO',
        'source'  => 'Sistema',
        'error'   => 'Todos os provedores falharam.',
    ];

    $fallbackResult['success'] = false;
    $fallbackResult['all_errors'] = $errors;
    if (!isset($fallbackResult['error']) || $fallbackResult['error'] === '') {
        $fallbackResult['error'] = 'Todos os provedores falharam: ' . implode(' | ', $errors);
    }

    $result = council_build_result($fallbackResult, $abbr, $num, $uf, $start);
    council_log($abbr, $num, $uf, $result, 'fallback_exhausted', $startMicro);

    return $result;
}

/**
 * Retorna a lista de provedores disponíveis e configurados para um conselho,
 * ordenados por prioridade (menor = maior prioridade).
 *
 * @return CouncilProviderInterface[]
 */
function council_get_providers(string $councilAbbr): array
{
    $allProviders = council_get_all_providers();
    $applicable   = [];

    foreach ($allProviders as $provider) {
        if ($provider->supports($councilAbbr) && $provider->isConfigured()) {
            $applicable[] = $provider;
        }
    }

    // Ordena por prioridade (menor número = maior prioridade)
    usort($applicable, function (CouncilProviderInterface $a, CouncilProviderInterface $b) {
        return $a->getPriority() <=> $b->getPriority();
    });

    return $applicable;
}

/**
 * Retorna todas as instâncias de provedores registrados no sistema.
 *
 * @return CouncilProviderInterface[]
 */
function council_get_all_providers(): array
{
    static $providers = null;

    if ($providers === null) {
        $providers = [
            new ConsultarIoProvider(),
            new InfosimplesProvider(),
            new PortalDirectProvider(),
        ];
    }

    return $providers;
}

/**
 * Retorna informações sobre os provedores configurados (para painel admin).
 */
function council_get_providers_info(): array
{
    $info = [];
    foreach (council_get_all_providers() as $provider) {
        $info[] = [
            'name'       => $provider->getName(),
            'configured' => $provider->isConfigured(),
            'priority'   => $provider->getPriority(),
            'councils'   => $provider->supportedCouncils(),
        ];
    }

    usort($info, fn($a, $b) => $a['priority'] <=> $b['priority']);

    return $info;
}

// ---------------------------------------------------------------------------
// Helpers de resultado padronizado
// ---------------------------------------------------------------------------

/**
 * Garante que o resultado tenha todos os campos obrigatórios.
 */
function council_build_result(array $result, string $abbr, string $num, string $uf, string $consultedAt): array
{
    $result['registry_type']   = $abbr;
    $result['registry_number'] = $num;
    $result['state']           = $uf;
    $result['consulted_at']    = $consultedAt;

    // Garante campos mínimos
    if (!isset($result['success'])) {
        $result['success'] = false;
    }
    if (!isset($result['valid'])) {
        $result['valid'] = false;
    }
    if (!isset($result['name'])) {
        $result['name'] = null;
    }
    if (!isset($result['status'])) {
        $result['status'] = 'DESCONHECIDO';
    }
    if (!isset($result['source'])) {
        $result['source'] = 'Sistema';
    }

    return $result;
}

// ---------------------------------------------------------------------------
// Cache MySQL — tabela council_validation_cache
// ---------------------------------------------------------------------------

function council_cache_get(string $abbr, string $number, string $uf): ?array
{
    try {
        $stmt = db()->prepare(
            'SELECT result_json FROM council_validation_cache
             WHERE council_abbr = :abbr AND registry_number = :num AND council_state = :uf
               AND expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute(['abbr' => $abbr, 'num' => $number, 'uf' => $uf]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $decoded = json_decode((string)$row['result_json'], true);
        if (!is_array($decoded)) {
            return null;
        }
        $decoded['from_cache'] = true;
        return $decoded;
    } catch (\Throwable) {
        return null;
    }
}

function council_cache_set(string $abbr, string $number, string $uf, array $result): void
{
    // Não cacheia resultados de erro — devem ser revalidados sempre
    if (empty($result['success'])) {
        return;
    }

    try {
        $stmt = db()->prepare(
            'INSERT INTO council_validation_cache
                (council_abbr, registry_number, council_state, result_json, expires_at)
             VALUES (:abbr, :num, :uf, :json, DATE_ADD(NOW(), INTERVAL ' . COUNCIL_CACHE_TTL_HOURS . ' HOUR))
             ON DUPLICATE KEY UPDATE
                result_json = VALUES(result_json),
                expires_at  = VALUES(expires_at),
                updated_at  = NOW()'
        );
        $stmt->execute([
            'abbr' => $abbr,
            'num'  => $number,
            'uf'   => $uf,
            'json' => json_encode($result, JSON_UNESCAPED_UNICODE),
        ]);
    } catch (\Throwable) {
        // Cache é best-effort; não interrompe o fluxo
    }
}

function council_cache_delete(string $abbr, string $number, string $uf): void
{
    try {
        $stmt = db()->prepare(
            'DELETE FROM council_validation_cache
             WHERE council_abbr = :abbr AND registry_number = :num AND council_state = :uf'
        );
        $stmt->execute(['abbr' => $abbr, 'num' => $number, 'uf' => $uf]);
    } catch (\Throwable) {
        // best-effort
    }
}

// ---------------------------------------------------------------------------
// Log detalhado — tabela council_validation_logs
// ---------------------------------------------------------------------------

function council_log(string $abbr, string $number, string $uf, array $result, string $providerUsed = '', float $startMicro = 0): void
{
    $responseTimeMs = $startMicro > 0 ? (int)((microtime(true) - $startMicro) * 1000) : null;

    try {
        $stmt = db()->prepare(
            'INSERT INTO council_validation_logs
                (council_abbr, registry_number, council_state, success, valid, name_found,
                 status_found, source, error_message, raw_result_json,
                 provider_used, response_time_ms)
             VALUES
                (:abbr, :num, :uf, :success, :valid, :name, :status, :source, :error, :raw,
                 :provider, :time_ms)'
        );
        $stmt->execute([
            'abbr'     => $abbr,
            'num'      => $number,
            'uf'       => $uf,
            'success'  => (int)($result['success'] ?? false),
            'valid'    => (int)($result['valid'] ?? false),
            'name'     => $result['name'] ?? null,
            'status'   => $result['status'] ?? null,
            'source'   => $result['source'] ?? null,
            'error'    => $result['error'] ?? null,
            'raw'      => json_encode($result, JSON_UNESCAPED_UNICODE),
            'provider' => $providerUsed !== '' ? $providerUsed : ($result['source'] ?? 'unknown'),
            'time_ms'  => $responseTimeMs,
        ]);
    } catch (\Throwable $e) {
        error_log('[CouncilValidator] Erro ao gravar log: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Funções auxiliares para o painel administrativo
// ---------------------------------------------------------------------------

/**
 * Retorna o histórico de validações para uma candidatura específica.
 */
function council_validation_history(int $applicationId, int $limit = 20): array
{
    try {
        $stmt = db()->prepare(
            'SELECT * FROM council_validation_logs
             WHERE triggered_by_application_id = :aid
             ORDER BY id DESC
             LIMIT :lim'
        );
        $stmt->bindValue('aid', $applicationId, PDO::PARAM_INT);
        $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (\Throwable) {
        return [];
    }
}

/**
 * Retorna o histórico de validações para um registro específico.
 */
function council_validation_history_by_registry(string $abbr, string $number, string $uf, int $limit = 20): array
{
    try {
        $stmt = db()->prepare(
            'SELECT * FROM council_validation_logs
             WHERE council_abbr = :abbr AND registry_number = :num AND council_state = :uf
             ORDER BY id DESC
             LIMIT :lim'
        );
        $stmt->bindValue('abbr', $abbr, PDO::PARAM_STR);
        $stmt->bindValue('num', $number, PDO::PARAM_STR);
        $stmt->bindValue('uf', $uf, PDO::PARAM_STR);
        $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (\Throwable) {
        return [];
    }
}

/**
 * Retorna estatísticas gerais de validações (para dashboard admin).
 */
function council_validation_stats(): array
{
    try {
        $db = db();

        $total = (int)$db->query('SELECT COUNT(*) FROM council_validation_logs')->fetchColumn();
        $valid = (int)$db->query('SELECT COUNT(*) FROM council_validation_logs WHERE valid = 1')->fetchColumn();
        $errors = (int)$db->query('SELECT COUNT(*) FROM council_validation_logs WHERE success = 0')->fetchColumn();

        $lastStmt = $db->query('SELECT * FROM council_validation_logs ORDER BY id DESC LIMIT 1');
        $last = $lastStmt->fetch() ?: null;

        $byCouncil = $db->query(
            'SELECT council_abbr, COUNT(*) as total, SUM(valid) as valid_count, SUM(success = 0) as error_count
             FROM council_validation_logs
             GROUP BY council_abbr
             ORDER BY total DESC'
        )->fetchAll();

        $byProvider = $db->query(
            'SELECT provider_used, COUNT(*) as total, SUM(valid) as valid_count, AVG(response_time_ms) as avg_time_ms
             FROM council_validation_logs
             WHERE provider_used IS NOT NULL
             GROUP BY provider_used
             ORDER BY total DESC'
        )->fetchAll();

        return [
            'total_queries'   => $total,
            'valid_results'   => $valid,
            'error_results'   => $errors,
            'last_query'      => $last,
            'by_council'      => $byCouncil,
            'by_provider'     => $byProvider,
        ];
    } catch (\Throwable) {
        return [
            'total_queries'   => 0,
            'valid_results'   => 0,
            'error_results'   => 0,
            'last_query'      => null,
            'by_council'      => [],
            'by_provider'     => [],
        ];
    }
}

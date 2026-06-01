<?php

declare(strict_types=1);

/**
 * Council Validator — Validação de registros profissionais nos portais públicos dos conselhos brasileiros.
 *
 * Suporta: CRP, CRN, COREN, CREFITO, CRM, CRO, CREA, OAB
 *
 * Estratégia por conselho:
 *  - Prioriza endpoints JSON/AJAX internos quando disponíveis.
 *  - Fallback para parsing HTML via DOMDocument + DOMXPath.
 *  - Cache em MySQL (tabela council_validation_cache) por 24h.
 *  - Log detalhado em council_validation_logs.
 */

// ---------------------------------------------------------------------------
// Função pública principal
// ---------------------------------------------------------------------------

/**
 * Valida um registro profissional no portal oficial do conselho.
 *
 * @param string $councilAbbr  Sigla do conselho (CRP, CRN, COREN, CREFITO, CRM, CRO, CREA, OAB)
 * @param string $number       Número do registro (apenas dígitos ou com formatação)
 * @param string $state        UF do conselho regional (ex: SP, RJ)
 * @return array               Resultado padronizado (ver docblock abaixo)
 */
function council_validate(string $councilAbbr, string $number, string $state, bool $forceRefresh = false): array
{
    $abbr  = strtoupper(trim($councilAbbr));
    $num   = trim($number);
    $uf    = strtoupper(trim($state));
    $start = date('Y-m-d H:i:s');

    // Verifica cache (ignorado quando forceRefresh=true ou resultado anterior era ERRO)
    if (!$forceRefresh) {
        $cached = council_cache_get($abbr, $num, $uf);
        if ($cached !== null) {
            // Não serve cache de resultados de erro — sempre revalida
            $cachedStatus = strtoupper((string)($cached['status'] ?? ''));
            if ($cachedStatus !== 'ERRO' && ($cached['success'] ?? false) !== false) {
                return $cached;
            }
            // Limpa o cache de erro para forçar nova consulta
            council_cache_delete($abbr, $num, $uf);
        }
    } else {
        council_cache_delete($abbr, $num, $uf);
    }

    // Despacha para o handler correto
    try {
        $result = match ($abbr) {
            'CRP'     => council_crp($num, $uf),
            'CRN'     => council_crn($num, $uf),
            'COREN'   => council_coren($num, $uf),
            'CREFITO' => council_crefito($num, $uf),
            'CRM'     => council_crm($num, $uf),
            'CRO'     => council_cro($num, $uf),
            'CREA'    => council_crea($num, $uf),
            'OAB'     => council_oab($num, $uf),
            default   => council_unsupported($abbr),
        };
    } catch (Throwable $e) {
        $result = council_error_result($abbr, $num, $uf, 'Exceção: ' . $e->getMessage());
    }

    // Garante campos obrigatórios
    $result['registry_type']   = $abbr;
    $result['registry_number'] = $num;
    $result['state']           = $uf;
    $result['consulted_at']    = $start;

    // Persiste cache e log
    council_cache_set($abbr, $num, $uf, $result);
    council_log($abbr, $num, $uf, $result);

    return $result;
}

// ---------------------------------------------------------------------------
// Helpers de resultado padronizado
// ---------------------------------------------------------------------------

function council_success(string $name, string $status, string $source, array $extra = []): array
{
    return array_merge([
        'success' => true,
        'valid'   => true,
        'name'    => $name,
        'status'  => $status,
        'source'  => $source,
    ], $extra);
}

function council_not_found(string $source): array
{
    return [
        'success' => true,
        'valid'   => false,
        'name'    => null,
        'status'  => 'NÃO ENCONTRADO',
        'source'  => $source,
    ];
}

function council_error_result(string $abbr, string $num, string $uf, string $reason): array
{
    return [
        'success'          => false,
        'valid'            => false,
        'name'             => null,
        'status'           => 'ERRO',
        'source'           => 'Portal Oficial ' . $abbr,
        'error'            => $reason,
        'has_captcha'      => str_contains(strtolower($reason), 'captcha'),
        'has_cloudflare'   => str_contains(strtolower($reason), 'cloudflare'),
        'has_auth'         => str_contains(strtolower($reason), 'autenticação') || str_contains(strtolower($reason), 'login'),
        'has_ip_block'     => str_contains(strtolower($reason), 'bloqueio') || str_contains(strtolower($reason), 'ip block'),
    ];
}

function council_unsupported(string $abbr): array
{
    return [
        'success' => false,
        'valid'   => false,
        'name'    => null,
        'status'  => 'CONSELHO NÃO SUPORTADO',
        'source'  => $abbr,
        'error'   => "Conselho '$abbr' não é suportado por este sistema.",
    ];
}

// ---------------------------------------------------------------------------
// HTTP helper interno (cURL com User-Agent de browser)
// ---------------------------------------------------------------------------

/**
 * Realiza requisição HTTP simulando browser real.
 * Retorna ['status', 'body', 'headers', 'error'].
 */
function council_http(
    string $method,
    string $url,
    array  $headers = [],
    ?string $body = null,
    int    $timeout = 20,
    bool   $followRedirects = true
): array {
    $ch = curl_init();
    if ($ch === false) {
        return ['status' => 0, 'body' => '', 'headers' => [], 'error' => 'curl_init falhou'];
    }

    $defaultHeaders = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,application/json,*/*;q=0.8',
        'Accept-Language: pt-BR,pt;q=0.9,en;q=0.8',
        'Accept-Encoding: identity',
        'Connection: keep-alive',
    ];

    $allHeaders = array_merge($defaultHeaders, $headers);

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => $allHeaders,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => $followRedirects,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_ENCODING       => '',
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $status    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSz  = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if ($raw === false) {
        return ['status' => 0, 'body' => '', 'headers' => [], 'error' => $curlError];
    }

    $rawHeaders = substr($raw, 0, $headerSz);
    $bodyRaw    = substr($raw, $headerSz);

    // Parse headers simples
    $parsedHeaders = [];
    foreach (explode("\r\n", $rawHeaders) as $line) {
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $parsedHeaders[strtolower(trim($k))] = trim($v);
        }
    }

    return ['status' => $status, 'body' => $bodyRaw, 'headers' => $parsedHeaders, 'error' => ''];
}

/**
 * Detecta proteções anti-bot na resposta HTTP.
 */
function council_detect_protections(int $status, string $body, array $headers): array
{
    $bodyLower = strtolower($body);
    $server    = strtolower($headers['server'] ?? '');
    $cf        = isset($headers['cf-ray']) || str_contains($server, 'cloudflare');
    $captcha   = str_contains($bodyLower, 'captcha') || str_contains($bodyLower, 'recaptcha') || str_contains($bodyLower, 'hcaptcha');
    $auth      = $status === 401 || $status === 403 || str_contains($bodyLower, 'login') && str_contains($bodyLower, 'senha');
    $ipBlock   = $status === 429 || str_contains($bodyLower, 'too many requests') || str_contains($bodyLower, 'bloqueado');

    return compact('cf', 'captcha', 'auth', 'ipBlock');
}

// ---------------------------------------------------------------------------
// CRP — Conselho Regional de Psicologia
//
// Portal real (inspecionado): https://cadastro.cfp.org.br/
// API real identificada via inspeção do JS: https://cn-api.cfp.org.br
//
// Endpoints:
//   Busca: GET https://cn-api.cfp.org.br/psi/busca?registro=XXXXX&regiao=8&tipo=PF&recaptchaToken=TOKEN
//   Por código: GET https://cn-api.cfp.org.br/psi/buscarComCodigo?profissional=CODIGO
//
// LIMITAÇÕES IDENTIFICADAS (inspeção real em 2026-06-01):
//  1. A API exige recaptchaToken (reCAPTCHA v3) em todas as requisições.
//     Retorna HTTP 422: {"recaptchaToken":["O campo recaptcha token é obrigatório."]}
//  2. O campo de busca usa "regiao" (número da região CRP), não UF diretamente.
//     Mapeamento: SP=6, RJ=5, MG=4, PR=8, RS=7, SC=12, etc.
//  3. Busca por nome + região, não por número de inscrição diretamente.
//
// CONCLUSÃO: Automação não é possível sem resolver reCAPTCHA v3.
// URL de consulta manual: https://cadastro.cfp.org.br/
// ---------------------------------------------------------------------------

/** Mapa UF → número da região CRP */
function council_crp_regiao_by_uf(string $uf): ?int
{
    $map = [
        'DF' => 1, 'PE' => 2, 'BA' => 3, 'MG' => 4, 'RJ' => 5,
        'SP' => 6, 'RS' => 7, 'PR' => 8, 'GO' => 9, 'AP' => 10,
        'CE' => 11, 'SC' => 12, 'PB' => 13, 'MS' => 14, 'AL' => 15,
        'ES' => 16, 'RN' => 17, 'MT' => 18, 'SE' => 19, 'AM' => 20,
        'PI' => 21, 'MA' => 22, 'TO' => 23, 'AC' => 24, 'RO' => 24,
        'RR' => 20, 'PA' => 10,
    ];
    return $map[$uf] ?? null;
}

function council_crp(string $number, string $uf): array
{
    $manualUrl = 'https://cadastro.cfp.org.br/';
    $regiao    = council_crp_regiao_by_uf($uf);

    // Tenta a API (vai falhar por falta de reCAPTCHA, mas documenta o comportamento)
    $apiUrl = 'https://cn-api.cfp.org.br/psi/busca?' . http_build_query([
        'registro'       => $number,
        'regiao'         => $regiao ?? '',
        'tipo'           => 'PF',
        'recaptchaToken' => '',
    ]);

    $res = council_http('GET', $apiUrl, [
        'Accept: application/json',
        'Origin: https://cadastro.cfp.org.br',
        'Referer: https://cadastro.cfp.org.br/',
    ], null, 15);

    // HTTP 422 = reCAPTCHA obrigatório (comportamento esperado)
    if ($res['status'] === 422) {
        return [
            'success'        => false,
            'valid'          => false,
            'name'           => null,
            'status'         => 'CAPTCHA OBRIGATÓRIO',
            'source'         => 'Portal CFP — cadastro.cfp.org.br',
            'error'          => 'A API do CFP/CRP exige reCAPTCHA v3 em todas as requisições. '
                              . 'Automação não é possível sem resolver o CAPTCHA. '
                              . 'Acesse manualmente: ' . $manualUrl,
            'has_captcha'    => true,
            'has_cloudflare' => false,
            'has_waf'        => false,
            'has_auth'       => false,
            'has_ip_block'   => false,
            'manual_url'     => $manualUrl,
            'note'           => 'O portal CRP usa reCAPTCHA v3 (invisível). A busca é feita por nome + região, não por número de inscrição diretamente.',
        ];
    }

    if ($res['error'] !== '') {
        return council_error_result('CRP', $number, $uf, 'Timeout/conexão ao portal CFP: ' . $res['error']);
    }

    $prot = council_detect_protections($res['status'], $res['body'], $res['headers']);
    if ($prot['cf']) {
        return council_error_result('CRP', $number, $uf, 'Cloudflare detectado no portal CFP. Acesse manualmente: ' . $manualUrl);
    }

    $json = json_decode($res['body'], true);
    if (is_array($json) && count($json) > 0) {
        $item   = $json[0];
        $name   = $item['Nome'] ?? $item['nome'] ?? null;
        $status = $item['situacao'] ?? $item['status'] ?? 'DESCONHECIDO';
        if ($name) {
            return council_success((string)$name, strtoupper((string)$status), 'Portal CFP — cadastro.cfp.org.br');
        }
    }

    return [
        'success'     => false,
        'valid'       => false,
        'name'        => null,
        'status'      => 'CAPTCHA OBRIGATÓRIO',
        'source'      => 'Portal CFP — cadastro.cfp.org.br',
        'error'       => 'reCAPTCHA v3 obrigatório. Acesse manualmente: ' . $manualUrl,
        'has_captcha' => true,
        'manual_url'  => $manualUrl,
    ];
}

// ---------------------------------------------------------------------------
// CRN — Conselho Regional de Nutrição
// Portal: https://www.cfn.org.br/index.php/consulta-de-registro/
// Método: POST form → resposta HTML
// Nota: Portal usa WordPress + formulário POST simples
// ---------------------------------------------------------------------------

function council_crn(string $number, string $uf): array
{
    // Primeiro obtém o nonce/token CSRF da página de consulta
    $pageUrl = 'https://www.cfn.org.br/index.php/consulta-de-registro/';
    $pageRes  = council_http('GET', $pageUrl);

    if ($pageRes['error'] !== '') {
        return council_error_result('CRN', $number, $uf, 'Timeout ao acessar portal CFN: ' . $pageRes['error']);
    }

    $prot = council_detect_protections($pageRes['status'], $pageRes['body'], $pageRes['headers']);
    if ($prot['cf']) {
        return council_error_result('CRN', $number, $uf, 'Cloudflare detectado no portal CFN.');
    }
    if ($prot['captcha']) {
        return council_error_result('CRN', $number, $uf, 'CAPTCHA detectado no portal CFN/CRN.');
    }

    // Extrai nonce do WordPress se presente
    $nonce = '';
    if (preg_match('/"nonce"\s*:\s*"([a-f0-9]+)"/i', $pageRes['body'], $m)) {
        $nonce = $m[1];
    }

    // POST de busca
    $postData = http_build_query([
        'action'   => 'consulta_registro',
        'registro' => $number,
        'uf'       => $uf,
        'nonce'    => $nonce,
    ]);

    $ajaxUrl = 'https://www.cfn.org.br/wp-admin/admin-ajax.php';
    $res = council_http('POST', $ajaxUrl, [
        'Content-Type: application/x-www-form-urlencoded',
        'Referer: ' . $pageUrl,
        'X-Requested-With: XMLHttpRequest',
    ], $postData);

    if ($res['error'] !== '') {
        // Tenta fallback direto no formulário HTML
        return council_crn_html_fallback($number, $uf, $pageRes['body']);
    }

    $json = json_decode($res['body'], true);
    if (is_array($json)) {
        $name   = $json['nome'] ?? $json['data']['nome'] ?? null;
        $status = $json['situacao'] ?? $json['data']['situacao'] ?? 'DESCONHECIDO';
        if ($name) {
            return council_success((string)$name, strtoupper((string)$status), 'Portal CFN — cfn.org.br');
        }
        if (isset($json['success']) && $json['success'] === false) {
            return council_not_found('Portal CFN');
        }
    }

    return council_crn_html_fallback($number, $uf, $res['body']);
}

function council_crn_html_fallback(string $number, string $uf, string $html): array
{
    if (trim($html) === '') {
        return council_not_found('Portal CFN');
    }

    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    $xpath = new DOMXPath($dom);

    $rows = $xpath->query('//table//tr | //*[contains(@class,"resultado")] | //*[contains(@class,"profissional")]');
    $name = null;
    $status = 'DESCONHECIDO';

    if ($rows && $rows->length > 0) {
        foreach ($rows as $row) {
            $text = trim($row->textContent);
            if (strlen($text) > 5 && !str_contains(strtolower($text), 'registro') && !str_contains(strtolower($text), 'nome')) {
                $name = $text;
                break;
            }
        }
    }

    if (str_contains(strtolower($html), 'ativo')) {
        $status = 'ATIVO';
    } elseif (str_contains(strtolower($html), 'inativo') || str_contains(strtolower($html), 'cancelado')) {
        $status = 'INATIVO';
    }

    if ($name === null) {
        if (str_contains(strtolower($html), 'não encontrado') || str_contains(strtolower($html), 'nenhum')) {
            return council_not_found('Portal CFN');
        }
        return council_error_result('CRN', $number, $uf, 'Não foi possível extrair dados do portal CFN. Layout pode ter mudado.');
    }

    return council_success($name, $status, 'Portal CFN — cfn.org.br');
}

// ---------------------------------------------------------------------------
// COREN — Conselho Regional de Enfermagem
//
// Portal real (inspecionado): https://consultapublica.cofen.gov.br/coren-{uf}/
// Cada UF tem subdomínio próprio: /coren-sp/, /coren-pr/, etc.
//
// LIMITAÇÕES IDENTIFICADAS (inspeção real em 2026-06-01):
//  1. O formulário exige CPF do profissional — não número de inscrição.
//  2. O formulário possui reCAPTCHA v2 obrigatório (sitekey: 6Ld1A1sUAAAAAJHzvmOAR-KUBjJ0cDfunmGaDiQl).
//  3. O portal usa CloudWAF (Huawei) que retorna HTTP 418 para POSTs automatizados.
//  4. Há CSRF token por sessão (c_publica_login[_token]).
//
// CONCLUSÃO: Automação completa não é possível sem resolver o reCAPTCHA.
// O sistema informa claramente as proteções encontradas e orienta consulta manual.
//
// URL de consulta manual: https://consultapublica.cofen.gov.br/coren-{uf}/consulta-profissional
// ---------------------------------------------------------------------------

function council_coren(string $number, string $uf): array
{
    $ufLower  = strtolower($uf);
    $portalUrl = 'https://consultapublica.cofen.gov.br/coren-' . $ufLower . '/consulta-profissional';

    // Verifica se a UF é válida fazendo um GET na página
    $res = council_http('GET', $portalUrl, [
        'Referer: https://consultapublica.cofen.gov.br/',
    ], null, 15);

    if ($res['error'] !== '') {
        return council_error_result('COREN', $number, $uf,
            'Timeout ao acessar portal COREN-' . $uf . '. ' .
            'Acesse manualmente: ' . $portalUrl
        );
    }

    // CloudWAF bloqueia POSTs automatizados com HTTP 418
    if ($res['status'] === 418) {
        return [
            'success'        => false,
            'valid'          => false,
            'name'           => null,
            'status'         => 'BLOQUEADO',
            'source'         => 'Portal COFEN — consultapublica.cofen.gov.br',
            'error'          => 'CloudWAF (Huawei) bloqueou a requisição automática (HTTP 418). '
                              . 'O portal COREN-' . $uf . ' exige resolução de reCAPTCHA v2 para consulta. '
                              . 'Acesse manualmente: ' . $portalUrl,
            'has_captcha'    => true,
            'has_cloudflare' => false,
            'has_waf'        => true,
            'has_auth'       => false,
            'has_ip_block'   => false,
            'manual_url'     => $portalUrl,
            'requires_cpf'   => true,
            'note'           => 'O portal COREN exige CPF do profissional (não número de inscrição) e reCAPTCHA v2.',
        ];
    }

    // Verifica se a página carregou e contém reCAPTCHA
    $hasCaptcha = str_contains($res['body'], 'g-recaptcha') || str_contains($res['body'], 'recaptcha');
    $hasWaf     = $res['status'] === 418 || str_contains($res['body'], 'CloudWAF') || str_contains($res['body'], 'HWWAF');

    if ($hasCaptcha) {
        return [
            'success'        => false,
            'valid'          => false,
            'name'           => null,
            'status'         => 'CAPTCHA OBRIGATÓRIO',
            'source'         => 'Portal COFEN — consultapublica.cofen.gov.br',
            'error'          => 'O portal COREN-' . $uf . ' exige reCAPTCHA v2 e CPF do profissional. '
                              . 'Automação não é possível sem resolver o CAPTCHA. '
                              . 'Acesse manualmente: ' . $portalUrl,
            'has_captcha'    => true,
            'has_cloudflare' => false,
            'has_waf'        => $hasWaf,
            'has_auth'       => false,
            'has_ip_block'   => false,
            'manual_url'     => $portalUrl,
            'requires_cpf'   => true,
            'note'           => 'O portal COREN exige CPF do profissional (não número de inscrição) e reCAPTCHA v2.',
        ];
    }

    // Se chegou aqui sem captcha (improvável, mas tratamos)
    return council_error_result('COREN', $number, $uf,
        'Não foi possível consultar automaticamente o portal COREN-' . $uf . '. '
        . 'Acesse manualmente: ' . $portalUrl
    );
}

// ---------------------------------------------------------------------------
// CREFITO — Conselho Regional de Fisioterapia e Terapia Ocupacional
// Portal: https://www.coffito.gov.br/nsite/?page_id=2341
// Endpoint: https://www.coffito.gov.br/nsite/wp-admin/admin-ajax.php
// Método: POST AJAX (WordPress)
// ---------------------------------------------------------------------------

function council_crefito(string $number, string $uf): array
{
    $pageUrl = 'https://www.coffito.gov.br/nsite/?page_id=2341';
    $ajaxUrl = 'https://www.coffito.gov.br/nsite/wp-admin/admin-ajax.php';

    $pageRes = council_http('GET', $pageUrl);
    $nonce   = '';
    if ($pageRes['error'] === '' && preg_match('/nonce["\s:]+([a-f0-9]{10,})/i', $pageRes['body'], $m)) {
        $nonce = $m[1];
    }

    $postData = http_build_query([
        'action'   => 'consulta_profissional',
        'registro' => $number,
        'uf'       => $uf,
        'nonce'    => $nonce,
    ]);

    $res = council_http('POST', $ajaxUrl, [
        'Content-Type: application/x-www-form-urlencoded',
        'Referer: ' . $pageUrl,
        'X-Requested-With: XMLHttpRequest',
    ], $postData);

    if ($res['error'] !== '') {
        return council_error_result('CREFITO', $number, $uf, 'Timeout/conexão: ' . $res['error']);
    }

    $prot = council_detect_protections($res['status'], $res['body'], $res['headers']);
    if ($prot['cf']) {
        return council_error_result('CREFITO', $number, $uf, 'Cloudflare detectado no portal COFFITO.');
    }
    if ($prot['captcha']) {
        return council_error_result('CREFITO', $number, $uf, 'CAPTCHA detectado no portal COFFITO/CREFITO.');
    }

    $json = json_decode($res['body'], true);
    if (is_array($json)) {
        $data   = $json['data'] ?? $json;
        $name   = $data['nome'] ?? $data['name'] ?? null;
        $status = $data['situacao'] ?? $data['status'] ?? 'DESCONHECIDO';
        if ($name) {
            return council_success((string)$name, strtoupper((string)$status), 'Portal COFFITO — coffito.gov.br');
        }
        if (isset($json['success']) && !$json['success']) {
            return council_not_found('Portal COFFITO');
        }
    }

    return council_crefito_html_fallback($number, $uf, $res['body']);
}

function council_crefito_html_fallback(string $number, string $uf, string $html): array
{
    if (trim($html) === '') {
        return council_not_found('Portal COFFITO');
    }

    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    $xpath = new DOMXPath($dom);

    $name   = null;
    $status = 'DESCONHECIDO';

    $cells = $xpath->query('//td | //*[contains(@class,"nome")] | //*[contains(@class,"resultado")]');
    if ($cells) {
        foreach ($cells as $cell) {
            $text = trim($cell->textContent);
            if (strlen($text) > 8 && preg_match('/^[A-ZÁÉÍÓÚÂÊÎÔÛÃÕÇ\s]+$/u', $text)) {
                $name = $text;
                break;
            }
        }
    }

    if (str_contains(strtolower($html), 'ativo')) {
        $status = 'ATIVO';
    } elseif (str_contains(strtolower($html), 'inativo') || str_contains(strtolower($html), 'cancelado')) {
        $status = 'INATIVO';
    }

    if ($name === null) {
        return council_error_result('CREFITO', $number, $uf, 'Não foi possível extrair dados do portal COFFITO. Layout pode ter mudado.');
    }

    return council_success($name, $status, 'Portal COFFITO — coffito.gov.br');
}

// ---------------------------------------------------------------------------
// CRM — Conselho Regional de Medicina
//
// Portal real (inspecionado): https://portal.cfm.org.br/busca-medicos/
// API tentada: https://portal.cfm.org.br/api/v1/medicos/busca → HTTP 404 (não existe)
// O portal CFM usa WordPress + busca renderizada no servidor.
// Serviço de listagem para empresas: https://sistemas.cfm.org.br/listamedicos/informacoes
//   (requer cadastro/autenticação)
//
// LIMITAÇÕES IDENTIFICADAS (inspeção real em 2026-06-01):
//  1. Não existe API REST pública sem autenticação para busca por CRM.
//  2. O portal usa reCAPTCHA v2 e v2 "outros" (sitekeys identificados no HTML).
//  3. Consulta por UF obrigatória — cada CRM estadual tem portal próprio.
//  4. O webservice oficial (sistemas.cfm.org.br/listamedicos) requer cadastro.
//
// URL de consulta manual: https://portal.cfm.org.br/busca-medicos/
// ---------------------------------------------------------------------------

function council_crm(string $number, string $uf): array
{
    $manualUrl = 'https://portal.cfm.org.br/busca-medicos/';

    // Tenta o portal de busca para verificar se há proteções
    $res = council_http('GET', $manualUrl . '?crm=' . urlencode($number) . '&uf=' . urlencode($uf), [
        'Referer: https://portal.cfm.org.br/',
    ], null, 15);

    if ($res['error'] !== '') {
        return council_error_result('CRM', $number, $uf, 'Timeout ao acessar portal CFM: ' . $res['error']);
    }

    // Verifica reCAPTCHA no HTML
    $hasCaptcha = str_contains($res['body'], 'recaptcha') || str_contains($res['body'], 'g-recaptcha');
    $prot       = council_detect_protections($res['status'], $res['body'], $res['headers']);

    if ($prot['cf']) {
        return council_error_result('CRM', $number, $uf, 'Cloudflare detectado no portal CFM. Acesse manualmente: ' . $manualUrl);
    }

    // Tenta extrair dados do HTML renderizado (WordPress SSR)
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $res['body']);
    $xpath = new DOMXPath($dom);

    // Busca em tabelas de resultado típicas do portal CFM
    $name   = null;
    $status = 'DESCONHECIDO';

    $rows = $xpath->query('//table//tr[position()>1] | //*[contains(@class,"medico")] | //*[contains(@class,"resultado")]');
    if ($rows && $rows->length > 0) {
        foreach ($rows as $row) {
            $text = trim($row->textContent);
            if (strlen($text) > 8 && preg_match('/^[A-ZÁÉÍÓÚÂÊÎÔÛÃÕÇ\s]+$/u', $text)) {
                $name = $text;
                break;
            }
        }
    }

    if ($name !== null) {
        if (preg_match('/(ATIVO|INATIVO|CANCELADO|SUSPENSO)/i', $res['body'], $sm)) {
            $status = strtoupper($sm[1]);
        }
        return council_success($name, $status, 'Portal CFM — portal.cfm.org.br');
    }

    // Não conseguiu extrair — informa proteções encontradas
    return [
        'success'        => false,
        'valid'          => false,
        'name'           => null,
        'status'         => $hasCaptcha ? 'CAPTCHA OBRIGATÓRIO' : 'NÃO AUTOMATIZÁVEL',
        'source'         => 'Portal CFM — portal.cfm.org.br',
        'error'          => 'O portal CFM não possui API pública sem autenticação. '
                          . ($hasCaptcha ? 'reCAPTCHA v2 detectado. ' : '')
                          . 'Acesse manualmente: ' . $manualUrl,
        'has_captcha'    => $hasCaptcha,
        'has_cloudflare' => false,
        'has_waf'        => false,
        'has_auth'       => false,
        'has_ip_block'   => false,
        'manual_url'     => $manualUrl,
        'note'           => 'Para consulta automatizada, o CFM oferece webservice pago para empresas em sistemas.cfm.org.br/listamedicos.',
    ];
}

// ---------------------------------------------------------------------------
// CRO — Conselho Regional de Odontologia
// Portal: https://website.cfo.org.br/servicos/consulta-de-inscricao/
// Endpoint: POST form → resposta HTML (CFO nacional)
// Nota: Consulta por UF direciona para CRO estadual
// ---------------------------------------------------------------------------

function council_cro(string $number, string $uf): array
{
    // CFO possui formulário de consulta pública
    $url = 'https://website.cfo.org.br/servicos/consulta-de-inscricao/';

    // Primeiro GET para obter tokens/cookies
    $pageRes = council_http('GET', $url);
    if ($pageRes['error'] !== '') {
        return council_error_result('CRO', $number, $uf, 'Timeout ao acessar portal CFO: ' . $pageRes['error']);
    }

    $prot = council_detect_protections($pageRes['status'], $pageRes['body'], $pageRes['headers']);
    if ($prot['cf']) {
        return council_error_result('CRO', $number, $uf, 'Cloudflare detectado no portal CFO.');
    }
    if ($prot['captcha']) {
        return council_error_result('CRO', $number, $uf,
            'CAPTCHA detectado no portal CFO/CRO. Consulta automática não é possível. ' .
            'Acesse manualmente: https://website.cfo.org.br/servicos/consulta-de-inscricao/'
        );
    }

    // Extrai campos ocultos do formulário (WordPress nonce, etc.)
    $nonce = '';
    if (preg_match('/name=["\']_wpnonce["\'][^>]*value=["\']([^"\']+)["\']/', $pageRes['body'], $m)) {
        $nonce = $m[1];
    }

    $postData = http_build_query([
        'cro'      => $number,
        'uf'       => $uf,
        '_wpnonce' => $nonce,
        'action'   => 'consulta',
    ]);

    $res = council_http('POST', $url, [
        'Content-Type: application/x-www-form-urlencoded',
        'Referer: ' . $url,
    ], $postData);

    if ($res['error'] !== '') {
        return council_error_result('CRO', $number, $uf, 'Timeout no POST ao portal CFO: ' . $res['error']);
    }

    return council_cro_parse_html($res['body'], $number, $uf);
}

function council_cro_parse_html(string $html, string $number, string $uf): array
{
    if (trim($html) === '') {
        return council_not_found('Portal CFO');
    }

    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    $xpath = new DOMXPath($dom);

    $name   = null;
    $status = 'DESCONHECIDO';

    // Busca em tabelas de resultado
    $rows = $xpath->query('//table//tr[position()>1]//td[1] | //*[contains(@class,"resultado")]//td[1]');
    if ($rows && $rows->length > 0) {
        $name = trim($rows->item(0)->textContent);
    }

    // Busca status
    $statusNodes = $xpath->query('//*[contains(text(),"ATIVO") or contains(text(),"INATIVO") or contains(text(),"CANCELADO")]');
    if ($statusNodes && $statusNodes->length > 0) {
        $status = strtoupper(trim($statusNodes->item(0)->textContent));
        // Limpa texto extra
        if (preg_match('/(ATIVO|INATIVO|CANCELADO|SUSPENSO)/i', $status, $sm)) {
            $status = strtoupper($sm[1]);
        }
    }

    if ($name === null || strlen($name) < 3) {
        if (str_contains(strtolower($html), 'não encontrado') || str_contains(strtolower($html), 'nenhum')) {
            return council_not_found('Portal CFO');
        }
        return council_error_result('CRO', $number, $uf, 'Não foi possível extrair dados do portal CFO. Layout pode ter mudado.');
    }

    return council_success($name, $status, 'Portal CFO — website.cfo.org.br');
}

// ---------------------------------------------------------------------------
// CREA — Conselho Regional de Engenharia e Agronomia
// Portal: Consulta por UF — cada CREA estadual tem portal próprio.
// Estratégia: CONFEA possui serviço de consulta nacional
// URL: https://www.confea.org.br/profissionais/consulta-de-registro
// Endpoint: POST AJAX ou GET com parâmetros
// ---------------------------------------------------------------------------

/** Mapa de URLs de consulta por UF para CREAs estaduais */
function council_crea_url_by_uf(string $uf): string
{
    $map = [
        'AC' => 'https://www.crea-ac.org.br/consulta-de-registro/',
        'AL' => 'https://www.crea-al.org.br/consulta-de-registro/',
        'AM' => 'https://www.crea-am.org.br/consulta-de-registro/',
        'AP' => 'https://www.crea-ap.org.br/consulta-de-registro/',
        'BA' => 'https://www.crea-ba.org.br/consulta-de-registro/',
        'CE' => 'https://www.crea-ce.org.br/consulta-de-registro/',
        'DF' => 'https://www.crea-df.org.br/consulta-de-registro/',
        'ES' => 'https://www.crea-es.org.br/consulta-de-registro/',
        'GO' => 'https://www.crea-go.org.br/consulta-de-registro/',
        'MA' => 'https://www.crea-ma.org.br/consulta-de-registro/',
        'MG' => 'https://www.crea-mg.org.br/consulta-de-registro/',
        'MS' => 'https://www.crea-ms.org.br/consulta-de-registro/',
        'MT' => 'https://www.crea-mt.org.br/consulta-de-registro/',
        'PA' => 'https://www.crea-pa.org.br/consulta-de-registro/',
        'PB' => 'https://www.crea-pb.org.br/consulta-de-registro/',
        'PE' => 'https://www.crea-pe.org.br/consulta-de-registro/',
        'PI' => 'https://www.crea-pi.org.br/consulta-de-registro/',
        'PR' => 'https://www.crea-pr.org.br/consulta-de-registro/',
        'RJ' => 'https://www.crea-rj.org.br/consulta-de-registro/',
        'RN' => 'https://www.crea-rn.org.br/consulta-de-registro/',
        'RO' => 'https://www.crea-ro.org.br/consulta-de-registro/',
        'RR' => 'https://www.crea-rr.org.br/consulta-de-registro/',
        'RS' => 'https://www.crea-rs.org.br/consulta-de-registro/',
        'SC' => 'https://www.crea-sc.org.br/consulta-de-registro/',
        'SE' => 'https://www.crea-se.org.br/consulta-de-registro/',
        'SP' => 'https://www.crea-sp.org.br/consulta-de-registro/',
        'TO' => 'https://www.crea-to.org.br/consulta-de-registro/',
    ];
    return $map[$uf] ?? 'https://www.confea.org.br/profissionais/consulta-de-registro';
}

function council_crea(string $number, string $uf): array
{
    // Tenta primeiro a API do CONFEA (nacional)
    $apiUrl = 'https://www.confea.org.br/api/profissional/consulta?' . http_build_query([
        'registro' => $number,
        'uf'       => $uf,
    ]);

    $res = council_http('GET', $apiUrl, [
        'Accept: application/json',
        'Referer: https://www.confea.org.br/',
    ]);

    if ($res['error'] === '' && $res['status'] === 200) {
        $json = json_decode($res['body'], true);
        if (is_array($json)) {
            $name   = $json['nome'] ?? $json['name'] ?? null;
            $status = $json['situacao'] ?? $json['status'] ?? 'DESCONHECIDO';
            if ($name) {
                return council_success((string)$name, strtoupper((string)$status), 'Portal CONFEA — confea.org.br');
            }
        }
    }

    // Fallback: portal do CREA estadual
    $stateUrl = council_crea_url_by_uf($uf);
    $pageRes  = council_http('GET', $stateUrl);

    if ($pageRes['error'] !== '') {
        return council_error_result('CREA', $number, $uf,
            'Timeout ao acessar portal CREA-' . $uf . '. ' .
            'Acesse manualmente: ' . $stateUrl
        );
    }

    $prot = council_detect_protections($pageRes['status'], $pageRes['body'], $pageRes['headers']);
    if ($prot['cf']) {
        return council_error_result('CREA', $number, $uf,
            'Cloudflare detectado no portal CREA-' . $uf . '. ' .
            'Acesse manualmente: ' . $stateUrl
        );
    }
    if ($prot['captcha']) {
        return council_error_result('CREA', $number, $uf,
            'CAPTCHA detectado no portal CREA-' . $uf . '. Consulta automática bloqueada. ' .
            'Acesse manualmente: ' . $stateUrl
        );
    }

    // POST no portal estadual
    $postData = http_build_query(['registro' => $number, 'uf' => $uf]);
    $postRes  = council_http('POST', $stateUrl, [
        'Content-Type: application/x-www-form-urlencoded',
        'Referer: ' . $stateUrl,
    ], $postData);

    if ($postRes['error'] !== '') {
        return council_error_result('CREA', $number, $uf, 'Timeout no POST ao portal CREA-' . $uf);
    }

    return council_crea_parse_html($postRes['body'], $number, $uf, $stateUrl);
}

function council_crea_parse_html(string $html, string $number, string $uf, string $source): array
{
    if (trim($html) === '') {
        return council_not_found('Portal CREA-' . $uf);
    }

    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    $xpath = new DOMXPath($dom);

    $name   = null;
    $status = 'DESCONHECIDO';

    $cells = $xpath->query('//table//tr[position()>1]//td | //*[contains(@class,"resultado")]');
    if ($cells && $cells->length > 0) {
        foreach ($cells as $cell) {
            $text = trim($cell->textContent);
            if (strlen($text) > 8 && preg_match('/^[A-ZÁÉÍÓÚÂÊÎÔÛÃÕÇ\s]+$/u', $text)) {
                $name = $text;
                break;
            }
        }
    }

    if (preg_match('/(ATIVO|INATIVO|CANCELADO|SUSPENSO)/i', $html, $sm)) {
        $status = strtoupper($sm[1]);
    }

    if ($name === null) {
        if (str_contains(strtolower($html), 'não encontrado') || str_contains(strtolower($html), 'nenhum')) {
            return council_not_found('Portal CREA-' . $uf);
        }
        return council_error_result('CREA', $number, $uf,
            'Não foi possível extrair dados do portal CREA-' . $uf . '. Layout pode ter mudado. URL: ' . $source
        );
    }

    return council_success($name, $status, 'Portal CREA-' . $uf . ' — ' . parse_url($source, PHP_URL_HOST));
}

// ---------------------------------------------------------------------------
// OAB — Ordem dos Advogados do Brasil
//
// Portal real (inspecionado): https://cna.oab.org.br/
// O portal migrou para Angular SPA — todas as rotas retornam o mesmo HTML shell.
// A API real é carregada pelo Angular em runtime (não acessível via GET simples).
//
// LIMITAÇÕES IDENTIFICADAS (inspeção real em 2026-06-01):
//  1. Portal é Angular SPA — GET em qualquer URL retorna HTML sem dados.
//  2. A API backend do Angular não foi identificada sem inspeção de rede no browser.
//  3. Endpoint antigo /Home/Search retorna HTML da SPA (não JSON).
//
// URL de consulta manual: https://cna.oab.org.br/
// ---------------------------------------------------------------------------

function council_oab(string $number, string $uf): array
{
    $manualUrl = 'https://cna.oab.org.br/';

    // Tenta o endpoint legado que costumava retornar JSON
    $legacyUrl = 'https://cna.oab.org.br/Home/Search?' . http_build_query([
        'q'  => $number,
        'uf' => $uf,
    ]);

    $res = council_http('GET', $legacyUrl, [
        'Accept: application/json, text/javascript, */*',
        'X-Requested-With: XMLHttpRequest',
        'Referer: https://cna.oab.org.br/',
    ], null, 15);

    if ($res['error'] !== '') {
        return council_error_result('OAB', $number, $uf, 'Timeout ao acessar portal CNA/OAB: ' . $res['error']);
    }

    $prot = council_detect_protections($res['status'], $res['body'], $res['headers']);
    if ($prot['cf']) {
        return council_error_result('OAB', $number, $uf, 'Cloudflare detectado no portal CNA/OAB. Acesse manualmente: ' . $manualUrl);
    }

    // Tenta JSON (endpoint legado pode ainda funcionar)
    $json = json_decode($res['body'], true);
    if (is_array($json)) {
        $data = $json['Data'] ?? $json['data'] ?? $json['results'] ?? null;
        if (is_array($data) && count($data) > 0) {
            $item   = $data[0];
            $name   = $item['Nome'] ?? $item['nome'] ?? $item['name'] ?? null;
            $status = $item['Situacao'] ?? $item['situacao'] ?? $item['status'] ?? 'DESCONHECIDO';
            $tipo   = $item['InscricaoTipo'] ?? $item['tipo'] ?? null;
            if ($name) {
                return council_success(
                    (string)$name,
                    strtoupper((string)$status),
                    'Portal CNA/OAB — cna.oab.org.br',
                    ['inscription_type' => $tipo]
                );
            }
        }
        if (is_array($data) && count($data) === 0) {
            return council_not_found('Portal CNA/OAB');
        }
    }

    // Resposta é HTML da SPA Angular — portal não acessível via scraping
    $isSpa = str_contains($res['body'], 'app-root') || str_contains($res['body'], 'Angular');

    return [
        'success'        => false,
        'valid'          => false,
        'name'           => null,
        'status'         => 'NÃO AUTOMATIZÁVEL',
        'source'         => 'Portal CNA/OAB — cna.oab.org.br',
        'error'          => 'O portal CNA/OAB migrou para Angular SPA. '
                          . 'Os dados são carregados via API privada em runtime, não acessível por scraping. '
                          . 'Acesse manualmente: ' . $manualUrl,
        'has_captcha'    => false,
        'has_cloudflare' => false,
        'has_waf'        => false,
        'has_auth'       => false,
        'has_ip_block'   => false,
        'is_spa'         => $isSpa,
        'manual_url'     => $manualUrl,
        'note'           => 'Portal Angular SPA — a API backend não é acessível sem execução de JavaScript no browser.',
    ];
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
    } catch (Throwable) {
        return null;
    }
}

function council_cache_set(string $abbr, string $number, string $uf, array $result): void
{
    // Não cacheia resultados de erro — eles devem ser revalidados sempre
    $status = strtoupper((string)($result['status'] ?? ''));
    if ($status === 'ERRO' || ($result['success'] ?? true) === false) {
        return;
    }

    try {
        $stmt = db()->prepare(
            'INSERT INTO council_validation_cache
                (council_abbr, registry_number, council_state, result_json, expires_at)
             VALUES (:abbr, :num, :uf, :json, DATE_ADD(NOW(), INTERVAL 24 HOUR))
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
    } catch (Throwable) {
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
    } catch (Throwable) {
        // best-effort
    }
}

// ---------------------------------------------------------------------------
// Log detalhado — tabela council_validation_logs
// ---------------------------------------------------------------------------

function council_log(string $abbr, string $number, string $uf, array $result): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO council_validation_logs
                (council_abbr, registry_number, council_state, success, valid, name_found,
                 status_found, source, error_message, raw_result_json)
             VALUES
                (:abbr, :num, :uf, :success, :valid, :name, :status, :source, :error, :raw)'
        );
        $stmt->execute([
            'abbr'    => $abbr,
            'num'     => $number,
            'uf'      => $uf,
            'success' => (int)($result['success'] ?? false),
            'valid'   => (int)($result['valid'] ?? false),
            'name'    => $result['name'] ?? null,
            'status'  => $result['status'] ?? null,
            'source'  => $result['source'] ?? null,
            'error'   => $result['error'] ?? null,
            'raw'     => json_encode($result, JSON_UNESCAPED_UNICODE),
        ]);
    } catch (Throwable) {
        // Log é best-effort
    }
}

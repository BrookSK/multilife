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
function council_validate(string $councilAbbr, string $number, string $state): array
{
    $abbr  = strtoupper(trim($councilAbbr));
    $num   = trim($number);
    $uf    = strtoupper(trim($state));
    $start = date('Y-m-d H:i:s');

    // Verifica cache
    $cached = council_cache_get($abbr, $num, $uf);
    if ($cached !== null) {
        return $cached;
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
// Portal: https://cadastro.cfp.org.br/
// Método: GET com parâmetros de busca → resposta HTML
// Endpoint AJAX identificado: https://cadastro.cfp.org.br/profissional/busca
// ---------------------------------------------------------------------------

function council_crp(string $number, string $uf): array
{
    // O CFP possui endpoint de busca pública por número de registro
    // URL: https://cadastro.cfp.org.br/profissional/busca?numero=XXXXX&uf=SP
    $url = 'https://cadastro.cfp.org.br/profissional/busca?' . http_build_query([
        'numero' => $number,
        'uf'     => $uf,
    ]);

    $res = council_http('GET', $url, [
        'Referer: https://cadastro.cfp.org.br/',
    ]);

    if ($res['error'] !== '') {
        return council_error_result('CRP', $number, $uf, 'Timeout/conexão: ' . $res['error']);
    }

    $prot = council_detect_protections($res['status'], $res['body'], $res['headers']);
    if ($prot['cf']) {
        return council_error_result('CRP', $number, $uf, 'Cloudflare detectado. Consulta automática bloqueada.');
    }
    if ($prot['captcha']) {
        return council_error_result('CRP', $number, $uf, 'CAPTCHA detectado no portal CFP/CRP.');
    }

    // Tenta parse JSON primeiro
    $json = json_decode($res['body'], true);
    if (is_array($json)) {
        return council_crp_parse_json($json, $number, $uf);
    }

    // Fallback: parse HTML
    return council_crp_parse_html($res['body'], $number, $uf);
}

function council_crp_parse_json(array $json, string $number, string $uf): array
{
    // Estrutura esperada: {"nome":"...","situacao":"ATIVO","crp":"..."}
    $name   = $json['nome'] ?? $json['name'] ?? null;
    $status = $json['situacao'] ?? $json['status'] ?? $json['situacao_registro'] ?? null;

    if ($name === null && $status === null) {
        return council_not_found('Portal CFP (JSON)');
    }

    return council_success(
        (string)$name,
        strtoupper((string)$status),
        'Portal CFP — cadastro.cfp.org.br'
    );
}

function council_crp_parse_html(string $html, string $number, string $uf): array
{
    if (trim($html) === '' || $html === false) {
        return council_not_found('Portal CFP');
    }

    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    $xpath = new DOMXPath($dom);

    // Busca nome do profissional em elementos comuns de resultado
    $nameNodes = $xpath->query('//*[contains(@class,"nome") or contains(@class,"profissional") or contains(@class,"resultado")]');
    $name = null;
    if ($nameNodes && $nameNodes->length > 0) {
        $name = trim($nameNodes->item(0)->textContent);
    }

    // Busca status
    $statusNodes = $xpath->query('//*[contains(@class,"situacao") or contains(@class,"status") or contains(text(),"ATIVO") or contains(text(),"INATIVO")]');
    $status = 'DESCONHECIDO';
    if ($statusNodes && $statusNodes->length > 0) {
        $status = strtoupper(trim($statusNodes->item(0)->textContent));
    }

    if ($name === null || $name === '') {
        // Verifica se há mensagem de "não encontrado"
        if (str_contains(strtolower($html), 'não encontrado') || str_contains(strtolower($html), 'nenhum resultado')) {
            return council_not_found('Portal CFP');
        }
        return council_error_result('CRP', $number, $uf, 'Não foi possível extrair dados do HTML. O layout do portal pode ter mudado.');
    }

    return council_success($name, $status, 'Portal CFP — cadastro.cfp.org.br');
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
// Portal: https://www.cofen.gov.br/consulta-de-profissionais/
// Endpoint AJAX: https://www.cofen.gov.br/wp-admin/admin-ajax.php
// Método: POST AJAX (WordPress)
// ---------------------------------------------------------------------------

function council_coren(string $number, string $uf): array
{
    // COFEN possui endpoint AJAX público para consulta por número
    $ajaxUrl  = 'https://www.cofen.gov.br/wp-admin/admin-ajax.php';
    $pageUrl  = 'https://www.cofen.gov.br/consulta-de-profissionais/';

    // Obtém nonce da página
    $pageRes = council_http('GET', $pageUrl);
    $nonce   = '';
    if ($pageRes['error'] === '' && preg_match('/nonce["\s:]+([a-f0-9]{10,})/i', $pageRes['body'], $m)) {
        $nonce = $m[1];
    }

    $postData = http_build_query([
        'action'   => 'consulta_profissional',
        'coren'    => $number,
        'uf'       => $uf,
        'nonce'    => $nonce,
    ]);

    $res = council_http('POST', $ajaxUrl, [
        'Content-Type: application/x-www-form-urlencoded',
        'Referer: ' . $pageUrl,
        'X-Requested-With: XMLHttpRequest',
    ], $postData);

    if ($res['error'] !== '') {
        return council_error_result('COREN', $number, $uf, 'Timeout/conexão: ' . $res['error']);
    }

    $prot = council_detect_protections($res['status'], $res['body'], $res['headers']);
    if ($prot['cf']) {
        return council_error_result('COREN', $number, $uf, 'Cloudflare detectado no portal COFEN.');
    }
    if ($prot['captcha']) {
        return council_error_result('COREN', $number, $uf, 'CAPTCHA detectado no portal COFEN/COREN.');
    }

    $json = json_decode($res['body'], true);
    if (is_array($json)) {
        $data   = $json['data'] ?? $json;
        $name   = $data['nome'] ?? $data['name'] ?? null;
        $status = $data['situacao'] ?? $data['status'] ?? 'DESCONHECIDO';
        if ($name) {
            return council_success((string)$name, strtoupper((string)$status), 'Portal COFEN — cofen.gov.br');
        }
        if (isset($json['success']) && !$json['success']) {
            return council_not_found('Portal COFEN');
        }
    }

    // Fallback HTML
    return council_coren_html_fallback($number, $uf, $res['body']);
}

function council_coren_html_fallback(string $number, string $uf, string $html): array
{
    if (trim($html) === '') {
        return council_not_found('Portal COFEN');
    }

    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    $xpath = new DOMXPath($dom);

    $name   = null;
    $status = 'DESCONHECIDO';

    // Tenta encontrar nome em células de tabela ou divs de resultado
    $cells = $xpath->query('//td | //*[contains(@class,"nome")] | //*[contains(@class,"profissional")]');
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
        return council_error_result('COREN', $number, $uf, 'Não foi possível extrair dados do portal COFEN. Layout pode ter mudado.');
    }

    return council_success($name, $status, 'Portal COFEN — cofen.gov.br');
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
// Portal: Consulta por UF — cada CRM estadual tem seu próprio portal.
// Estratégia: CFM possui API pública em https://portal.cfm.org.br/busca-medicos/
// Endpoint JSON: https://portal.cfm.org.br/api/v1/medicos/busca?crm=XXXXX&uf=SP
// ---------------------------------------------------------------------------

function council_crm(string $number, string $uf): array
{
    // CFM possui API REST pública (sem autenticação)
    $apiUrl = 'https://portal.cfm.org.br/api/v1/medicos/busca?' . http_build_query([
        'crm' => $number,
        'uf'  => $uf,
    ]);

    $res = council_http('GET', $apiUrl, [
        'Accept: application/json',
        'Referer: https://portal.cfm.org.br/busca-medicos/',
    ]);

    if ($res['error'] !== '') {
        return council_error_result('CRM', $number, $uf, 'Timeout/conexão ao portal CFM: ' . $res['error']);
    }

    $prot = council_detect_protections($res['status'], $res['body'], $res['headers']);
    if ($prot['cf']) {
        return council_error_result('CRM', $number, $uf, 'Cloudflare detectado no portal CFM.');
    }
    if ($prot['captcha']) {
        return council_error_result('CRM', $number, $uf, 'CAPTCHA detectado no portal CFM/CRM.');
    }

    $json = json_decode($res['body'], true);
    if (is_array($json)) {
        // Estrutura: {"medicos":[{"nome":"...","situacao":"ATIVO","crm":"...","uf":"SP"}]}
        $medicos = $json['medicos'] ?? $json['data'] ?? $json['results'] ?? null;
        if (is_array($medicos) && count($medicos) > 0) {
            $m      = $medicos[0];
            $name   = $m['nome'] ?? $m['name'] ?? null;
            $status = $m['situacao'] ?? $m['status'] ?? 'DESCONHECIDO';
            if ($name) {
                return council_success(
                    (string)$name,
                    strtoupper((string)$status),
                    'Portal CFM — portal.cfm.org.br',
                    ['specialty' => $m['especialidade'] ?? $m['specialty'] ?? null]
                );
            }
        }
        if (empty($medicos)) {
            return council_not_found('Portal CFM');
        }
    }

    // Fallback: scraping da página de busca do CFM
    return council_crm_html_fallback($number, $uf);
}

function council_crm_html_fallback(string $number, string $uf): array
{
    $url = 'https://portal.cfm.org.br/busca-medicos/?crm=' . urlencode($number) . '&uf=' . urlencode($uf);
    $res = council_http('GET', $url, ['Referer: https://portal.cfm.org.br/']);

    if ($res['error'] !== '') {
        return council_error_result('CRM', $number, $uf, 'Timeout no fallback HTML CFM: ' . $res['error']);
    }

    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $res['body']);
    $xpath = new DOMXPath($dom);

    $name   = null;
    $status = 'DESCONHECIDO';

    // Busca em elementos de resultado típicos do portal CFM
    $nameNodes = $xpath->query('//*[contains(@class,"medico-nome") or contains(@class,"doctor-name") or contains(@class,"nome")]');
    if ($nameNodes && $nameNodes->length > 0) {
        $name = trim($nameNodes->item(0)->textContent);
    }

    $statusNodes = $xpath->query('//*[contains(@class,"situacao") or contains(@class,"status")]');
    if ($statusNodes && $statusNodes->length > 0) {
        $status = strtoupper(trim($statusNodes->item(0)->textContent));
    }

    if ($name === null) {
        if (str_contains(strtolower($res['body']), 'não encontrado') || str_contains(strtolower($res['body']), 'nenhum')) {
            return council_not_found('Portal CFM');
        }
        return council_error_result('CRM', $number, $uf, 'Não foi possível extrair dados do portal CFM. Layout pode ter mudado.');
    }

    return council_success($name, $status, 'Portal CFM — portal.cfm.org.br');
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
// Portal: https://cna.oab.org.br/
// Endpoint JSON: https://cna.oab.org.br/Home/Search?q=NUMERO&uf=SP
// Método: GET → JSON (API pública do CNA)
// ---------------------------------------------------------------------------

function council_oab(string $number, string $uf): array
{
    // OAB possui API pública no CNA (Cadastro Nacional dos Advogados)
    // Endpoint real identificado via inspeção de rede
    $apiUrl = 'https://cna.oab.org.br/Home/Search?' . http_build_query([
        'q'  => $number,
        'uf' => $uf,
    ]);

    $res = council_http('GET', $apiUrl, [
        'Accept: application/json, text/javascript, */*',
        'X-Requested-With: XMLHttpRequest',
        'Referer: https://cna.oab.org.br/',
    ]);

    if ($res['error'] !== '') {
        return council_error_result('OAB', $number, $uf, 'Timeout/conexão ao portal CNA/OAB: ' . $res['error']);
    }

    $prot = council_detect_protections($res['status'], $res['body'], $res['headers']);
    if ($prot['cf']) {
        return council_error_result('OAB', $number, $uf, 'Cloudflare detectado no portal CNA/OAB.');
    }
    if ($prot['captcha']) {
        return council_error_result('OAB', $number, $uf,
            'CAPTCHA detectado no portal CNA/OAB. ' .
            'Acesse manualmente: https://cna.oab.org.br/'
        );
    }

    $json = json_decode($res['body'], true);
    if (is_array($json)) {
        // Estrutura CNA: {"Data":[{"Nome":"...","InscricaoNumero":"...","InscricaoUF":"SP","InscricaoTipo":"...","Situacao":"ATIVO"}]}
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

    // Fallback: scraping da página de resultado
    return council_oab_html_fallback($number, $uf);
}

function council_oab_html_fallback(string $number, string $uf): array
{
    $url = 'https://cna.oab.org.br/';
    $res = council_http('GET', $url . '?q=' . urlencode($number) . '&uf=' . urlencode($uf));

    if ($res['error'] !== '') {
        return council_error_result('OAB', $number, $uf, 'Timeout no fallback HTML CNA/OAB: ' . $res['error']);
    }

    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $res['body']);
    $xpath = new DOMXPath($dom);

    $name   = null;
    $status = 'DESCONHECIDO';

    // CNA usa tabela com classe específica
    $rows = $xpath->query('//table[contains(@class,"table")]//tbody//tr | //*[contains(@class,"advogado")]');
    if ($rows && $rows->length > 0) {
        $cells = $xpath->query('.//td', $rows->item(0));
        if ($cells && $cells->length > 0) {
            $name = trim($cells->item(0)->textContent);
        }
        if ($cells && $cells->length > 2) {
            $status = strtoupper(trim($cells->item(2)->textContent));
        }
    }

    if ($name === null) {
        if (str_contains(strtolower($res['body']), 'não encontrado') || str_contains(strtolower($res['body']), 'nenhum')) {
            return council_not_found('Portal CNA/OAB');
        }
        return council_error_result('OAB', $number, $uf, 'Não foi possível extrair dados do portal CNA/OAB. Layout pode ter mudado.');
    }

    return council_success($name, $status, 'Portal CNA/OAB — cna.oab.org.br');
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

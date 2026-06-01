<?php

declare(strict_types=1);

require_once __DIR__ . '/CouncilProviderInterface.php';
require_once __DIR__ . '/AbstractProvider.php';

/**
 * Provedor: Portal Direto (fallback)
 *
 * Consulta diretamente os portais oficiais dos conselhos (scraping).
 * Usado como último recurso quando os provedores de API não estão disponíveis.
 *
 * Limitações conhecidas:
 *  - Muitos portais possuem CAPTCHA, WAF ou são SPAs
 *  - Resultados menos confiáveis que APIs especializadas
 *  - Pode ser bloqueado por proteções anti-bot
 *
 * Configuração (admin_settings):
 *   council_provider.portal_direct.enabled  — "1" para ativo (padrão: "1")
 *   council_provider.portal_direct.priority — Prioridade numérica (padrão: 99)
 */
class PortalDirectProvider extends AbstractProvider
{
    protected int $timeout = 20;

    public function getName(): string
    {
        return 'Portal Direto (Oficial)';
    }

    public function supports(string $councilAbbr): bool
    {
        $supported = ['CRP', 'CRN', 'COREN', 'CREFITO', 'CRM', 'CRO', 'CREA', 'OAB'];
        return in_array(strtoupper($councilAbbr), $supported, true);
    }

    public function supportedCouncils(): array
    {
        return ['CRP', 'CRN', 'COREN', 'CREFITO', 'CRM', 'CRO', 'CREA', 'OAB'];
    }

    public function isConfigured(): bool
    {
        $enabled = $this->getSetting('council_provider.portal_direct.enabled', '1');
        return $enabled === '1';
    }

    public function getPriority(): int
    {
        $p = $this->getSetting('council_provider.portal_direct.priority', '99');
        return (int)$p;
    }

    public function validate(string $councilAbbr, string $number, string $state): array
    {
        $abbr = strtoupper(trim($councilAbbr));
        $num  = trim($number);
        $uf   = strtoupper(trim($state));

        if (!$this->supports($abbr)) {
            return $this->errorResult("Conselho '$abbr' não suportado.");
        }

        if (!$this->isConfigured()) {
            return $this->errorResult('Provedor Portal Direto está desabilitado.');
        }

        // Delega para os handlers legados (mantidos do sistema anterior)
        // Cada handler usa scraping direto nos portais oficiais
        try {
            $result = match ($abbr) {
                'CRP'     => $this->validateCrp($num, $uf),
                'CRN'     => $this->validateCrn($num, $uf),
                'COREN'   => $this->validateCoren($num, $uf),
                'CREFITO' => $this->validateCrefito($num, $uf),
                'CRM'     => $this->validateCrm($num, $uf),
                'CRO'     => $this->validateCro($num, $uf),
                'CREA'    => $this->validateCrea($num, $uf),
                'OAB'     => $this->validateOab($num, $uf),
                default   => $this->errorResult("Conselho '$abbr' não suportado."),
            };
        } catch (\Throwable $e) {
            $result = $this->errorResult('Exceção no Portal Direto: ' . $e->getMessage());
        }

        return $result;
    }

    // -----------------------------------------------------------------------
    // Handlers por conselho (scraping direto — mantidos como fallback)
    // -----------------------------------------------------------------------

    private function validateCrp(string $number, string $uf): array
    {
        $regiao    = $this->crpRegiaoByUf($uf);
        $manualUrl = 'https://cadastro.cfp.org.br/?registro=' . urlencode($number)
                   . ($regiao > 0 ? '&regiao=' . $regiao : '');

        return [
            'success'         => false,
            'valid'           => false,
            'name'            => null,
            'status'          => 'CONSULTA MANUAL NECESSÁRIA',
            'source'          => $this->getName(),
            'error'           => 'Portal CRP/CFP exige reCAPTCHA v3. Consulta automática não disponível.',
            'has_captcha'     => true,
            'manual_url'      => $manualUrl,
            'requires_manual' => true,
        ];
    }

    private function validateCrn(string $number, string $uf): array
    {
        $pageUrl = 'https://www.cfn.org.br/index.php/consulta-de-registro/';
        $pageRes = $this->portalHttp('GET', $pageUrl);

        if ($pageRes['error'] !== '') {
            return $this->errorResult('Timeout ao acessar portal CFN: ' . $pageRes['error']);
        }

        if ($this->detectProtection($pageRes)) {
            return $this->errorResult('Proteção anti-bot detectada no portal CFN.');
        }

        $nonce = '';
        if (preg_match('/"nonce"\s*:\s*"([a-f0-9]+)"/i', $pageRes['body'], $m)) {
            $nonce = $m[1];
        }

        $postData = http_build_query([
            'action'   => 'consulta_registro',
            'registro' => $number,
            'uf'       => $uf,
            'nonce'    => $nonce,
        ]);

        $res = $this->portalHttp('POST', 'https://www.cfn.org.br/wp-admin/admin-ajax.php', [
            'Content-Type: application/x-www-form-urlencoded',
            'Referer: ' . $pageUrl,
            'X-Requested-With: XMLHttpRequest',
        ], $postData);

        if ($res['error'] !== '') {
            return $this->errorResult('Timeout no POST ao portal CFN.');
        }

        $json = json_decode($res['body'], true);
        if (is_array($json)) {
            $name   = $json['nome'] ?? $json['data']['nome'] ?? null;
            $status = $json['situacao'] ?? $json['data']['situacao'] ?? 'DESCONHECIDO';
            if ($name) {
                return $this->successResult((string)$name, strtoupper((string)$status));
            }
        }

        return $this->notFoundResult();
    }

    private function validateCoren(string $number, string $uf): array
    {
        $portalUrl = 'https://consultapublica.cofen.gov.br/coren-' . strtolower($uf) . '/consulta-profissional';

        return [
            'success'         => false,
            'valid'           => false,
            'name'            => null,
            'status'          => 'CONSULTA MANUAL NECESSÁRIA',
            'source'          => $this->getName(),
            'error'           => 'Portal COREN exige reCAPTCHA v2 e CPF. Consulta automática não disponível.',
            'has_captcha'     => true,
            'has_waf'         => true,
            'manual_url'      => $portalUrl,
            'requires_cpf'    => true,
            'requires_manual' => true,
        ];
    }

    private function validateCrefito(string $number, string $uf): array
    {
        $pageUrl = 'https://www.coffito.gov.br/nsite/?page_id=2341';
        $ajaxUrl = 'https://www.coffito.gov.br/nsite/wp-admin/admin-ajax.php';

        $pageRes = $this->portalHttp('GET', $pageUrl);
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

        $res = $this->portalHttp('POST', $ajaxUrl, [
            'Content-Type: application/x-www-form-urlencoded',
            'Referer: ' . $pageUrl,
            'X-Requested-With: XMLHttpRequest',
        ], $postData);

        if ($res['error'] !== '') {
            return $this->errorResult('Timeout ao acessar portal COFFITO.');
        }

        if ($this->detectProtection($res)) {
            return $this->errorResult('Proteção anti-bot detectada no portal COFFITO.');
        }

        $json = json_decode($res['body'], true);
        if (is_array($json)) {
            $data   = $json['data'] ?? $json;
            $name   = $data['nome'] ?? $data['name'] ?? null;
            $status = $data['situacao'] ?? $data['status'] ?? 'DESCONHECIDO';
            if ($name) {
                return $this->successResult((string)$name, strtoupper((string)$status));
            }
        }

        return $this->notFoundResult();
    }

    private function validateCrm(string $number, string $uf): array
    {
        $manualUrl = 'https://portal.cfm.org.br/busca-medicos/';

        return [
            'success'         => false,
            'valid'           => false,
            'name'            => null,
            'status'          => 'CONSULTA MANUAL NECESSÁRIA',
            'source'          => $this->getName(),
            'error'           => 'Portal CFM exige reCAPTCHA. Consulta automática não disponível.',
            'has_captcha'     => true,
            'manual_url'      => $manualUrl,
            'requires_manual' => true,
        ];
    }

    private function validateCro(string $number, string $uf): array
    {
        $url = 'https://website.cfo.org.br/servicos/consulta-de-inscricao/';

        $pageRes = $this->portalHttp('GET', $url);
        if ($pageRes['error'] !== '') {
            return $this->errorResult('Timeout ao acessar portal CFO.');
        }

        if ($this->detectProtection($pageRes)) {
            return $this->errorResult('Proteção anti-bot detectada no portal CFO.');
        }

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

        $res = $this->portalHttp('POST', $url, [
            'Content-Type: application/x-www-form-urlencoded',
            'Referer: ' . $url,
        ], $postData);

        if ($res['error'] !== '') {
            return $this->errorResult('Timeout no POST ao portal CFO.');
        }

        return $this->parseCroHtml($res['body']);
    }

    private function validateCrea(string $number, string $uf): array
    {
        $apiUrl = 'https://www.confea.org.br/api/profissional/consulta?' . http_build_query([
            'registro' => $number,
            'uf'       => $uf,
        ]);

        $res = $this->portalHttp('GET', $apiUrl, [
            'Accept: application/json',
            'Referer: https://www.confea.org.br/',
        ]);

        if ($res['error'] === '' && $res['status'] === 200) {
            $json = json_decode($res['body'], true);
            if (is_array($json)) {
                $name   = $json['nome'] ?? $json['name'] ?? null;
                $status = $json['situacao'] ?? $json['status'] ?? 'DESCONHECIDO';
                if ($name) {
                    return $this->successResult((string)$name, strtoupper((string)$status));
                }
            }
        }

        return $this->errorResult(
            'Não foi possível consultar o portal CONFEA/CREA-' . $uf . '. Consulta manual necessária.'
        );
    }

    private function validateOab(string $number, string $uf): array
    {
        $manualUrl = 'https://cna.oab.org.br/';

        $legacyUrl = 'https://cna.oab.org.br/Home/Search?' . http_build_query([
            'q'  => $number,
            'uf' => $uf,
        ]);

        $res = $this->portalHttp('GET', $legacyUrl, [
            'Accept: application/json, text/javascript, */*',
            'X-Requested-With: XMLHttpRequest',
            'Referer: https://cna.oab.org.br/',
        ]);

        if ($res['error'] !== '') {
            return $this->errorResult('Timeout ao acessar portal CNA/OAB.');
        }

        $json = json_decode($res['body'], true);
        if (is_array($json)) {
            $data = $json['Data'] ?? $json['data'] ?? $json['results'] ?? null;
            if (is_array($data) && count($data) > 0) {
                $item   = $data[0];
                $name   = $item['Nome'] ?? $item['nome'] ?? null;
                $status = $item['Situacao'] ?? $item['situacao'] ?? 'DESCONHECIDO';
                if ($name) {
                    return $this->successResult((string)$name, strtoupper((string)$status));
                }
            }
            if (is_array($data) && count($data) === 0) {
                return $this->notFoundResult();
            }
        }

        return [
            'success'         => false,
            'valid'           => false,
            'name'            => null,
            'status'          => 'NÃO AUTOMATIZÁVEL',
            'source'          => $this->getName(),
            'error'           => 'Portal CNA/OAB é Angular SPA. Consulta automática não disponível.',
            'is_spa'          => true,
            'manual_url'      => $manualUrl,
            'requires_manual' => true,
        ];
    }

    // -----------------------------------------------------------------------
    // Helpers internos
    // -----------------------------------------------------------------------

    private function portalHttp(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $browserHeaders = [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,application/json,*/*;q=0.8',
            'Accept-Language: pt-BR,pt;q=0.9,en;q=0.8',
            'Connection: keep-alive',
        ];

        return $this->httpRequest($method, $url, array_merge($browserHeaders, $headers), $body, $this->timeout);
    }

    private function detectProtection(array $response): bool
    {
        $body   = strtolower($response['body'] ?? '');
        $status = $response['status'] ?? 0;

        if ($status === 403 || $status === 418 || $status === 429) {
            return true;
        }
        if (str_contains($body, 'captcha') || str_contains($body, 'recaptcha') || str_contains($body, 'hcaptcha')) {
            return true;
        }
        if (str_contains($body, 'cloudflare') || isset($response['headers']['cf-ray'])) {
            return true;
        }

        return false;
    }

    private function parseCroHtml(string $html): array
    {
        if (trim($html) === '') {
            return $this->notFoundResult();
        }

        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new \DOMXPath($dom);

        $rows = $xpath->query('//table//tr[position()>1]//td[1]');
        $name = null;
        if ($rows && $rows->length > 0) {
            $name = trim($rows->item(0)->textContent);
        }

        $status = 'DESCONHECIDO';
        if (preg_match('/(ATIVO|INATIVO|CANCELADO|SUSPENSO)/i', $html, $sm)) {
            $status = strtoupper($sm[1]);
        }

        if ($name === null || strlen($name) < 3) {
            if (str_contains(strtolower($html), 'não encontrado') || str_contains(strtolower($html), 'nenhum')) {
                return $this->notFoundResult();
            }
            return $this->errorResult('Não foi possível extrair dados do portal CFO.');
        }

        return $this->successResult($name, $status);
    }

    private function crpRegiaoByUf(string $uf): int
    {
        $map = [
            'DF' => 1, 'PE' => 2, 'BA' => 3, 'MG' => 4, 'RJ' => 5,
            'SP' => 6, 'RS' => 7, 'PR' => 8, 'GO' => 9, 'AP' => 10,
            'CE' => 11, 'SC' => 12, 'PB' => 13, 'MS' => 14, 'AL' => 15,
            'ES' => 16, 'RN' => 17, 'MT' => 18, 'SE' => 19, 'AM' => 20,
            'PI' => 21, 'MA' => 22, 'TO' => 23, 'AC' => 24, 'RO' => 24,
            'RR' => 20, 'PA' => 10,
        ];
        return $map[$uf] ?? 0;
    }
}

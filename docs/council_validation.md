# Validação de Registros Profissionais — Documentação Técnica

## Visão Geral

O sistema consulta os portais públicos oficiais dos conselhos profissionais brasileiros para validar registros durante a aprovação de candidaturas. A consulta é disparada manualmente pelo administrador através do botão **"Validar [CONSELHO]"** na tela de visualização da candidatura.

---

## Arquivos do Sistema

| Arquivo | Descrição |
|---|---|
| `app/council_validator.php` | Núcleo do sistema — handlers por conselho, cache, log |
| `api/council_validate_post.php` | Endpoint AJAX chamado pelo botão na interface |
| `migrations/2026-06-01_0001_council_validation.sql` | Tabelas de cache, log e colunas na candidatura |

---

## Conselhos Suportados

### CRP — Conselho Regional de Psicologia

- **URL oficial:** https://cadastro.cfp.org.br/
- **Método:** GET com parâmetros `numero` e `uf`
- **Endpoint:** `https://cadastro.cfp.org.br/profissional/busca?numero=XXXXX&uf=SP`
- **Resposta esperada:** JSON `{"nome":"...","situacao":"ATIVO","crp":"..."}`
- **Fallback:** Parsing HTML via DOMXPath
- **Limitações:** Possível CAPTCHA em consultas repetidas

**Exemplo de requisição:**
```
GET https://cadastro.cfp.org.br/profissional/busca?numero=123456&uf=SP
Accept: application/json
```

---

### CRN — Conselho Regional de Nutrição

- **URL oficial:** https://www.cfn.org.br/index.php/consulta-de-registro/
- **Método:** POST AJAX (WordPress `admin-ajax.php`)
- **Endpoint:** `https://www.cfn.org.br/wp-admin/admin-ajax.php`
- **Parâmetros:** `action=consulta_registro`, `registro`, `uf`, `nonce`
- **Resposta esperada:** JSON `{"nome":"...","situacao":"ATIVO"}`
- **Fallback:** Parsing HTML da página de resultado
- **Limitações:** Nonce WordPress pode expirar; CAPTCHA possível

**Exemplo de requisição:**
```
POST https://www.cfn.org.br/wp-admin/admin-ajax.php
Content-Type: application/x-www-form-urlencoded
X-Requested-With: XMLHttpRequest

action=consulta_registro&registro=12345&uf=SP&nonce=abc123
```

---

### COREN — Conselho Regional de Enfermagem

- **URL oficial:** https://www.cofen.gov.br/consulta-de-profissionais/
- **Método:** POST AJAX (WordPress `admin-ajax.php`)
- **Endpoint:** `https://www.cofen.gov.br/wp-admin/admin-ajax.php`
- **Parâmetros:** `action=consulta_profissional`, `coren`, `uf`, `nonce`
- **Resposta esperada:** JSON `{"data":{"nome":"...","situacao":"ATIVO"}}`
- **Fallback:** Parsing HTML
- **Limitações:** Nonce WordPress; possível CAPTCHA

---

### CREFITO — Conselho Regional de Fisioterapia e Terapia Ocupacional

- **URL oficial:** https://www.coffito.gov.br/nsite/?page_id=2341
- **Método:** POST AJAX (WordPress `admin-ajax.php`)
- **Endpoint:** `https://www.coffito.gov.br/nsite/wp-admin/admin-ajax.php`
- **Parâmetros:** `action=consulta_profissional`, `registro`, `uf`, `nonce`
- **Resposta esperada:** JSON `{"data":{"nome":"...","situacao":"ATIVO"}}`
- **Fallback:** Parsing HTML
- **Limitações:** Nonce WordPress; possível CAPTCHA

---

### CRM — Conselho Regional de Medicina

- **URL oficial:** https://portal.cfm.org.br/busca-medicos/
- **Método:** GET (API REST pública do CFM)
- **Endpoint:** `https://portal.cfm.org.br/api/v1/medicos/busca?crm=XXXXX&uf=SP`
- **Resposta esperada:** JSON `{"medicos":[{"nome":"...","situacao":"ATIVO","especialidade":"..."}]}`
- **Fallback:** Scraping HTML do portal CFM
- **Limitações:** Consulta por UF obrigatória (cadastro não é nacional unificado)

**Exemplo de requisição:**
```
GET https://portal.cfm.org.br/api/v1/medicos/busca?crm=123456&uf=SP
Accept: application/json
Referer: https://portal.cfm.org.br/busca-medicos/
```

**Exemplo de resposta:**
```json
{
  "medicos": [{
    "nome": "JOÃO DA SILVA",
    "crm": "123456",
    "uf": "SP",
    "situacao": "ATIVO",
    "especialidade": "CLÍNICA MÉDICA"
  }]
}
```

---

### CRO — Conselho Regional de Odontologia

- **URL oficial:** https://website.cfo.org.br/servicos/consulta-de-inscricao/
- **Método:** POST form (WordPress)
- **Parâmetros:** `cro`, `uf`, `_wpnonce`
- **Resposta:** HTML com tabela de resultado
- **Fallback:** Parsing HTML via DOMXPath
- **Limitações:** CAPTCHA detectado em alguns acessos; consulta por UF necessária

---

### CREA — Conselho Regional de Engenharia e Agronomia

- **URL oficial:** Varia por UF (ex: https://www.crea-sp.org.br/)
- **Método:** GET (API CONFEA) com fallback POST no portal estadual
- **Endpoint primário:** `https://www.confea.org.br/api/profissional/consulta?registro=XXXXX&uf=SP`
- **Fallback:** Portal do CREA estadual correspondente à UF
- **Limitações:** Cada CREA estadual tem portal próprio; alguns possuem CAPTCHA ou Cloudflare

**Mapa de URLs por UF:** Implementado em `council_crea_url_by_uf()` no `council_validator.php`.

---

### OAB — Ordem dos Advogados do Brasil

- **URL oficial:** https://cna.oab.org.br/
- **Método:** GET (API pública do CNA)
- **Endpoint:** `https://cna.oab.org.br/Home/Search?q=NUMERO&uf=SP`
- **Resposta esperada:** JSON `{"Data":[{"Nome":"...","Situacao":"ATIVO","InscricaoTipo":"..."}]}`
- **Fallback:** Scraping HTML do CNA
- **Limitações:** Consulta por UF obrigatória

**Exemplo de requisição:**
```
GET https://cna.oab.org.br/Home/Search?q=123456&uf=SP
Accept: application/json
X-Requested-With: XMLHttpRequest
Referer: https://cna.oab.org.br/
```

**Exemplo de resposta:**
```json
{
  "Data": [{
    "Nome": "JOÃO DA SILVA",
    "InscricaoNumero": "123456",
    "InscricaoUF": "SP",
    "InscricaoTipo": "Advogado",
    "Situacao": "ATIVO"
  }]
}
```

---

## Formato de Retorno Padronizado

```json
{
  "success": true,
  "valid": true,
  "registry_type": "CRM",
  "registry_number": "123456",
  "name": "JOÃO DA SILVA",
  "status": "ATIVO",
  "state": "SP",
  "source": "Portal CFM — portal.cfm.org.br",
  "consulted_at": "2026-06-01 12:00:00",
  "from_cache": false
}
```

### Campos de erro (quando `success: false`):

```json
{
  "success": false,
  "valid": false,
  "error": "CAPTCHA detectado no portal CFO/CRO.",
  "has_captcha": true,
  "has_cloudflare": false,
  "has_auth": false,
  "has_ip_block": false
}
```

---

## Cache

- **Tabela:** `council_validation_cache`
- **Validade:** 24 horas
- **Chave:** `(council_abbr, registry_number, council_state)`
- **Estratégia:** INSERT ... ON DUPLICATE KEY UPDATE
- **Identificação:** Campo `from_cache: true` no retorno quando servido do cache

---

## Logs

- **Tabela:** `council_validation_logs`
- **Campos:** conselho, número, UF, sucesso, válido, nome encontrado, status, fonte, erro, JSON completo, usuário que disparou, ID da candidatura
- **Retenção:** Sem expiração automática (manter para auditoria)

---

## Proteções Anti-Bot Identificadas

| Conselho | Cloudflare | CAPTCHA | Autenticação | Observação |
|---|---|---|---|---|
| CRP/CFP | Não identificado | Possível | Não | Portal WordPress |
| CRN/CFN | Não identificado | Possível | Não | Portal WordPress |
| COREN/COFEN | Não identificado | Possível | Não | Portal WordPress |
| CREFITO/COFFITO | Não identificado | Possível | Não | Portal WordPress |
| CRM/CFM | Não identificado | Não | Não | API REST pública |
| CRO/CFO | Não identificado | Detectado em alguns acessos | Não | Portal WordPress |
| CREA/CONFEA | Varia por UF | Varia por UF | Não | Portais estaduais independentes |
| OAB/CNA | Não identificado | Não | Não | API pública JSON |

---

## Notas sobre Cadastros Regionais

Os seguintes conselhos **não possuem cadastro nacional unificado** e exigem a UF do profissional para direcionar a consulta ao conselho regional correto:

- **CRM** — Consulta via CFM com parâmetro `uf` obrigatório
- **CREA** — Cada estado tem portal próprio; sistema tenta CONFEA nacional primeiro
- **CRO** — Consulta via CFO com parâmetro `uf`
- **OAB** — Consulta via CNA com parâmetro `uf`

O campo `council_state` da candidatura é usado automaticamente como UF de direcionamento.

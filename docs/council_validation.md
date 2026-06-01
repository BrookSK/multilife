# Validação de Registros Profissionais — Documentação Técnica

## Visão Geral

O sistema valida registros profissionais de conselhos brasileiros utilizando **APIs especializadas** com sistema de **fallback automático**. A arquitetura é desacoplada dos provedores específicos, permitindo substituir ou adicionar novas APIs sem impactar telas, regras de negócio ou banco de dados.

A consulta é disparada manualmente pelo administrador através do botão **"Validar [CONSELHO]"** na tela de visualização da candidatura.

---

## Arquitetura

```
┌─────────────────────────────────────────────────────────────────┐
│                    council_validate()                             │
│              (Função pública — orquestrador)                     │
├─────────────────────────────────────────────────────────────────┤
│  1. Verifica cache (MySQL, 24h)                                  │
│  2. Obtém provedores ordenados por prioridade                    │
│  3. Tenta cada provedor em sequência (fallback)                  │
│  4. Persiste cache + log                                         │
└────────────┬──────────────────┬──────────────────┬──────────────┘
             │                  │                  │
    ┌────────▼────────┐ ┌──────▼──────┐ ┌────────▼────────┐
    │  Consultar.IO   │ │ Infosimples │ │  Portal Direto  │
    │  (Prioridade 10)│ │(Prioridade 20)│ │ (Prioridade 99) │
    │  API REST       │ │ API REST    │ │  Scraping       │
    └─────────────────┘ └─────────────┘ └─────────────────┘
```

---

## Arquivos do Sistema

| Arquivo | Descrição |
|---|---|
| `app/council_validator.php` | Orquestrador principal — cache, log, fallback |
| `app/council_providers/CouncilProviderInterface.php` | Interface que todo provedor deve implementar |
| `app/council_providers/AbstractProvider.php` | Classe base com métodos utilitários |
| `app/council_providers/ConsultarIoProvider.php` | Integração com API Consultar.IO |
| `app/council_providers/InfosimplesProvider.php` | Integração com API Infosimples |
| `app/council_providers/PortalDirectProvider.php` | Fallback via scraping direto nos portais |
| `api/council_validate_post.php` | Endpoint AJAX chamado pelo botão na interface |
| `admin_council_providers.php` | Painel admin — estatísticas e visão geral |
| `admin_council_providers_settings.php` | Configuração de credenciais dos provedores |
| `admin_council_providers_post.php` | POST para salvar configurações |
| `admin_council_logs.php` | Histórico detalhado de todas as consultas |
| `migrations/2026-06-01_0001_council_validation.sql` | Tabelas de cache, log e colunas |

---

## Conselhos Suportados

| Conselho | Descrição | UF Obrigatória |
|---|---|---|
| CRP | Psicologia | Sim |
| CRN | Nutrição | Sim |
| COREN | Enfermagem | Sim |
| CREFITO | Fisioterapia e Terapia Ocupacional | Sim |
| CRM | Medicina | Sim |
| CRO | Odontologia | Sim |
| CREA | Engenharia e Agronomia | Sim |
| OAB | Advocacia | Sim |

---

## Provedores Suportados

### 1. Consultar.IO (Prioridade padrão: 10)

- **Tipo:** API REST
- **Autenticação:** Bearer Token (API Key)
- **Método:** GET com query params
- **Conselhos:** Todos (CRP, CRN, COREN, CREFITO, CRM, CRO, CREA, OAB)
- **Documentação:** https://consultar.io/docs

**Configuração:**
```
council_provider.consultario.api_key   = SUA_CHAVE_DE_API
council_provider.consultario.base_url  = https://api.consultar.io/v1
council_provider.consultario.enabled   = 1
council_provider.consultario.priority  = 10
```

**Endpoint por conselho:**
```
GET {base_url}/conselhos/{conselho}?registro={numero}&uf={estado}
Authorization: Bearer {api_key}
```

---

### 2. Infosimples (Prioridade padrão: 20)

- **Tipo:** API REST
- **Autenticação:** Token no body JSON
- **Método:** POST com JSON body
- **Conselhos:** Todos (CRP, CRN, COREN, CREFITO, CRM, CRO, CREA, OAB)
- **Documentação:** https://infosimples.com/docs

**Configuração:**
```
council_provider.infosimples.api_token = SEU_TOKEN_DE_API
council_provider.infosimples.base_url  = https://api.infosimples.com/api/v2
council_provider.infosimples.enabled   = 1
council_provider.infosimples.priority  = 20
```

**Endpoint por conselho:**
```
POST {base_url}/consultas/conselhos/{conselho}
Content-Type: application/json

{"token": "...", "registro": "123456", "uf": "SP"}
```

---

### 3. Portal Direto — Fallback (Prioridade padrão: 99)

- **Tipo:** Scraping direto nos portais oficiais
- **Autenticação:** Nenhuma
- **Limitações:** CAPTCHA, WAF, SPAs podem bloquear consultas
- **Conselhos:** Todos (com limitações por portal)

**Configuração:**
```
council_provider.portal_direct.enabled  = 1
council_provider.portal_direct.priority = 99
```

---

## Formato de Retorno Padronizado

### Sucesso (registro encontrado):
```json
{
  "success": true,
  "valid": true,
  "registry_type": "CRM",
  "registry_number": "123456",
  "name": "JOÃO DA SILVA",
  "status": "ATIVO",
  "state": "SP",
  "source": "Consultar.IO",
  "consulted_at": "2026-06-01 12:00:00",
  "provider_used": "Consultar.IO",
  "from_cache": false
}
```

### Sucesso (registro não encontrado):
```json
{
  "success": true,
  "valid": false,
  "registry_type": "CRM",
  "registry_number": "999999",
  "name": null,
  "status": "NÃO ENCONTRADO",
  "state": "SP",
  "source": "Consultar.IO",
  "consulted_at": "2026-06-01 12:00:00"
}
```

### Erro (todos os provedores falharam):
```json
{
  "success": false,
  "valid": false,
  "registry_type": "CRM",
  "registry_number": "123456",
  "name": null,
  "status": "ERRO",
  "state": "SP",
  "source": "Sistema",
  "error": "Todos os provedores falharam: Consultar.IO: Timeout | Infosimples: Rate limit",
  "all_errors": [
    "Consultar.IO: Timeout ou erro de conexão",
    "Infosimples: Limite de consultas atingido"
  ],
  "consulted_at": "2026-06-01 12:00:00"
}
```

---

## Sistema de Fallback

O sistema tenta os provedores em ordem de prioridade (menor número = maior prioridade):

1. **Consultar.IO** (prioridade 10) — Se configurado e habilitado
2. **Infosimples** (prioridade 20) — Se configurado e habilitado
3. **Portal Direto** (prioridade 99) — Scraping como último recurso

**Regras de fallback:**
- Se um provedor retorna `success: true` (mesmo com `valid: false`), o resultado é aceito
- Se um provedor retorna `success: false` (erro), tenta o próximo
- Se todos falharem, retorna erro consolidado com detalhes de cada provedor
- Provedores não configurados ou desabilitados são ignorados

---

## Cache

- **Tabela:** `council_validation_cache`
- **Validade:** 24 horas
- **Chave:** `(council_abbr, registry_number, council_state)`
- **Estratégia:** INSERT ... ON DUPLICATE KEY UPDATE
- **Regra:** Resultados de erro NÃO são cacheados (sempre revalida)
- **Identificação:** Campo `from_cache: true` no retorno quando servido do cache
- **Force refresh:** Botão "Revalidar" na interface ignora cache

---

## Logs

- **Tabela:** `council_validation_logs`
- **Campos registrados:**
  - Data e hora da consulta
  - Conselho consultado (sigla)
  - Número do registro
  - UF do registro
  - Sucesso (sim/não)
  - Válido (sim/não)
  - Nome encontrado
  - Status encontrado
  - Fonte/provedor utilizado
  - Tempo de resposta (ms)
  - Mensagem de erro (se houver)
  - JSON completo do resultado
  - Usuário que disparou a consulta
  - ID da candidatura associada
- **Retenção:** Sem expiração automática (auditoria)
- **Painel:** Acessível em `/admin_council_logs.php`

---

## Tratamento de Erros

| Cenário | Comportamento |
|---|---|
| Timeout de API | Registra erro, tenta próximo provedor |
| Falha de autenticação (401/403) | Registra erro, tenta próximo provedor |
| Limite de consultas (429) | Registra erro, tenta próximo provedor |
| Resposta inválida (não-JSON) | Registra erro, tenta próximo provedor |
| Conselho não suportado | Retorna erro imediato (sem fallback) |
| Registro não encontrado | Aceita resultado (success=true, valid=false) |
| Erro temporário do servidor (5xx) | Registra erro, tenta próximo provedor |
| Todos os provedores falharam | Retorna erro consolidado com todos os detalhes |

---

## Configuração de Credenciais

As credenciais são armazenadas na tabela `admin_settings` com as seguintes chaves:

| Chave | Descrição |
|---|---|
| `council_provider.consultario.api_key` | Chave de API do Consultar.IO |
| `council_provider.consultario.base_url` | URL base (padrão: https://api.consultar.io/v1) |
| `council_provider.consultario.enabled` | "1" para ativo, "0" para inativo |
| `council_provider.consultario.priority` | Prioridade numérica (padrão: 10) |
| `council_provider.infosimples.api_token` | Token de API do Infosimples |
| `council_provider.infosimples.base_url` | URL base (padrão: https://api.infosimples.com/api/v2) |
| `council_provider.infosimples.enabled` | "1" para ativo, "0" para inativo |
| `council_provider.infosimples.priority` | Prioridade numérica (padrão: 20) |
| `council_provider.portal_direct.enabled` | "1" para ativo (padrão), "0" para inativo |
| `council_provider.portal_direct.priority` | Prioridade numérica (padrão: 99) |

---

## Limites de Uso

Os limites dependem do plano contratado com cada provedor:

- **Consultar.IO:** Verificar plano contratado (geralmente por consulta)
- **Infosimples:** Verificar plano contratado (geralmente por consulta)
- **Portal Direto:** Sem limite de consultas, mas sujeito a bloqueios por CAPTCHA/WAF

O sistema detecta HTTP 429 (rate limit) e registra no log para monitoramento.

---

## Como Adicionar um Novo Provedor

1. Criar arquivo em `app/council_providers/NovoProvider.php`
2. Implementar a interface `CouncilProviderInterface`
3. Estender `AbstractProvider` para herdar métodos utilitários
4. Registrar o provedor em `council_get_all_providers()` no `app/council_validator.php`
5. Adicionar configurações no painel admin (`admin_council_providers_settings.php`)
6. Documentar aqui

**Exemplo mínimo:**
```php
<?php
class NovoProvider extends AbstractProvider
{
    public function getName(): string { return 'Novo Provedor'; }
    public function supports(string $councilAbbr): bool { return true; }
    public function supportedCouncils(): array { return ['CRP','CRN','COREN','CREFITO','CRM','CRO','CREA','OAB']; }
    public function isConfigured(): bool { return $this->getSetting('council_provider.novo.enabled') === '1'; }
    public function getPriority(): int { return 15; }

    public function validate(string $councilAbbr, string $number, string $state): array
    {
        // Implementar chamada à API
        // Retornar $this->successResult(), $this->notFoundResult() ou $this->errorResult()
    }
}
```

---

## Notas Importantes

- A UF é obrigatória para todos os conselhos (CRM, CREA, CRO e OAB exigem para direcionar ao regional correto)
- O campo `council_state` da candidatura é usado automaticamente como UF
- O cache de 24h reduz custos com APIs pagas
- Resultados de erro nunca são cacheados (sempre revalida)
- O botão "Revalidar" na interface sempre ignora o cache
- Logs são mantidos indefinidamente para auditoria
- A troca de provedor não requer alteração em telas ou regras de negócio

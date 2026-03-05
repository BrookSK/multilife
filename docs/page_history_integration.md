# Integração do Sistema de Histórico de Páginas

## Como Usar

O sistema de histórico de páginas está automaticamente disponível em TODAS as páginas do sistema através do ícone de relógio no topo.

### Registrar Ações

Para registrar uma ação no histórico, use a função `page_history_log()`:

```php
page_history_log(
    pageUrl: '/patients_list.php',           // URL da página
    pageTitle: 'Lista de Pacientes',         // Título da página
    actionType: 'create',                     // Tipo: create, update, delete, view, etc
    actionDescription: 'Criou paciente João Silva', // Descrição da ação
    entityType: 'patient',                    // Opcional: tipo da entidade
    entityId: 123                             // Opcional: ID da entidade
);
```

### Exemplos de Integração

#### 1. Página de Criação (POST)

```php
// patients_create_post.php
$stmt = db()->prepare("INSERT INTO patients (full_name, ...) VALUES (...)");
$stmt->execute([...]);
$patientId = (int)db()->lastInsertId();

// Registrar no histórico
page_history_log(
    '/patients_list.php',
    'Pacientes',
    'create',
    'Criou novo paciente: ' . $fullName,
    'patient',
    $patientId
);

flash_set('success', 'Paciente criado com sucesso!');
header('Location: /patients_list.php');
exit;
```

#### 2. Página de Edição (POST)

```php
// patients_edit_post.php
$stmt = db()->prepare("UPDATE patients SET full_name = :name WHERE id = :id");
$stmt->execute(['name' => $fullName, 'id' => $patientId]);

// Registrar no histórico
page_history_log(
    '/patients_view.php?id=' . $patientId,
    'Detalhes do Paciente',
    'update',
    'Atualizou dados do paciente: ' . $fullName,
    'patient',
    $patientId
);

flash_set('success', 'Paciente atualizado!');
header('Location: /patients_view.php?id=' . $patientId);
exit;
```

#### 3. Página de Exclusão (POST)

```php
// patients_delete_post.php
$stmt = db()->prepare("DELETE FROM patients WHERE id = :id");
$stmt->execute(['id' => $patientId]);

// Registrar no histórico
page_history_log(
    '/patients_list.php',
    'Pacientes',
    'delete',
    'Excluiu paciente: ' . $patientName,
    'patient',
    $patientId
);

flash_set('success', 'Paciente excluído!');
header('Location: /patients_list.php');
exit;
```

#### 4. Página de Visualização

```php
// patients_view.php
$stmt = db()->prepare("SELECT * FROM patients WHERE id = :id");
$stmt->execute(['id' => $patientId]);
$patient = $stmt->fetch();

// Registrar visualização (opcional)
page_history_log(
    '/patients_view.php?id=' . $patientId,
    'Detalhes do Paciente',
    'view',
    'Visualizou paciente: ' . $patient['full_name'],
    'patient',
    $patientId
);
```

### Tipos de Ação Recomendados

- `create` - Criação de registro
- `update` - Atualização de registro
- `delete` - Exclusão de registro
- `view` - Visualização de registro
- `approve` - Aprovação
- `reject` - Rejeição
- `send` - Envio (email, whatsapp, etc)
- `upload` - Upload de arquivo
- `download` - Download de arquivo
- `export` - Exportação de dados
- `import` - Importação de dados
- `status_change` - Mudança de status
- `assign` - Atribuição
- `transfer` - Transferência

### Visualizar Histórico

O histórico é acessado automaticamente através do ícone de relógio (⏰) no topo de cada página.

O modal mostra:
- Avatar com iniciais do usuário
- Nome e email do usuário
- Descrição da ação
- Data e hora
- Paginação automática (30 registros por página)

### Banco de Dados

A tabela `page_history` armazena:
- `page_url` - URL da página
- `page_title` - Título da página
- `action_type` - Tipo da ação
- `action_description` - Descrição detalhada
- `entity_type` - Tipo da entidade (opcional)
- `entity_id` - ID da entidade (opcional)
- `user_id`, `user_name`, `user_email` - Dados do usuário
- `ip_address`, `user_agent` - Informações técnicas
- `created_at` - Data/hora da ação

### Migração SQL

Execute a migration: `migrations/2026-03-05_0005_page_history.sql`

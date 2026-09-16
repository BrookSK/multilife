<?php

declare(strict_types=1);

/**
 * Cadastro rápido (PRÉ-CADASTRO) de profissional a partir do Chat ao Vivo.
 * Solicita apenas: Nome, Telefone, E-mail (opcional), Especialidade e Cidade.
 * Não é o cadastro completo — cria o profissional com a marca is_pre_registration = 1
 * para que a equipe complete os dados depois. Responde em JSON (chamado via fetch).
 */

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

// Resposta JSON padronizada
function qc_json(bool $ok, string $message, array $extra = []): void
{
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra));
    exit;
}

try {
    auth_require_login();
} catch (Throwable $e) {
    qc_json(false, 'Sessão expirada. Faça login novamente.');
}

$uid = (int)auth_user_id();
if (!rbac_user_can($uid, 'users.manage') && !rbac_user_can($uid, 'demands.manage')) {
    qc_json(false, 'Você não tem permissão para cadastrar profissionais.');
}

$name = trim((string)($_POST['name'] ?? ''));
$emailRaw = trim((string)($_POST['email'] ?? ''));
$phoneRaw = trim((string)($_POST['phone'] ?? ''));
$specialty = trim((string)($_POST['specialty'] ?? ''));
$city = trim((string)($_POST['city'] ?? ''));

// Validações mínimas (pré-cadastro): nome e telefone são obrigatórios.
if ($name === '') {
    qc_json(false, 'Informe o nome do profissional.');
}

$phone = preg_replace('/\D+/', '', $phoneRaw);
if ($phone === '' || strlen($phone) < 10) {
    qc_json(false, 'Informe um telefone válido (com DDD).');
}

// E-mail é OPCIONAL. Se informado, precisa ser válido.
$email = null;
if ($emailRaw !== '') {
    if (!filter_var($emailRaw, FILTER_VALIDATE_EMAIL)) {
        qc_json(false, 'E-mail inválido.');
    }
    $email = $emailRaw;
}

$db = db();

// Garantir colunas de apoio (fallback, idempotente).
try { $db->exec("ALTER TABLE users ADD COLUMN is_pre_registration TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE users ADD COLUMN city VARCHAR(120) NULL"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE users ADD COLUMN professional_type VARCHAR(20) NOT NULL DEFAULT 'new'"); } catch (Throwable $e) {}

// Evitar duplicidade: por e-mail (se informado) ou por telefone.
if ($email !== null) {
    $stmt = $db->prepare('SELECT id, name FROM users WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $email]);
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        qc_json(false, 'Já existe um usuário com esse e-mail: ' . (string)$row['name']);
    }
}
$stmt = $db->prepare("SELECT id, name FROM users WHERE REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone,''),' ',''),'-',''),'(',''),')','') = :phone LIMIT 1");
$stmt->execute(['phone' => $phone]);
if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    qc_json(false, 'Já existe um profissional com esse telefone: ' . (string)$row['name'], ['duplicate_user_id' => (int)$row['id']]);
}

// E-mail placeholder quando não informado (a coluna costuma ser NOT NULL/única).
$emailToSave = $email;
if ($emailToSave === null) {
    $emailToSave = 'precadastro_' . $phone . '_' . substr(bin2hex(random_bytes(3)), 0, 6) . '@precadastro.local';
}

// Senha aleatória: o pré-cadastro não faz login até ser completado.
$randomPassword = bin2hex(random_bytes(8));
$hash = password_hash($randomPassword, PASSWORD_BCRYPT);

$db->beginTransaction();
try {
    $ins = $db->prepare(
        'INSERT INTO users (name, email, phone, specialty, city, password_hash, status, is_pre_registration, professional_type)
         VALUES (:name, :email, :phone, :specialty, :city, :hash, :status, 1, :ptype)'
    );
    $ins->execute([
        'name' => $name,
        'email' => $emailToSave,
        'phone' => $phone,
        'specialty' => $specialty !== '' ? $specialty : null,
        'city' => $city !== '' ? $city : null,
        'hash' => $hash,
        'status' => 'active',
        'ptype' => 'new',
    ]);
    $newId = (int)$db->lastInsertId();

    // Atribuir a role 'profissional'
    $roleStmt = $db->prepare("SELECT id FROM roles WHERE slug = 'profissional' LIMIT 1");
    $roleStmt->execute();
    $roleRow = $roleStmt->fetch(PDO::FETCH_ASSOC);
    if ($roleRow) {
        $assign = $db->prepare('INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (:uid, :rid)');
        $assign->execute(['uid' => $newId, 'rid' => (int)$roleRow['id']]);
    }

    $db->commit();

    audit_log('create', 'users_pre_registration', (string)$newId, null, [
        'name' => $name,
        'phone' => $phone,
        'email' => $email,
        'specialty' => $specialty,
        'city' => $city,
    ]);

    qc_json(true, 'Profissional pré-cadastrado com sucesso.', [
        'user_id' => $newId,
        'name' => $name,
        'phone' => $phone,
    ]);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[QUICK_PROF_CREATE] ' . $e->getMessage());
    qc_json(false, 'Erro ao pré-cadastrar profissional. Tente novamente.');
}

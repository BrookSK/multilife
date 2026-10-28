<?php

declare(strict_types=1);

/**
 * Cadastro RÁPIDO (botão "+" do card de captação): cliente, operadora ou
 * especialidade, sem sair da tela. Responde JSON {ok, id, name} para o
 * formulário já deixar o item selecionado.
 */

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function qr_json(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (auth_user_id() === null) {
    qr_json(['ok' => false, 'error' => 'Sessão expirada. Faça login novamente.']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    qr_json(['ok' => false, 'error' => 'Método inválido.']);
}
if (!quick_register_allowed()) {
    qr_json(['ok' => false, 'error' => 'Você não tem permissão para cadastrar.']);
}

$type = (string)($_POST['type'] ?? '');
$name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 200);
$email = trim((string)($_POST['contact_email'] ?? ''));
$domain = strtolower(trim((string)($_POST['email_domain'] ?? '')));
$domain = ltrim(preg_replace('/^https?:\/\//', '', $domain) ?? $domain, '@');
$clientId = (int)($_POST['client_id'] ?? 0);

if ($name === '') {
    qr_json(['ok' => false, 'error' => 'Informe o nome.']);
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    qr_json(['ok' => false, 'error' => 'E-mail inválido.']);
}
if ($domain !== '' && !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain)) {
    qr_json(['ok' => false, 'error' => 'Domínio inválido (ex.: operadora.com.br).']);
}
// Sem domínio informado, usa o do e-mail de contato (ajuda a reconhecer e-mails futuros).
if ($domain === '' && $email !== '') {
    $domain = strtolower((string)substr(strrchr($email, '@') ?: '', 1));
}

$db = db();
try {
    if ($type === 'specialty') {
        $st = $db->prepare('SELECT id, name, status FROM specialties WHERE LOWER(name) = LOWER(:n) LIMIT 1');
        $st->execute(['n' => $name]);
        if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            if ((string)$row['status'] !== 'active') {
                $db->prepare("UPDATE specialties SET status = 'active' WHERE id = :id")->execute(['id' => (int)$row['id']]);
            }
            qr_json(['ok' => true, 'id' => (int)$row['id'], 'name' => (string)$row['name'], 'existing' => true]);
        }
        $db->prepare("INSERT INTO specialties (name, status) VALUES (:n, 'active')")->execute(['n' => mb_substr($name, 0, 120)]);
        $newId = (int)$db->lastInsertId();
        audit_log('create', 'specialties', (string)$newId, null, ['name' => $name, 'via' => 'cadastro_rapido']);
        qr_json(['ok' => true, 'id' => $newId, 'name' => $name]);
    }

    if ($type === 'client') {
        clients_ensure_schema();
        $st = $db->prepare('SELECT id, name FROM clients WHERE LOWER(name) = LOWER(:n) LIMIT 1');
        $st->execute(['n' => $name]);
        if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            qr_json(['ok' => true, 'id' => (int)$row['id'], 'name' => (string)$row['name'], 'existing' => true]);
        }
        $db->prepare('INSERT INTO clients (name, contact_email, email_domain, is_active) VALUES (:n, :e, :d, 1)')
            ->execute(['n' => $name, 'e' => $email !== '' ? $email : null, 'd' => $domain !== '' ? $domain : null]);
        $newId = (int)$db->lastInsertId();
        audit_log('create', 'clients', (string)$newId, null, ['name' => $name, 'via' => 'cadastro_rapido']);
        qr_json(['ok' => true, 'id' => $newId, 'name' => $name]);
    }

    if ($type === 'health_insurer') {
        clients_ensure_schema();
        try { $db->exec('ALTER TABLE health_insurers ADD COLUMN email_domain VARCHAR(255) NULL'); } catch (Throwable $e) {}
        $st = $db->prepare('SELECT id, name FROM health_insurers WHERE LOWER(name) = LOWER(:n) LIMIT 1');
        $st->execute(['n' => $name]);
        if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            qr_json(['ok' => true, 'id' => (int)$row['id'], 'name' => (string)$row['name'], 'existing' => true]);
        }
        $db->prepare('INSERT INTO health_insurers (name, client_id, contact_email, email_domain, is_active) VALUES (:n, :c, :e, :d, 1)')
            ->execute([
                'n' => $name,
                'c' => $clientId > 0 ? $clientId : null,
                'e' => $email !== '' ? $email : null,
                'd' => $domain !== '' ? $domain : null,
            ]);
        $newId = (int)$db->lastInsertId();
        audit_log('create', 'health_insurers', (string)$newId, null, ['name' => $name, 'via' => 'cadastro_rapido']);
        qr_json(['ok' => true, 'id' => $newId, 'name' => $name]);
    }

    qr_json(['ok' => false, 'error' => 'Tipo de cadastro inválido.']);
} catch (Throwable $e) {
    error_log('[QUICK_REGISTER] ' . $e->getMessage());
    qr_json(['ok' => false, 'error' => 'Erro ao cadastrar: ' . $e->getMessage()]);
}

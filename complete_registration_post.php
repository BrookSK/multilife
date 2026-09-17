<?php
/**
 * Processa o envio do formulário público de atualização cadastral (sem login).
 * Valida o token, grava/atualiza a candidatura (professional_applications) vinculada
 * ao profissional e atualiza os dados básicos em users. Ao final, invalida o token.
 */

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

function cr_fail(string $token, string $msg): void
{
    // Volta para a página pública mantendo o token; a mensagem é opcional.
    if ($token !== '') {
        header('Location: /atualizar-cadastro?token=' . urlencode($token));
    } else {
        http_response_code(400);
        echo 'Requisição inválida.';
    }
    exit;
}

$token = trim((string)($_POST['token'] ?? ''));
if ($token === '' || strlen($token) < 32) {
    http_response_code(404);
    echo 'Link inválido ou expirado.';
    exit;
}

$db = db();

// Colunas de apoio (idempotente)
try { $db->exec("ALTER TABLE users ADD COLUMN registration_token VARCHAR(64) NULL"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE users ADD COLUMN registration_token_created_at DATETIME NULL"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE users ADD COLUMN city VARCHAR(120) NULL"); } catch (Throwable $e) {}
try { $db->exec("ALTER TABLE users ADD COLUMN is_pre_registration TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}

// Localizar profissional pelo token
$uStmt = $db->prepare('SELECT id, name, email, phone FROM users WHERE registration_token = :t LIMIT 1');
$uStmt->execute(['t' => $token]);
$user = $uStmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    http_response_code(404);
    echo 'Link inválido ou expirado.';
    exit;
}
$userId = (int)$user['id'];

// Coletar campos (todos opcionais, exceto nome e telefone)
$fullName = trim((string)($_POST['full_name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$phone = trim((string)($_POST['phone'] ?? ''));

if ($fullName === '') {
    cr_fail($token, 'Informe o nome completo.');
}
$phoneDigits = preg_replace('/\D+/', '', $phone);
if ($phoneDigits === '' || strlen($phoneDigits) < 10) {
    cr_fail($token, 'Informe um telefone válido.');
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    cr_fail($token, 'E-mail inválido.');
}

// Campos da candidatura (professional_applications)
$appFields = [
    'cities_of_operation', 'marital_status', 'sex', 'religion', 'birthplace', 'nationality', 'education_level',
    'address_street', 'address_number', 'address_complement', 'address_neighborhood', 'address_city', 'address_state', 'address_zip',
    'rg', 'council_abbr', 'council_number', 'council_state',
    'bank_name', 'bank_agency', 'bank_account', 'bank_account_type', 'bank_account_holder', 'bank_account_holder_cpf', 'pix_key', 'pix_holder',
    'home_care_experience', 'years_of_experience', 'specialty', 'specializations',
];
$data = [];
foreach ($appFields as $f) {
    $v = trim((string)($_POST[$f] ?? ''));
    $data[$f] = ($v !== '') ? $v : null;
}
// UFs em maiúsculas (CHAR(2))
foreach (['address_state', 'council_state'] as $ufField) {
    if ($data[$ufField] !== null) {
        $data[$ufField] = strtoupper(substr($data[$ufField], 0, 2));
    }
}

$db->beginTransaction();
try {
    // 1) Atualizar dados básicos do usuário e marcar que não é mais pré-cadastro
    // (não sobrescreve o e-mail por placeholder vazio; só atualiza se informado)
    if ($email !== '') {
        $db->prepare('UPDATE users SET name = :name, email = :email, phone = :phone, specialty = :spec, city = :city, is_pre_registration = 0 WHERE id = :id')
            ->execute([
                'name' => $fullName,
                'email' => $email,
                'phone' => $phoneDigits,
                'spec' => $data['specialty'],
                'city' => $data['address_city'],
                'id' => $userId,
            ]);
    } else {
        $db->prepare('UPDATE users SET name = :name, phone = :phone, specialty = :spec, city = :city, is_pre_registration = 0 WHERE id = :id')
            ->execute([
                'name' => $fullName,
                'phone' => $phoneDigits,
                'spec' => $data['specialty'],
                'city' => $data['address_city'],
                'id' => $userId,
            ]);
    }

    // E-mail a usar na candidatura (a coluna é única e NOT NULL)
    $appEmail = $email !== '' ? $email : (string)($user['email'] ?? '');
    if ($appEmail === '') {
        $appEmail = 'cadastro_' . $phoneDigits . '@precadastro.local';
    }

    // 2) Verificar se já existe candidatura vinculada a este usuário
    $existing = null;
    try {
        $exStmt = $db->prepare('SELECT id FROM professional_applications WHERE created_user_id = :uid ORDER BY id DESC LIMIT 1');
        $exStmt->execute(['uid' => $userId]);
        $existing = $exStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { $existing = null; }

    if ($existing) {
        // Atualizar candidatura existente
        $set = ['full_name = :full_name', 'phone = :phone'];
        $params = ['full_name' => $fullName, 'phone' => $phoneDigits, 'id' => (int)$existing['id']];
        // Só atualiza e-mail se informado (evita conflito com unique quando placeholder)
        if ($email !== '') {
            $set[] = 'email = :email';
            $params['email'] = $email;
        }
        foreach ($appFields as $f) {
            $set[] = "$f = :$f";
            $params[$f] = $data[$f];
        }
        $sql = 'UPDATE professional_applications SET ' . implode(', ', $set) . ', updated_at = NOW() WHERE id = :id';
        $db->prepare($sql)->execute($params);
        $applicationId = (int)$existing['id'];
    } else {
        // Criar candidatura nova vinculada ao usuário (status approved, pois já passou pela pré-admissão)
        $cols = array_merge(['status', 'full_name', 'email', 'phone', 'created_user_id', 'reviewed_at'], $appFields);
        $placeholders = [];
        $insParams = [
            'status' => 'approved',
            'full_name' => $fullName,
            'email' => $appEmail,
            'phone' => $phoneDigits,
            'created_user_id' => $userId,
        ];
        foreach ($appFields as $f) {
            $insParams[$f] = $data[$f];
        }
        // Montar SQL
        $colList = [];
        $valList = [];
        foreach ($cols as $c) {
            $colList[] = $c;
            if ($c === 'reviewed_at') {
                $valList[] = 'NOW()';
            } else {
                $valList[] = ':' . $c;
            }
        }
        $sql = 'INSERT INTO professional_applications (' . implode(', ', $colList) . ') VALUES (' . implode(', ', $valList) . ')';
        try {
            $db->prepare($sql)->execute($insParams);
            $applicationId = (int)$db->lastInsertId();
        } catch (Throwable $e) {
            // Fallback: e-mail pode colidir com unique. Tenta com e-mail placeholder único.
            $insParams['email'] = 'cadastro_' . $phoneDigits . '_' . substr(bin2hex(random_bytes(3)), 0, 6) . '@precadastro.local';
            $db->prepare($sql)->execute($insParams);
            $applicationId = (int)$db->lastInsertId();
        }
    }

    // 3) Invalidar o token (uso único) — evita reenvio/edição posterior pelo mesmo link.
    $db->prepare('UPDATE users SET registration_token = NULL WHERE id = :id')->execute(['id' => $userId]);

    $db->commit();

    // Pendência interna para a equipe conferir o cadastro completado
    try {
        $db->prepare(
            "INSERT INTO pending_items (type, status, title, detail, related_table, related_id, assigned_user_id)"
            . " VALUES ('professional_registration_completed','open',:title,:detail,'users',:rid,NULL)"
        )->execute([
            'title' => 'Cadastro completado pelo profissional: ' . $fullName,
            'detail' => 'O profissional completou os dados cadastrais pelo link público (pós pré-admissão).',
            'rid' => $userId,
        ]);
    } catch (Throwable $e) { /* pendências são opcionais */ }

    audit_log('update', 'professional_registration_completed', (string)$userId, null, [
        'application_id' => $applicationId ?? null,
        'full_name' => $fullName,
    ]);

} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[COMPLETE_REGISTRATION] ' . $e->getMessage());
    // Mostra uma página simples de erro (o token pode já não existir para voltar ao form)
    http_response_code(500);
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><title>Erro</title></head>'
        . '<body style="font-family:sans-serif;text-align:center;padding:60px">'
        . '<h2>Não foi possível salvar seu cadastro</h2>'
        . '<p>Tente novamente em instantes ou entre em contato com a equipe MultiLife Care.</p>'
        . '</body></html>';
    exit;
}

// Sucesso: página de confirmação (o token já foi invalidado, então mostramos uma página final)
header('Content-Type: text/html; charset=utf-8');
$logoUrl = (string)admin_setting_get('app.logo_url', '');
echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Cadastro enviado</title>';
echo '<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f5f7fa;color:#1a1a2e;text-align:center;padding:60px 16px}';
echo '.box{max-width:520px;margin:0 auto;background:#fff;border-radius:12px;padding:32px;box-shadow:0 2px 8px rgba(0,0,0,.06)}';
echo 'h1{color:#00a884;font-size:24px;margin-bottom:12px}p{color:#475569;font-size:15px;line-height:1.6}.check{font-size:56px;margin-bottom:8px}</style></head><body>';
echo '<div class="box">';
if ($logoUrl !== '') {
    echo '<img src="' . htmlspecialchars($logoUrl, ENT_QUOTES) . '" alt="MultiLife Care" style="max-height:54px;margin-bottom:16px">';
}
echo '<div class="check">✅</div>';
echo '<h1>Cadastro enviado com sucesso!</h1>';
echo '<p>Obrigado, <strong>' . htmlspecialchars($fullName, ENT_QUOTES) . '</strong>. Recebemos as suas informações. A equipe MultiLife Care fará a conferência.</p>';
echo '</div></body></html>';
exit;

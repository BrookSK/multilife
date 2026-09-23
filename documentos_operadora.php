<?php

declare(strict_types=1);

/**
 * Página PÚBLICA (sem login) de documentos da operadora para o profissional.
 *
 * Acessada por link com token dedicado (users.documents_token, persistente).
 * Exibe os documentos das operadoras vinculadas aos atendimentos ativos do
 * profissional, organizados por ESPECIALIDADE e por TIPO de documento, e
 * separando os DOCUMENTOS EXTRAS/COMPLEMENTARES adicionados pela operadora.
 *
 * Fonte dos documentos: health_insurer_documents (operadora + especialidade +
 * tipo + is_extra + professional_type). Vínculo com o profissional via
 * patient_assignments.health_insurer_id + professional_user_id.
 */

require_once __DIR__ . '/app/bootstrap.php';

function docs_invalid_link(): void
{
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><title>Link inválido</title></head>'
        . '<body style="font-family:sans-serif;text-align:center;padding:60px;color:#1a1a2e">'
        . '<h2>Link inválido ou expirado</h2>'
        . '<p>Verifique o link recebido por WhatsApp/e-mail ou entre em contato com a equipe MultiLife Care.</p>'
        . '</body></html>';
    exit;
}

$token = trim((string)($_GET['token'] ?? ''));
if ($token === '' || strlen($token) < 32) {
    docs_invalid_link();
}

// Garantir colunas de apoio (idempotente).
try { db()->exec("ALTER TABLE users ADD COLUMN documents_token VARCHAR(64) NULL"); } catch (Throwable $e) {}
try { db()->exec("ALTER TABLE health_insurer_documents ADD COLUMN specialty VARCHAR(120) NULL"); } catch (Throwable $e) {}
try { db()->exec("ALTER TABLE health_insurer_documents ADD COLUMN doc_type VARCHAR(120) NULL"); } catch (Throwable $e) {}
try { db()->exec("ALTER TABLE health_insurer_documents ADD COLUMN is_extra TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
try { db()->exec("ALTER TABLE health_insurer_documents ADD COLUMN professional_type ENUM('novo','antigo','ambos') NOT NULL DEFAULT 'ambos'"); } catch (Throwable $e) {}

// Localizar o profissional pelo token de documentos.
$uStmt = db()->prepare('SELECT id, name, professional_type FROM users WHERE documents_token = :t LIMIT 1');
$uStmt->execute(['t' => $token]);
$user = $uStmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    docs_invalid_link();
}
$userId = (int)$user['id'];

// professional_type do profissional (novo/antigo) para filtrar documentos.
// Coluna users.professional_type usa 'new'/'old' no cadastro; mapeamos para a
// nomenclatura dos documentos ('novo'/'antigo').
// users.professional_type usa 'new' (novo) e 'legacy'/'old' (antigo) no cadastro.
$profTypeRaw = strtolower(trim((string)($user['professional_type'] ?? '')));
$profTypeDoc = in_array($profTypeRaw, ['old', 'legacy', 'antigo'], true) ? 'antigo'
    : (in_array($profTypeRaw, ['new', 'novo'], true) ? 'novo' : '');

// Buscar documentos das operadoras dos atendimentos ATIVOS do profissional.
// Filtra por professional_type do documento ('ambos' sempre entra; senão precisa
// bater com o tipo do profissional, quando conhecido).
$rows = [];
try {
    $sql = "SELECT DISTINCT hid.id, hid.file_name, hid.file_path, hid.mime_type,
                   hid.specialty, hid.doc_type, hid.is_extra, hid.professional_type,
                   hi.name AS insurer_name,
                   cl.name AS client_name
            FROM health_insurer_documents hid
            INNER JOIN health_insurers hi ON hi.id = hid.health_insurer_id
            INNER JOIN patient_assignments pa ON pa.health_insurer_id = hi.id
            LEFT JOIN clients cl ON cl.id = hi.client_id
            WHERE pa.professional_user_id = :uid
              AND pa.status IN ('admitted', 'awaiting_documents', 'awaiting_financial_approval', 'approved')
            ORDER BY hi.name ASC, hid.is_extra ASC, hid.specialty ASC, hid.doc_type ASC, hid.file_name ASC";
    $stmt = db()->prepare($sql);
    $stmt->execute(['uid' => $userId]);
    $allRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Filtro de professional_type em PHP (mantém 'ambos' e o tipo do profissional).
    foreach ($allRows as $r) {
        $docPt = (string)($r['professional_type'] ?? 'ambos');
        if ($docPt === 'ambos' || $docPt === '' || $profTypeDoc === '' || $docPt === $profTypeDoc) {
            $rows[] = $r;
        }
    }
} catch (Throwable $e) {
    error_log('[DOCUMENTOS_OPERADORA] Erro ao buscar documentos (user ' . $userId . '): ' . $e->getMessage());
    $rows = [];
}

// Organizar: operadora -> [principais: especialidade -> tipo -> [docs]] + [extras]
$byInsurer = [];
foreach ($rows as $r) {
    $insurer = (string)($r['insurer_name'] ?? 'Operadora');
    if (!isset($byInsurer[$insurer])) {
        $byInsurer[$insurer] = ['main' => [], 'extra' => [], 'client_name' => (string)($r['client_name'] ?? '')];
    }
    if ((int)($r['is_extra'] ?? 0) === 1) {
        $byInsurer[$insurer]['extra'][] = $r;
    } else {
        $spec = trim((string)($r['specialty'] ?? '')) !== '' ? (string)$r['specialty'] : INSURER_DOC_SPECIALTY_FALLBACK_LABEL;
        $type = trim((string)($r['doc_type'] ?? '')) !== '' ? (string)$r['doc_type'] : INSURER_DOC_TYPE_FALLBACK_LABEL;
        $byInsurer[$insurer]['main'][$spec][$type][] = $r;
    }
}

// Ordenar, dentro de cada especialidade, os tipos pela taxonomia canônica
// (Avaliação → Relatório gerencial → complementares → sem tipo). A especialidade
// já vem ordenada alfabeticamente pela query.
foreach ($byInsurer as &$groupsRef) {
    foreach ($groupsRef['main'] as &$typesRef) {
        uksort($typesRef, static function (string $a, string $b): int {
            $wa = insurer_doc_type_sort_weight($a === INSURER_DOC_TYPE_FALLBACK_LABEL ? '' : $a);
            $wb = insurer_doc_type_sort_weight($b === INSURER_DOC_TYPE_FALLBACK_LABEL ? '' : $b);
            if ($wa !== $wb) {
                return $wa <=> $wb;
            }
            return strcmp($a, $b);
        });
    }
    unset($typesRef);
}
unset($groupsRef);

$logoUrl = (string)admin_setting_get('app.logo_url', '');
$profName = (string)($user['name'] ?? 'profissional');

function docs_e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES); }

function docs_icon(string $fileName): string
{
    if (preg_match('/\.pdf$/i', $fileName)) return '📄';
    if (preg_match('/\.(doc|docx)$/i', $fileName)) return '📝';
    if (preg_match('/\.(xls|xlsx)$/i', $fileName)) return '📊';
    return '📎';
}

function docs_render_link(array $doc, string $token): string
{
    $href = docs_e((string)($doc['file_path'] ?? ''));
    $name = docs_e((string)($doc['file_name'] ?? 'Documento'));
    $icon = docs_icon((string)($doc['file_name'] ?? ''));
    $downloadUrl = docs_e('/documento_download.php?token=' . urlencode($token) . '&doc=' . (int)($doc['id'] ?? 0));

    return '<div class="doc">'
        . '<span class="doc-icon">' . $icon . '</span>'
        . '<span class="doc-name">' . $name . '</span>'
        . '<a class="doc-btn doc-open" href="' . $href . '" target="_blank" rel="noopener">abrir ↗</a>'
        . '<a class="doc-btn doc-download" href="' . $downloadUrl . '">baixar ↓</a>'
        . '</div>';
}

$totalDocs = count($rows);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Documentos da Operadora - MultiLife Care</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f7fa; color: #1a1a2e; line-height: 1.6; }
        .container { max-width: 820px; margin: 0 auto; padding: 24px 16px 60px; }
        .card { background: #fff; border-radius: 12px; padding: 24px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,.06); }
        .logo { text-align: center; margin-bottom: 20px; }
        .logo img { max-height: 54px; }
        h1 { font-size: 22px; font-weight: 700; margin-bottom: 8px; color: #00a884; }
        .intro { color: #64748b; font-size: 14px; margin-top: 4px; }
        .insurer-title { font-size: 18px; font-weight: 800; color: #1a1a2e; margin-bottom: 4px; }
        .section-title { font-size: 15px; font-weight: 700; margin: 18px 0 8px; color: #1a1a2e; border-bottom: 2px solid #e2e8f0; padding-bottom: 6px; }
        .type-title { font-size: 13px; font-weight: 700; color: #4338ca; margin: 12px 0 6px; text-transform: uppercase; letter-spacing: .3px; }
        .extra-title { font-size: 15px; font-weight: 700; margin: 18px 0 8px; color: #92400e; border-bottom: 2px solid #fde68a; padding-bottom: 6px; }
        .doc { display: flex; align-items: center; gap: 10px; padding: 12px 14px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 8px; color: #1a1a2e; flex-wrap: wrap; }
        .doc-icon { font-size: 20px; flex: 0 0 auto; }
        .doc-name { font-size: 14px; font-weight: 600; flex: 1 1 auto; word-break: break-word; min-width: 140px; }
        .doc-btn { font-size: 12px; font-weight: 700; flex: 0 0 auto; text-decoration: none; padding: 6px 12px; border-radius: 6px; border: 1px solid transparent; transition: background .15s, border-color .15s; }
        .doc-open { color: #00a884; border-color: #00a884; }
        .doc-open:hover { background: #eef6f3; }
        .doc-download { color: #fff; background: #00a884; }
        .doc-download:hover { background: #06976f; }
        .empty { text-align: center; padding: 40px 16px; color: #64748b; }
        .footer { text-align: center; color: #94a3b8; font-size: 12px; margin-top: 24px; }
    </style>
</head>
<body>
<div class="container">
    <?php if ($logoUrl !== ''): ?>
    <div class="logo"><img src="<?= docs_e($logoUrl) ?>" alt="MultiLife Care"></div>
    <?php endif; ?>

    <div class="card">
        <h1>Documentos da Operadora</h1>
        <p class="intro">Olá, <strong><?= docs_e($profName) ?></strong>! Aqui estão os documentos das operadoras dos seus atendimentos, organizados por especialidade (avaliação, relatório gerencial e materiais complementares).</p>
    </div>

    <?php if ($totalDocs === 0): ?>
        <div class="card empty">
            <div style="font-size:40px;margin-bottom:8px">📁</div>
            <div style="font-weight:700;margin-bottom:4px">Nenhum documento disponível no momento</div>
            <div style="font-size:14px">Assim que a operadora disponibilizar documentos para o seu atendimento, eles aparecerão aqui.</div>
        </div>
    <?php else: ?>
        <?php foreach ($byInsurer as $insurerName => $groups): ?>
            <div class="card">
                <div class="insurer-title"><?= docs_e($insurerName) ?><?php if (!empty($groups['client_name'])): ?><span style="font-weight:500;font-size:14px;color:#64748b"> — <?= docs_e($groups['client_name']) ?></span><?php endif; ?></div>

                <?php if (!empty($groups['main'])): ?>
                    <?php foreach ($groups['main'] as $spec => $types): ?>
                        <div class="section-title"><?= docs_e($spec) ?></div>
                        <?php foreach ($types as $type => $docs): ?>
                            <div class="type-title"><?= docs_e($type) ?></div>
                            <?php foreach ($docs as $doc): ?>
                                <?= docs_render_link($doc, $token) ?>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if (!empty($groups['extra'])): ?>
                    <div class="extra-title">Documentos extras / complementares</div>
                    <?php foreach ($groups['extra'] as $doc): ?>
                        <?= docs_render_link($doc, $token) ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="footer">© <?= date('Y') ?> MultiLife Care — Atendimento Domiciliar</div>
</div>
</body>
</html>

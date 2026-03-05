<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

// MD 5.4 + 10.3: monitorar validade documental do profissional.
// Gera pendências 30 dias antes e pendências de vencido.

$noticeDays = (int)admin_setting_get('professional.docs_expiry_notice_days', '30');
if ($noticeDays <= 0) {
    $noticeDays = 30;
}

$requiredCsv = trim((string)admin_setting_get('professional.required_doc_categories', ''));
$required = [];
if ($requiredCsv !== '') {
    foreach (preg_split('/\s*,\s*/', $requiredCsv) as $c) {
        $c = trim((string)$c);
        if ($c !== '') {
            $required[] = $c;
        }
    }
}

$db = db();

$today = (new DateTime('today'))->format('Y-m-d');
$noticeDate = (new DateTime('today'));
$noticeDate->modify('+' . $noticeDays . ' days');
$noticeUntil = $noticeDate->format('Y-m-d');

// Busca docs de profissionais com valid_until
$sql = "SELECT d.entity_id AS professional_user_id, d.category, v.valid_until,
               u.name AS professional_name
        FROM document_versions v
        INNER JOIN documents d ON d.id = v.document_id
        LEFT JOIN users u ON u.id = d.entity_id
        WHERE d.entity_type = 'professional'
          AND d.status = 'active'
          AND v.valid_until IS NOT NULL";
$params = [];

if (count($required) > 0) {
    $in = [];
    foreach ($required as $i => $cat) {
        $k = 'c' . $i;
        $in[] = ':' . $k;
        $params[$k] = $cat;
    }
    $sql .= ' AND d.category IN (' . implode(',', $in) . ')';
}

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

if (count($rows) === 0) {
    echo "OK: no professional docs with valid_until\n";
    exit;
}

$exists = $db->prepare(
    "SELECT id FROM pending_items
     WHERE status = 'open' AND type = :type
       AND related_table = 'documents' AND related_id = :rid
     LIMIT 1"
);

$ins = $db->prepare(
    "INSERT INTO pending_items (type, status, title, detail, related_table, related_id, assigned_user_id)
     VALUES (:type,'open',:title,:detail,'documents',:rid,:uid)"
);

$created = 0;
foreach ($rows as $r) {
    $uid = $r['professional_user_id'] !== null ? (int)$r['professional_user_id'] : 0;
    if ($uid <= 0) {
        continue;
    }

    $cat = (string)$r['category'];
    $validUntil = (string)$r['valid_until'];
    $name = $r['professional_name'] ? (string)$r['professional_name'] : ('User #' . $uid);

    $type = '';
    $title = '';
    $detail = '';

    if ($validUntil < $today) {
        $type = 'professional_doc_expired';
        $title = 'Documento vencido: ' . $name . ' — ' . $cat;
        $detail = 'Vencido em ' . $validUntil . '. Bloquear novos agendamentos até regularizar.';
    } elseif ($validUntil <= $noticeUntil) {
        $type = 'professional_doc_expiring';
        $title = 'Documento a vencer: ' . $name . ' — ' . $cat;
        $detail = 'Vence em ' . $validUntil . ' (aviso ' . $noticeDays . ' dias antes).';
    } else {
        continue;
    }

    // related_id: usar o document_id (mais preciso que versão)
    // buscamos o documento pela categoria/entidade/valid_until
    $stmt2 = $db->prepare(
        "SELECT d.id
         FROM documents d
         INNER JOIN document_versions v ON v.document_id = d.id
         WHERE d.entity_type='professional' AND d.entity_id=:uid AND d.category=:cat AND d.status='active' AND v.valid_until=:vu
         ORDER BY v.version_no DESC
         LIMIT 1"
    );
    $stmt2->execute(['uid' => $uid, 'cat' => $cat, 'vu' => $validUntil]);
    $doc = $stmt2->fetch();
    if (!$doc) {
        continue;
    }

    $rid = (int)$doc['id'];

    $exists->execute(['type' => $type, 'rid' => $rid]);
    if ($exists->fetch()) {
        continue;
    }

    $ins->execute([
        'type' => $type,
        'title' => $title,
        'detail' => $detail,
        'rid' => $rid,
        'uid' => $uid,
    ]);

    $created++;
}

echo 'OK: created=' . $created . "\n";

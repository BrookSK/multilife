<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

// Job CRON para monitoramento de validade documental (Módulo 10.3)
// 30 dias antes: notificação ao profissional e admin
// Vencido: pendência criada no Admin + bloqueio de novos agendamentos

$db = db();

$noticeDaysBefore = (int)admin_setting_get('professional.docs_expiry_notice_days', '30');

// 1. Documentos próximos do vencimento (30 dias antes)
$stmt = $db->prepare(
    "SELECT dv.id AS version_id, dv.document_id, dv.valid_until, d.entity_type, d.entity_id, d.category, d.title,
            u.name AS professional_name, u.email AS professional_email, u.phone AS professional_phone
     FROM document_versions dv
     INNER JOIN documents d ON d.id = dv.document_id
     LEFT JOIN users u ON u.id = d.entity_id AND d.entity_type = 'professional'
     WHERE dv.valid_until IS NOT NULL
       AND dv.valid_until BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL :days DAY)
       AND dv.id = (SELECT MAX(dv2.id) FROM document_versions dv2 WHERE dv2.document_id = dv.document_id)
       AND d.status = 'active'
       AND NOT EXISTS (
           SELECT 1 FROM integration_jobs ij
           WHERE ij.job_type = 'document_expiry_notice'
             AND ij.payload LIKE CONCAT('%\"version_id\":', dv.id, '%')
             AND ij.created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
       )"
);
$stmt->execute(['days' => $noticeDaysBefore]);
$expiringDocs = $stmt->fetchAll();

foreach ($expiringDocs as $doc) {
    $db->beginTransaction();
    try {
        // Notificação WhatsApp para profissional
        if ($doc['entity_type'] === 'professional' && $doc['professional_phone'] !== null) {
            $stmt = $db->prepare(
                "INSERT INTO integration_jobs (job_type, payload, status)
                 VALUES ('document_expiry_notice', :payload, 'pending')"
            );
            $stmt->execute([
                'payload' => json_encode([
                    'version_id' => (int)$doc['version_id'],
                    'document_id' => (int)$doc['document_id'],
                    'entity_id' => (int)$doc['entity_id'],
                    'category' => (string)$doc['category'],
                    'valid_until' => (string)$doc['valid_until'],
                    'professional_name' => (string)$doc['professional_name'],
                    'professional_phone' => (string)$doc['professional_phone'],
                ], JSON_THROW_ON_ERROR),
            ]);
        }

        // Notificação e-mail para profissional
        if ($doc['entity_type'] === 'professional' && $doc['professional_email'] !== null) {
            $stmt = $db->prepare(
                "INSERT INTO integration_jobs (job_type, payload, status)
                 VALUES ('document_expiry_notice_email', :payload, 'pending')"
            );
            $stmt->execute([
                'payload' => json_encode([
                    'version_id' => (int)$doc['version_id'],
                    'document_id' => (int)$doc['document_id'],
                    'entity_id' => (int)$doc['entity_id'],
                    'category' => (string)$doc['category'],
                    'valid_until' => (string)$doc['valid_until'],
                    'professional_name' => (string)$doc['professional_name'],
                    'professional_email' => (string)$doc['professional_email'],
                ], JSON_THROW_ON_ERROR),
            ]);
        }

        // Pendência para admin
        $stmt = $db->prepare(
            "INSERT INTO pending_items (type, status, title, detail, related_table, related_id, assigned_user_id)
             VALUES ('document_expiring','open',:title,:detail,'documents',:rid,NULL)"
        );
        $stmt->execute([
            'title' => 'Documento próximo do vencimento: ' . h((string)$doc['category']) . ' - ' . h((string)($doc['professional_name'] ?? 'N/A')),
            'detail' => 'Vence em: ' . h((string)$doc['valid_until']) . ' | Doc #' . (int)$doc['document_id'],
            'rid' => (int)$doc['document_id'],
        ]);

        $db->commit();
        echo "Notificação de vencimento enviada para documento #{$doc['document_id']}\n";
    } catch (Throwable $e) {
        $db->rollBack();
        echo "ERRO ao processar documento #{$doc['document_id']}: " . $e->getMessage() . "\n";
    }
}

// 2. Documentos vencidos (criar pendência + bloqueio já está em appointments_create_post.php)
$stmt = $db->query(
    "SELECT dv.id AS version_id, dv.document_id, dv.valid_until, d.entity_type, d.entity_id, d.category, d.title,
            u.name AS professional_name
     FROM document_versions dv
     INNER JOIN documents d ON d.id = dv.document_id
     LEFT JOIN users u ON u.id = d.entity_id AND d.entity_type = 'professional'
     WHERE dv.valid_until IS NOT NULL
       AND dv.valid_until < CURDATE()
       AND dv.id = (SELECT MAX(dv2.id) FROM document_versions dv2 WHERE dv2.document_id = dv.document_id)
       AND d.status = 'active'
       AND NOT EXISTS (
           SELECT 1 FROM pending_items pi
           WHERE pi.type = 'document_expired'
             AND pi.related_table = 'documents'
             AND pi.related_id = d.id
             AND pi.status IN ('open','in_progress')
       )"
);
$expiredDocs = $stmt->fetchAll();

foreach ($expiredDocs as $doc) {
    $db->beginTransaction();
    try {
        $stmt = $db->prepare(
            "INSERT INTO pending_items (type, status, title, detail, related_table, related_id, assigned_user_id)
             VALUES ('document_expired','open',:title,:detail,'documents',:rid,NULL)"
        );
        $stmt->execute([
            'title' => 'Documento VENCIDO: ' . h((string)$doc['category']) . ' - ' . h((string)($doc['professional_name'] ?? 'N/A')),
            'detail' => 'Venceu em: ' . h((string)$doc['valid_until']) . ' | Doc #' . (int)$doc['document_id'] . ' | BLOQUEIO DE AGENDAMENTOS ATIVO',
            'rid' => (int)$doc['document_id'],
        ]);

        $db->commit();
        echo "Pendência de documento vencido criada para documento #{$doc['document_id']}\n";
    } catch (Throwable $e) {
        $db->rollBack();
        echo "ERRO ao criar pendência para documento #{$doc['document_id']}: " . $e->getMessage() . "\n";
    }
}

echo "Job concluído: " . count($expiringDocs) . " docs expirando, " . count($expiredDocs) . " docs vencidos.\n";

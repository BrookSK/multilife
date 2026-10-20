<?php

declare(strict_types=1);

/**
 * Aprendizado da classificação de e-mails (captação x não captação).
 *
 * - email_classification_feedback: histórico das marcações da equipe.
 * - email_classification_rules: regras consolidadas (remetente/domínio/assunto)
 *   usadas pelo classificador ANTES da IA.
 */

function email_classification_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS email_classification_feedback (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            inbound_email_id BIGINT UNSIGNED NULL,
            demand_id BIGINT UNSIGNED NULL,
            from_email VARCHAR(190) NULL,
            from_domain VARCHAR(190) NULL,
            subject VARCHAR(255) NULL,
            label ENUM('captation','not_captation') NOT NULL,
            created_by_user_id INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_ecf_domain (from_domain),
            KEY idx_ecf_email (from_email),
            KEY idx_ecf_label (label)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        error_log('[EMAIL_CLASS] ensure feedback: ' . $e->getMessage());
    }
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS email_classification_rules (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            rule_type ENUM('from_email','from_domain','subject_keyword') NOT NULL,
            rule_value VARCHAR(190) NOT NULL,
            label ENUM('captation','not_captation') NOT NULL,
            hits INT NOT NULL DEFAULT 1,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_ecr_rule (rule_type, rule_value),
            KEY idx_ecr_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        error_log('[EMAIL_CLASS] ensure rules: ' . $e->getMessage());
    }
}

/**
 * Extrai o domínio de um e-mail (minúsculo). Retorna '' se inválido.
 */
function email_classification_domain(string $email): string
{
    $email = mb_strtolower(trim($email));
    $at = strrpos($email, '@');
    if ($at === false) {
        return '';
    }
    return trim(substr($email, $at + 1));
}

/**
 * Consulta as REGRAS APRENDIDAS para decidir a classificação de um e-mail.
 * Ordem de precedência: remetente exato > domínio > palavra no assunto.
 *
 * @return array{found:bool, is_captation:bool, reason:string}
 */
function email_classification_lookup(string $fromEmail, string $subject): array
{
    email_classification_ensure_schema();
    $fromEmail = mb_strtolower(trim($fromEmail));
    $domain = email_classification_domain($fromEmail);
    $subjectLower = mb_strtolower(trim($subject));

    try {
        // 1) Regra por remetente exato.
        if ($fromEmail !== '') {
            $st = db()->prepare("SELECT label FROM email_classification_rules WHERE is_active = 1 AND rule_type = 'from_email' AND rule_value = :v LIMIT 1");
            $st->execute(['v' => $fromEmail]);
            $label = (string)($st->fetchColumn() ?: '');
            if ($label !== '') {
                return ['found' => true, 'is_captation' => $label === 'captation', 'reason' => 'regra aprendida (remetente ' . $fromEmail . ')'];
            }
        }
        // 2) Regra por domínio.
        if ($domain !== '') {
            $st = db()->prepare("SELECT label FROM email_classification_rules WHERE is_active = 1 AND rule_type = 'from_domain' AND rule_value = :v LIMIT 1");
            $st->execute(['v' => $domain]);
            $label = (string)($st->fetchColumn() ?: '');
            if ($label !== '') {
                return ['found' => true, 'is_captation' => $label === 'captation', 'reason' => 'regra aprendida (domínio ' . $domain . ')'];
            }
        }
        // 3) Regra por palavra no assunto (não-captação tem prioridade para cortar ruído).
        if ($subjectLower !== '') {
            $st = db()->query("SELECT rule_value, label FROM email_classification_rules WHERE is_active = 1 AND rule_type = 'subject_keyword' ORDER BY label ASC");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $kw = mb_strtolower(trim((string)$row['rule_value']));
                if ($kw !== '' && mb_strpos($subjectLower, $kw) !== false) {
                    return ['found' => true, 'is_captation' => (string)$row['label'] === 'captation', 'reason' => 'regra aprendida (assunto "' . $kw . '")'];
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[EMAIL_CLASS] lookup: ' . $e->getMessage());
    }

    return ['found' => false, 'is_captation' => false, 'reason' => ''];
}

/**
 * Registra o FEEDBACK da equipe (é/não é captação) e consolida a regra aprendida
 * por remetente e por domínio. É a base do aprendizado.
 *
 * @param string $label 'captation' | 'not_captation'
 */
function email_classification_record_feedback(
    string $label,
    string $fromEmail,
    string $subject = '',
    ?int $inboundEmailId = null,
    ?int $demandId = null,
    ?int $userId = null
): void {
    $label = $label === 'captation' ? 'captation' : 'not_captation';
    email_classification_ensure_schema();

    $fromEmail = mb_strtolower(trim($fromEmail));
    $domain = email_classification_domain($fromEmail);

    try {
        // Histórico.
        db()->prepare(
            "INSERT INTO email_classification_feedback
               (inbound_email_id, demand_id, from_email, from_domain, subject, label, created_by_user_id)
             VALUES (:ieid, :did, :fe, :fd, :subj, :label, :uid)"
        )->execute([
            'ieid' => $inboundEmailId,
            'did' => $demandId,
            'fe' => $fromEmail !== '' ? mb_substr($fromEmail, 0, 190) : null,
            'fd' => $domain !== '' ? mb_substr($domain, 0, 190) : null,
            'subj' => $subject !== '' ? mb_substr($subject, 0, 255) : null,
            'label' => $label,
            'uid' => $userId,
        ]);

        // Consolidar regra por REMETENTE (decisão mais específica; sempre vence).
        if ($fromEmail !== '') {
            email_classification_upsert_rule('from_email', $fromEmail, $label);
        }
        // Consolidar regra por DOMÍNIO, mas só quando NÃO conflita (evita que um
        // único e-mail "não captação" derrube um domínio que costuma ser captação).
        if ($domain !== '' && !email_classification_domain_is_ambiguous($domain)) {
            email_classification_upsert_rule('from_domain', $domain, $label);
        }
    } catch (Throwable $e) {
        error_log('[EMAIL_CLASS] record feedback: ' . $e->getMessage());
    }
}

/**
 * Insere/atualiza uma regra. Se já existir com o MESMO label, incrementa hits.
 * Se existir com label diferente, decide pelo histórico de feedback (maioria).
 */
function email_classification_upsert_rule(string $ruleType, string $ruleValue, string $label): void
{
    $ruleValue = mb_strtolower(trim($ruleValue));
    if ($ruleValue === '') {
        return;
    }
    try {
        $st = db()->prepare("SELECT id, label, hits FROM email_classification_rules WHERE rule_type = :t AND rule_value = :v LIMIT 1");
        $st->execute(['t' => $ruleType, 'v' => $ruleValue]);
        $existing = $st->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            db()->prepare(
                "INSERT INTO email_classification_rules (rule_type, rule_value, label, hits, is_active)
                 VALUES (:t, :v, :label, 1, 1)"
            )->execute(['t' => $ruleType, 'v' => $ruleValue, 'label' => $label]);
            return;
        }

        if ((string)$existing['label'] === $label) {
            // Reforça a mesma decisão.
            db()->prepare("UPDATE email_classification_rules SET hits = hits + 1, is_active = 1 WHERE id = :id")
                ->execute(['id' => (int)$existing['id']]);
            return;
        }

        // Conflito: recalcular pela MAIORIA do histórico de feedback.
        $field = $ruleType === 'from_email' ? 'from_email' : 'from_domain';
        $cnt = db()->prepare(
            "SELECT label, COUNT(*) AS n FROM email_classification_feedback
             WHERE $field = :v GROUP BY label ORDER BY n DESC"
        );
        $cnt->execute(['v' => $ruleValue]);
        $rows = $cnt->fetchAll(PDO::FETCH_ASSOC);
        $winner = $label;
        if (!empty($rows)) {
            $winner = (string)$rows[0]['label'];
        }
        db()->prepare("UPDATE email_classification_rules SET label = :label, hits = hits + 1, is_active = 1 WHERE id = :id")
            ->execute(['label' => $winner, 'id' => (int)$existing['id']]);
    } catch (Throwable $e) {
        error_log('[EMAIL_CLASS] upsert rule: ' . $e->getMessage());
    }
}

/**
 * Um domínio é "ambíguo" quando o histórico tem marcações dos DOIS tipos
 * (às vezes é captação, às vezes não) — nesse caso não criamos regra de domínio,
 * deixando a decisão para o remetente específico + IA.
 */
function email_classification_domain_is_ambiguous(string $domain): bool
{
    try {
        $st = db()->prepare(
            "SELECT COUNT(DISTINCT label) AS k FROM email_classification_feedback WHERE from_domain = :d"
        );
        $st->execute(['d' => $domain]);
        return (int)($st->fetchColumn() ?: 0) > 1;
    } catch (Throwable $e) {
        return false;
    }
}

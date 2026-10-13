<?php
/**
 * ============================================================================
 * IMPORTAÇÃO DO MONITORAMENTO — Planilha "MONITORAMENTO (MAJU)", aba SETEMBRO26
 * ============================================================================
 *
 * O QUE ESTE SCRIPT FAZ
 * ---------------------
 * Lê a planilha de monitoramento (docs/MONITORAMENTO (MAJU).xlsx, aba SETEMBRO26)
 * e insere cada atendimento na parte de Monitoramento do sistema, criando o que
 * faltar (opção "criar tudo"):
 *   - operadora         (health_insurers)
 *   - profissional      (users + papel 'profissional')
 *   - paciente          (patients)
 *   - demanda           (demands, status 'admitido')
 *   - atendimento       (patient_assignments, status 'admitted' + admitted_at)
 *   - sessão            (billing_document_requirements)
 *
 * NÃO DUPLICA: todas as gravações usam checagem de existência antes de inserir.
 * O script é IDEMPOTENTE — rodar duas vezes NÃO cria registros repetidos; a
 * segunda execução apenas ATUALIZA os campos variáveis (hora, confirmação,
 * observação) do que já existe.
 *
 * CHAVE ANTI-DUPLICAÇÃO (o que é "o mesmo atendimento"):
 *   paciente + profissional + especialidade + data de início.
 *
 * COMO RODAR NO SERVIDOR (Plesk)
 * ------------------------------
 *   a) Pela linha de comando:   php importar_monitoramento_setembro26.php
 *   b) Ou acesse pelo navegador: https://SEU_DOMINIO/importar_monitoramento_setembro26.php?run=1
 *      (exige o parâmetro ?run=1 para evitar execução acidental)
 *
 * IMPORTANTE: a planilha precisa estar em docs/MONITORAMENTO (MAJU).xlsx no
 * servidor (já está no repositório, então sobe junto no push).
 *
 * Depois de rodar e conferir o monitoramento, remova este arquivo do servidor.
 * ============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$isCli = (PHP_SAPI === 'cli');

// Trava de segurança para execução via navegador.
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    if (($_GET['run'] ?? '') !== '1') {
        echo "Para executar a importação, acesse esta URL com ?run=1\n";
        echo "Ex.: importar_monitoramento_setembro26.php?run=1\n";
        exit;
    }
}

/* ---------------------------------------------------------------------------
 * 1) LOCALIZAR E LER A PLANILHA (.xlsx) — aba SETEMBRO26
 * ------------------------------------------------------------------------- */

$xlsxPath = __DIR__ . '/docs/MONITORAMENTO (MAJU).xlsx';
if (!is_file($xlsxPath)) {
    fwrite(STDERR, "Planilha não encontrada em: $xlsxPath\n");
    echo "ERRO: planilha não encontrada em docs/MONITORAMENTO (MAJU).xlsx\n";
    exit(1);
}

/**
 * Lê uma aba do .xlsx (sem dependências externas) e devolve as linhas como
 * arrays indexados por coluna (0 = A, 1 = B, ...). Resolve sharedStrings e
 * converte datas seriais do Excel para Y-m-d.
 */
function readXlsxSheet(string $xlsxPath, string $sheetName): array
{
    $zip = new ZipArchive();
    if ($zip->open($xlsxPath) !== true) {
        throw new RuntimeException('Não foi possível abrir o .xlsx.');
    }

    // workbook: achar r:id da aba pelo nome
    $workbook = $zip->getFromName('xl/workbook.xml');
    $rels     = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($workbook === false || $rels === false) {
        throw new RuntimeException('Estrutura do .xlsx inválida.');
    }

    $wbXml = new SimpleXMLElement($workbook);
    $wbXml->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $wbXml->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

    $rid = null;
    foreach ($wbXml->sheets->sheet as $sheet) {
        $attrsM = $sheet->attributes();
        $attrsR = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        if ((string)$attrsM['name'] === $sheetName) {
            $rid = (string)$attrsR['id'];
            break;
        }
    }
    if ($rid === null) {
        throw new RuntimeException("Aba '$sheetName' não encontrada no .xlsx.");
    }

    // rels: r:id -> target
    $relsXml = new SimpleXMLElement($rels);
    $target = null;
    foreach ($relsXml->Relationship as $rel) {
        if ((string)$rel['Id'] === $rid) {
            $target = (string)$rel['Target'];
            break;
        }
    }
    if ($target === null) {
        throw new RuntimeException('Relationship da aba não encontrada.');
    }
    $sheetPath = 'xl/' . ltrim($target, '/');

    // sharedStrings
    $shared = [];
    $ssRaw = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssRaw !== false) {
        $ssXml = new SimpleXMLElement($ssRaw);
        foreach ($ssXml->si as $si) {
            if (isset($si->t)) {
                $shared[] = (string)$si->t;
            } elseif (isset($si->r)) {
                $txt = '';
                foreach ($si->r as $r) {
                    $txt .= (string)$r->t;
                }
                $shared[] = $txt;
            } else {
                $shared[] = '';
            }
        }
    }

    $sheetRaw = $zip->getFromName($sheetPath);
    if ($sheetRaw === false) {
        throw new RuntimeException('Não foi possível ler a aba.');
    }
    $zip->close();

    $colIndex = static function (string $ref): int {
        $letters = preg_replace('/[0-9]/', '', $ref);
        $n = 0;
        $len = strlen($letters);
        for ($i = 0; $i < $len; $i++) {
            $n = $n * 26 + (ord($letters[$i]) - 64);
        }
        return $n - 1;
    };

    $excelDate = static function ($v): string {
        if (!is_numeric($v)) {
            return (string)$v;
        }
        $f = (float)$v;
        // intervalo plausível de datas (2009..2064) em serial Excel
        if ($f < 40000 || $f > 60000) {
            return (string)$v;
        }
        $ts = ($f - 25569) * 86400;
        return gmdate('Y-m-d', (int)round($ts));
    };

    $xml = new SimpleXMLElement($sheetRaw);
    $rows = [];
    foreach ($xml->sheetData->row as $row) {
        $cells = [];
        $max = 0;
        foreach ($row->c as $c) {
            $ref = (string)$c['r'];
            $ci = $colIndex($ref);
            if ($ci > $max) {
                $max = $ci;
            }
            $t = (string)$c['t'];
            $v = isset($c->v) ? (string)$c->v : '';
            if ($t === 's') {
                $v = $shared[(int)$v] ?? '';
            } elseif ($t === '' && $v !== '') {
                $v = $excelDate($v);
            }
            $cells[$ci] = trim($v);
        }
        for ($i = 0; $i <= $max; $i++) {
            if (!array_key_exists($i, $cells)) {
                $cells[$i] = '';
            }
        }
        ksort($cells);
        $rows[] = $cells;
    }

    return $rows;
}

/* ---------------------------------------------------------------------------
 * 2) HELPERS DE NORMALIZAÇÃO
 * ------------------------------------------------------------------------- */

/** Remove espaços duplicados, NBSP e apara. */
function cleanText(string $s): string
{
    $s = str_replace(["\xC2\xA0", "\u{00A0}"], ' ', $s); // NBSP
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim($s);
}

/** Mapeia a especialidade da planilha para o nome canônico do sistema. */
function mapSpecialty(string $raw): string
{
    $k = strtoupper(cleanText($raw));
    $map = [
        'FISIO'            => 'Fisioterapia',
        'FONO'             => 'Fonoaudiologia',
        'NUTRI'            => 'Nutrição',
        'PSICO'            => 'Psicologia',
        'T.O'              => 'Terapia Ocupacional',
        'T.O.'             => 'Terapia Ocupacional',
        'TO'               => 'Terapia Ocupacional',
        'MÉDICO'           => 'Médico',
        'MEDICO'           => 'Médico',
        'ENFERMEIRO'       => 'Enfermagem',
        'ENFERMAGEM'       => 'Enfermagem',
        'TEC ENFERMAGEM'   => 'Enfermagem',
        'TÉC ENFERMAGEM'   => 'Enfermagem',
        'TEC. ENFERMAGEM'  => 'Enfermagem',
    ];
    return $map[$k] ?? ($k !== '' ? ucfirst(mb_strtolower($raw)) : 'Não informado');
}

/** Gera um e-mail determinístico para o profissional (igual padrão da importação anterior). */
function profEmail(string $name): string
{
    $name = cleanText($name);
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT', $name) ?: $name;
    $ascii = strtolower(preg_replace('/[^a-zA-Z ]/', '', $ascii));
    $parts = array_values(array_filter(explode(' ', $ascii)));
    if (!$parts) {
        $parts = ['prof'];
    }
    $first = $parts[0];
    $last  = count($parts) > 1 ? end($parts) : '';
    $local = $last !== '' ? "$first.$last" : $first;
    // sufixo curto estável para evitar colisão entre homônimos distintos
    $hash = substr(md5($name), 0, 6);
    return "{$local}.{$hash}@importacao.multilife.local";
}

/** Converte "DATA INÍCIO" + "HORA" em DATETIME; HORA pode ser serial, "16h", "10:30h", "tarde"... */
function buildStartDatetime(string $date, string $hora): string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return ''; // sem data válida
    }
    $time = '08:00:00';
    $h = cleanText($hora);

    if ($h !== '') {
        if (is_numeric($h) && (float)$h > 0 && (float)$h < 1) {
            // fração do dia (serial de hora do Excel)
            $secs = (int)round((float)$h * 86400);
            $time = gmdate('H:i:s', $secs);
        } elseif (preg_match('/(\d{1,2})[:h](\d{2})/', $h, $m)) {
            $time = sprintf('%02d:%02d:00', (int)$m[1], (int)$m[2]);
        } elseif (preg_match('/(\d{1,2})\s*h/i', $h, $m)) {
            $time = sprintf('%02d:00:00', (int)$m[1]);
        } elseif (stripos($h, 'manh') !== false) {
            $time = '09:00:00';
        } elseif (stripos($h, 'tarde') !== false) {
            $time = '14:00:00';
        }
    }
    return "$date $time";
}

/* ---------------------------------------------------------------------------
 * 3) IMPORTAÇÃO
 * ------------------------------------------------------------------------- */

$db = db();

// Garantir colunas usadas pelo monitoramento/importação (idempotente).
// Em produção já existem; aqui servem de rede de segurança.
foreach ([
    "ALTER TABLE patient_assignments ADD COLUMN is_indefinite TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE patient_assignments ADD COLUMN admitted_at DATETIME NULL",
    "ALTER TABLE patient_assignments ADD COLUMN agreed_value DECIMAL(10,2) NULL",
    "ALTER TABLE patient_assignments ADD COLUMN authorized_value DECIMAL(10,2) NULL",
    "ALTER TABLE patient_assignments ADD COLUMN health_insurer_id INT NULL",
    "ALTER TABLE patients ADD COLUMN health_insurer_id INT NULL",
    "ALTER TABLE billing_document_requirements ADD COLUMN created_by_user_id INT UNSIGNED NULL",
    "ALTER TABLE billing_document_requirements ADD COLUMN is_manual TINYINT(1) NOT NULL DEFAULT 0",
] as $ddl) {
    try { $db->exec($ddl); } catch (Throwable $e) { /* já existe */ }
}

// Garantir o papel 'profissional'.
$db->prepare("INSERT INTO roles (name, slug)
    SELECT 'Profissional', 'profissional' FROM DUAL
    WHERE NOT EXISTS (SELECT 1 FROM roles WHERE slug = 'profissional')")->execute();

// Usuário que consta como "criador" dos registros (primeiro admin disponível).
$importUserId = (int)($db->query("
    SELECT u.id FROM users u
    INNER JOIN user_roles ur ON ur.user_id = u.id
    INNER JOIN roles r ON r.id = ur.role_id
    WHERE r.slug = 'admin' ORDER BY u.id ASC LIMIT 1
")->fetchColumn() ?: 0);
if ($importUserId === 0) {
    // fallback: qualquer usuário
    $importUserId = (int)($db->query("SELECT id FROM users ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 0);
}

$rows = readXlsxSheet($xlsxPath, 'SETEMBRO26');

// Índices das colunas na aba SETEMBRO26:
// 0 PACIENTE | 1 CLIENTE | 2 PROFISSIONAIS | 3 TELEFONE | 4 ESPECIALIDADE
// 5 DATA IMPLANTAÇÃO | 6 DATA INÍCIO | 7 HORA | 8 CONFIRMADO | 9 OBSERVAÇÃO
const C_PACIENTE = 0, C_CLIENTE = 1, C_PROF = 2, C_TEL = 3, C_ESP = 4,
      C_IMPLANT = 5, C_INICIO = 6, C_HORA = 7, C_CONF = 8, C_OBS = 9;

// Prepared statements reaproveitados ------------------------------------------------

$insInsurer = $db->prepare("INSERT INTO health_insurers (name, notes, is_active)
    SELECT :name, 'Importado do monitoramento (MAJU)', 1 FROM DUAL
    WHERE NOT EXISTS (SELECT 1 FROM health_insurers WHERE name = :name2)");

$selInsurer = $db->prepare("SELECT id FROM health_insurers WHERE name = :name LIMIT 1");

$insUser = $db->prepare("INSERT INTO users (name, email, phone, password_hash, status)
    SELECT :name, :email, :phone,
           '\$2y\$12\$FAyenM0P71RDaxUU2KDqn.BjY5N8/LqJCkJsjBC.c8BFPYOzz8lO6', 'active'
    FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = :email2)");
$insUserRole = $db->prepare("INSERT INTO user_roles (user_id, role_id)
    SELECT u.id, r.id FROM users u, roles r
    WHERE u.email = :email AND r.slug = 'profissional'
      AND NOT EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id AND ur.role_id = r.id)");
$selUser = $db->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");

$insPatient = $db->prepare("INSERT INTO patients (full_name, whatsapp, admin_status, health_insurer_id)
    SELECT :name, :wa, 'ativo', :hid FROM DUAL
    WHERE NOT EXISTS (SELECT 1 FROM patients WHERE full_name = :name2 AND deleted_at IS NULL)");
$selPatient = $db->prepare("SELECT id FROM patients WHERE full_name = :name AND deleted_at IS NULL ORDER BY id ASC LIMIT 1");

$insDemand = $db->prepare("INSERT INTO demands (title, specialty, status, created_at)
    SELECT :title, :spec, 'admitido', :created FROM DUAL
    WHERE NOT EXISTS (SELECT 1 FROM demands WHERE title = :title2)");
$selDemand = $db->prepare("SELECT id FROM demands WHERE title = :title ORDER BY id DESC LIMIT 1");

// atendimento (assignment) — chave: paciente+profissional+especialidade+data início
$selAssignment = $db->prepare("SELECT id FROM patient_assignments
    WHERE patient_id = :pid AND professional_user_id = :prof
      AND specialty = :spec AND DATE(admitted_at) = :inicio
    ORDER BY id DESC LIMIT 1");
$insAssignment = $db->prepare("INSERT INTO patient_assignments
    (demand_id, patient_id, professional_remote_jid, professional_user_id, assigned_by_user_id,
     specialty, service_type, session_quantity, session_frequency,
     payment_value, agreed_value, authorized_value, health_insurer_id,
     notes, status, confirmed_at, approved_at, admitted_at, created_at)
    VALUES
    (:demand, :pid, :jid, :prof, :assigned,
     :spec, 'Atendimento Domiciliar', 1, NULL,
     0.00, 0.00, 0.00, :hid,
     :notes, 'admitted', :cat, :aat, :adt, :created)");
$updAssignment = $db->prepare("UPDATE patient_assignments
    SET notes = :notes, health_insurer_id = :hid, admitted_at = :adt
    WHERE id = :id");

// sessão (billing_document_requirements) — chave: assignment + session_date
$selSession = $db->prepare("SELECT id FROM billing_document_requirements
    WHERE assignment_id = :aid AND session_date = :sdate LIMIT 1");
$maxSession = $db->prepare("SELECT COALESCE(MAX(session_number),0)+1 FROM billing_document_requirements WHERE assignment_id = :aid");
$insSession = $db->prepare("INSERT INTO billing_document_requirements
    (assignment_id, patient_id, professional_user_id, session_number, session_date, status, is_manual, created_by_user_id, created_at)
    VALUES (:aid, :pid, :prof, :snum, :sdate, 'approved', 1, :uid, :created)");

// Contadores para o relatório
$stat = [
    'linhas'        => 0,
    'operadoras'    => 0,
    'profissionais' => 0,
    'pacientes'     => 0,
    'demandas'      => 0,
    'atend_novos'   => 0,
    'atend_update'  => 0,
    'sessoes_novas' => 0,
    'ignoradas'     => 0,
];
$avisos = [];

$db->beginTransaction();
try {
    foreach ($rows as $idx => $r) {
        $linhaPlanilha = $idx + 1;
        // pular cabeçalho (linha 1) e linhas vazias
        if ($linhaPlanilha === 1) {
            continue;
        }
        $paciente = cleanText($r[C_PACIENTE] ?? '');
        $prof     = cleanText($r[C_PROF] ?? '');
        if ($paciente === '' || $prof === '') {
            continue; // linha vazia
        }
        $stat['linhas']++;

        $operadora = cleanText($r[C_CLIENTE] ?? '');
        $telefone  = cleanText($r[C_TEL] ?? '');
        $especRaw  = cleanText($r[C_ESP] ?? '');
        $espec     = mapSpecialty($especRaw);
        $dataImpl  = cleanText($r[C_IMPLANT] ?? '');
        $dataInic  = cleanText($r[C_INICIO] ?? '');
        $hora      = cleanText($r[C_HORA] ?? '');
        $confirm   = cleanText($r[C_CONF] ?? '');
        $obs       = cleanText($r[C_OBS] ?? '');

        // Data de início: usa DATA INÍCIO; se vazia, cai para DATA IMPLANTAÇÃO.
        $dataBase = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataInic) ? $dataInic
                  : (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataImpl) ? $dataImpl : '');
        if ($dataBase === '') {
            $stat['ignoradas']++;
            $avisos[] = "Linha $linhaPlanilha ($paciente / $prof): sem data válida — ignorada.";
            continue;
        }
        $startDatetime = buildStartDatetime($dataBase, $hora);
        if ($startDatetime === '') {
            $startDatetime = "$dataBase 08:00:00";
        }

        // --- Operadora ---
        $insurerId = null;
        if ($operadora !== '') {
            $insInsurer->execute(['name' => $operadora, 'name2' => $operadora]);
            if ($insInsurer->rowCount() > 0) { $stat['operadoras']++; }
            $selInsurer->execute(['name' => $operadora]);
            $insurerId = (int)($selInsurer->fetchColumn() ?: 0) ?: null;
        }

        // --- Profissional ---
        $email = profEmail($prof);
        $insUser->execute(['name' => $prof, 'email' => $email, 'phone' => $telefone, 'email2' => $email]);
        if ($insUser->rowCount() > 0) { $stat['profissionais']++; }
        $insUserRole->execute(['email' => $email]);
        $selUser->execute(['email' => $email]);
        $profId = (int)($selUser->fetchColumn() ?: 0);
        if ($profId === 0) {
            $stat['ignoradas']++;
            $avisos[] = "Linha $linhaPlanilha: não foi possível resolver o profissional '$prof'.";
            continue;
        }

        // --- Paciente ---
        $insPatient->execute(['name' => $paciente, 'wa' => $telefone, 'hid' => $insurerId, 'name2' => $paciente]);
        if ($insPatient->rowCount() > 0) { $stat['pacientes']++; }
        $selPatient->execute(['name' => $paciente]);
        $patientId = (int)($selPatient->fetchColumn() ?: 0);
        if ($patientId === 0) {
            $stat['ignoradas']++;
            $avisos[] = "Linha $linhaPlanilha: não foi possível resolver o paciente '$paciente'.";
            continue;
        }

        // --- Demanda ---
        $demandTitle = "$espec - $paciente" . ($operadora !== '' ? " ($operadora)" : '');
        $insDemand->execute(['title' => $demandTitle, 'spec' => $espec, 'created' => $startDatetime, 'title2' => $demandTitle]);
        if ($insDemand->rowCount() > 0) { $stat['demandas']++; }
        $selDemand->execute(['title' => $demandTitle]);
        $demandId = (int)($selDemand->fetchColumn() ?: 0);

        // --- Atendimento (assignment) ---
        $notes = trim(($confirm !== '' ? "Confirmado: $confirm. " : '') . ($obs !== '' ? "Obs: $obs" : ''));
        $selAssignment->execute([
            'pid' => $patientId, 'prof' => $profId, 'spec' => $espec, 'inicio' => $dataBase,
        ]);
        $assignmentId = (int)($selAssignment->fetchColumn() ?: 0);

        if ($assignmentId === 0) {
            $jid = preg_replace('/\D+/', '', $telefone);
            $jid = $jid !== '' ? "55{$jid}@s.whatsapp.net" : "importacao@s.whatsapp.net";
            $insAssignment->execute([
                'demand' => $demandId, 'pid' => $patientId, 'jid' => $jid, 'prof' => $profId,
                'assigned' => $importUserId, 'spec' => $espec, 'hid' => $insurerId,
                'notes' => $notes, 'cat' => $startDatetime, 'aat' => $startDatetime,
                'adt' => $startDatetime, 'created' => $startDatetime,
            ]);
            $assignmentId = (int)$db->lastInsertId();
            $stat['atend_novos']++;
        } else {
            // Já existe: atualiza campos variáveis (sem duplicar).
            $updAssignment->execute(['notes' => $notes, 'hid' => $insurerId, 'adt' => $startDatetime, 'id' => $assignmentId]);
            $stat['atend_update']++;
        }

        // --- Sessão (billing_document_requirements) ---
        $selSession->execute(['aid' => $assignmentId, 'sdate' => $dataBase]);
        $sessionId = (int)($selSession->fetchColumn() ?: 0);
        if ($sessionId === 0) {
            $maxSession->execute(['aid' => $assignmentId]);
            $snum = (int)($maxSession->fetchColumn() ?: 1);
            $insSession->execute([
                'aid' => $assignmentId, 'pid' => $patientId, 'prof' => $profId,
                'snum' => $snum, 'sdate' => $dataBase, 'uid' => $importUserId, 'created' => $startDatetime,
            ]);
            $stat['sessoes_novas']++;
        }
    }

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    echo "ERRO durante a importação (nada foi gravado): " . $e->getMessage() . "\n";
    exit(1);
}

/* ---------------------------------------------------------------------------
 * 4) RELATÓRIO
 * ------------------------------------------------------------------------- */

echo "==========================================================\n";
echo " IMPORTAÇÃO DO MONITORAMENTO — aba SETEMBRO26 — CONCLUÍDA\n";
echo "==========================================================\n";
echo "Linhas de atendimento processadas : {$stat['linhas']}\n";
echo "Operadoras criadas                : {$stat['operadoras']}\n";
echo "Profissionais criados             : {$stat['profissionais']}\n";
echo "Pacientes criados                 : {$stat['pacientes']}\n";
echo "Demandas criadas                  : {$stat['demandas']}\n";
echo "Atendimentos NOVOS                : {$stat['atend_novos']}\n";
echo "Atendimentos ATUALIZADOS          : {$stat['atend_update']}\n";
echo "Sessões criadas (monitoramento)   : {$stat['sessoes_novas']}\n";
echo "Linhas ignoradas                  : {$stat['ignoradas']}\n";
echo "----------------------------------------------------------\n";
if ($avisos) {
    echo "AVISOS:\n";
    foreach ($avisos as $a) {
        echo "  - $a\n";
    }
} else {
    echo "Sem avisos.\n";
}
echo "==========================================================\n";
echo "Dica: rodar novamente NÃO duplica — apenas atualiza o que já existe.\n";

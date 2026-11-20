<?php

declare(strict_types=1);

/**
 * QUEM ENTRA NO GRUPO DE CAPTAÇÃO.
 *
 * Centraliza a seleção de profissionais para um card (especialidade + cidade
 * + zona), antes repetida em 3 lugares do disparo só com filtro de
 * especialidade (o que colocava profissionais do país inteiro em todo grupo).
 *
 * Fontes, nesta ordem:
 *  1. Profissionais cadastrados (users com perfil 'profissional'), usando a
 *     localização do cadastro e, se faltar, a do formulário de candidatura.
 *  2. Contatos importados da agenda (categoria 'profissional') ainda não
 *     pré-cadastrados, com especialidade deduzida do nome quando não informada.
 *     Pode ser desligado com o setting 'demands.dispatch_include_contacts' = '0'.
 */

/** A especialidade do profissional casa com a do card? (mesma regra flexível do disparo antigo + chave canônica) */
function dispatch_specialty_matches(string $profSpecialty, string $demandSpecialty): bool
{
    $p = location_normalize($profSpecialty);
    $d = location_normalize($demandSpecialty);
    if ($p === '' || $d === '') {
        return false;
    }
    if ($p === $d || strpos($p, $d) !== false || strpos($d, $p) !== false) {
        return true;
    }
    $firstWord = explode(' ', $d)[0] ?? '';
    if ($firstWord !== '' && strlen($firstWord) >= 4 && strpos($p, $firstWord) !== false) {
        return true;
    }
    $kp = specialty_key($profSpecialty);
    return $kp !== '' && $kp === specialty_key($demandSpecialty);
}

/**
 * Seleciona os telefones (55+DDD+número) dos profissionais do card.
 *
 * @return array{phones:string[], stats:array<string,int>}
 */
function dispatch_collect_professionals(string $specialty, string $city, string $state, ?string $zone, bool $testMode, string $scope = 'city'): array
{
    location_users_ensure_schema();
    $target = ['city' => $city, 'state' => $state, 'zone' => $zone];
    // scope 'state' (captação AMPLIADA, confirmada pelo captador): vale qualquer
    // profissional da mesma UF (cadastro, candidatura ou DDD), ignorando cidade/zona.
    $stateScope = $scope === 'state';
    $stateCompatible = static function (array $loc) use ($state): bool {
        $uf = strtoupper(trim((string)($loc['state'] ?? '')));
        if ($uf === '') {
            $uf = (string)(location_ddd_state($loc['phone'] ?? '') ?? '');
        }
        return $uf === '' || $uf === strtoupper($state);
    };
    $stats = ['users' => 0, 'contacts' => 0, 'skipped_location' => 0, 'unknown_location' => 0];
    $phones = [];
    $seenKeys = [];

    // ---------------------------------------------------------------- 1) users
    $sql = "SELECT DISTINCT u.id, u.phone, u.specialty, u.city, u.state, u.neighborhood, u.zone
            FROM users u
            INNER JOIN user_roles ur ON ur.user_id = u.id
            INNER JOIN roles r ON r.id = ur.role_id
            WHERE u.status = 'active' AND r.slug = 'profissional'
              AND u.phone IS NOT NULL AND u.phone <> ''";
    if ($testMode) {
        $sql .= ' AND u.is_test_professional = 1';
    }
    try {
        $users = db()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[DISPATCH_TARGETS] Falha ao ler profissionais: ' . $e->getMessage());
        $users = [];
    }

    $users = array_values(array_filter($users, static fn($u) => dispatch_specialty_matches((string)($u['specialty'] ?? ''), $specialty)));

    // Endereço da candidatura para quem não tem cidade no cadastro.
    $appAddr = [];
    $needIds = [];
    foreach ($users as $u) {
        if (trim((string)($u['city'] ?? '')) === '') {
            $needIds[] = (int)$u['id'];
        }
    }
    if ($needIds) {
        try {
            $ph = implode(',', array_fill(0, count($needIds), '?'));
            $st = db()->prepare("SELECT created_user_id, address_city, address_state, address_neighborhood
                                 FROM professional_applications
                                 WHERE created_user_id IN ($ph) ORDER BY id DESC");
            $st->execute($needIds);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $uid = (int)$a['created_user_id'];
                if (!isset($appAddr[$uid])) {
                    $appAddr[$uid] = $a;
                }
            }
        } catch (Throwable $e) { /* tabela/colunas ausentes: segue sem */ }
    }

    foreach ($users as $u) {
        $uid = (int)$u['id'];
        $loc = [
            'city' => (string)($u['city'] ?? ''),
            'state' => (string)($u['state'] ?? ''),
            'neighborhood' => (string)($u['neighborhood'] ?? ''),
            'zone' => $u['zone'] ?? null,
            'phone' => (string)$u['phone'],
        ];
        if ($loc['city'] === '' && isset($appAddr[$uid])) {
            $loc['city'] = (string)($appAddr[$uid]['address_city'] ?? '');
            $loc['state'] = $loc['state'] !== '' ? $loc['state'] : (string)($appAddr[$uid]['address_state'] ?? '');
            $loc['neighborhood'] = $loc['neighborhood'] !== '' ? $loc['neighborhood'] : (string)($appAddr[$uid]['address_neighborhood'] ?? '');
        }
        if ($stateScope) {
            $ok = $stateCompatible($loc);
            $level = 'state';
        } else {
            [$ok, $level] = location_professional_compatible($target, $loc);
        }
        if (!$ok) {
            $stats['skipped_location']++;
            continue;
        }
        if ($level === 'unknown') {
            $stats['unknown_location']++;
        }
        $norm = demand_dispatch_normalize_phone((string)$u['phone']);
        if ($norm === null) {
            continue;
        }
        $k = dispatch_member_key($norm);
        if (isset($seenKeys[$k])) {
            continue;
        }
        $seenKeys[$k] = true;
        $phones[] = $norm;
        $stats['users']++;
    }

    // ------------------------------------------------------------- 2) contatos
    $includeContacts = !$testMode && (string)admin_setting_get('demands.dispatch_include_contacts', '1') !== '0';
    if ($includeContacts) {
        $demandKey = specialty_key($specialty);
        try {
            $contacts = db()->query("SELECT id, name, phone, specialty, city, state, neighborhood, notes
                                     FROM imported_contacts
                                     WHERE category = 'profissional' AND phone IS NOT NULL AND phone <> ''
                                       AND user_id IS NULL
                                       AND (notes IS NULL OR notes <> 'nao_atende')")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $contacts = [];
        }
        foreach ($contacts as $c) {
            $cSpec = trim((string)($c['specialty'] ?? ''));
            $match = $cSpec !== ''
                ? dispatch_specialty_matches($cSpec, $specialty)
                : ($demandKey !== '' && specialty_key((string)$c['name']) === $demandKey);
            if (!$match) {
                continue;
            }
            [$ok, $level] = location_professional_compatible($target, [
                'city' => (string)($c['city'] ?? ''),
                'state' => (string)($c['state'] ?? ''),
                'neighborhood' => (string)($c['neighborhood'] ?? ''),
                'phone' => (string)$c['phone'],
            ]);
            // Contato da agenda só entra com CIDADE informada e compatível. Só pelo DDD
            // (estado inteiro) colocava centenas de contatos em cada grupo novo, o que
            // deixava a criação do grupo lenta/instável no WhatsApp.
            if ($stateScope) {
                // Ampliado: contato com UF informada igual à do card (DDD não basta).
                $ok = strtoupper(trim((string)($c['state'] ?? ''))) === strtoupper($state)
                    || ($ok && in_array($level, ['city', 'zone'], true));
                $level = 'state';
            }
            if (!$ok || !in_array($level, ['city', 'zone', 'state'], true) || (!$stateScope && $level === 'state')) {
                $stats['skipped_location']++;
                continue;
            }
            $norm = demand_dispatch_normalize_phone((string)$c['phone']);
            if ($norm === null) {
                continue;
            }
            $k = dispatch_member_key($norm);
            if (isset($seenKeys[$k])) {
                continue;
            }
            $seenKeys[$k] = true;
            $phones[] = $norm;
            $stats['contacts']++;
        }
    }

    return ['phones' => $phones, 'stats' => $stats];
}

/**
 * Telefones da EQUIPE que devem estar em todo grupo de captação: números das
 * instâncias conectadas, o usuário que dispara e quem gerencia captação.
 * No modo de teste não inclui ninguém da equipe.
 *
 * @return string[]
 */
function dispatch_team_phones(bool $testMode, ?int $currentUserId): array
{
    if ($testMode) {
        return [];
    }
    $out = [];
    foreach (whatsapp_get_all_connected_numbers() as $n) {
        $norm = demand_dispatch_normalize_phone((string)$n);
        if ($norm !== null) {
            $out[$norm] = true;
        }
    }
    try {
        if ($currentUserId) {
            $st = db()->prepare('SELECT phone FROM users WHERE id = ?');
            $st->execute([$currentUserId]);
            $norm = demand_dispatch_normalize_phone((string)($st->fetchColumn() ?: ''));
            if ($norm !== null) {
                $out[$norm] = true;
            }
        }
        $st = db()->query("SELECT DISTINCT u.phone FROM users u
            INNER JOIN user_roles ur ON ur.user_id = u.id
            INNER JOIN role_permissions rp ON rp.role_id = ur.role_id
            INNER JOIN permissions p ON p.id = rp.permission_id
            WHERE u.status = 'active' AND p.slug = 'demands.manage' AND u.phone IS NOT NULL AND u.phone <> ''");
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $ph) {
            $norm = demand_dispatch_normalize_phone((string)$ph);
            if ($norm !== null) {
                $out[$norm] = true;
            }
        }
    } catch (Throwable $e) {
        error_log('[DISPATCH_TARGETS] Falha ao ler telefones da equipe: ' . $e->getMessage());
    }
    return array_keys($out);
}

/**
 * Nome padrão do grupo: "Especialidade - Cidade/UF[ - Zona]".
 */
function dispatch_group_location_label(string $city, string $state, ?string $zone): string
{
    $loc = trim($city !== '' ? ($city . ($state !== '' ? '/' . $state : '')) : $state);
    if ($loc === '') {
        $loc = 'Geral';
    }
    if ($zone !== null && $zone !== '') {
        $loc .= ' - ' . $zone;
    }
    return $loc;
}

/**
 * Procura um grupo ATIVO compatível com especialidade + cidade + UF (+ zona).
 * Não reaproveita grupo de outra cidade (antes a busca ignorava a localização
 * e todo card caía no primeiro grupo da especialidade, ex.: "São Paulo").
 *
 * @return array{id:int,name:string,evolution_group_jid:string}|null
 */
function dispatch_find_group(string $specialty, string $city, string $state, ?string $zone, string $captadorInstance): ?array
{
    location_users_ensure_schema();
    try {
        $st = db()->prepare("SELECT id, name, evolution_group_jid, specialty, city, state, zone, instance_name
            FROM whatsapp_groups
            WHERE status = 'active' AND evolution_group_jid LIKE '%@g.us'
              AND UPPER(COALESCE(state, '')) = :st
            ORDER BY id DESC");
        $st->execute(['st' => strtoupper($state)]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[DISPATCH_TARGETS] Falha ao buscar grupos: ' . $e->getMessage());
        return null;
    }

    $cityN = location_normalize($city);
    $specN = location_normalize($specialty);
    $hasZones = location_city_has_zones($city, $state);
    $best = null;
    $bestScore = -1;
    foreach ($rows as $g) {
        if (location_normalize((string)$g['city']) !== $cityN) {
            continue;
        }
        if ($hasZones) {
            $gZone = location_normalize_zone((string)($g['zone'] ?? ''), $city, $state);
            if ($gZone === null) {
                // Grupo antigo sem coluna zone: tenta ler a zona do nome do grupo.
                foreach (location_zone_options($city, $state) as $opt) {
                    if (stripos((string)$g['name'], $opt) !== false) {
                        $gZone = $opt;
                        break;
                    }
                }
            }
            if ($gZone !== $zone) {
                continue;
            }
        }
        $gSpec = location_normalize((string)$g['specialty']);
        if ($gSpec === $specN) {
            $score = 20;
        } elseif (specialty_key((string)$g['specialty']) !== '' && specialty_key((string)$g['specialty']) === specialty_key($specialty)) {
            $score = 10;
        } else {
            continue;
        }
        // Desempate: preferir grupo da instância do captador.
        if ($captadorInstance !== '' && (string)($g['instance_name'] ?? '') === $captadorInstance) {
            $score += 1;
        }
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $g;
        }
    }
    if ($best === null) {
        return null;
    }
    return ['id' => (int)$best['id'], 'name' => (string)$best['name'], 'evolution_group_jid' => (string)$best['evolution_group_jid']];
}

/**
 * Especialidade, cidade, UF e zona usadas na captação (sub-solicitação tem prioridade).
 *
 * @return array{specialty:string, city:string, state:string, zone:?string}
 */
function dispatch_resolve_location(array $d, ?array $subRequest): array
{
    $pick = static function (string $col) use ($subRequest, $d): string {
        $sv = $subRequest ? trim((string)($subRequest[$col] ?? '')) : '';
        return $sv !== '' ? $sv : trim((string)($d[$col] ?? ''));
    };
    $city = $pick('location_city');
    $state = strtoupper($pick('location_state'));
    $specialty = $subRequest ? trim((string)($subRequest['specialty'] ?? '')) : trim((string)($d['specialty'] ?? ''));
    $zone = null;
    if (location_city_has_zones($city, $state)) {
        $zone = location_normalize_zone((string)($d['location_zone'] ?? ''), $city, $state)
            ?? location_zone_for($city, $state, $pick('location_neighborhood'));
    }
    return ['specialty' => $specialty, 'city' => $city, 'state' => $state, 'zone' => $zone];
}

/**
 * Checagem antes de captar: há profissional da especialidade para o local do card?
 * Se não houver, a captação NÃO é enviada — o captador recebe o aviso e pode
 * confirmar a ampliação para o estado (se houver alguém no estado).
 *
 * @return array{ok:bool, city_count:int, state_count:int, message:string}
 */
function dispatch_precheck_professionals(array $loc, bool $testMode): array
{
    $cityCount = count(dispatch_collect_professionals($loc['specialty'], $loc['city'], $loc['state'], $loc['zone'], $testMode, 'city')['phones']);
    if ($cityCount > 0) {
        return ['ok' => true, 'city_count' => $cityCount, 'state_count' => 0, 'message' => ''];
    }
    $stateCount = count(dispatch_collect_professionals($loc['specialty'], $loc['city'], $loc['state'], $loc['zone'], $testMode, 'state')['phones']);
    $where = dispatch_group_location_label($loc['city'], $loc['state'], $loc['zone']);
    $who = $testMode ? 'profissional de TESTE' : 'profissional';
    $msg = 'Nenhum ' . $who . ' de ' . $loc['specialty'] . ' cadastrado para ' . $where . '. A captação NÃO foi enviada.';
    if ($stateCount > 0) {
        $msg .= ' Existem ' . $stateCount . ' no estado ' . $loc['state'] . '.';
    } else {
        $msg .= ' Também não há nenhum no estado ' . $loc['state'] . ' — cadastre profissionais (com cidade/UF) antes de captar.';
    }
    return ['ok' => false, 'city_count' => 0, 'state_count' => $stateCount, 'message' => $msg];
}

/**
 * Especialidades citadas num texto (chaves: tecenf, enf, fisio, fono, psico,
 * psicoped, nutri, to). "Técnico de Enfermagem" não conta também como "enf".
 *
 * @return string[]
 */
function dispatch_specialties_in_text(string $text): array
{
    $found = [];
    $rest = $text;
    $tec = '/(?<!\p{L})t[ée]cnic[oa]s?\s+(?:de\s+|em\s+)?enfermagem(?!\p{L})/iu';
    if (preg_match($tec, $rest)) {
        $found[] = 'tecenf';
        $rest = (string)preg_replace($tec, ' ', $rest);
    }
    $patterns = [
        'enf' => '/(?<!\p{L})(?:enfermag|enfermeir)\p{L}*/iu',
        'fisio' => '/(?<!\p{L})fisio\p{L}*/iu',
        'fono' => '/(?<!\p{L})fono\p{L}*/iu',
        'psicoped' => '/(?<!\p{L})psicopedag\p{L}*/iu',
        'psico' => '/(?<!\p{L})psic[oó]log\p{L}*/iu',
        'nutri' => '/(?<!\p{L})(?:nutri[cç][aã]o|nutricionista\p{L}*)(?!\p{L})/iu',
        'to' => '/(?<!\p{L})(?:terapia|terapeuta)\s+ocupacional(?!\p{L})/iu',
    ];
    foreach ($patterns as $key => $re) {
        if (preg_match($re, $rest)) {
            $found[] = $key;
        }
    }
    return $found;
}

/**
 * Quadro clínico da mensagem de captação só com a especialidade captada.
 *
 * A captação é feita por especialidade (card multidisciplinar = uma captação para
 * cada uma), então trechos sobre OUTRA especialidade saem do texto. Ex., captando
 * Fisioterapia: "Serviços: Fisioterapia 3x por semana, Enfermagem 1x por semana."
 * vira "Serviços: Fisioterapia 3x por semana.". Trechos sem especialidade ficam.
 * Se a especialidade captada não for reconhecida, o texto volta igual.
 */
function dispatch_summary_only_specialty(string $text, string $specialty): string
{
    $current = dispatch_specialties_in_text($specialty);
    if ($text === '' || !$current) {
        return $text;
    }
    // Item fica se não cita especialidade ou se cita a captada.
    $keep = static function (string $piece) use ($current): bool {
        $m = dispatch_specialties_in_text($piece);
        return !$m || (bool)array_intersect($m, $current);
    };
    $specStart = '(?:t[ée]cnic|enferm|fisio|fono|psic|nutri|terapia\s+ocup|terapeuta\s+ocup)';

    $outLines = [];
    foreach (preg_split('/\R/u', $text) as $line) {
        $outSentences = [];
        foreach (preg_split('/(?<=[.!?;])\s+/u', trim($line)) as $sentence) {
            if ($sentence === '') {
                continue;
            }
            if (!array_diff(dispatch_specialties_in_text($sentence), $current)) {
                $outSentences[] = $sentence; // não cita outra especialidade
                continue;
            }
            $end = preg_match('/[.!?;]$/u', $sentence) ? mb_substr($sentence, -1) : '';
            $body = $end !== '' ? rtrim(mb_substr($sentence, 0, -1)) : $sentence;
            // Rótulo no começo ("Serviços:", "Solicitado:") fica se sobrar algum item.
            $prefix = '';
            if (preg_match('/^([^:]{1,40}:)\s*(.*)$/us', $body, $pm) && !dispatch_specialties_in_text($pm[1])) {
                $prefix = $pm[1] . ' ';
                $body = $pm[2];
            }
            $items = [];
            foreach (preg_split('/\s*,\s*/u', $body) as $chunk) {
                // "Fonoaudiologia 2x por semana e Psicologia 1x" -> dois itens
                foreach (preg_split('/\s+e\s+(?=(?:a\s+|o\s+|de\s+)?' . $specStart . ')/iu', $chunk) as $item) {
                    $item = trim($item);
                    if ($item !== '' && $keep($item)) {
                        $items[] = $item;
                    }
                }
            }
            if ($items) {
                $outSentences[] = $prefix . implode(', ', $items) . $end;
            }
        }
        $outLines[] = implode(' ', $outSentences);
    }
    $out = implode("\n", $outLines);
    $out = (string)preg_replace('/[ \t]{2,}/u', ' ', $out);
    $out = (string)preg_replace("/\n{3,}/u", "\n\n", $out);
    return trim($out);
}

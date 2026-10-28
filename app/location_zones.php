<?php

declare(strict_types=1);

/**
 * LOCALIZAÇÃO PARA CAPTAÇÃO: cidade, UF, bairro e ZONA.
 *
 * Capitais grandes (São Paulo e Rio de Janeiro) são divididas por zona: o card
 * de captação e os grupos de WhatsApp dessas cidades são separados por zona
 * (ex.: "Fonoaudiologia - São Paulo/SP - Zona Sul"). A zona é deduzida do
 * bairro (tabelas abaixo) e pode ser ajustada manualmente no card.
 *
 * Também concentra a regra de COMPATIBILIDADE de localização de um
 * profissional com o card (cidade/UF/zona/DDD), usada para escolher quem entra
 * em cada grupo.
 */

/** Normaliza texto para comparação: minúsculas, sem acentos, abreviações expandidas. */
function location_normalize(?string $s): string
{
    $s = mb_strtolower(trim((string)$s), 'UTF-8');
    if ($s === '') {
        return '';
    }
    $s = strtr($s, [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n',
    ]);
    $s = preg_replace('/[^a-z0-9 ]+/', ' ', $s) ?? $s;
    $s = ' ' . preg_replace('/\s+/', ' ', trim($s)) . ' ';
    // Abreviações comuns em endereços.
    $s = strtr($s, [
        ' jd ' => ' jardim ', ' jdm ' => ' jardim ', ' vl ' => ' vila ',
        ' pq ' => ' parque ', ' pqe ' => ' parque ', ' sta ' => ' santa ', ' sto ' => ' santo ',
        ' cid ' => ' cidade ', ' cj ' => ' conjunto ', ' conj ' => ' conjunto ',
        ' n sra ' => ' nossa senhora ',
    ]);
    return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
}

/** Chave "cidade|UF" normalizada. */
function location_city_key(?string $city, ?string $state): string
{
    return location_normalize($city) . '|' . strtoupper(trim((string)$state));
}

/**
 * Cidades divididas por zona => [zona => [bairros/distritos]].
 * São Paulo: 96 distritos oficiais agrupados por zona + bairros populares.
 * Rio de Janeiro: bairros oficiais por zona.
 */
function location_zone_map(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [
        'sao paulo|SP' => [
            'Centro' => [
                'Bela Vista', 'Bom Retiro', 'Cambuci', 'Consolação', 'Liberdade', 'República', 'Santa Cecília', 'Sé',
                'Centro', 'Aclimação', 'Higienópolis', 'Vila Buarque', 'Campos Elíseos', 'Luz', 'Glicério', 'Bixiga',
            ],
            'Zona Norte' => [
                'Anhanguera', 'Brasilândia', 'Cachoeirinha', 'Casa Verde', 'Freguesia do Ó', 'Jaçanã', 'Jaraguá', 'Limão',
                'Mandaqui', 'Perus', 'Pirituba', 'Santana', 'São Domingos', 'Tremembé', 'Tucuruvi', 'Vila Guilherme',
                'Vila Maria', 'Vila Medeiros', 'Vila Nova Cachoeirinha', 'Parada Inglesa', 'Água Fria', 'Lauzane Paulista',
                'Horto Florestal', 'Imirim', 'Vila Gustavo', 'Jardim São Paulo', 'Carandiru', 'Parque Novo Mundo',
                'Vila Sabrina', 'Jardim Brasil', 'Vila Nova Galvão', 'Chora Menino', 'Jardim Tremembé',
            ],
            'Zona Oeste' => [
                'Alto de Pinheiros', 'Barra Funda', 'Butantã', 'Itaim Bibi', 'Jaguara', 'Jaguaré', 'Jardim Paulista', 'Lapa',
                'Morumbi', 'Perdizes', 'Pinheiros', 'Raposo Tavares', 'Rio Pequeno', 'Vila Leopoldina', 'Vila Sônia',
                'Vila Madalena', 'Jardins', 'Jardim América', 'Jardim Europa', 'Vila Olímpia', 'Pacaembu', 'Pompeia',
                'Vila Romana', 'Água Branca', 'Sumaré', 'Cerqueira César', 'Jardim Paulistano', 'Cidade Jardim',
                'Vila Anglo Brasileira', 'Vila Ipojuca', 'City Lapa', 'Jardim Bonfiglioli', 'Caxingui', 'Vila Indiana',
                'Ferreira', 'Parque Continental',
            ],
            'Zona Sul' => [
                'Campo Belo', 'Campo Grande', 'Campo Limpo', 'Capão Redondo', 'Cidade Ademar', 'Cidade Dutra', 'Cursino',
                'Grajaú', 'Ipiranga', 'Jabaquara', 'Jardim Ângela', 'Jardim São Luís', 'Marsilac', 'Moema', 'Parelheiros',
                'Pedreira', 'Sacomã', 'Santo Amaro', 'Saúde', 'Socorro', 'Vila Andrade', 'Vila Mariana', 'Brooklin',
                'Paraíso', 'Vila Clementino', 'Chácara Santo Antônio', 'Interlagos', 'Indianópolis', 'Planalto Paulista',
                'Mirandópolis', 'Vila Gumercindo', 'Vila Monumento', 'Americanópolis', 'Jardim Marajoara', 'Granja Julieta',
                'Chácara Klabin', 'Vila Cruzeiro', 'Jardim Prudência', 'Cupecê', 'Pedreira', 'Jardim Miriam', 'Heliópolis',
                'Vila Santa Catarina', 'Veleiros', 'Jardim Herculano', 'Paraisópolis', 'Vila das Belezas',
            ],
            'Zona Leste' => [
                'Água Rasa', 'Aricanduva', 'Artur Alvim', 'Belém', 'Brás', 'Cangaíba', 'Carrão', 'Cidade Líder',
                'Cidade Tiradentes', 'Ermelino Matarazzo', 'Guaianases', 'Iguatemi', 'Itaim Paulista', 'Itaquera',
                'Jardim Helena', 'José Bonifácio', 'Lajeado', 'Mooca', 'Pari', 'Parque do Carmo', 'Penha', 'Ponte Rasa',
                'Sapopemba', 'São Lucas', 'São Mateus', 'São Miguel', 'São Miguel Paulista', 'São Rafael', 'Tatuapé',
                'Vila Curuçá', 'Vila Formosa', 'Vila Jacuí', 'Vila Matilde', 'Vila Prudente', 'Vila Carrão',
                'Anália Franco', 'Jardim Anália Franco', 'Vila Ema', 'Vila Zelina', 'Vila Alpina', 'Parque São Lucas',
                'Penha de França', 'Vila Esperança', 'Guilhermina', 'Patriarca', 'Cidade AE Carvalho', 'Vila Talarico',
                'Jardim Iva', 'Vila Granada', 'Alto da Mooca', 'Parque da Mooca', 'Vila Califórnia', 'Jardim Textil',
                'Burgo Paulista', 'Vila Nova Manchester', 'Chácara Califórnia',
            ],
        ],
        'rio de janeiro|RJ' => [
            'Centro' => [
                'Centro', 'Lapa', 'Santa Teresa', 'Saúde', 'Gamboa', 'Santo Cristo', 'Cidade Nova', 'Catumbi',
                'Rio Comprido', 'Estácio', 'Paquetá', 'Caju', 'São Cristóvão', 'Benfica', 'Mangueira', 'Vasco da Gama',
            ],
            'Zona Sul' => [
                'Botafogo', 'Catete', 'Copacabana', 'Cosme Velho', 'Flamengo', 'Gávea', 'Glória', 'Humaitá', 'Ipanema',
                'Jardim Botânico', 'Lagoa', 'Laranjeiras', 'Leblon', 'Leme', 'Rocinha', 'São Conrado', 'Urca', 'Vidigal',
            ],
            'Zona Oeste' => [
                'Barra da Tijuca', 'Recreio dos Bandeirantes', 'Recreio', 'Jacarepaguá', 'Freguesia', 'Freguesia de Jacarepaguá',
                'Taquara', 'Pechincha', 'Tanque', 'Curicica', 'Anil', 'Gardênia Azul', 'Cidade de Deus', 'Praça Seca',
                'Vila Valqueire', 'Itanhangá', 'Joá', 'Vargem Grande', 'Vargem Pequena', 'Camorim', 'Grumari',
                'Campo Grande', 'Bangu', 'Realengo', 'Santa Cruz', 'Sepetiba', 'Guaratiba', 'Barra de Guaratiba',
                'Pedra de Guaratiba', 'Paciência', 'Cosmos', 'Inhoaíba', 'Santíssimo', 'Senador Camará',
                'Senador Vasconcelos', 'Padre Miguel', 'Magalhães Bastos', 'Deodoro', 'Vila Militar', 'Campo dos Afonsos',
                'Jardim Sulacap', 'Gericinó', 'Barra Olímpica',
            ],
            'Zona Norte' => [
                'Tijuca', 'Vila Isabel', 'Grajaú', 'Andaraí', 'Maracanã', 'Praça da Bandeira', 'Alto da Boa Vista',
                'Méier', 'Engenho Novo', 'Lins de Vasconcelos', 'Cachambi', 'Todos os Santos', 'Engenho de Dentro',
                'Encantado', 'Piedade', 'Abolição', 'Pilares', 'Água Santa', 'Riachuelo', 'Rocha', 'Sampaio',
                'São Francisco Xavier', 'Jacaré', 'Madureira', 'Cascadura', 'Campinho', 'Quintino Bocaiúva', 'Cavalcanti',
                'Engenheiro Leal', 'Oswaldo Cruz', 'Bento Ribeiro', 'Marechal Hermes', 'Vaz Lobo', 'Turiaçu',
                'Rocha Miranda', 'Honório Gurgel', 'Irajá', 'Vicente de Carvalho', 'Vila da Penha', 'Vista Alegre',
                'Colégio', 'Penha', 'Penha Circular', 'Brás de Pina', 'Olaria', 'Ramos', 'Bonsucesso', 'Manguinhos',
                'Higienópolis', 'Maré', 'Ilha do Governador', 'Jardim Guanabara', 'Cacuia', 'Portuguesa', 'Galeão',
                'Tauá', 'Moneró', 'Cocotá', 'Bancários', 'Pitangueiras', 'Praia da Bandeira', 'Zumbi', 'Ribeira',
                'Jardim Carioca', 'Pavuna', 'Anchieta', 'Guadalupe', 'Ricardo de Albuquerque', 'Parque Anchieta',
                'Costa Barros', 'Barros Filho', 'Acari', 'Coelho Neto', 'Del Castilho', 'Inhaúma', 'Engenho da Rainha',
                'Tomás Coelho', 'Maria da Graça', 'Jacarezinho', 'Complexo do Alemão', 'Cordovil', 'Parada de Lucas',
                'Vigário Geral', 'Jardim América', 'Mangueira',
            ],
        ],
    ];
    return $map;
}

/** A cidade é dividida por zonas? */
function location_city_has_zones(?string $city, ?string $state): bool
{
    return isset(location_zone_map()[location_city_key($city, $state)]);
}

/** Lista de zonas da cidade (vazio se a cidade não for dividida por zona). */
function location_zone_options(?string $city, ?string $state): array
{
    $m = location_zone_map()[location_city_key($city, $state)] ?? [];
    return array_keys($m);
}

/** Normaliza o nome de uma zona digitada ("zona sul", "ZS", "sul") para o rótulo oficial. */
function location_normalize_zone(?string $zone, ?string $city = null, ?string $state = null): ?string
{
    $z = location_normalize($zone);
    if ($z === '') {
        return null;
    }
    $aliases = [
        'centro' => 'Centro', 'zona central' => 'Centro', 'regiao central' => 'Centro',
        'zona norte' => 'Zona Norte', 'norte' => 'Zona Norte', 'zn' => 'Zona Norte',
        'zona sul' => 'Zona Sul', 'sul' => 'Zona Sul', 'zs' => 'Zona Sul',
        'zona leste' => 'Zona Leste', 'leste' => 'Zona Leste', 'zl' => 'Zona Leste',
        'zona oeste' => 'Zona Oeste', 'oeste' => 'Zona Oeste', 'zo' => 'Zona Oeste',
    ];
    $label = $aliases[$z] ?? null;
    if ($label === null) {
        return null;
    }
    if ($city !== null && location_city_has_zones($city, $state)) {
        return in_array($label, location_zone_options($city, $state), true) ? $label : null;
    }
    return $label;
}

/**
 * Deduz a zona a partir do bairro (só para cidades divididas por zona).
 * Retorna null quando a cidade não tem zonas ou o bairro não é reconhecido.
 */
function location_zone_for(?string $city, ?string $state, ?string $neighborhood): ?string
{
    $zones = location_zone_map()[location_city_key($city, $state)] ?? null;
    if ($zones === null) {
        return null;
    }
    $nb = location_normalize($neighborhood);
    if ($nb === '') {
        return null;
    }
    // O próprio campo pode já conter a zona ("Zona Sul", "ZL").
    $direct = location_normalize_zone($neighborhood, $city, $state);
    if ($direct !== null) {
        return $direct;
    }
    // 1) Igualdade exata.
    $candidates = [];
    foreach ($zones as $zone => $list) {
        foreach ($list as $name) {
            $key = location_normalize($name);
            if ($key === $nb) {
                return $zone;
            }
            $candidates[] = [$key, $zone];
        }
    }
    // 2) Bairro contém o nome conhecido como palavras inteiras (o mais longo vence):
    //    "Vila Mariana II" -> Vila Mariana; "Jd. São Luís" -> Jardim São Luís.
    usort($candidates, static fn($a, $b) => strlen($b[0]) <=> strlen($a[0]));
    $hay = ' ' . $nb . ' ';
    foreach ($candidates as [$key, $zone]) {
        if (strlen($key) >= 4 && strpos($hay, ' ' . $key . ' ') !== false) {
            return $zone;
        }
    }
    return null;
}

/** UF a partir do DDD de um telefone brasileiro (com ou sem 55). */
function location_ddd_state(?string $phone): ?string
{
    $d = preg_replace('/\D+/', '', (string)$phone);
    if (strlen($d) >= 12 && strncmp($d, '55', 2) === 0) {
        $d = substr($d, 2);
    }
    if (strlen($d) < 10) {
        return null;
    }
    $ddd = (int)substr($d, 0, 2);
    static $map = null;
    if ($map === null) {
        $map = [];
        $groups = [
            'SP' => [11, 12, 13, 14, 15, 16, 17, 18, 19], 'RJ' => [21, 22, 24], 'ES' => [27, 28],
            'MG' => [31, 32, 33, 34, 35, 37, 38], 'PR' => [41, 42, 43, 44, 45, 46], 'SC' => [47, 48, 49],
            'RS' => [51, 53, 54, 55], 'DF' => [61], 'GO' => [62, 64], 'TO' => [63], 'MT' => [65, 66], 'MS' => [67],
            'AC' => [68], 'RO' => [69], 'BA' => [71, 73, 74, 75, 77], 'SE' => [79], 'PE' => [81, 87], 'AL' => [82],
            'PB' => [83], 'RN' => [84], 'CE' => [85, 88], 'PI' => [86, 89], 'PA' => [91, 93, 94], 'AM' => [92, 97],
            'RR' => [95], 'AP' => [96], 'MA' => [98, 99],
        ];
        foreach ($groups as $uf => $ddds) {
            foreach ($ddds as $x) {
                $map[$x] = $uf;
            }
        }
    }
    return $map[$ddd] ?? null;
}

/**
 * Compatibilidade de localização de um profissional com o card.
 *
 * Regra (quem sabidamente está em outro lugar fica de fora; quem não tem
 * localização cadastrada continua entrando, para não esvaziar os grupos):
 *  - Cidade conhecida: precisa ser a mesma cidade (e a mesma UF, se informada).
 *    Em cidade com zonas, se a zona do profissional for conhecida, precisa bater.
 *  - Sem cidade, mas com UF (cadastro ou DDD do telefone): precisa ser a mesma UF.
 *  - Sem nenhuma informação: compatível.
 *
 * @param array{city:string,state:string,zone:?string} $target
 * @param array{city?:?string,state?:?string,neighborhood?:?string,zone?:?string,phone?:?string} $prof
 * @return array{0:bool,1:string} [compatível, nível usado: city|zone|state|ddd|unknown|mismatch]
 */
function location_professional_compatible(array $target, array $prof): array
{
    $tCity = location_normalize($target['city'] ?? '');
    $tState = strtoupper(trim((string)($target['state'] ?? '')));
    $tZone = $target['zone'] ?? null;

    $pCity = location_normalize($prof['city'] ?? '');
    $pState = strtoupper(trim((string)($prof['state'] ?? '')));

    if ($pCity !== '') {
        if ($tCity === '' || $pCity !== $tCity) {
            return [false, 'mismatch'];
        }
        if ($pState !== '' && $tState !== '' && $pState !== $tState) {
            return [false, 'mismatch'];
        }
        if ($tZone !== null && $tZone !== '') {
            $pZone = location_normalize_zone($prof['zone'] ?? null)
                ?? location_zone_for($prof['city'] ?? '', $pState !== '' ? $pState : $tState, $prof['neighborhood'] ?? '');
            if ($pZone !== null) {
                return $pZone === $tZone ? [true, 'zone'] : [false, 'mismatch'];
            }
        }
        return [true, 'city'];
    }
    if ($pState !== '') {
        return $pState === $tState ? [true, 'state'] : [false, 'mismatch'];
    }
    $dddState = location_ddd_state($prof['phone'] ?? '');
    if ($dddState !== null) {
        return $dddState === $tState ? [true, 'ddd'] : [false, 'mismatch'];
    }
    return [true, 'unknown'];
}

/**
 * Chave canônica da ESPECIALIDADE (para casar "Fono", "Fga", "Fonoaudiologia
 * Domiciliar"...). Usada para profissionais/contatos e grupos.
 */
function specialty_key(?string $text): string
{
    $t = ' ' . location_normalize($text) . ' ';
    if (trim($t) === '') {
        return '';
    }
    $rules = [
        'psicopedagogia' => '/ psicopedag/',
        'terapia_ocupacional' => '/ (terapia ocupacional|terapeuta ocupacional|t o|to) /',
        'fonoaudiologia' => '/ (fono\w*|fga|fno|fng|fgo|fg) /',
        'fisioterapia' => '/ (fisio\w*|fta|ft) /',
        'nutricao' => '/ (nutri\w*|nt) /',
        'enfermagem' => '/ (enferm\w*|enf|tec enf|tecnico de enfermagem|tecnica de enfermagem) /',
        'psicologia' => '/ (psic\w*) /',
        'cuidador' => '/ (cuidador\w*) /',
        'medicina' => '/ (medic[oa]|medicina|med|dra?) /',
        'odontologia' => '/ (dentista|odonto\w*) /',
    ];
    foreach ($rules as $key => $re) {
        if (preg_match($re, $t)) {
            return $key;
        }
    }
    // Sem regra: primeira palavra significativa.
    $parts = explode(' ', trim($t));
    return $parts[0] ?? '';
}

/**
 * Garante as colunas de localização detalhada usadas na captação
 * (fallback idempotente caso a migration 2026-10-27_0002 não tenha rodado).
 * Chamar FORA de transação (DDL faz commit implícito no MySQL).
 */
function location_users_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    // Atalho barato: se as colunas-chave já existem, não roda os ALTERs (evita DDL a cada página).
    try {
        $has = static fn(string $table, string $col): bool => (bool)db()->query("SHOW COLUMNS FROM `$table` LIKE '$col'")->fetch();
        if ($has('users', 'zone') && $has('demands', 'location_zone') && $has('demands', 'location_zip')
            && $has('whatsapp_groups', 'zone') && $has('imported_contacts', 'neighborhood') && $has('imported_contacts', 'user_id')) {
            return;
        }
    } catch (Throwable $e) { /* segue para os ALTERs */ }
    $alters = [
        "ALTER TABLE users ADD COLUMN city VARCHAR(120) NULL",
        "ALTER TABLE users ADD COLUMN state CHAR(2) NULL",
        "ALTER TABLE users ADD COLUMN neighborhood VARCHAR(120) NULL",
        "ALTER TABLE users ADD COLUMN zone VARCHAR(40) NULL",
        "ALTER TABLE demands ADD COLUMN location_zone VARCHAR(40) NULL",
        "ALTER TABLE demands ADD COLUMN location_zip VARCHAR(10) NULL",
        "ALTER TABLE demands ADD COLUMN location_complement VARCHAR(120) NULL",
        "ALTER TABLE whatsapp_groups ADD COLUMN zone VARCHAR(40) NULL",
        "ALTER TABLE imported_contacts ADD COLUMN user_id INT UNSIGNED NULL",
        "ALTER TABLE imported_contacts ADD COLUMN specialty VARCHAR(120) NULL",
        "ALTER TABLE imported_contacts ADD COLUMN city VARCHAR(120) NULL",
        "ALTER TABLE imported_contacts ADD COLUMN state CHAR(2) NULL",
        "ALTER TABLE imported_contacts ADD COLUMN neighborhood VARCHAR(120) NULL",
    ];
    foreach ($alters as $sql) {
        try { db()->exec($sql); } catch (Throwable $e) { /* já existe / tabela ausente */ }
    }
}

/**
 * Separa um endereço colado em UMA linha nos campos do card.
 * Os e-mails não seguem um padrão, então a função tenta, nesta ordem:
 *  1) rótulos explícitos ("Bairro: X", "Cidade: Y", "CEP: ...");
 *  2) CEP (00000-000) e UF (sigla válida, preferindo o final do texto);
 *  3) partes separadas por vírgula, " - ", "/", ";" ou quebra de linha:
 *     logradouro (1ª parte), número, complemento, bairro e cidade (últimas partes).
 * Campos não reconhecidos voltam vazios — o captador confere antes de salvar.
 *
 * @return array{location_zip:string,location_street:string,location_number:string,location_complement:string,location_neighborhood:string,location_city:string,location_state:string}
 */
function location_parse_address(string $text): array
{
    $out = ['location_zip' => '', 'location_street' => '', 'location_number' => '', 'location_complement' => '',
        'location_neighborhood' => '', 'location_city' => '', 'location_state' => ''];
    $ufs = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];
    $s = trim(preg_replace('/\s+/u', ' ', str_replace(["\r", "\n", "\t"], ', ', $text)) ?? '');
    if ($s === '') {
        return $out;
    }
    $s = preg_replace('/^\s*(endere[cç]o( completo)?|local( do atendimento)?|logradouro)\s*[:\-]\s*/iu', '', $s) ?? $s;
    // "Rua: Bahia" -> "Rua Bahia"; "Logradouro: ..." -> "..."
    $s = preg_replace('/\b(rua|avenida|av|travessa|alameda|estrada|rodovia|pra[cç]a)\s*:\s*/iu', '$1 ', $s) ?? $s;
    $s = preg_replace('/\blogradouro\s*:\s*/iu', '', $s) ?? $s;
    // Protege "s/n" (sem número) antes de separar por "/".
    $s = preg_replace('/\bs\s*\/\s*n[º°o]?\b/iu', 's.n', $s) ?? $s;

    // 1) Rótulos explícitos.
    $labels = [
        'location_zip' => 'cep',
        'location_neighborhood' => 'bairro',
        'location_city' => 'cidade|munic[ií]pio',
        'location_state' => 'uf|estado',
        'location_complement' => 'complemento|compl\.?',
        'location_number' => 'n[uú]mero|n[º°o]\.?',
    ];
    foreach ($labels as $field => $re) {
        if (preg_match('/(?:^|[,;\-\s])(?:' . $re . ')\s*[:\-]\s*([^,;]+?)(?=\s*(?:[,;]|\s-\s|$|\b(?:cep|bairro|cidade|uf|estado|complemento|n[uú]mero)\s*:))/iu', $s, $m)) {
            $out[$field] = trim($m[1], " .-");
            $s = str_replace($m[0], ',', $s);
        }
    }

    // 2) CEP e UF.
    if ($out['location_zip'] === '' && preg_match('/\b(\d{5})-?\s?(\d{3})\b/', $s, $m)) {
        $out['location_zip'] = $m[1] . $m[2];
        $s = str_replace($m[0], ',', $s);
    }
    $out['location_zip'] = preg_replace('/\D+/', '', $out['location_zip']) ?? '';
    $s = preg_replace('/\bcep\b\s*:?/iu', ',', $s) ?? $s;
    if ($out['location_state'] === '') {
        // Sigla isolada (ex.: "Mirassol/SP", "Mirassol - SP", "Mirassol SP"), preferindo a última.
        if (preg_match_all('/(?:^|[\s,\/\-\(])([A-Z]{2})(?=$|[\s,\.\/\-\)])/u', $s, $mm, PREG_OFFSET_CAPTURE)) {
            for ($i = count($mm[1]) - 1; $i >= 0; $i--) {
                if (in_array($mm[1][$i][0], $ufs, true)) {
                    $out['location_state'] = $mm[1][$i][0];
                    $s = substr($s, 0, $mm[1][$i][1]) . ',' . substr($s, $mm[1][$i][1] + 2);
                    break;
                }
            }
        }
    } else {
        $out['location_state'] = strtoupper(substr($out['location_state'], 0, 2));
    }

    // 3) Partes.
    $parts = preg_split('/\s*(?:,|;|\s[-–]\s|\/|\|)\s*/u', $s) ?: [];
    $parts = array_values(array_filter(array_map(static fn($p) => trim($p, " .-–"), $parts), static fn($p) => $p !== ''));
    $streetRe = '/^(rua|r\.|r |avenida|av\.?|av |travessa|tv\.?|alameda|al\.?|estrada|est\.|rodovia|rod\.?|pra[cç]a|p[cç]a\.?|largo|lgo\.?|viela|vila|via|rua projetada|servid[aã]o)\b/iu';
    $complementRe = '/^(apto?\.?|apartamento|ap\.?|casa|cs\.?|bloco|bl\.?|sala|sl\.?|conjunto|cj\.?|lote|lt\.?|quadra|qd\.?|fundos|frente|andar|torre|edif[ií]cio|ed\.?|condom[ií]nio|cond\.?|km)\b/iu';
    $numberRe = '/^(?:n[º°o.]?\s*)?(\d{1,6}[a-z]?|s\.?n|sem n[uú]mero)$/iu';

    // Tudo numa parte só, sem separadores ("Avenida Brasil 2000 Copacabana Rio de Janeiro"):
    // corta no número; o que vem depois é bairro (e cidade, se for uma cidade conhecida).
    if (count($parts) === 1 && preg_match('/^(.*?\p{L}.*?)\s+(?:n[º°o.]?\s*)?(\d{1,6}[a-z]?)\s+(.+)$/iu', $parts[0], $m)) {
        $parts = [$m[1] . ' ' . $m[2]];
        $tail = trim($m[3]);
        foreach (['São Paulo', 'Rio de Janeiro'] as $knownCity) {
            if (preg_match('/^(.*?)\s*' . preg_quote($knownCity, '/') . '$/iu', $tail, $mc)) {
                $out['location_city'] = $out['location_city'] !== '' ? $out['location_city'] : $knownCity;
                $tail = trim($mc[1]);
                break;
            }
        }
        if ($tail !== '') {
            $parts[] = $tail;
            if ($out['location_city'] === '') {
                // Sem como saber onde termina o bairro e começa a cidade: deixa no bairro
                // (o card marca "Cidade" como pendente para o captador conferir).
                $out['location_neighborhood'] = $tail;
                array_pop($parts);
            }
        }
    }

    $rest = [];
    $numberFromStreet = false;
    foreach ($parts as $idx => $p) {
        if ($out['location_street'] === '' && ($idx === 0 || preg_match($streetRe, $p))) {
            // "Rua das Flores 123" ou "Rua das Flores, nº 123"
            if (preg_match('/^(.*\D)\s+(?:n[º°o.]?\s*)?(\d{1,6}[a-z]?)$/iu', $p, $m) && preg_match('/\p{L}/u', $m[1])) {
                $out['location_street'] = trim($m[1], " ,");
                if ($out['location_number'] === '') {
                    $out['location_number'] = $m[2];
                    $numberFromStreet = true;
                }
            } else {
                $out['location_street'] = $p;
            }
            continue;
        }
        // "455 apto 32" -> número 455 + complemento "apto 32"
        if (preg_match('/^(?:n[º°o.]?\s*)?(\d{1,6}[a-z]?)\s+(.+)$/iu', $p, $m) && preg_match($complementRe, $m[2])) {
            if ($out['location_number'] === '' || $numberFromStreet) {
                if ($numberFromStreet) {
                    $out['location_street'] .= ' ' . $out['location_number'];
                    $numberFromStreet = false;
                }
                $out['location_number'] = $m[1];
                $p = $m[2];
            }
        }
        if (preg_match($numberRe, $p, $m) && ($out['location_number'] === '' || $numberFromStreet)) {
            // Número explícito depois da rua: o número "grudado" na rua era parte do nome
            // (ex.: "Rua Projetada 5, s/n").
            if ($numberFromStreet) {
                $out['location_street'] .= ' ' . $out['location_number'];
                $numberFromStreet = false;
            }
            $out['location_number'] = stripos($m[1], 's') === 0 ? 's/n' : $m[1];
            continue;
        }
        if (preg_match($complementRe, $p)) {
            $out['location_complement'] = trim($out['location_complement'] . ($out['location_complement'] !== '' ? ' - ' : '') . $p);
            continue;
        }
        if (preg_match('/^(brasil)$/iu', $p)) {
            continue;
        }
        $rest[] = preg_replace('/^(bairro|b\.)\s+/iu', '', $p) ?? $p;
    }

    // Últimas partes: ... bairro, cidade.
    if ($out['location_city'] === '' && $rest) {
        $out['location_city'] = (string)array_pop($rest);
    }
    if ($out['location_neighborhood'] === '' && $rest) {
        $out['location_neighborhood'] = (string)array_pop($rest);
    }
    if ($rest) {
        // Sobrou algo (ex.: ponto de referência): vai para o complemento.
        $out['location_complement'] = trim($out['location_complement'] . ($out['location_complement'] !== '' ? ' - ' : '') . implode(' - ', $rest));
    }
    foreach ($out as $k => $v) {
        $out[$k] = trim((string)$v);
    }
    return $out;
}

<?php

declare(strict_types=1);

/**
 * FORMULÁRIO DO CARD DE CAPTAÇÃO (Novo card e Editar card).
 *
 * Um único formulário para criar e editar, com TODOS os campos que o disparo
 * no WhatsApp/e-mail realmente usa (rua, bairro, cidade, UF, zona, quadro
 * clínico, frequência...). Antes o "Novo card" gravava o endereço só em
 * location_raw (campo bruto que ninguém lia) e a notificação saía sem endereço;
 * e o "Editar" só tinha título/cidade/UF, sem como completar o que faltava.
 *
 * Também define quais campos BLOQUEIAM a captação (o card fica em
 * "Tratamento Manual" e mostra exatamente o que falta).
 */

/** Garante colunas usadas pelo formulário (fallback idempotente; chamar fora de transação). */
function demand_form_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (function_exists('clients_ensure_schema')) {
        try { clients_ensure_schema(); } catch (Throwable $e) {}
    }
    location_users_ensure_schema();
    $need = ['patient_name', 'location_street', 'location_neighborhood', 'location_number', 'location_raw',
        'frequency', 'urgency', 'procedure_value', 'ai_summary', 'captation_note'];
    try {
        $existing = db()->query('SHOW COLUMNS FROM demands')->fetchAll(PDO::FETCH_COLUMN);
        if (!array_diff($need, $existing)) {
            return; // tudo já existe: sem DDL
        }
    } catch (Throwable $e) {}
    $alters = [
        "ALTER TABLE demands ADD COLUMN patient_name VARCHAR(200) NULL",
        "ALTER TABLE demands ADD COLUMN location_street VARCHAR(200) NULL",
        "ALTER TABLE demands ADD COLUMN location_neighborhood VARCHAR(120) NULL",
        "ALTER TABLE demands ADD COLUMN location_number VARCHAR(20) NULL",
        "ALTER TABLE demands ADD COLUMN location_raw TEXT NULL",
        "ALTER TABLE demands ADD COLUMN frequency VARCHAR(120) NULL",
        "ALTER TABLE demands ADD COLUMN urgency VARCHAR(20) NULL",
        "ALTER TABLE demands ADD COLUMN procedure_value DECIMAL(10,2) NULL",
        "ALTER TABLE demands ADD COLUMN ai_summary TEXT NULL",
        "ALTER TABLE demands ADD COLUMN captation_note TEXT NULL",
    ];
    foreach ($alters as $sql) {
        try { db()->exec($sql); } catch (Throwable $e) { /* já existe */ }
    }
}

/** Colunas existentes em demands (cache por requisição). */
function demand_form_columns(): array
{
    static $cols = null;
    if ($cols === null) {
        $cols = [];
        try {
            foreach (db()->query('SHOW COLUMNS FROM demands')->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $cols[(string)$c['Field']] = true;
            }
        } catch (Throwable $e) {}
    }
    return $cols;
}

/** Campos editáveis do card (coluna => rótulo). */
function demand_form_field_labels(): array
{
    return [
        'patient_name' => 'Nome do paciente',
        'title' => 'Título do card',
        'client_id' => 'Cliente (contratante)',
        'health_insurer_id' => 'Operadora',
        'specialty' => 'Especialidade',
        'frequency' => 'Frequência',
        'urgency' => 'Urgência',
        'procedure_value' => 'Valor do procedimento',
        'origin_email' => 'E-mail de origem',
        'location_zip' => 'CEP',
        'location_street' => 'Rua',
        'location_number' => 'Número',
        'location_complement' => 'Complemento',
        'location_neighborhood' => 'Bairro',
        'location_city' => 'Cidade',
        'location_state' => 'UF',
        'location_zone' => 'Zona',
        'ai_summary' => 'Quadro clínico',
        'captation_note' => 'Observação para os profissionais',
        'description' => 'Observações internas',
    ];
}

/**
 * O que falta no card.
 *  - blocking: sem isso o disparo não acontece (card fica em Tratamento Manual).
 *  - recommended: o disparo acontece, mas a mensagem sai incompleta.
 *
 * @return array{blocking:array<string,string>, recommended:array<string,string>}
 */
function demand_missing_fields(array $d): array
{
    $labels = demand_form_field_labels();
    $val = static fn(string $k): string => trim((string)($d[$k] ?? ''));
    $blocking = [];
    $recommended = [];

    if ($val('specialty') === '') {
        $blocking['specialty'] = $labels['specialty'];
    }
    if ($val('location_city') === '') {
        $blocking['location_city'] = $labels['location_city'];
    }
    if (!preg_match('/^[A-Z]{2}$/', strtoupper($val('location_state')))) {
        $blocking['location_state'] = $labels['location_state'];
    }
    if ($val('location_city') !== '' && location_city_has_zones($val('location_city'), $val('location_state'))) {
        $zone = location_normalize_zone($val('location_zone'), $val('location_city'), $val('location_state'))
            ?? location_zone_for($val('location_city'), $val('location_state'), $val('location_neighborhood'));
        if ($zone === null) {
            $blocking['location_zone'] = $labels['location_zone'] . ' (' . $val('location_city') . ' é dividida por zona — informe o bairro ou escolha a zona)';
        }
    }

    if ($val('patient_name') === '') {
        $recommended['patient_name'] = $labels['patient_name'];
    }
    if ($val('location_street') === '') {
        $recommended['location_street'] = $labels['location_street'];
    }
    if ($val('location_neighborhood') === '') {
        $recommended['location_neighborhood'] = $labels['location_neighborhood'];
    }
    if ($val('frequency') === '') {
        $recommended['frequency'] = $labels['frequency'];
    }
    if ($val('ai_summary') === '') {
        $recommended['ai_summary'] = $labels['ai_summary'];
    }
    return ['blocking' => $blocking, 'recommended' => $recommended];
}

/** Texto curto "Falta: X, Y" para cards/listas. */
function demand_missing_summary(array $d): string
{
    $m = demand_missing_fields($d);
    $parts = [];
    foreach ($m['blocking'] as $k => $label) {
        $parts[] = $k === 'location_zone' ? 'Zona' : $label;
    }
    return implode(', ', $parts);
}

/**
 * Lê e normaliza o POST do formulário.
 *
 * @return array{data:array<string,mixed>, errors:string[]}
 */
function demand_form_from_post(array $p): array
{
    $t = static fn(string $k, int $max = 0): string => $max > 0
        ? mb_substr(trim((string)($p[$k] ?? '')), 0, $max)
        : trim((string)($p[$k] ?? ''));
    $errors = [];

    $data = [
        'patient_name' => $t('patient_name', 200),
        'title' => $t('title', 200),
        'client_id' => (int)($p['client_id'] ?? 0),
        'health_insurer_id' => (int)($p['health_insurer_id'] ?? 0),
        'specialty' => $t('specialty', 120),
        'frequency' => $t('frequency', 120),
        'urgency' => $t('urgency', 20),
        'procedure_value' => null,
        'origin_email' => $t('origin_email', 190),
        'location_zip' => preg_replace('/\D+/', '', $t('location_zip')) ?? '',
        'location_street' => $t('location_street', 200),
        'location_number' => $t('location_number', 20),
        'location_complement' => $t('location_complement', 120),
        'location_neighborhood' => $t('location_neighborhood', 120),
        'location_city' => $t('location_city', 120),
        'location_state' => strtoupper($t('location_state', 2)),
        'location_zone' => $t('location_zone', 40),
        'ai_summary' => $t('ai_summary'),
        'captation_note' => $t('captation_note'),
        'description' => $t('description'),
    ];

    if ($data['location_state'] !== '' && !preg_match('/^[A-Z]{2}$/', $data['location_state'])) {
        $errors[] = 'UF inválida (use 2 letras, ex.: SP).';
    }
    if ($data['origin_email'] !== '' && !filter_var($data['origin_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'E-mail de origem inválido.';
    }
    if (!in_array($data['urgency'], ['urgente', 'normal', 'baixa'], true)) {
        $data['urgency'] = '';
    }
    $pv = $t('procedure_value');
    if ($pv !== '') {
        // Aceita "1.234,56" e "1234.56".
        if (strpos($pv, ',') !== false) {
            $pvNorm = str_replace(['.', ','], ['', '.'], $pv);
        } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $pv)) {
            $pvNorm = str_replace('.', '', $pv); // "1.500" = mil e quinhentos
        } else {
            $pvNorm = $pv;
        }
        if (is_numeric($pvNorm) && (float)$pvNorm >= 0) {
            $data['procedure_value'] = round((float)$pvNorm, 2);
        } else {
            $errors[] = 'Valor do procedimento inválido.';
        }
    }
    if (strlen($data['location_zip']) > 0 && strlen($data['location_zip']) !== 8) {
        $errors[] = 'CEP inválido (8 dígitos).';
    }
    if ($data['frequency'] !== '' && function_exists('frequency_normalize')) {
        $norm = frequency_normalize($data['frequency']);
        if ($norm !== '') {
            $data['frequency'] = $norm;
        }
    }

    // Zona: só existe em cidades divididas por zona. Se não informada, deduz do bairro.
    if ($data['location_city'] !== '' && location_city_has_zones($data['location_city'], $data['location_state'])) {
        $data['location_zone'] = location_normalize_zone($data['location_zone'], $data['location_city'], $data['location_state'])
            ?? location_zone_for($data['location_city'], $data['location_state'], $data['location_neighborhood'])
            ?? '';
    } else {
        $data['location_zone'] = '';
    }

    if ($data['title'] === '') {
        $base = $data['patient_name'] !== '' ? $data['patient_name'] : 'Atendimento';
        $data['title'] = mb_strimwidth($base . ($data['specialty'] !== '' ? ' - ' . $data['specialty'] : ''), 0, 200, '');
    }

    return ['data' => $data, 'errors' => $errors];
}

/**
 * Grava o card (INSERT quando $id = null, UPDATE caso contrário).
 * Só grava colunas que existem na tabela.
 */
function demand_form_persist(array $data, ?int $id, ?string $status = null): int
{
    $cols = demand_form_columns();
    $nullable = static function ($v) {
        if ($v === null) {
            return null;
        }
        if (is_int($v)) {
            return $v > 0 ? $v : null;
        }
        if (is_float($v)) {
            return $v;
        }
        $v = trim((string)$v);
        return $v === '' ? null : $v;
    };

    $values = [];
    foreach (array_keys(demand_form_field_labels()) as $col) {
        if (!isset($cols[$col]) || !array_key_exists($col, $data)) {
            continue;
        }
        $values[$col] = $col === 'title' ? (string)$data['title'] : $nullable($data[$col]);
    }
    if ($status !== null) {
        $values['status'] = $status;
    }

    $db = db();
    if ($id === null) {
        $names = array_keys($values);
        $ph = array_map(static fn($c) => ':' . $c, $names);
        $st = $db->prepare('INSERT INTO demands (' . implode(', ', $names) . ') VALUES (' . implode(', ', $ph) . ')');
        $st->execute($values);
        return (int)$db->lastInsertId();
    }
    $sets = array_map(static fn($c) => $c . ' = :' . $c, array_keys($values));
    $values['__id'] = $id;
    $st = $db->prepare('UPDATE demands SET ' . implode(', ', $sets) . ' WHERE id = :__id');
    $st->execute($values);
    return $id;
}

/**
 * Renderiza o formulário completo.
 *
 * @param array $d       valores atuais (vazio para novo card)
 * @param string $action URL do POST
 * @param array $opts    ['id'=>int, 'submit'=>string, 'cancel_url'=>string, 'show_status'=>bool]
 */
function demand_form_render(array $d, string $action, array $opts = []): void
{
    demand_form_ensure_schema();
    $v = static fn(string $k): string => (string)($d[$k] ?? '');
    $isEdit = !empty($opts['id']);
    $missing = $isEdit ? demand_missing_fields($d) : ['blocking' => [], 'recommended' => []];
    $miss = static function (string $k) use ($missing): string {
        if (isset($missing['blocking'][$k])) {
            return ' data-missing="blocking"';
        }
        if (isset($missing['recommended'][$k])) {
            return ' data-missing="recommended"';
        }
        return '';
    };

    $specialties = [];
    try {
        $specialties = db()->query("SELECT id, name FROM specialties WHERE status = 'active' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
    $clients = function_exists('clients_list') ? clients_list(true) : [];
    $operators = function_exists('operators_list') ? operators_list(null, true) : [];
    $freqOpts = function_exists('frequency_get_options') ? frequency_get_options() : [];
    $canQuick = function_exists('quick_register_allowed') ? quick_register_allowed() : false;

    echo '<style>';
    echo '.dfSection{border:1px solid hsl(var(--border));border-radius:12px;padding:14px;display:grid;gap:12px}';
    echo '.dfSection h3{margin:0;font-size:15px;font-weight:800}';
    echo '.dfRow{display:flex;gap:6px;align-items:flex-end}.dfRow>label{flex:1}';
    echo '.dfPlus{height:38px;min-width:38px;padding:0 10px;font-weight:900}';
    echo '[data-missing="blocking"] input,[data-missing="blocking"] select,[data-missing="blocking"] textarea{border:2px solid hsl(0,84%,60%)!important;background:hsla(0,84%,60%,.05)}';
    echo '[data-missing="recommended"] input,[data-missing="recommended"] select,[data-missing="recommended"] textarea{border:2px solid hsl(36,90%,55%)!important}';
    echo '.dfHint{font-size:12px;color:hsl(var(--muted-foreground))}';
    echo '#qrModal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.35);z-index:200;align-items:center;justify-content:center}';
    echo '#qrModal .card{width:460px;max-width:92vw}';
    echo '</style>';

    // Resumo do que falta (edição).
    if ($isEdit && ($missing['blocking'] || $missing['recommended'])) {
        echo '<div class="alert" style="background:hsla(0,84%,60%,.08);border-color:hsl(0,84%,60%)">';
        if ($missing['blocking']) {
            echo '<div><strong>Impede a captação (preencha para liberar):</strong> ' . h(implode(', ', $missing['blocking'])) . '</div>';
        }
        if ($missing['recommended']) {
            echo '<div style="margin-top:4px"><strong>Recomendado (a mensagem sai incompleta sem isso):</strong> ' . h(implode(', ', $missing['recommended'])) . '</div>';
        }
        echo '<div class="dfHint" style="margin-top:4px">Campos em vermelho bloqueiam; em laranja são recomendados. Ao salvar com os obrigatórios preenchidos, o card sai de Tratamento Manual automaticamente.</div>';
        echo '</div>';
    }

    echo '<form method="post" action="' . h($action) . '" id="demandForm" style="display:grid;gap:14px;max-width:980px">';
    if ($isEdit) {
        echo '<input type="hidden" name="id" value="' . (int)$opts['id'] . '">';
    }

    // ---------------------------------------------------------------- IA
    echo '<details class="dfSection" style="background:hsla(var(--primary)/.04)"' . ($isEdit ? '' : ' open') . '>';
    echo '<summary style="cursor:pointer;font-weight:800">🤖 Preencher a partir do texto do e-mail (opcional)</summary>';
    echo '<div class="dfHint">Cole o texto da solicitação e clique em "Preencher com IA". Os campos abaixo são preenchidos para você conferir antes de salvar. Nada é salvo sem você clicar em Salvar.</div>';
    echo '<textarea id="dfAiText" rows="5" placeholder="Cole aqui o texto do e-mail/solicitação..."></textarea>';
    echo '<div style="display:flex;gap:10px;align-items:center"><button type="button" class="btn" id="dfAiBtn">Preencher com IA</button><span id="dfAiMsg" class="dfHint"></span></div>';
    echo '</details>';

    // ------------------------------------------------------- Identificação
    echo '<div class="dfSection"><h3>Paciente e contratante</h3>';
    echo '<div class="grid">';
    echo '<div class="col6"' . $miss('patient_name') . '><label>Nome do paciente *<input name="patient_name" required maxlength="200" value="' . h($v('patient_name')) . '" placeholder="Ex: João da Silva"></label></div>';
    echo '<div class="col6"><label>Título do card<input name="title" maxlength="200" value="' . h($v('title')) . '" placeholder="Opcional — gerado do paciente + especialidade"></label></div>';

    echo '<div class="col6"><div class="dfRow"><label>Cliente (contratante)<select name="client_id" id="df_client_id">';
    echo '<option value="">— Selecione —</option>';
    foreach ($clients as $cl) {
        $sel = (int)$cl['id'] === (int)($d['client_id'] ?? 0) ? ' selected' : '';
        echo '<option value="' . (int)$cl['id'] . '"' . $sel . '>' . h((string)$cl['name']) . '</option>';
    }
    echo '</select></label>';
    if ($canQuick) {
        echo '<button type="button" class="btn dfPlus" data-qr="client" title="Cadastrar cliente rapidamente">+</button>';
    }
    echo '</div></div>';

    echo '<div class="col6"><div class="dfRow"><label>Operadora / Convênio<select name="health_insurer_id" id="df_health_insurer_id">';
    echo '<option value="">— Selecione —</option>';
    foreach ($operators as $op) {
        $label = (string)$op['name'] . (!empty($op['client_name']) ? ' (' . (string)$op['client_name'] . ')' : '');
        $sel = (int)$op['id'] === (int)($d['health_insurer_id'] ?? 0) ? ' selected' : '';
        echo '<option value="' . (int)$op['id'] . '"' . $sel . '>' . h($label) . '</option>';
    }
    echo '</select></label>';
    if ($canQuick) {
        echo '<button type="button" class="btn dfPlus" data-qr="health_insurer" title="Cadastrar operadora rapidamente">+</button>';
    }
    echo '</div></div>';
    echo '<div class="col6"><label>E-mail de origem (operadora/cliente)<input type="email" name="origin_email" maxlength="190" value="' . h($v('origin_email')) . '" placeholder="origem@operadora.com.br"></label></div>';
    echo '</div></div>';

    // ------------------------------------------------------------- Serviço
    echo '<div class="dfSection"><h3>Atendimento</h3><div class="grid">';
    $curSpec = $v('specialty');
    echo '<div class="col6"' . $miss('specialty') . '><div class="dfRow"><label>Especialidade *<select name="specialty" id="df_specialty" required>';
    echo '<option value="">Selecione...</option>';
    $found = false;
    foreach ($specialties as $spec) {
        $name = (string)$spec['name'];
        $sel = $name === $curSpec ? ' selected' : '';
        $found = $found || $sel !== '';
        echo '<option value="' . h($name) . '"' . $sel . '>' . h($name) . '</option>';
    }
    if (!$found && $curSpec !== '') {
        // Especialidade vinda do e-mail que não está no cadastro: mantém selecionada.
        echo '<option value="' . h($curSpec) . '" selected>' . h($curSpec) . ' (não cadastrada)</option>';
    }
    echo '</select></label>';
    if ($canQuick) {
        echo '<button type="button" class="btn dfPlus" data-qr="specialty" title="Cadastrar especialidade rapidamente">+</button>';
    }
    echo '</div></div>';

    $curFreq = $v('frequency');
    echo '<div class="col6"' . $miss('frequency') . '><label>Frequência<select name="frequency">';
    echo '<option value="">Selecione...</option>';
    $foundF = false;
    foreach ($freqOpts as $fo) {
        $sel = (string)$fo['code'] === $curFreq ? ' selected' : '';
        $foundF = $foundF || $sel !== '';
        echo '<option value="' . h((string)$fo['code']) . '"' . $sel . '>' . h((string)$fo['label']) . ' — ' . h((string)$fo['description']) . '</option>';
    }
    if (!$foundF && $curFreq !== '') {
        echo '<option value="' . h($curFreq) . '" selected>' . h($curFreq) . '</option>';
    }
    echo '</select></label></div>';

    $curUrg = $v('urgency');
    echo '<div class="col6"><label>Urgência<select name="urgency">';
    foreach (['' => 'Normal', 'urgente' => 'Urgente', 'baixa' => 'Baixa'] as $k => $lbl) {
        $sel = ($curUrg === $k || ($k === '' && $curUrg === 'normal')) ? ' selected' : '';
        echo '<option value="' . h($k) . '"' . $sel . '>' . h($lbl) . '</option>';
    }
    echo '</select></label></div>';
    $pv = isset($d['procedure_value']) && $d['procedure_value'] !== null && $d['procedure_value'] !== '' ? number_format((float)$d['procedure_value'], 2, ',', '.') : '';
    echo '<div class="col6"><label>Valor do procedimento (R$) — não vai para o grupo<input name="procedure_value" inputmode="decimal" value="' . h($pv) . '" placeholder="Ex: 150,00"></label></div>';
    echo '</div></div>';

    // ----------------------------------------------------------- Endereço
    $zoneCities = [];
    foreach (array_keys(location_zone_map()) as $key) {
        [$c, $uf] = explode('|', $key);
        $zoneCities[] = ['city' => $c, 'state' => $uf];
    }
    echo '<div class="dfSection"><h3>Local do atendimento</h3>';
    echo '<div class="dfHint">Cidade e UF definem o grupo de WhatsApp. Em São Paulo e Rio de Janeiro o grupo também é separado por zona (deduzida do bairro).</div>';
    echo '<div class="grid">';
    echo '<div class="col3"><label>CEP<input name="location_zip" id="df_zip" maxlength="9" inputmode="numeric" value="' . h($v('location_zip')) . '" placeholder="00000-000"></label></div>';
    echo '<div class="col6"' . $miss('location_street') . '><label>Rua<input name="location_street" id="df_street" maxlength="200" value="' . h($v('location_street')) . '"></label></div>';
    echo '<div class="col3"><label>Número<input name="location_number" maxlength="20" value="' . h($v('location_number')) . '"></label></div>';
    echo '<div class="col4"><label>Complemento<input name="location_complement" maxlength="120" value="' . h($v('location_complement')) . '"></label></div>';
    echo '<div class="col4"' . $miss('location_neighborhood') . '><label>Bairro<input name="location_neighborhood" id="df_neighborhood" maxlength="120" value="' . h($v('location_neighborhood')) . '"></label></div>';
    echo '<div class="col4"' . $miss('location_city') . '><label>Cidade *<input name="location_city" id="df_city" required maxlength="120" value="' . h($v('location_city')) . '" placeholder="Ex: Mirassol"></label></div>';
    echo '<div class="col3"' . $miss('location_state') . '><label>UF *<input name="location_state" id="df_state" required maxlength="2" value="' . h($v('location_state')) . '" placeholder="SP" style="text-transform:uppercase"></label></div>';
    echo '<div class="col4" id="df_zone_wrap"' . $miss('location_zone') . '><label>Zona<select name="location_zone" id="df_zone"><option value="">Automática (pelo bairro)</option>';
    foreach (['Centro', 'Zona Norte', 'Zona Sul', 'Zona Leste', 'Zona Oeste'] as $z) {
        $sel = $v('location_zone') === $z ? ' selected' : '';
        echo '<option value="' . h($z) . '"' . $sel . '>' . h($z) . '</option>';
    }
    echo '</select></label><div class="dfHint" id="df_zone_hint"></div></div>';
    echo '</div>';
    if ($v('location_raw') !== '') {
        echo '<div class="dfHint"><strong>Endereço como veio no e-mail:</strong> ' . h($v('location_raw')) . '</div>';
    }
    echo '</div>';

    // ------------------------------------------------------- Mensagem
    echo '<div class="dfSection"><h3>Informações para a mensagem de captação</h3>';
    echo '<div' . $miss('ai_summary') . '><label>Quadro clínico (vai na mensagem do grupo, sem nome do paciente)<textarea name="ai_summary" rows="4" placeholder="Ex.: Paciente 72 anos, pós-AVC, necessita reabilitação motora...">' . h($v('ai_summary')) . '</textarea></label></div>';
    echo '<label>Observação para os profissionais (vai na mensagem)<textarea name="captation_note" rows="2" placeholder="Ex.: preferência por atendimento pela manhã">' . h($v('captation_note')) . '</textarea></label>';
    echo '<label>Observações internas (não vão para o grupo)<textarea name="description" rows="4" placeholder="Detalhes, texto original do e-mail...">' . h($v('description')) . '</textarea></label>';
    echo '</div>';

    echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end">';
    echo '<a class="btn" href="' . h((string)($opts['cancel_url'] ?? '/demands_list.php')) . '">Cancelar</a>';
    echo '<button class="btn btnPrimary" type="submit">' . h((string)($opts['submit'] ?? 'Salvar')) . '</button>';
    echo '</div>';
    echo '</form>';

    // ------------------------------------------- Modal de cadastro rápido
    if ($canQuick) {
        echo '<div id="qrModal"><section class="card">';
        echo '<div style="display:flex;justify-content:space-between;align-items:center"><strong id="qrTitle">Cadastro rápido</strong><button type="button" class="btn" id="qrClose">Fechar</button></div>';
        echo '<div style="height:10px"></div>';
        echo '<div style="display:grid;gap:10px">';
        echo '<label>Nome *<input id="qrName" maxlength="200"></label>';
        echo '<label data-qr-only="client health_insurer">E-mail de contato<input id="qrEmail" type="email" maxlength="190"></label>';
        echo '<label data-qr-only="client health_insurer">Domínio de e-mail (para reconhecer os e-mails automaticamente)<input id="qrDomain" maxlength="190" placeholder="operadora.com.br"></label>';
        echo '<label data-qr-only="health_insurer">Cliente (contratante)<select id="qrClient"><option value="">— Nenhum —</option>';
        foreach ($clients as $cl) {
            echo '<option value="' . (int)$cl['id'] . '">' . h((string)$cl['name']) . '</option>';
        }
        echo '</select></label>';
        echo '<div id="qrMsg" class="dfHint"></div>';
        echo '<div style="display:flex;justify-content:flex-end"><button type="button" class="btn btnPrimary" id="qrSave">Cadastrar e selecionar</button></div>';
        echo '</div></section></div>';
    }

    $jsCfg = json_encode(['zoneCities' => $zoneCities], JSON_UNESCAPED_UNICODE);
    ?>
<script>
(function(){
  var cfg = <?= $jsCfg ?>;
  var form = document.getElementById('demandForm');
  function norm(s){ return String(s||'').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/[^a-z0-9 ]+/g,' ').replace(/\s+/g,' ').trim(); }
  var cityEl = document.getElementById('df_city'), stEl = document.getElementById('df_state'),
      nbEl = document.getElementById('df_neighborhood'), zoneWrap = document.getElementById('df_zone_wrap'),
      zoneEl = document.getElementById('df_zone'), zoneHint = document.getElementById('df_zone_hint');

  function cityHasZones(){
    var c = norm(cityEl.value), uf = String(stEl.value||'').toUpperCase().trim();
    return cfg.zoneCities.some(function(z){ return z.city === c && z.state === uf; });
  }
  var zoneTimer = null;
  function refreshZone(){
    var has = cityHasZones();
    zoneWrap.style.display = has ? '' : 'none';
    if (!has) { zoneEl.value = ''; zoneHint.textContent = ''; return; }
    clearTimeout(zoneTimer);
    zoneTimer = setTimeout(function(){
      var q = new URLSearchParams({city: cityEl.value, state: stEl.value, neighborhood: nbEl.value});
      fetch('/location_zone_lookup.php?' + q.toString(), {headers:{'X-Requested-With':'XMLHttpRequest'}})
        .then(function(r){ return r.json(); })
        .then(function(j){
          if (j && j.zone) { zoneHint.textContent = 'Bairro reconhecido: ' + j.zone + (zoneEl.value && zoneEl.value !== j.zone ? ' (você escolheu outra zona)' : ''); if (!zoneEl.value) zoneEl.value = j.zone; }
          else { zoneHint.textContent = nbEl.value ? 'Bairro não reconhecido — escolha a zona manualmente.' : 'Informe o bairro ou escolha a zona.'; }
        }).catch(function(){});
    }, 350);
  }
  [cityEl, stEl, nbEl].forEach(function(el){ el.addEventListener('input', refreshZone); el.addEventListener('change', refreshZone); });
  refreshZone();

  // CEP -> endereço (ViaCEP). Só preenche campos vazios.
  var zipEl = document.getElementById('df_zip');
  zipEl.addEventListener('blur', function(){
    var cep = zipEl.value.replace(/\D/g,'');
    if (cep.length !== 8) return;
    fetch('https://viacep.com.br/ws/' + cep + '/json/').then(function(r){ return r.json(); }).then(function(j){
      if (!j || j.erro) return;
      var set = function(id, val){ var el = document.getElementById(id); if (el && !el.value && val) el.value = val; };
      set('df_street', j.logradouro); set('df_neighborhood', j.bairro); set('df_city', j.localidade); set('df_state', j.uf);
      refreshZone();
    }).catch(function(){});
  });

  // Preencher com IA.
  var aiBtn = document.getElementById('dfAiBtn'), aiMsg = document.getElementById('dfAiMsg');
  aiBtn.addEventListener('click', function(){
    var txt = document.getElementById('dfAiText').value.trim();
    if (txt.length < 20) { aiMsg.textContent = 'Cole o texto da solicitação (mínimo 20 caracteres).'; return; }
    aiBtn.disabled = true; aiMsg.textContent = 'Lendo o texto…';
    var fd = new FormData(); fd.append('text', txt);
    fetch('/demands_ai_parse_post.php', {method:'POST', body: fd, headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(function(r){ return r.json(); })
      .then(function(j){
        aiBtn.disabled = false;
        if (!j || !j.ok) { aiMsg.textContent = (j && j.error) ? j.error : 'Não foi possível ler o texto.'; return; }
        var filled = 0;
        Object.keys(j.fields || {}).forEach(function(k){
          var val = j.fields[k]; if (val === null || val === '') return;
          var el = form.querySelector('[name="' + k + '"]'); if (!el) return;
          if (el.tagName === 'SELECT') {
            var opt = Array.prototype.find.call(el.options, function(o){ return norm(o.value) === norm(val) || norm(o.text) === norm(val); });
            if (!opt) { opt = new Option(val + ' (não cadastrada)', val); el.add(opt); }
            el.value = opt.value;
          } else { el.value = val; }
          filled++;
        });
        var desc = form.querySelector('[name="description"]'); if (desc && !desc.value) desc.value = txt;
        refreshZone();
        aiMsg.textContent = filled + ' campo(s) preenchido(s). Confira antes de salvar.';
      })
      .catch(function(){ aiBtn.disabled = false; aiMsg.textContent = 'Falha de conexão com a IA.'; });
  });

  // Cadastro rápido (+) sem sair da tela.
  var modal = document.getElementById('qrModal');
  if (modal) {
    var qrType = null;
    var titles = {client:'Novo cliente (contratante)', health_insurer:'Nova operadora', specialty:'Nova especialidade'};
    var selects = {client:'df_client_id', health_insurer:'df_health_insurer_id', specialty:'df_specialty'};
    document.querySelectorAll('[data-qr]').forEach(function(btn){
      btn.addEventListener('click', function(){
        qrType = btn.getAttribute('data-qr');
        document.getElementById('qrTitle').textContent = titles[qrType];
        modal.querySelectorAll('[data-qr-only]').forEach(function(el){ el.style.display = el.getAttribute('data-qr-only').split(' ').indexOf(qrType) >= 0 ? '' : 'none'; });
        ['qrName','qrEmail','qrDomain'].forEach(function(id){ document.getElementById(id).value = ''; });
        document.getElementById('qrMsg').textContent = '';
        modal.style.display = 'flex';
        document.getElementById('qrName').focus();
      });
    });
    document.getElementById('qrClose').addEventListener('click', function(){ modal.style.display = 'none'; });
    document.getElementById('qrSave').addEventListener('click', function(){
      var fd = new FormData();
      fd.append('type', qrType);
      fd.append('name', document.getElementById('qrName').value);
      fd.append('contact_email', document.getElementById('qrEmail').value);
      fd.append('email_domain', document.getElementById('qrDomain').value);
      fd.append('client_id', document.getElementById('qrClient').value);
      var msg = document.getElementById('qrMsg'); msg.textContent = 'Salvando…';
      fetch('/quick_register_post.php', {method:'POST', body: fd, headers:{'X-Requested-With':'XMLHttpRequest'}})
        .then(function(r){ return r.json(); })
        .then(function(j){
          if (!j || !j.ok) { msg.textContent = (j && j.error) ? j.error : 'Erro ao cadastrar.'; return; }
          var sel = document.getElementById(selects[qrType]);
          var value = qrType === 'specialty' ? j.name : String(j.id);
          var opt = Array.prototype.find.call(sel.options, function(o){ return o.value === value; });
          if (!opt) { opt = new Option(j.name, value); sel.add(opt); }
          sel.value = value;
          if (qrType === 'client') {
            var qc = document.getElementById('qrClient');
            if (!Array.prototype.some.call(qc.options, function(o){ return o.value === String(j.id); })) qc.add(new Option(j.name, String(j.id)));
          }
          modal.style.display = 'none';
        })
        .catch(function(){ msg.textContent = 'Falha de conexão.'; });
    });
  }
})();
</script>
<?php
}

/** Quem pode usar o cadastro rápido (+) no card. */
function quick_register_allowed(): bool
{
    $uid = auth_user_id();
    if ($uid === null) {
        return false;
    }
    $perms = rbac_user_permissions($uid);
    return (bool)array_intersect(['admin.settings.manage', 'cadastros.manage', 'demands.manage'], $perms);
}

<?php

declare(strict_types=1);

/**
 * Controle de cadastros pendentes de profissionais.
 *
 * Acompanha o fluxo de PRÉ-CADASTRO (cadastro rápido) e do link de atualização
 * cadastral enviado ao profissional. Permite à equipe identificar rapidamente:
 *   - Profissionais com pré-cadastro/cadastro rápido;
 *   - Quem ainda NÃO completou o cadastro (is_pre_registration = 1);
 *   - Quem já completou o cadastro (is_pre_registration = 0 + candidatura vinculada).
 *
 * Integra com:
 *   - professional_quick_create_post.php (cria o pré-cadastro)
 *   - complete_registration.php / _post.php (profissional completa via link)
 *   - pre_admissao_approve.php (gera o registration_token e envia o link)
 */

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('users.manage');

// Garantir colunas de apoio (idempotente) — mesmas usadas pelo fluxo de pré-cadastro.
try { db()->exec("ALTER TABLE users ADD COLUMN is_pre_registration TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
try { db()->exec("ALTER TABLE users ADD COLUMN registration_token VARCHAR(64) NULL"); } catch (Throwable $e) {}
try { db()->exec("ALTER TABLE users ADD COLUMN registration_token_created_at DATETIME NULL"); } catch (Throwable $e) {}
try { db()->exec("ALTER TABLE users ADD COLUMN city VARCHAR(120) NULL"); } catch (Throwable $e) {}

// ------------------------------------------------------------------
// Filtros
// ------------------------------------------------------------------
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
// status: '' (com pré-cadastro), 'pending' (não completou), 'complete' (completou)
$statusFilter = isset($_GET['status']) ? trim((string)$_GET['status']) : 'pending';
if (!in_array($statusFilter, ['', 'pending', 'complete'], true)) {
    $statusFilter = 'pending';
}

$page = isset($_GET['page']) && ctype_digit((string)$_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 25;
$offset = ($page - 1) * $perPage;

// ------------------------------------------------------------------
// Montagem do WHERE
// ------------------------------------------------------------------
// Base: apenas usuários com a role 'profissional'.
$isProfessional = "EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id AND r.slug = 'profissional')";

// "Passou por pré-cadastro" (universo desta tela): quem é/foi pré-cadastro
// (marca atual, link já gerado, ou e-mail placeholder de pré-cadastro).
$hadPreRegistration = "(u.is_pre_registration = 1 OR u.registration_token IS NOT NULL OR u.email LIKE '%@precadastro.local')";

$hasCompletedApp = "EXISTS (SELECT 1 FROM professional_applications pa WHERE pa.created_user_id = u.id)";

$where = [$isProfessional];
$params = [];

if ($statusFilter === 'pending') {
    // Ainda não completou.
    $where[] = 'u.is_pre_registration = 1';
} elseif ($statusFilter === 'complete') {
    // Já completou: deixou de ser pré-cadastro E possui candidatura vinculada.
    $where[] = 'u.is_pre_registration = 0';
    $where[] = $hasCompletedApp;
} else {
    // Todos que passaram por pré-cadastro (pendentes + completados).
    $where[] = '(' . $hadPreRegistration . ' OR ' . $hasCompletedApp . ')';
}

if ($q !== '') {
    $where[] = '(u.name LIKE :q1 OR u.email LIKE :q2 OR u.phone LIKE :q3)';
    $qLike = '%' . $q . '%';
    $params['q1'] = $qLike;
    $params['q2'] = $qLike;
    $params['q3'] = $qLike;
}

$whereSql = ' WHERE ' . implode(' AND ', $where);

// ------------------------------------------------------------------
// KPIs (contadores dos 3 estados) — independentes do filtro de status,
// mas respeitam a busca textual.
// ------------------------------------------------------------------
$kpiWhere = [$isProfessional];
$kpiParams = [];
if ($q !== '') {
    $kpiWhere[] = '(u.name LIKE :q1 OR u.email LIKE :q2 OR u.phone LIKE :q3)';
    $kpiParams['q1'] = '%' . $q . '%';
    $kpiParams['q2'] = '%' . $q . '%';
    $kpiParams['q3'] = '%' . $q . '%';
}
$kpiWhereSql = ' WHERE ' . implode(' AND ', $kpiWhere);

$kpiSql = "SELECT
    SUM(CASE WHEN ({$hadPreRegistration}) OR ({$hasCompletedApp}) THEN 1 ELSE 0 END) AS total_pre,
    SUM(CASE WHEN u.is_pre_registration = 1 THEN 1 ELSE 0 END) AS total_pending,
    SUM(CASE WHEN u.is_pre_registration = 0 AND ({$hasCompletedApp}) THEN 1 ELSE 0 END) AS total_complete
    FROM users u" . $kpiWhereSql;
$kpiStmt = db()->prepare($kpiSql);
$kpiStmt->execute($kpiParams);
$kpi = $kpiStmt->fetch(PDO::FETCH_ASSOC) ?: ['total_pre' => 0, 'total_pending' => 0, 'total_complete' => 0];
$totalPre = (int)($kpi['total_pre'] ?? 0);
$totalPending = (int)($kpi['total_pending'] ?? 0);
$totalComplete = (int)($kpi['total_complete'] ?? 0);

// ------------------------------------------------------------------
// Total para paginação
// ------------------------------------------------------------------
$countSql = 'SELECT COUNT(*) FROM users u' . $whereSql;
$countStmt = db()->prepare($countSql);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

// ------------------------------------------------------------------
// Query paginada
// ------------------------------------------------------------------
$sql = 'SELECT u.id, u.name, u.email, u.phone, u.specialty, u.city, u.created_at,
               u.is_pre_registration, u.registration_token, u.registration_token_created_at,
               (' . $hasCompletedApp . ') AS has_application
        FROM users u' . $whereSql . '
        ORDER BY u.is_pre_registration DESC, u.created_at DESC
        LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset;
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Base URL pública para montar o link de atualização cadastral.
$publicBaseUrl = trim((string)admin_setting_get('app.public_base_url', ''));
if ($publicBaseUrl === '') {
    $publicBaseUrl = trim((string)admin_setting_get('app.base_url', ''));
}
if ($publicBaseUrl === '') {
    $publicBaseUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'multilife.onsolutionsbrasil.com.br');
}
$publicBaseUrl = rtrim($publicBaseUrl, '/');

// Helper de URL preservando filtros na paginação.
$buildPageUrl = function (int $p) use ($q, $statusFilter): string {
    $qs = array_filter([
        'q' => $q, 'status' => $statusFilter, 'page' => $p,
    ], fn($v) => $v !== '' && $v !== null);
    return '/professional_pre_registrations_list.php?' . http_build_query($qs);
};

// Detecta e-mail placeholder de pré-cadastro (sem e-mail real informado).
$isPlaceholderEmail = function (string $email): bool {
    return str_ends_with(strtolower($email), '@precadastro.local');
};

view_header('Cadastros Pendentes');

echo '<div class="grid">';

// ---- Cabeçalho ---------------------------------------------------
echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Cadastros pendentes de profissionais</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.5">Acompanhe quem tem pré-cadastro e ainda não completou os dados enviados pelo link de atualização cadastral.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/users_list.php?role=profissional">Todos os profissionais</a>';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';
echo '</section>';

// ---- KPIs (também funcionam como filtros rápidos) ----------------
$kpiCard = function (string $label, int $value, string $statusValue, string $accent) use ($statusFilter, $q): void {
    $active = ($statusFilter === $statusValue);
    $qs = array_filter(['q' => $q, 'status' => $statusValue], fn($v) => $v !== '' && $v !== null);
    $href = '/professional_pre_registrations_list.php?' . http_build_query($qs);
    $border = $active ? 'border:2px solid hsl(' . $accent . ')' : 'border:1px solid hsl(var(--border))';
    echo '<a class="card col4" href="' . h($href) . '" style="text-decoration:none;' . $border . ';display:block">';
    echo '<div style="font-size:13px;font-weight:700;color:hsl(var(--muted-foreground))">' . h($label) . '</div>';
    echo '<div style="font-size:30px;font-weight:900;margin-top:6px;color:hsl(' . $accent . ')">' . number_format($value, 0, ',', '.') . '</div>';
    echo '</a>';
};
$kpiCard('Com pré-cadastro', $totalPre, '', 'var(--info)');
$kpiCard('Aguardando cadastro', $totalPending, 'pending', 'var(--warning)');
$kpiCard('Cadastro completo', $totalComplete, 'complete', 'var(--success)');

// ---- Filtros -----------------------------------------------------
echo '<section class="card col12">';
echo '<form method="get" action="/professional_pre_registrations_list.php" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">';
echo '<input name="q" value="' . h($q) . '" placeholder="Buscar por nome, e-mail ou telefone" style="flex:1;min-width:220px">';
echo '<select name="status" style="min-width:200px">';
echo '<option value=""' . ($statusFilter === '' ? ' selected' : '') . '>Todos (com pré-cadastro)</option>';
echo '<option value="pending"' . ($statusFilter === 'pending' ? ' selected' : '') . '>Aguardando cadastro</option>';
echo '<option value="complete"' . ($statusFilter === 'complete' ? ' selected' : '') . '>Cadastro completo</option>';
echo '</select>';
echo '<button class="btn btnPrimary" type="submit">Filtrar</button>';
if ($q !== '' || $statusFilter !== 'pending') {
    echo '<a class="btn" href="/professional_pre_registrations_list.php">Limpar</a>';
}
echo '</form>';
echo '<div style="margin-top:8px;font-size:13px;color:hsl(var(--muted-foreground))">' . number_format($totalRows, 0, ',', '.') . ' profissional(is) encontrado(s)</div>';
echo '</section>';

// ---- Tabela ------------------------------------------------------
echo '<section class="card col12">';
echo '<div style="overflow:auto">';
echo '<table>';
echo '<thead><tr>';
echo '<th>ID</th><th>Nome</th><th>Contato</th><th>Especialidade</th><th>Cidade</th><th>Situação</th><th>Pré-cadastro em</th><th style="text-align:right">Ações</th>';
echo '</tr></thead>';
echo '<tbody>';

if (empty($rows)) {
    echo '<tr><td colspan="8" style="text-align:center;padding:32px;color:hsl(var(--muted-foreground))">Nenhum cadastro encontrado com os filtros atuais.</td></tr>';
}

foreach ($rows as $r) {
    $userId = (int)$r['id'];
    $isPending = (int)$r['is_pre_registration'] === 1;
    $hasApp = (int)$r['has_application'] === 1;
    $token = (string)($r['registration_token'] ?? '');
    $hasActiveLink = $token !== '' && strlen($token) >= 32;
    $email = (string)($r['email'] ?? '');
    $showEmail = ($email !== '' && !$isPlaceholderEmail($email)) ? $email : '';

    // Badge de situação.
    if ($isPending) {
        $badgeColor = 'var(--warning)';
        $badgeText = 'Aguardando cadastro';
    } elseif ($hasApp) {
        $badgeColor = 'var(--success)';
        $badgeText = 'Cadastro completo';
    } else {
        $badgeColor = 'var(--muted-foreground)';
        $badgeText = 'Pré-cadastro';
    }
    $badge = '<span style="display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:700;color:#fff;background:hsl(' . $badgeColor . ')">' . h($badgeText) . '</span>';

    echo '<tr>';
    echo '<td>' . $userId . '</td>';
    echo '<td style="font-weight:700">' . h((string)$r['name']) . '</td>';
    echo '<td>';
    echo $showEmail !== '' ? h($showEmail) : '<span style="color:hsl(var(--muted-foreground))">sem e-mail</span>';
    if (!empty($r['phone'])) {
        echo '<div style="font-size:12px;color:hsl(var(--muted-foreground))">' . h((string)$r['phone']) . '</div>';
    }
    echo '</td>';
    echo '<td>' . h((string)($r['specialty'] ?? '') ?: '-') . '</td>';
    echo '<td>' . h((string)($r['city'] ?? '') ?: '-') . '</td>';
    echo '<td>' . $badge;
    if ($isPending && $hasActiveLink) {
        echo '<div style="font-size:11px;color:hsl(var(--success));margin-top:4px">● link ativo</div>';
    } elseif ($isPending && !$hasActiveLink) {
        echo '<div style="font-size:11px;color:hsl(var(--muted-foreground));margin-top:4px">sem link gerado</div>';
    }
    echo '</td>';
    echo '<td>' . h((string)($r['created_at'] ?? '') ?: '-') . '</td>';
    echo '<td style="text-align:right;white-space:nowrap">';

    if ($isPending) {
        // Enviar o link por e-mail + WhatsApp (gera o token se ainda não existir).
        $emailReal = ($email !== '' && !$isPlaceholderEmail($email));
        $canSend = $emailReal || !empty($r['phone']);
        echo '<form method="post" action="/professional_registration_link_send_post.php" style="display:inline">';
        echo '<input type="hidden" name="user_id" value="' . $userId . '">';
        $sendAttrs = $canSend
            ? ' onclick="return confirm(\'Enviar o link de atualização cadastral por e-mail e WhatsApp para este profissional?\')"'
            : ' disabled title="Cadastre e-mail ou telefone para enviar" style="opacity:.5;cursor:not-allowed"';
        echo '<button class="btn btnPrimary" type="submit"' . $sendAttrs . '>Enviar link</button>';
        echo '</form> ';

        // Botão para gerar/renovar (copiar) o link de atualização cadastral.
        $linkUrl = $hasActiveLink ? ($publicBaseUrl . '/atualizar-cadastro?token=' . urlencode($token)) : '';
        echo '<form method="post" action="/professional_registration_link_post.php" style="display:inline">';
        echo '<input type="hidden" name="user_id" value="' . $userId . '">';
        echo '<button class="btn" type="submit">' . ($hasActiveLink ? 'Renovar link' : 'Gerar link') . '</button>';
        echo '</form> ';
        if ($linkUrl !== '') {
            echo '<button class="btn js-copy-link" type="button" data-link="' . h($linkUrl) . '">Copiar link</button> ';
        }
    }
    echo '<a class="btn" href="/users_edit.php?id=' . $userId . '">Editar</a>';
    echo '</td>';
    echo '</tr>';
}

echo '</tbody>';
echo '</table>';
echo '</div>';

// ---- Paginação ---------------------------------------------------
if ($totalPages > 1) {
    echo '<div style="display:flex;align-items:center;justify-content:center;gap:8px;margin-top:16px;flex-wrap:wrap">';
    if ($page > 1) {
        echo '<a class="btn" href="' . h($buildPageUrl($page - 1)) . '">← Anterior</a>';
    }
    $start = max(1, $page - 2);
    $end = min($totalPages, $page + 2);
    if ($start > 1) { echo '<a class="btn" href="' . h($buildPageUrl(1)) . '">1</a>'; if ($start > 2) echo '<span style="color:hsl(var(--muted-foreground))">…</span>'; }
    for ($i = $start; $i <= $end; $i++) {
        if ($i === $page) {
            echo '<span class="btn btnPrimary" style="pointer-events:none">' . $i . '</span>';
        } else {
            echo '<a class="btn" href="' . h($buildPageUrl($i)) . '">' . $i . '</a>';
        }
    }
    if ($end < $totalPages) { if ($end < $totalPages - 1) echo '<span style="color:hsl(var(--muted-foreground))">…</span>'; echo '<a class="btn" href="' . h($buildPageUrl($totalPages)) . '">' . $totalPages . '</a>'; }
    if ($page < $totalPages) {
        echo '<a class="btn" href="' . h($buildPageUrl($page + 1)) . '">Próxima →</a>';
    }
    echo '</div>';
    echo '<div style="text-align:center;margin-top:8px;font-size:13px;color:hsl(var(--muted-foreground))">Página ' . $page . ' de ' . $totalPages . '</div>';
}

echo '</section>';
echo '</div>';

// Copiar link para a área de transferência (delegação de eventos).
echo '<script>document.addEventListener("click",function(e){'
    . 'var b=e.target.closest(".js-copy-link");if(!b)return;'
    . 'var url=b.getAttribute("data-link")||"";'
    . 'var done=function(){var t=b.textContent;b.textContent="Copiado!";setTimeout(function(){b.textContent=t;},1500);};'
    . 'if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(url).then(done).catch(function(){window.prompt("Copie o link:",url);});}'
    . 'else{window.prompt("Copie o link:",url);}'
    . '});</script>';

view_footer();

<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('whatsapp_groups.manage');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = db()->prepare('SELECT * FROM whatsapp_groups WHERE id = :id');
$stmt->execute(['id' => $id]);
$g = $stmt->fetch();

if (!$g) {
    flash_set('error', 'Grupo não encontrado.');
    header('Location: /whatsapp_groups_list.php');
    exit;
}

$jid = trim((string)($g['evolution_group_jid'] ?? ''));
if ($jid === '') {
    flash_set('error', 'Grupo sem Evolution Group JID configurado.');
    header('Location: /whatsapp_groups_edit.php?id=' . $id);
    exit;
}

$api = null;
try {
    $api = new EvolutionApiV1();
} catch (Throwable $e) {
    flash_set('error', 'Evolution API não configurada: ' . mb_strimwidth($e->getMessage(), 0, 220, ''));
    header('Location: /whatsapp_groups_edit.php?id=' . $id);
    exit;
}

$res = $api->findGroupMembers($jid);
$json = $res['json'] ?? null;
$data = null;
if (is_array($json)) {
    $data = $json['participants'] ?? ($json['data'] ?? $json);
}

$memberPhones = [];
if (is_array($data)) {
    foreach ($data as $p) {
        if (!is_array($p)) {
            continue;
        }
        $raw = (string)($p['id'] ?? ($p['jid'] ?? ($p['participant'] ?? ($p['phone'] ?? ''))));
        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === '') {
            continue;
        }
        if (!in_array($digits, $memberPhones, true)) {
            $memberPhones[] = $digits;
        }
    }
}

$stmt = db()->prepare(
    "SELECT u.id, u.name, u.email, u.phone\n"
    . "FROM users u\n"
    . "INNER JOIN user_roles ur ON ur.user_id = u.id\n"
    . "INNER JOIN roles r ON r.id = ur.role_id\n"
    . "WHERE u.status = 'active' AND r.slug = 'profissional'\n"
    . "ORDER BY u.name ASC"
);
$stmt->execute();
$professionals = $stmt->fetchAll();

$byPhone = [];
foreach ($professionals as $p) {
    $ph = preg_replace('/\D+/', '', (string)($p['phone'] ?? ''));
    if ($ph === '') {
        continue;
    }
    $byPhone[$ph] = $p;
}

$currentPros = [];
$unknownPhones = [];
foreach ($memberPhones as $ph) {
    if (isset($byPhone[$ph])) {
        $currentPros[] = $byPhone[$ph];
    } else {
        $unknownPhones[] = $ph;
    }
}

$currentIds = [];
foreach ($currentPros as $p) {
    $currentIds[] = (int)$p['id'];
}

$availablePros = [];
foreach ($professionals as $p) {
    $pid = (int)$p['id'];
    if (in_array($pid, $currentIds, true)) {
        continue;
    }
    $availablePros[] = $p;
}

view_header('Membros do grupo (Evolution)');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Membros (Evolution)</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Grupo: ' . h((string)$g['name']) . ' (#' . (int)$g['id'] . ')</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/whatsapp_groups_edit.php?id=' . (int)$g['id'] . '">Voltar</a>';
echo '</div>';
echo '</div>';
echo '</section>';

// Adicionar

echo '<section class="card col12">';
echo '<div style="font-weight:900;margin-bottom:10px">Adicionar profissionais ao grupo</div>';
if (count($availablePros) === 0) {
    echo '<div class="pill" style="display:block">Nenhum profissional disponível para adicionar.</div>';
} else {
    echo '<form method="post" action="/whatsapp_groups_members_evolution_post.php" style="display:grid;gap:10px;max-width:860px">';
    echo '<input type="hidden" name="id" value="' . (int)$g['id'] . '">';
    echo '<input type="hidden" name="action" value="add">';
    echo '<label>Profissionais<select name="professional_user_ids[]" multiple required size="10">';
    foreach ($availablePros as $p) {
        $label = (string)$p['name'] . ' — ' . (string)$p['email'];
        $phone = trim((string)($p['phone'] ?? ''));
        if ($phone !== '') {
            $label .= ' — ' . $phone;
        } else {
            $label .= ' — (sem telefone)';
        }
        echo '<option value="' . (int)$p['id'] . '">' . h($label) . '</option>';
    }
    echo '</select></label>';
    echo '<button class="btn btnPrimary" type="submit">Adicionar</button>';
    echo '</form>';
}

echo '</section>';

// Remover

echo '<section class="card col12">';
echo '<div style="font-weight:900;margin-bottom:10px">Remover profissionais do grupo</div>';
if (count($currentPros) === 0) {
    echo '<div class="pill" style="display:block">Nenhum profissional cadastrado do sistema encontrado no grupo.</div>';
} else {
    echo '<form method="post" action="/whatsapp_groups_members_evolution_post.php" style="display:grid;gap:10px;max-width:860px">';
    echo '<input type="hidden" name="id" value="' . (int)$g['id'] . '">';
    echo '<input type="hidden" name="action" value="remove">';
    echo '<label>Profissionais<select name="professional_user_ids[]" multiple required size="10">';
    foreach ($currentPros as $p) {
        $label = (string)$p['name'] . ' — ' . (string)$p['email'];
        $phone = trim((string)($p['phone'] ?? ''));
        if ($phone !== '') {
            $label .= ' — ' . $phone;
        }
        echo '<option value="' . (int)$p['id'] . '">' . h($label) . '</option>';
    }
    echo '</select></label>';
    echo '<button class="btn" type="submit" onclick="return confirm(\'Remover os profissionais selecionados do grupo?\')">Remover</button>';
    echo '</form>';
}

if (count($unknownPhones) > 0) {
    echo '<div style="height:10px"></div>';
    echo '<div class="pill" style="display:block"><strong>Membros não cadastrados no sistema:</strong><br>' . h(implode(', ', $unknownPhones)) . '</div>';
}

echo '</section>';

echo '</div>';

view_footer();

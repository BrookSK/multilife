<?php

declare(strict_types=1);

/**
 * Cadastro de POLOS.
 *
 * Hierarquia: CLIENTE (ex.: GLOBAL) -> POLO (ex.: Rio Preto) -> OPERADORA (ex.: Unimed).
 * O polo é gravado na demanda (card) e no atendimento; a operadora pode ter um
 * polo padrão, usado para preencher automaticamente.
 */

require_once __DIR__ . '/app/bootstrap.php';
auth_require_login();
rbac_require_any_permission(['admin.settings.manage', 'cadastros.manage']);

$db = db();
clients_ensure_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create' || $action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 255);
        $clientId = (int)($_POST['client_id'] ?? 0);
        $city = mb_substr(trim((string)($_POST['city'] ?? '')), 0, 120);
        $state = strtoupper(mb_substr(trim((string)($_POST['state'] ?? '')), 0, 2));
        $notes = trim((string)($_POST['notes'] ?? ''));

        if ($name === '' || $clientId <= 0 || clients_find($clientId) === null) {
            flash_set('error', 'Informe o nome do polo e o cliente.');
            header('Location: /polos_config.php');
            exit;
        }
        if ($state !== '' && !preg_match('/^[A-Z]{2}$/', $state)) {
            flash_set('error', 'UF inválida (use 2 letras, ex.: SP).');
            header('Location: /polos_config.php');
            exit;
        }

        // Evita polo duplicado no mesmo cliente.
        $dup = $db->prepare('SELECT id FROM polos WHERE client_id = :c AND name = :n AND id <> :id LIMIT 1');
        $dup->execute(['c' => $clientId, 'n' => $name, 'id' => $id]);
        if ($dup->fetchColumn()) {
            flash_set('error', 'Já existe um polo com esse nome neste cliente.');
            header('Location: /polos_config.php');
            exit;
        }

        try {
            if ($action === 'create') {
                $db->prepare('INSERT INTO polos (client_id, name, city, state, notes) VALUES (:c, :n, :city, :st, :notes)')
                    ->execute(['c' => $clientId, 'n' => $name, 'city' => $city ?: null, 'st' => $state ?: null, 'notes' => $notes ?: null]);
                $newId = (int)$db->lastInsertId();
                audit_log('create', 'polos', (string)$newId, null, ['client_id' => $clientId, 'name' => $name]);
                flash_set('success', 'Polo cadastrado!');
            } elseif ($id > 0) {
                $before = polos_find($id);
                $db->prepare('UPDATE polos SET client_id = :c, name = :n, city = :city, state = :st, notes = :notes WHERE id = :id')
                    ->execute(['c' => $clientId, 'n' => $name, 'city' => $city ?: null, 'st' => $state ?: null, 'notes' => $notes ?: null, 'id' => $id]);
                audit_log('update', 'polos', (string)$id, $before, ['client_id' => $clientId, 'name' => $name, 'city' => $city, 'state' => $state]);
                flash_set('success', 'Polo atualizado!');
            }
        } catch (Throwable $e) {
            flash_set('error', 'Erro ao salvar: ' . $e->getMessage());
        }
        header('Location: /polos_config.php');
        exit;
    }

    if ($action === 'toggle_status') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $db->prepare('UPDATE polos SET is_active = NOT is_active WHERE id = :id')->execute(['id' => $id]);
            audit_log('update', 'polos', (string)$id, null, ['toggle_status' => true]);
            flash_set('success', 'Status do polo atualizado!');
        }
        header('Location: /polos_config.php');
        exit;
    }
}

$filterClient = (int)($_GET['client_id'] ?? 0);
$clients = clients_list(false);
$polos = polos_list($filterClient > 0 ? $filterClient : null, false);

// Uso de cada polo (operadoras com ele como padrão + atendimentos).
$opCounts = [];
$paCounts = [];
try {
    foreach ($db->query('SELECT polo_id, COUNT(*) c FROM health_insurers WHERE polo_id IS NOT NULL GROUP BY polo_id') as $r) {
        $opCounts[(int)$r['polo_id']] = (int)$r['c'];
    }
    foreach ($db->query('SELECT polo_id, COUNT(*) c FROM patient_assignments WHERE polo_id IS NOT NULL GROUP BY polo_id') as $r) {
        $paCounts[(int)$r['polo_id']] = (int)$r['c'];
    }
} catch (Throwable $e) {}

$inputStyle = 'width:100%;padding:10px;border:1px solid #d1d7db;border-radius:8px';

view_header('Polos');

echo '<div class="grid">';
echo '<section class="card col12">';
echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Polos</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Unidades/regiões de cada cliente. Ex.: cliente <strong>GLOBAL</strong> → polo <strong>Rio Preto</strong> → operadora <strong>Unimed</strong>.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a href="/clients_config.php" class="btn">Clientes</a>';
echo '<a href="/health_insurers_config.php" class="btn">Operadoras</a>';
echo '<button type="button" onclick="openPoloModal()" class="btn-primary">+ Novo Polo</button>';
echo '<a href="/settings.php" class="btn">← Voltar</a>';
echo '</div>';
echo '</div>';

echo '<form method="get" style="margin-top:14px;display:flex;gap:8px;align-items:center">';
echo '<label style="font-size:13px;font-weight:600">Cliente</label>';
echo '<select name="client_id" onchange="this.form.submit()" style="padding:8px;border:1px solid hsl(var(--border));border-radius:6px"><option value="0">Todos</option>';
foreach ($clients as $cl) {
    $sel = $filterClient === (int)$cl['id'] ? ' selected' : '';
    echo '<option value="' . (int)$cl['id'] . '"' . $sel . '>' . h((string)$cl['name']) . '</option>';
}
echo '</select>';
echo '</form>';
echo '</section>';

echo '<section class="card col12">';
echo '<table class="data-table"><thead><tr><th>Polo</th><th>Cliente</th><th>Cidade/UF</th><th style="text-align:center">Operadoras (padrão)</th><th style="text-align:center">Atendimentos</th><th>Status</th><th style="width:140px">Ações</th></tr></thead><tbody>';
if (empty($polos)) {
    echo '<tr><td colspan="7" style="text-align:center;padding:30px;color:hsl(var(--muted-foreground))">Nenhum polo cadastrado.</td></tr>';
}
foreach ($polos as $po) {
    $pid = (int)$po['id'];
    $cityUf = trim((string)($po['city'] ?? '') . ((string)($po['state'] ?? '') !== '' ? '/' . $po['state'] : ''), '/');
    echo '<tr>';
    echo '<td><strong>' . h((string)$po['name']) . '</strong>' . ((string)($po['notes'] ?? '') !== '' ? '<div style="font-size:12px;color:hsl(var(--muted-foreground))">' . h(mb_strimwidth((string)$po['notes'], 0, 80, '…')) . '</div>' : '') . '</td>';
    echo '<td>' . ($po['client_name'] ? h((string)$po['client_name']) : '<span style="color:hsl(var(--destructive))">Sem cliente</span>') . '</td>';
    echo '<td>' . ($cityUf !== '' ? h($cityUf) : '-') . '</td>';
    echo '<td style="text-align:center">' . (int)($opCounts[$pid] ?? 0) . '</td>';
    echo '<td style="text-align:center">' . (int)($paCounts[$pid] ?? 0) . '</td>';
    echo '<td>' . ((int)$po['is_active'] === 1 ? '<span style="color:hsl(142,76%,36%);font-weight:600">● Ativo</span>' : '<span style="color:hsl(var(--muted-foreground))">○ Inativo</span>') . '</td>';
    echo '<td><div style="display:flex;gap:6px">';
    echo '<button type="button" data-polo="' . h(json_encode([
        'id' => $pid,
        'client_id' => $po['client_id'] !== null ? (int)$po['client_id'] : '',
        'name' => (string)$po['name'],
        'city' => (string)($po['city'] ?? ''),
        'state' => (string)($po['state'] ?? ''),
        'notes' => (string)($po['notes'] ?? ''),
    ], JSON_UNESCAPED_UNICODE)) . '" onclick="editPolo(this)" style="padding:5px 10px;font-size:11px;font-weight:600;background:transparent;color:hsl(var(--primary));border:1px solid hsl(var(--primary));border-radius:6px;cursor:pointer">Editar</button>';
    $active = (int)$po['is_active'] === 1;
    echo '<form method="post" style="display:inline"><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="id" value="' . $pid . '">';
    echo '<button type="submit" style="padding:5px 10px;font-size:11px;font-weight:600;background:transparent;color:' . ($active ? 'hsl(var(--destructive))' : 'hsl(var(--success))') . ';border:1px solid ' . ($active ? 'hsl(var(--destructive))' : 'hsl(var(--success))') . ';border-radius:6px;cursor:pointer">' . ($active ? 'Desativar' : 'Ativar') . '</button></form>';
    echo '</div></td>';
    echo '</tr>';
}
echo '</tbody></table>';
echo '</section>';
echo '</div>';

// Modal criar/editar
echo '<div id="poloModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;padding:20px;overflow-y:auto" onclick="if(event.target===this)closePoloModal()">';
echo '<div style="max-width:560px;margin:40px auto;background:#fff;border-radius:12px;padding:24px">';
echo '<h2 id="poloModalTitle" style="margin:0 0 20px;font-size:20px;font-weight:700">Novo Polo</h2>';
echo '<form method="post" id="poloForm">';
echo '<input type="hidden" name="action" id="poloAction" value="create"><input type="hidden" name="id" id="poloId" value="">';
echo '<div style="margin-bottom:14px"><label style="display:block;margin-bottom:6px;font-weight:600">Cliente *</label>';
echo '<select name="client_id" id="poloClient" required style="' . $inputStyle . '"><option value="">Selecione o cliente…</option>';
foreach ($clients as $cl) {
    echo '<option value="' . (int)$cl['id'] . '"' . ($filterClient === (int)$cl['id'] ? ' selected' : '') . '>' . h((string)$cl['name']) . '</option>';
}
echo '</select></div>';
echo '<div style="margin-bottom:14px"><label style="display:block;margin-bottom:6px;font-weight:600">Nome do polo *</label>';
echo '<input type="text" name="name" id="poloName" required maxlength="255" placeholder="Ex.: Rio Preto" style="' . $inputStyle . '"></div>';
echo '<div style="display:grid;grid-template-columns:3fr 1fr;gap:12px;margin-bottom:14px">';
echo '<div><label style="display:block;margin-bottom:6px;font-weight:600">Cidade</label><input type="text" name="city" id="poloCity" maxlength="120" placeholder="Ex.: São José do Rio Preto" style="' . $inputStyle . '"></div>';
echo '<div><label style="display:block;margin-bottom:6px;font-weight:600">UF</label><input type="text" name="state" id="poloState" maxlength="2" placeholder="SP" style="' . $inputStyle . ';text-transform:uppercase"></div>';
echo '</div>';
echo '<div style="margin-bottom:20px"><label style="display:block;margin-bottom:6px;font-weight:600">Observações</label><textarea name="notes" id="poloNotes" rows="3" style="' . $inputStyle . ';resize:vertical"></textarea></div>';
echo '<div style="display:flex;gap:12px"><button type="button" onclick="closePoloModal()" class="btn" style="flex:1">Cancelar</button><button type="submit" class="btn-primary" style="flex:1">Salvar</button></div>';
echo '</form></div></div>';

echo '<script>
function openPoloModal(){
    document.getElementById("poloModalTitle").textContent = "Novo Polo";
    document.getElementById("poloAction").value = "create";
    document.getElementById("poloId").value = "";
    ["poloName","poloCity","poloState","poloNotes"].forEach(function(id){ document.getElementById(id).value = ""; });
    document.getElementById("poloModal").style.display = "block";
}
function editPolo(btn){
    var p = {};
    try { p = JSON.parse(btn.getAttribute("data-polo")); } catch(e){ return; }
    document.getElementById("poloModalTitle").textContent = "Editar Polo";
    document.getElementById("poloAction").value = "update";
    document.getElementById("poloId").value = p.id;
    document.getElementById("poloClient").value = p.client_id || "";
    document.getElementById("poloName").value = p.name || "";
    document.getElementById("poloCity").value = p.city || "";
    document.getElementById("poloState").value = p.state || "";
    document.getElementById("poloNotes").value = p.notes || "";
    document.getElementById("poloModal").style.display = "block";
}
function closePoloModal(){ document.getElementById("poloModal").style.display = "none"; }
document.addEventListener("keydown", function(e){ if(e.key === "Escape") closePoloModal(); });
</script>';

view_footer();

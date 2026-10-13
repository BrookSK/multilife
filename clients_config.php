<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
auth_require_login();
rbac_require_permission('admin.settings.manage');

$db = db();
clients_ensure_schema();

// Processar ações (create / update / toggle_status)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $cnpj = trim($_POST['cnpj'] ?? '');
        $contactPhone = trim($_POST['contact_phone'] ?? '');
        $contactEmail = trim($_POST['contact_email'] ?? '');
        $billingEmail = trim($_POST['billing_email'] ?? '');
        $emailDomain = trim($_POST['email_domain'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $closingDay = (int)($_POST['closing_day'] ?? 0);
        $closingDay = ($closingDay >= 1 && $closingDay <= 31) ? $closingDay : null;

        if ($name !== '') {
            try {
                if ($action === 'create') {
                    $stmt = $db->prepare("
                        INSERT INTO clients (name, closing_day, cnpj, contact_phone, contact_email, billing_email, email_domain, notes)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$name, $closingDay, $cnpj, $contactPhone, $contactEmail, $billingEmail, $emailDomain, $notes]);
                    $_SESSION['success'] = 'Cliente cadastrado com sucesso!';
                } elseif ($id > 0) {
                    $stmt = $db->prepare("
                        UPDATE clients
                        SET name = ?, closing_day = ?, cnpj = ?, contact_phone = ?, contact_email = ?, billing_email = ?, email_domain = ?, notes = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$name, $closingDay, $cnpj, $contactPhone, $contactEmail, $billingEmail, $emailDomain, $notes, $id]);
                    $_SESSION['success'] = 'Cliente atualizado com sucesso!';
                }
            } catch (PDOException $e) {
                $_SESSION['error'] = 'Erro ao salvar: ' . $e->getMessage();
            }
        }
        header('Location: /clients_config.php');
        exit;
    }

    if ($action === 'toggle_status') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $db->prepare("UPDATE clients SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
            $_SESSION['success'] = 'Status atualizado!';
        }
        header('Location: /clients_config.php');
        exit;
    }
}

// Contagem de operadoras por cliente (para exibir)
$opCounts = [];
try {
    foreach ($db->query("SELECT client_id, COUNT(*) c FROM health_insurers WHERE client_id IS NOT NULL GROUP BY client_id") as $r) {
        $opCounts[(int)$r['client_id']] = (int)$r['c'];
    }
} catch (Throwable $e) { $opCounts = []; }

$clients = clients_list(false);

view_header('Configuração de Clientes');
?>

<div class="grid">
    <section class="card col12">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
            <div>
                <div style="font-size:22px;font-weight:900">Clientes</div>
                <div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Contratantes/pagadores (ex.: GLOBAL). Cada cliente tem seu dia de fechamento e agrupa várias operadoras.</div>
            </div>
            <div style="display:flex;gap:10px">
                <a href="/health_insurers_config.php" class="btn">Operadoras</a>
                <button onclick="openClientModal()" class="btn-primary">+ Novo Cliente</button>
                <a href="/settings.php" class="btn">← Voltar</a>
            </div>
        </div>
    </section>

    <section class="card col12">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Cliente</th>
                    <th>Dia fech.</th>
                    <th>Operadoras</th>
                    <th>CNPJ</th>
                    <th>Domínio e-mail</th>
                    <th>Status</th>
                    <th style="width:150px">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($clients as $c): ?>
                <tr>
                    <td><strong><?= h((string)$c['name']) ?></strong></td>
                    <td><?= $c['closing_day'] ? ('dia ' . (int)$c['closing_day']) : '-' ?></td>
                    <td><?= (int)($opCounts[(int)$c['id']] ?? 0) ?></td>
                    <td><?= h((string)($c['cnpj'] ?? '-')) ?></td>
                    <td><?= h((string)($c['email_domain'] ?? '-')) ?></td>
                    <td>
                        <?php if ($c['is_active']): ?>
                            <span style="color:hsl(142, 76%, 36%);font-weight:600">● Ativo</span>
                        <?php else: ?>
                            <span style="color:hsl(var(--muted-foreground))">○ Inativo</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="display:flex;gap:6px">
                            <button onclick='editClient(<?= json_encode($c) ?>)' style="padding:5px 10px;font-size:11px;font-weight:600;background:transparent;color:hsl(var(--primary));border:1px solid hsl(var(--primary));border-radius:6px;cursor:pointer">Editar</button>
                            <form method="post" style="display:inline">
                                <input type="hidden" name="action" value="toggle_status">
                                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                <button type="submit" style="padding:5px 10px;font-size:11px;font-weight:600;background:transparent;color:<?= $c['is_active'] ? 'hsl(var(--destructive))' : 'hsl(var(--success))' ?>;border:1px solid <?= $c['is_active'] ? 'hsl(var(--destructive))' : 'hsl(var(--success))' ?>;border-radius:6px;cursor:pointer">
                                    <?= $c['is_active'] ? 'Desativar' : 'Ativar' ?>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($clients)): ?>
                <tr><td colspan="7" style="text-align:center;padding:32px;color:hsl(var(--muted-foreground))">Nenhum cliente cadastrado.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>
</div>

<!-- Modal Criar/Editar Cliente -->
<div id="clientModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;padding:20px;overflow-y:auto">
    <div style="max-width:560px;margin:40px auto;background:#fff;border-radius:12px;padding:24px">
        <h2 id="clientModalTitle" style="margin:0 0 20px;font-size:20px;font-weight:700">Novo Cliente</h2>
        <form method="post" id="clientForm">
            <input type="hidden" name="action" id="clientFormAction" value="create">
            <input type="hidden" name="id" id="clientId">

            <div style="display:grid;grid-template-columns:2fr 1fr;gap:12px;margin-bottom:16px">
                <div>
                    <label style="display:block;margin-bottom:8px;font-weight:600">Nome do Cliente *</label>
                    <input type="text" name="name" id="clientName" required style="width:100%;padding:10px;border:1px solid #d1d7db;border-radius:8px">
                </div>
                <div>
                    <label style="display:block;margin-bottom:8px;font-weight:600">Dia de fechamento</label>
                    <input type="number" name="closing_day" id="clientClosingDay" min="1" max="31" placeholder="1-31" style="width:100%;padding:10px;border:1px solid #d1d7db;border-radius:8px">
                </div>
            </div>

            <div style="margin-bottom:16px">
                <label style="display:block;margin-bottom:8px;font-weight:600">CNPJ</label>
                <input type="text" name="cnpj" id="clientCnpj" placeholder="00.000.000/0000-00" style="width:100%;padding:10px;border:1px solid #d1d7db;border-radius:8px">
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px">
                <div>
                    <label style="display:block;margin-bottom:8px;font-weight:600">Telefone</label>
                    <input type="text" name="contact_phone" id="clientPhone" style="width:100%;padding:10px;border:1px solid #d1d7db;border-radius:8px">
                </div>
                <div>
                    <label style="display:block;margin-bottom:8px;font-weight:600">E-mail Contato</label>
                    <input type="email" name="contact_email" id="clientEmail" style="width:100%;padding:10px;border:1px solid #d1d7db;border-radius:8px">
                </div>
            </div>

            <div style="margin-bottom:16px">
                <label style="display:block;margin-bottom:8px;font-weight:600">E-mail Faturamento</label>
                <input type="email" name="billing_email" id="clientBillingEmail" style="width:100%;padding:10px;border:1px solid #d1d7db;border-radius:8px">
            </div>

            <div style="margin-bottom:16px">
                <label style="display:block;margin-bottom:8px;font-weight:600">Domínio de E-mail</label>
                <input type="text" name="email_domain" id="clientEmailDomain" placeholder="ex: global.com.br" style="width:100%;padding:10px;border:1px solid #d1d7db;border-radius:8px">
                <div style="font-size:12px;color:hsl(var(--muted-foreground));margin-top:4px">Usado para identificar o cliente automaticamente pelo e-mail de origem das captações.</div>
            </div>

            <div style="margin-bottom:20px">
                <label style="display:block;margin-bottom:8px;font-weight:600">Observações</label>
                <textarea name="notes" id="clientNotes" rows="3" style="width:100%;padding:10px;border:1px solid #d1d7db;border-radius:8px;resize:vertical"></textarea>
            </div>

            <div style="display:flex;gap:12px">
                <button type="button" onclick="closeClientModal()" class="btn" style="flex:1">Cancelar</button>
                <button type="submit" class="btn-primary" style="flex:1">Salvar</button>
            </div>
        </form>
    </div>
</div>

<script>
function openClientModal() {
    document.getElementById('clientModalTitle').textContent = 'Novo Cliente';
    document.getElementById('clientFormAction').value = 'create';
    document.getElementById('clientForm').reset();
    document.getElementById('clientId').value = '';
    document.getElementById('clientModal').style.display = 'block';
}
function editClient(c) {
    document.getElementById('clientModalTitle').textContent = 'Editar Cliente';
    document.getElementById('clientFormAction').value = 'update';
    document.getElementById('clientId').value = c.id;
    document.getElementById('clientName').value = c.name || '';
    document.getElementById('clientClosingDay').value = c.closing_day || '';
    document.getElementById('clientCnpj').value = c.cnpj || '';
    document.getElementById('clientPhone').value = c.contact_phone || '';
    document.getElementById('clientEmail').value = c.contact_email || '';
    document.getElementById('clientBillingEmail').value = c.billing_email || '';
    document.getElementById('clientEmailDomain').value = c.email_domain || '';
    document.getElementById('clientNotes').value = c.notes || '';
    document.getElementById('clientModal').style.display = 'block';
}
function closeClientModal() {
    document.getElementById('clientModal').style.display = 'none';
}
</script>

<?php view_footer(); ?>

<?php
require_once __DIR__ . '/app/bootstrap.php';
auth_require_login();
rbac_require_permission('admin.settings.manage');

$db = db();

// Processar ações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $cnpj = trim($_POST['cnpj'] ?? '');
        $contactPhone = trim($_POST['contact_phone'] ?? '');
        $contactEmail = trim($_POST['contact_email'] ?? '');
        $billingEmail = trim($_POST['billing_email'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        
        if ($name !== '') {
            try {
                $stmt = $db->prepare("
                    INSERT INTO health_insurers (name, cnpj, contact_phone, contact_email, billing_email, notes)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$name, $cnpj, $contactPhone, $contactEmail, $billingEmail, $notes]);
                $_SESSION['success'] = 'Operadora cadastrada com sucesso!';
            } catch (PDOException $e) {
                $_SESSION['error'] = 'Erro ao cadastrar operadora: ' . $e->getMessage();
            }
        }
        header('Location: /health_insurers_config.php');
        exit;
    }
    
    if ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $cnpj = trim($_POST['cnpj'] ?? '');
        $contactPhone = trim($_POST['contact_phone'] ?? '');
        $contactEmail = trim($_POST['contact_email'] ?? '');
        $billingEmail = trim($_POST['billing_email'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        
        if ($id > 0 && $name !== '') {
            try {
                $stmt = $db->prepare("
                    UPDATE health_insurers 
                    SET name = ?, cnpj = ?, contact_phone = ?, contact_email = ?, billing_email = ?, notes = ?
                    WHERE id = ?
                ");
                $stmt->execute([$name, $cnpj, $contactPhone, $contactEmail, $billingEmail, $notes, $id]);
                $_SESSION['success'] = 'Operadora atualizada com sucesso!';
            } catch (PDOException $e) {
                $_SESSION['error'] = 'Erro ao atualizar operadora: ' . $e->getMessage();
            }
        }
        header('Location: /health_insurers_config.php');
        exit;
    }
    
    if ($action === 'toggle_status') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare("UPDATE health_insurers SET is_active = NOT is_active WHERE id = ?");
            $stmt->execute([$id]);
            $_SESSION['success'] = 'Status atualizado com sucesso!';
        }
        header('Location: /health_insurers_config.php');
        exit;
    }
}

// Buscar operadoras
$insurers = $db->query("
    SELECT * FROM health_insurers 
    ORDER BY is_active DESC, name ASC
")->fetchAll(PDO::FETCH_ASSOC);

view_header('Configuração de Operadoras');
?>

<div class="grid">
    <section class="card col12">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
            <div>
                <div style="font-size:22px;font-weight:900">Operadoras de Saúde</div>
                <div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Gerenciar convênios e operadoras</div>
            </div>
            <div style="display:flex;gap:10px">
                <button onclick="openCreateModal()" class="btn-primary">+ Nova Operadora</button>
                <a href="/settings.php" class="btn">← Voltar</a>
            </div>
        </div>
    </section>

    <section class="card col12">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>CNPJ</th>
                    <th>Telefone</th>
                    <th>E-mail Contato</th>
                    <th>E-mail Faturamento</th>
                    <th>Status</th>
                    <th style="width:120px">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($insurers as $insurer): ?>
                <tr>
                    <td><strong><?= h($insurer['name']) ?></strong></td>
                    <td><?= h($insurer['cnpj'] ?? '-') ?></td>
                    <td><?= h($insurer['contact_phone'] ?? '-') ?></td>
                    <td><?= h($insurer['contact_email'] ?? '-') ?></td>
                    <td><?= h($insurer['billing_email'] ?? '-') ?></td>
                    <td>
                        <?php if ($insurer['is_active']): ?>
                            <span style="color:hsl(142, 76%, 36%);font-weight:600">● Ativo</span>
                        <?php else: ?>
                            <span style="color:hsl(var(--muted-foreground))">○ Inativo</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <button onclick='editInsurer(<?= json_encode($insurer) ?>)' class="btn-sm">Editar</button>
                        <form method="post" style="display:inline">
                            <input type="hidden" name="action" value="toggle_status">
                            <input type="hidden" name="id" value="<?= $insurer['id'] ?>">
                            <button type="submit" class="btn-sm" style="background:hsl(var(--muted))">
                                <?= $insurer['is_active'] ? 'Desativar' : 'Ativar' ?>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</div>

<!-- Modal Criar/Editar -->
<div id="insurerModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;padding:20px;overflow-y:auto">
    <div style="max-width:600px;margin:40px auto;background:#fff;border-radius:12px;padding:24px">
        <h2 id="modalTitle" style="margin:0 0 20px;font-size:20px;font-weight:700">Nova Operadora</h2>
        
        <form method="post" id="insurerForm">
            <input type="hidden" name="action" id="formAction" value="create">
            <input type="hidden" name="id" id="insurerId">
            
            <div style="margin-bottom:16px">
                <label style="display:block;margin-bottom:8px;font-weight:600">Nome da Operadora *</label>
                <input type="text" name="name" id="insurerName" required style="width:100%;padding:10px;border:1px solid #d1d7db;border-radius:8px">
            </div>
            
            <div style="margin-bottom:16px">
                <label style="display:block;margin-bottom:8px;font-weight:600">CNPJ</label>
                <input type="text" name="cnpj" id="insurerCnpj" placeholder="00.000.000/0000-00" style="width:100%;padding:10px;border:1px solid #d1d7db;border-radius:8px">
            </div>
            
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px">
                <div>
                    <label style="display:block;margin-bottom:8px;font-weight:600">Telefone</label>
                    <input type="text" name="contact_phone" id="insurerPhone" placeholder="(00) 0000-0000" style="width:100%;padding:10px;border:1px solid #d1d7db;border-radius:8px">
                </div>
                <div>
                    <label style="display:block;margin-bottom:8px;font-weight:600">E-mail Contato</label>
                    <input type="email" name="contact_email" id="insurerEmail" style="width:100%;padding:10px;border:1px solid #d1d7db;border-radius:8px">
                </div>
            </div>
            
            <div style="margin-bottom:16px">
                <label style="display:block;margin-bottom:8px;font-weight:600">E-mail Faturamento</label>
                <input type="email" name="billing_email" id="insurerBillingEmail" style="width:100%;padding:10px;border:1px solid #d1d7db;border-radius:8px">
            </div>
            
            <div style="margin-bottom:20px">
                <label style="display:block;margin-bottom:8px;font-weight:600">Observações</label>
                <textarea name="notes" id="insurerNotes" rows="3" style="width:100%;padding:10px;border:1px solid #d1d7db;border-radius:8px;resize:vertical"></textarea>
            </div>
            
            <div style="display:flex;gap:12px">
                <button type="button" onclick="closeModal()" class="btn" style="flex:1">Cancelar</button>
                <button type="submit" class="btn-primary" style="flex:1">Salvar</button>
            </div>
        </form>
    </div>
</div>

<script>
function openCreateModal() {
    document.getElementById('modalTitle').textContent = 'Nova Operadora';
    document.getElementById('formAction').value = 'create';
    document.getElementById('insurerForm').reset();
    document.getElementById('insurerId').value = '';
    document.getElementById('insurerModal').style.display = 'block';
}

function editInsurer(insurer) {
    document.getElementById('modalTitle').textContent = 'Editar Operadora';
    document.getElementById('formAction').value = 'update';
    document.getElementById('insurerId').value = insurer.id;
    document.getElementById('insurerName').value = insurer.name;
    document.getElementById('insurerCnpj').value = insurer.cnpj || '';
    document.getElementById('insurerPhone').value = insurer.contact_phone || '';
    document.getElementById('insurerEmail').value = insurer.contact_email || '';
    document.getElementById('insurerBillingEmail').value = insurer.billing_email || '';
    document.getElementById('insurerNotes').value = insurer.notes || '';
    document.getElementById('insurerModal').style.display = 'block';
}

function closeModal() {
    document.getElementById('insurerModal').style.display = 'none';
}

// Fechar modal ao clicar fora
document.getElementById('insurerModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>

<?php view_footer(); ?>

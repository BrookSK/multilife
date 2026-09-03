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
        $emailDomain = trim($_POST['email_domain'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        
        if ($name !== '') {
            try {
                $stmt = $db->prepare("
                    INSERT INTO health_insurers (name, cnpj, contact_phone, contact_email, billing_email, email_domain, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$name, $cnpj, $contactPhone, $contactEmail, $billingEmail, $emailDomain, $notes]);
                $_SESSION['success'] = 'Operadora / Cliente cadastrada com sucesso!';
            } catch (PDOException $e) {
                $_SESSION['error'] = 'Erro ao cadastrar: ' . $e->getMessage();
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
        $emailDomain = trim($_POST['email_domain'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        
        if ($id > 0 && $name !== '') {
            try {
                $stmt = $db->prepare("
                    UPDATE health_insurers 
                    SET name = ?, cnpj = ?, contact_phone = ?, contact_email = ?, billing_email = ?, email_domain = ?, notes = ?
                    WHERE id = ?
                ");
                $stmt->execute([$name, $cnpj, $contactPhone, $contactEmail, $billingEmail, $emailDomain, $notes, $id]);
                $_SESSION['success'] = 'Operadora / Cliente atualizada com sucesso!';
            } catch (PDOException $e) {
                $_SESSION['error'] = 'Erro ao atualizar: ' . $e->getMessage();
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

view_header('Configuração de Operadoras / Clientes');
?>

<div class="grid">
    <section class="card col12">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
            <div>
                <div style="font-size:22px;font-weight:900">Operadoras / Clientes</div>
                <div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Gerenciar convênios, operadoras e clientes</div>
            </div>
            <div style="display:flex;gap:10px">
                <button onclick="openCreateModal()" class="btn-primary">+ Nova Operadora / Cliente</button>
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
                        <div style="display:flex;gap:6px">
                            <button onclick='editInsurer(<?= json_encode($insurer) ?>)' style="padding:5px 10px;font-size:11px;font-weight:600;background:transparent;color:hsl(var(--primary));border:1px solid hsl(var(--primary));border-radius:6px;cursor:pointer">Editar</button>
                            <form method="post" style="display:inline">
                                <input type="hidden" name="action" value="toggle_status">
                                <input type="hidden" name="id" value="<?= $insurer['id'] ?>">
                                <button type="submit" style="padding:5px 10px;font-size:11px;font-weight:600;background:transparent;color:<?= $insurer['is_active'] ? 'hsl(var(--destructive))' : 'hsl(var(--success))' ?>;border:1px solid <?= $insurer['is_active'] ? 'hsl(var(--destructive))' : 'hsl(var(--success))' ?>;border-radius:6px;cursor:pointer">
                                    <?= $insurer['is_active'] ? 'Desativar' : 'Ativar' ?>
                                </button>
                            </form>
                        </div>
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
                <label style="display:block;margin-bottom:8px;font-weight:600">Nome da Operadora / Cliente *</label>
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
            
            <div style="margin-bottom:16px">
                <label style="display:block;margin-bottom:8px;font-weight:600">Domínio de E-mail</label>
                <input type="text" name="email_domain" id="insurerEmailDomain" placeholder="ex: unimed.com.br, amil.com.br" style="width:100%;padding:10px;border:1px solid #d1d7db;border-radius:8px">
                <div style="font-size:12px;color:hsl(var(--muted-foreground));margin-top:4px">Usado para identificar automaticamente a operadora / cliente pelo e-mail de origem das captações</div>
            </div>
            
            <div style="margin-bottom:20px">
                <label style="display:block;margin-bottom:8px;font-weight:600">Observações</label>
                <textarea name="notes" id="insurerNotes" rows="3" style="width:100%;padding:10px;border:1px solid #d1d7db;border-radius:8px;resize:vertical"></textarea>
            </div>
            
            <!-- Documentação vinculada -->
            <div id="documentsSection" style="margin-bottom:20px;display:none">
                <label style="display:block;margin-bottom:8px;font-weight:600">Documentação (Manuais, Formulários, Termos)</label>
                <div id="documentsListContainer" style="margin-bottom:12px"></div>
                <div style="border:2px dashed hsl(var(--border));border-radius:8px;padding:16px;text-align:center">
                    <div style="margin-bottom:10px;text-align:left">
                        <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px">Documentação para:</label>
                        <select id="docProfessionalType" style="width:100%;padding:8px;border:1px solid hsl(var(--border));border-radius:6px;font-size:13px">
                            <option value="ambos">Ambos (novo e antigo)</option>
                            <option value="novo">Profissional novo</option>
                            <option value="antigo">Profissional antigo</option>
                        </select>
                    </div>
                    <input type="file" id="docFileInput" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.webp" style="display:none" onchange="uploadDocuments(this.files)">
                    <button type="button" onclick="document.getElementById('docFileInput').click()" style="padding:8px 16px;font-size:12px;font-weight:600;background:hsl(var(--primary));color:hsl(var(--primary-foreground));border:none;border-radius:6px;cursor:pointer">+ Adicionar Documento</button>
                    <div style="font-size:11px;color:hsl(var(--muted-foreground));margin-top:6px">PDF, DOC, DOCX, XLS, XLSX, JPG, PNG, WEBP (máx. 10MB)</div>
                </div>
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
    document.getElementById('modalTitle').textContent = 'Nova Operadora / Cliente';
    document.getElementById('formAction').value = 'create';
    document.getElementById('insurerForm').reset();
    document.getElementById('insurerId').value = '';
    document.getElementById('insurerModal').style.display = 'block';
}

function editInsurer(insurer) {
    document.getElementById('modalTitle').textContent = 'Editar Operadora / Cliente';
    document.getElementById('formAction').value = 'update';
    document.getElementById('insurerId').value = insurer.id;
    document.getElementById('insurerName').value = insurer.name;
    document.getElementById('insurerCnpj').value = insurer.cnpj || '';
    document.getElementById('insurerPhone').value = insurer.contact_phone || '';
    document.getElementById('insurerEmail').value = insurer.contact_email || '';
    document.getElementById('insurerBillingEmail').value = insurer.billing_email || '';
    document.getElementById('insurerEmailDomain').value = insurer.email_domain || '';
    document.getElementById('insurerNotes').value = insurer.notes || '';
    document.getElementById('documentsSection').style.display = 'block';
    loadDocuments(insurer.id);
    document.getElementById('insurerModal').style.display = 'block';
}

function closeModal() {
    document.getElementById('insurerModal').style.display = 'none';
    document.getElementById('documentsSection').style.display = 'none';
}

function loadDocuments(insurerId) {
    const container = document.getElementById('documentsListContainer');
    container.innerHTML = '<div style="font-size:12px;color:hsl(var(--muted-foreground))">Carregando...</div>';
    fetch('/health_insurers_documents_api.php?action=list&insurer_id=' + insurerId)
        .then(r => r.json())
        .then(data => {
            if (!data.documents || data.documents.length === 0) {
                container.innerHTML = '<div style="font-size:12px;color:hsl(var(--muted-foreground));padding:8px 0">Nenhum documento cadastrado.</div>';
                return;
            }
            let html = '';
            const typeBadges = {
                'novo': '<span style="font-size:10px;font-weight:700;background:#d9fdd3;color:#027a48;padding:1px 6px;border-radius:8px;margin-left:6px">NOVO</span>',
                'antigo': '<span style="font-size:10px;font-weight:700;background:#fdecc8;color:#b45309;padding:1px 6px;border-radius:8px;margin-left:6px">ANTIGO</span>',
                'ambos': '<span style="font-size:10px;font-weight:700;background:#e7f0fd;color:#1a56db;padding:1px 6px;border-radius:8px;margin-left:6px">AMBOS</span>'
            };
            data.documents.forEach(doc => {
                const icon = doc.file_name.match(/\.pdf$/i) ? '📄' : (doc.file_name.match(/\.(xls|xlsx)$/i) ? '📊' : (doc.file_name.match(/\.(doc|docx)$/i) ? '📝' : '🖼️'));
                const badge = typeBadges[doc.professional_type] || typeBadges['ambos'];
                html += '<div style="display:flex;align-items:center;justify-content:space-between;padding:8px 12px;background:hsl(var(--secondary));border-radius:6px;margin-bottom:6px">';
                html += '<a href="' + doc.file_path + '" target="_blank" style="font-size:13px;font-weight:500;color:hsl(var(--primary));text-decoration:none;display:flex;align-items:center;gap:6px">' + icon + ' ' + doc.file_name + badge + '</a>';
                html += '<button type="button" onclick="deleteDocument(' + doc.id + ',' + insurerId + ')" style="padding:3px 8px;font-size:11px;background:transparent;color:hsl(var(--destructive));border:1px solid hsl(var(--destructive));border-radius:4px;cursor:pointer">×</button>';
                html += '</div>';
            });
            container.innerHTML = html;
        })
        .catch(() => {
            container.innerHTML = '<div style="font-size:12px;color:hsl(var(--destructive))">Erro ao carregar documentos.</div>';
        });
}

function uploadDocuments(files) {
    const insurerId = document.getElementById('insurerId').value;
    if (!insurerId) { alert('Salve a operadora primeiro.'); return; }
    
    const profType = document.getElementById('docProfessionalType') ? document.getElementById('docProfessionalType').value : 'ambos';
    const formData = new FormData();
    formData.append('action', 'upload');
    formData.append('insurer_id', insurerId);
    formData.append('professional_type', profType);
    for (let i = 0; i < files.length; i++) {
        formData.append('files[]', files[i]);
    }
    
    fetch('/health_insurers_documents_api.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                loadDocuments(insurerId);
            } else {
                alert(data.error || 'Erro ao enviar documento.');
            }
            document.getElementById('docFileInput').value = '';
        })
        .catch(() => { alert('Erro de conexão.'); });
}

function deleteDocument(docId, insurerId) {
    if (!confirm('Remover este documento?')) return;
    fetch('/health_insurers_documents_api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ action: 'delete', document_id: docId })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            loadDocuments(insurerId);
        } else {
            alert(data.error || 'Erro ao remover.');
        }
    });
}

// Fechar modal ao clicar fora
document.getElementById('insurerModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>

<?php view_footer(); ?>

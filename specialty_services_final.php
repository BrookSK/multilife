<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

$specialty_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$specialty_id) {
    header('Location: /specialties_list.php');
    exit;
}

// Buscar especialidade
$stmt = db()->prepare("SELECT * FROM specialties WHERE id = ?");
$stmt->execute([$specialty_id]);
$specialty = $stmt->fetch();

if (!$specialty) {
    header('Location: /specialties_list.php');
    exit;
}

// Buscar serviços desta especialidade
$servicesStmt = db()->prepare("
    SELECT * FROM specialty_services 
    WHERE specialty_id = ?
    ORDER BY display_order, service_name
");
$servicesStmt->execute([$specialty_id]);
$services = $servicesStmt->fetchAll(PDO::FETCH_ASSOC);

// Debug: verificar se há serviços
error_log("Specialty ID: " . $specialty_id);
error_log("Services found: " . count($services));
if (count($services) > 0) {
    error_log("First service: " . json_encode($services[0]));
}

$success = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';

view_header('Gerenciar Serviços - ' . h($specialty['name']));
?>

<style>
.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
}
.modal-content {
    background-color: #fff;
    margin: 10% auto;
    padding: 30px;
    border-radius: 8px;
    width: 90%;
    max-width: 500px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}
.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 2px solid #e5e7eb;
}
.close {
    font-size: 28px;
    font-weight: bold;
    color: #999;
    cursor: pointer;
}
.close:hover {
    color: #000;
}
</style>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-1">Gerenciar Serviços</h1>
                    <p class="text-muted mb-0">
                        Especialidades > <strong><?= h($specialty['name']) ?></strong>
                    </p>
                </div>
                <a href="/specialties_list.php" class="btn btn-outline-secondary">Voltar</a>
            </div>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?= h($success) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?= h($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Serviços da Especialidade</h5>
                    <button type="button" class="btn btn-light btn-sm" onclick="openCreateModal()">
                        ➕ Criar Novo Serviço
                    </button>
                </div>
                <div class="card-body">
                    <?php if (count($services) === 0): ?>
                        <div class="text-center py-5">
                            <p class="text-muted mb-3">Nenhum serviço criado para esta especialidade.</p>
                            <p class="text-muted small">Clique em "Criar Novo Serviço" para começar.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th width="25%">Nome do Serviço</th>
                                        <th width="35%">Descrição</th>
                                        <th width="15%">Valor Base (R$)</th>
                                        <th width="10%">Status</th>
                                        <th width="15%">Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($services as $service): ?>
                                        <tr>
                                            <td><strong><?= h($service['service_name'] ?? '') ?></strong></td>
                                            <td><small class="text-muted"><?= h($service['description'] ?? '') ?></small></td>
                                            <td>R$ <?= number_format((float)($service['base_value'] ?? 0), 2, ',', '.') ?></td>
                                            <td>
                                                <?php if (($service['status'] ?? 'active') === 'active'): ?>
                                                    <span class="badge bg-success">Ativo</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Inativo</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-primary" onclick="openEditModal(<?= (int)$service['id'] ?>, '<?= addslashes($service['service_name'] ?? '') ?>', '<?= addslashes($service['description'] ?? '') ?>', <?= (float)($service['base_value'] ?? 0) ?>, '<?= $service['status'] ?? 'active' ?>', <?= (int)($service['display_order'] ?? 0) ?>)">
                                                    ✏️ Editar
                                                </button>
                                                <a href="/specialty_service_delete_final.php?id=<?= (int)$service['id'] ?>&specialty_id=<?= $specialty_id ?>" 
                                                   class="btn btn-sm btn-outline-danger"
                                                   onclick="return confirm('Excluir este serviço?')">
                                                    🗑️
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal para criar serviço -->
<div id="createModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h5>Criar Novo Serviço</h5>
            <span class="close" onclick="closeCreateModal()">&times;</span>
        </div>
        <form method="POST" action="/specialty_service_create_final.php">
            <input type="hidden" name="specialty_id" value="<?= $specialty_id ?>">
            <div class="mb-3">
                <label class="form-label">Nome do Serviço *</label>
                <input type="text" name="service_name" class="form-control" required placeholder="Ex: Atendimento Online">
            </div>
            <div class="mb-3">
                <label class="form-label">Descrição</label>
                <textarea name="description" class="form-control" rows="3" placeholder="Descreva o serviço..."></textarea>
            </div>
            <div class="mb-3">
                <label class="form-label">Valor Base (R$) *</label>
                <input type="number" name="base_value" class="form-control" step="0.01" min="0" value="0.00" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Ordem de Exibição</label>
                <input type="number" name="display_order" class="form-control" value="<?= count($services) + 1 ?>" min="0">
            </div>
            <div class="mb-3">
                <label class="form-label">Status</label>
                <select name="status" class="form-control">
                    <option value="active">Ativo</option>
                    <option value="inactive">Inativo</option>
                </select>
            </div>
            <div class="d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-secondary" onclick="closeCreateModal()">Cancelar</button>
                <button type="submit" class="btn btn-primary">Criar Serviço</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal para editar serviço -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h5>Editar Serviço</h5>
            <span class="close" onclick="closeEditModal()">&times;</span>
        </div>
        <form method="POST" action="/specialty_service_edit_final.php">
            <input type="hidden" name="id" id="edit_id">
            <input type="hidden" name="specialty_id" value="<?= $specialty_id ?>">
            <div class="mb-3">
                <label class="form-label">Nome do Serviço *</label>
                <input type="text" name="service_name" id="edit_service_name" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Descrição</label>
                <textarea name="description" id="edit_description" class="form-control" rows="3"></textarea>
            </div>
            <div class="mb-3">
                <label class="form-label">Valor Base (R$) *</label>
                <input type="number" name="base_value" id="edit_base_value" class="form-control" step="0.01" min="0" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Ordem de Exibição</label>
                <input type="number" name="display_order" id="edit_display_order" class="form-control" min="0">
            </div>
            <div class="mb-3">
                <label class="form-label">Status</label>
                <select name="status" id="edit_status" class="form-control">
                    <option value="active">Ativo</option>
                    <option value="inactive">Inativo</option>
                </select>
            </div>
            <div class="d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancelar</button>
                <button type="submit" class="btn btn-primary">Salvar Alterações</button>
            </div>
        </form>
    </div>
</div>

<script>
function openCreateModal() {
    document.getElementById('createModal').style.display = 'block';
}

function closeCreateModal() {
    document.getElementById('createModal').style.display = 'none';
}

function openEditModal(id, name, description, value, status, order) {
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_service_name').value = name;
    document.getElementById('edit_description').value = description;
    document.getElementById('edit_base_value').value = value;
    document.getElementById('edit_status').value = status;
    document.getElementById('edit_display_order').value = order;
    document.getElementById('editModal').style.display = 'block';
}

function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
}

window.onclick = function(event) {
    const createModal = document.getElementById('createModal');
    const editModal = document.getElementById('editModal');
    if (event.target == createModal) {
        closeCreateModal();
    }
    if (event.target == editModal) {
        closeEditModal();
    }
}
</script>

<?php view_footer(); ?>

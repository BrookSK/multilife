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

// Buscar todos os tipos de serviço disponíveis
$serviceTypesStmt = db()->query("SELECT * FROM service_types WHERE status = 'active' ORDER BY display_order");
$serviceTypes = $serviceTypesStmt->fetchAll();

// Buscar valores já cadastrados para esta especialidade
$valuesStmt = db()->prepare("
    SELECT ssv.*, st.name as service_name, st.description as service_description
    FROM specialty_service_values ssv
    JOIN service_types st ON st.id = ssv.service_type_id
    WHERE ssv.specialty_id = ?
    ORDER BY st.display_order
");
$valuesStmt->execute([$specialty_id]);
$existingValues = $valuesStmt->fetchAll(PDO::FETCH_ASSOC);

// Criar array indexado por service_type_id
$valuesByServiceType = [];
foreach ($existingValues as $value) {
    $valuesByServiceType[$value['service_type_id']] = $value;
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
                    <h5 class="mb-0">Tipos de Serviço e Valores</h5>
                    <button type="button" class="btn btn-light btn-sm" onclick="openNewServiceModal()">
                        ➕ Novo Tipo de Serviço
                    </button>
                </div>
                <div class="card-body">
                    <p class="text-muted mb-4">
                        Configure os valores para cada tipo de serviço oferecido nesta especialidade.
                    </p>

                    <form method="POST" action="/specialty_services_save.php">
                        <input type="hidden" name="specialty_id" value="<?= $specialty_id ?>">
                        
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th width="25%">Tipo de Serviço</th>
                                        <th width="30%">Descrição</th>
                                        <th width="15%">Valor Base (R$)</th>
                                        <th width="10%">Status</th>
                                        <th width="10%">Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($serviceTypes as $serviceType): ?>
                                        <?php
                                        $existingValue = $valuesByServiceType[$serviceType['id']] ?? null;
                                        $currentValue = $existingValue ? $existingValue['base_value'] : '0.00';
                                        $currentStatus = $existingValue ? $existingValue['status'] : 'active';
                                        $valueId = $existingValue ? $existingValue['id'] : 0;
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?= h($serviceType['name']) ?></strong>
                                                <input type="hidden" name="service_type_ids[]" value="<?= $serviceType['id'] ?>">
                                                <input type="hidden" name="value_ids[<?= $serviceType['id'] ?>]" value="<?= $valueId ?>">
                                            </td>
                                            <td>
                                                <small class="text-muted"><?= h($serviceType['description']) ?></small>
                                            </td>
                                            <td>
                                                <div class="input-group input-group-sm">
                                                    <span class="input-group-text">R$</span>
                                                    <input 
                                                        type="number" 
                                                        name="values[<?= $serviceType['id'] ?>]" 
                                                        class="form-control" 
                                                        value="<?= h($currentValue) ?>"
                                                        step="0.01"
                                                        min="0"
                                                        required
                                                    >
                                                </div>
                                            </td>
                                            <td>
                                                <select name="statuses[<?= $serviceType['id'] ?>]" class="form-select form-select-sm">
                                                    <option value="active" <?= $currentStatus === 'active' ? 'selected' : '' ?>>Ativo</option>
                                                    <option value="inactive" <?= $currentStatus === 'inactive' ? 'selected' : '' ?>>Inativo</option>
                                                </select>
                                            </td>
                                            <td>
                                                <?php if ($existingValue): ?>
                                                    <span class="badge bg-success">Configurado</span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning">Novo</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="d-flex justify-content-end mt-4 pt-3 border-top">
                            <a href="/specialties_list.php" class="btn btn-secondary me-2">Cancelar</a>
                            <button type="submit" class="btn btn-primary">Salvar Valores</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal para criar novo tipo de serviço -->
<div id="newServiceModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h5>Criar Novo Tipo de Serviço</h5>
            <span class="close" onclick="closeNewServiceModal()">&times;</span>
        </div>
        <form method="POST" action="/service_type_create.php">
            <input type="hidden" name="specialty_id" value="<?= $specialty_id ?>">
            <div class="mb-3">
                <label class="form-label">Nome do Serviço *</label>
                <input type="text" name="name" class="form-control" required placeholder="Ex: Atendimento Domiciliar">
            </div>
            <div class="mb-3">
                <label class="form-label">Descrição</label>
                <textarea name="description" class="form-control" rows="3" placeholder="Descreva o tipo de serviço..."></textarea>
            </div>
            <div class="mb-3">
                <label class="form-label">Ordem de Exibição</label>
                <input type="number" name="display_order" class="form-control" value="<?= count($serviceTypes) + 1 ?>" min="0">
            </div>
            <div class="d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-secondary" onclick="closeNewServiceModal()">Cancelar</button>
                <button type="submit" class="btn btn-primary">Criar Serviço</button>
            </div>
        </form>
    </div>
</div>

<script>
function openNewServiceModal() {
    document.getElementById('newServiceModal').style.display = 'block';
}

function closeNewServiceModal() {
    document.getElementById('newServiceModal').style.display = 'none';
}

// Fechar modal ao clicar fora
window.onclick = function(event) {
    const modal = document.getElementById('newServiceModal');
    if (event.target == modal) {
        closeNewServiceModal();
    }
}
</script>

<?php view_footer(); ?>

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

// Criar array indexado por service_type_id para fácil acesso
$valuesByServiceType = [];
foreach ($existingValues as $value) {
    $valuesByServiceType[$value['service_type_id']] = $value;
}

$success = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';

view_header('Gerenciar Serviços - ' . h($specialty['name']));
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-1">
                        <i class="bi bi-gear-fill text-primary"></i>
                        Gerenciar Serviços
                    </h1>
                    <p class="text-muted mb-0">
                        <a href="/specialties_list.php" class="text-decoration-none">Especialidades</a>
                        <i class="bi bi-chevron-right small"></i>
                        <strong><?= h($specialty['name']) ?></strong>
                    </p>
                </div>
                <a href="/specialties_list.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Voltar
                </a>
            </div>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill"></i>
            <?= h($success) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <?= h($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">
                        <i class="bi bi-list-check"></i>
                        Tipos de Serviço e Valores
                    </h5>
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
                                        <th width="30%">Tipo de Serviço</th>
                                        <th width="35%">Descrição</th>
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
                                                    <span class="badge bg-success">
                                                        <i class="bi bi-check-circle"></i> Configurado
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning">
                                                        <i class="bi bi-exclamation-circle"></i> Novo
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top">
                            <div class="text-muted">
                                <i class="bi bi-info-circle"></i>
                                <small>Os valores serão usados para cálculo de custos nas atribuições de pacientes.</small>
                            </div>
                            <div>
                                <a href="/specialties_list.php" class="btn btn-secondary me-2">
                                    <i class="bi bi-x-circle"></i> Cancelar
                                </a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-save"></i> Salvar Valores
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Card de Resumo -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header bg-light">
                    <h6 class="mb-0">
                        <i class="bi bi-bar-chart-fill"></i>
                        Resumo de Valores
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php foreach ($serviceTypes as $serviceType): ?>
                            <?php
                            $existingValue = $valuesByServiceType[$serviceType['id']] ?? null;
                            $currentValue = $existingValue ? $existingValue['base_value'] : 0;
                            ?>
                            <div class="col-md-4 mb-3">
                                <div class="p-3 border rounded">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <small class="text-muted d-block"><?= h($serviceType['name']) ?></small>
                                            <h4 class="mb-0">R$ <?= number_format($currentValue, 2, ',', '.') ?></h4>
                                        </div>
                                        <div>
                                            <?php if ($existingValue && $existingValue['status'] === 'active'): ?>
                                                <i class="bi bi-check-circle-fill text-success fs-3"></i>
                                            <?php else: ?>
                                                <i class="bi bi-dash-circle text-muted fs-3"></i>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php view_footer(); ?>

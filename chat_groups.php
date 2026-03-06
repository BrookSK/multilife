<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

view_header('Grupos WhatsApp');

// Buscar configurações da Evolution API
$baseUrl = admin_setting_get('evolution.base_url');
$apiKey = admin_setting_get('evolution.api_key');
$instanceName = admin_setting_get('evolution.instance');

$success = '';
$error = '';

// SEMPRE sincronizar grupos da Evolution API
if (!empty($baseUrl) && !empty($apiKey) && !empty($instanceName)) {
    try {
        // Criar tabelas se não existirem
        db()->exec("
            CREATE TABLE IF NOT EXISTS chat_groups (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                group_jid VARCHAR(100) NOT NULL UNIQUE,
                group_name VARCHAR(255) NOT NULL,
                group_description TEXT DEFAULT NULL,
                group_picture_url TEXT DEFAULT NULL,
                specialty VARCHAR(100) DEFAULT NULL,
                region VARCHAR(100) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE INDEX idx_group_jid (group_jid),
                INDEX idx_specialty (specialty),
                INDEX idx_region (region)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        // Buscar grupos da API
        $groupsUrl = $baseUrl . '/group/fetchAllGroups/' . urlencode($instanceName);
        $ch = curl_init($groupsUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['apikey: ' . $apiKey]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $groupsResponse = curl_exec($ch);
        $groupsHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($groupsHttpCode === 200 && $groupsResponse) {
            $groupsData = json_decode($groupsResponse, true);
            
            if (is_array($groupsData)) {
                foreach ($groupsData as $group) {
                    $groupJid = $group['id'] ?? '';
                    $groupName = $group['subject'] ?? 'Grupo sem nome';
                    $groupPic = $group['picture'] ?? null;
                    
                    if (!empty($groupJid)) {
                        $stmt = db()->prepare("
                            INSERT INTO chat_groups (group_jid, group_name, group_picture_url)
                            VALUES (?, ?, ?)
                            ON DUPLICATE KEY UPDATE 
                                group_name = VALUES(group_name),
                                group_picture_url = VALUES(group_picture_url),
                                updated_at = CURRENT_TIMESTAMP
                        ");
                        $stmt->execute([$groupJid, $groupName, $groupPic]);
                    }
                }
            }
        }
    } catch (Exception $e) {
        error_log("Erro ao sincronizar grupos: " . $e->getMessage());
    }
}

// Processar ações de grupo
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_metadata') {
        // Atualizar especialidade e região do grupo
        $groupJid = trim($_POST['group_jid'] ?? '');
        $specialty = trim($_POST['specialty'] ?? '');
        $region = trim($_POST['region'] ?? '');
        
        if (!empty($groupJid)) {
            try {
                $stmt = db()->prepare("
                    UPDATE chat_groups 
                    SET specialty = ?, region = ?
                    WHERE group_jid = ?
                ");
                $stmt->execute([$specialty, $region, $groupJid]);
                $success = 'Metadados do grupo atualizados com sucesso!';
            } catch (Exception $e) {
                $error = 'Erro ao atualizar metadados: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'add_participant') {
        // Adicionar participante ao grupo
        $groupJid = trim($_POST['group_jid'] ?? '');
        $participantPhone = trim($_POST['participant_phone'] ?? '');
        
        if (!empty($groupJid) && !empty($participantPhone)) {
            // Formatar número
            $participantPhone = preg_replace('/[^0-9]/', '', $participantPhone);
            if (!str_contains($participantPhone, '@')) {
                $participantPhone .= '@s.whatsapp.net';
            }
            
            // Adicionar via API
            $url = $baseUrl . '/group/updateParticipant/' . urlencode($instanceName);
            $payload = json_encode([
                'groupJid' => $groupJid,
                'action' => 'add',
                'participants' => [$participantPhone]
            ]);
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'apikey: ' . $apiKey,
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200 || $httpCode === 201) {
                $success = 'Participante adicionado com sucesso!';
            } else {
                $error = 'Erro ao adicionar participante. HTTP Code: ' . $httpCode;
            }
        }
    } elseif ($action === 'remove_participant') {
        // Remover participante do grupo
        $groupJid = trim($_POST['group_jid'] ?? '');
        $participantJid = trim($_POST['participant_jid'] ?? '');
        
        if (!empty($groupJid) && !empty($participantJid)) {
            $url = $baseUrl . '/group/updateParticipant/' . urlencode($instanceName);
            $payload = json_encode([
                'groupJid' => $groupJid,
                'action' => 'remove',
                'participants' => [$participantJid]
            ]);
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'apikey: ' . $apiKey,
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200 || $httpCode === 201) {
                $success = 'Participante removido com sucesso!';
            } else {
                $error = 'Erro ao remover participante. HTTP Code: ' . $httpCode;
            }
        }
    }
}

// Buscar grupos do banco
$specialtyFilter = isset($_GET['specialty']) ? trim($_GET['specialty']) : '';
$regionFilter = isset($_GET['region']) ? trim($_GET['region']) : '';

$whereClauses = [];
$params = [];

if (!empty($specialtyFilter)) {
    $whereClauses[] = "specialty = ?";
    $params[] = $specialtyFilter;
}

if (!empty($regionFilter)) {
    $whereClauses[] = "region = ?";
    $params[] = $regionFilter;
}

$whereSQL = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

try {
    $stmt = db()->prepare("
        SELECT 
            group_jid,
            group_name,
            group_description,
            group_picture_url,
            specialty,
            region,
            updated_at
        FROM chat_groups
        $whereSQL
        ORDER BY group_name ASC
    ");
    $stmt->execute($params);
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = 'Erro ao carregar grupos: ' . $e->getMessage();
    $groups = [];
}

// Buscar especialidades e regiões únicas para filtros
try {
    $specialties = db()->query("SELECT DISTINCT specialty FROM chat_groups WHERE specialty IS NOT NULL ORDER BY specialty")->fetchAll(PDO::FETCH_COLUMN);
    $regions = db()->query("SELECT DISTINCT region FROM chat_groups WHERE region IS NOT NULL ORDER BY region")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $specialties = [];
    $regions = [];
}

?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>Grupos WhatsApp</h2>
                <a href="/chat_web.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Voltar para Chat
                </a>
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

            <!-- Filtros -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="get" class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Especialidade</label>
                            <select name="specialty" class="form-select">
                                <option value="">Todas</option>
                                <?php foreach ($specialties as $spec): ?>
                                    <option value="<?= h($spec) ?>" <?= $specialtyFilter === $spec ? 'selected' : '' ?>>
                                        <?= h($spec) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Região</label>
                            <select name="region" class="form-select">
                                <option value="">Todas</option>
                                <?php foreach ($regions as $reg): ?>
                                    <option value="<?= h($reg) ?>" <?= $regionFilter === $reg ? 'selected' : '' ?>>
                                        <?= h($reg) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary me-2">
                                <i class="fas fa-filter"></i> Filtrar
                            </button>
                            <a href="/chat_groups.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Limpar
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Lista de Grupos -->
            <div class="row">
                <?php if (empty($groups)): ?>
                    <div class="col-12">
                        <div class="alert alert-info">
                            Nenhum grupo encontrado. Os grupos serão sincronizados automaticamente da Evolution API.
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($groups as $group): ?>
                        <div class="col-md-6 col-lg-4 mb-4">
                            <div class="card h-100">
                                <div class="card-body">
                                    <div class="d-flex align-items-start mb-3">
                                        <?php if ($group['group_picture_url']): ?>
                                            <img src="<?= h($group['group_picture_url']) ?>" 
                                                 alt="Foto do grupo" 
                                                 class="rounded-circle me-3" 
                                                 style="width:60px;height:60px;object-fit:cover;">
                                        <?php else: ?>
                                            <div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center me-3" 
                                                 style="width:60px;height:60px;font-size:24px;">
                                                <i class="fas fa-users"></i>
                                            </div>
                                        <?php endif; ?>
                                        <div class="flex-grow-1">
                                            <h5 class="card-title mb-1"><?= h($group['group_name']) ?></h5>
                                            <?php if ($group['specialty']): ?>
                                                <span class="badge bg-primary"><?= h($group['specialty']) ?></span>
                                            <?php endif; ?>
                                            <?php if ($group['region']): ?>
                                                <span class="badge bg-info"><?= h($group['region']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <?php if ($group['group_description']): ?>
                                        <p class="card-text text-muted small">
                                            <?= h(mb_strimwidth($group['group_description'], 0, 100, '...')) ?>
                                        </p>
                                    <?php endif; ?>

                                    <div class="d-grid gap-2">
                                        <a href="/chat_web.php?chat=<?= urlencode($group['group_jid']) ?>" 
                                           class="btn btn-success btn-sm">
                                            <i class="fas fa-comments"></i> Abrir Chat
                                        </a>
                                        <button type="button" 
                                                class="btn btn-outline-primary btn-sm" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#metadataModal<?= md5($group['group_jid']) ?>">
                                            <i class="fas fa-edit"></i> Editar Metadados
                                        </button>
                                        <button type="button" 
                                                class="btn btn-outline-secondary btn-sm" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#participantsModal<?= md5($group['group_jid']) ?>">
                                            <i class="fas fa-users"></i> Gerenciar Participantes
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Modal Metadados -->
                        <div class="modal fade" id="metadataModal<?= md5($group['group_jid']) ?>" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <form method="post">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Editar Metadados - <?= h($group['group_name']) ?></h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <input type="hidden" name="action" value="update_metadata">
                                            <input type="hidden" name="group_jid" value="<?= h($group['group_jid']) ?>">
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Especialidade</label>
                                                <input type="text" name="specialty" class="form-control" 
                                                       value="<?= h($group['specialty'] ?? '') ?>" 
                                                       placeholder="Ex: Cardiologia">
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label">Região</label>
                                                <input type="text" name="region" class="form-control" 
                                                       value="<?= h($group['region'] ?? '') ?>" 
                                                       placeholder="Ex: São Paulo">
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                            <button type="submit" class="btn btn-primary">Salvar</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- Modal Participantes -->
                        <div class="modal fade" id="participantsModal<?= md5($group['group_jid']) ?>" tabindex="-1">
                            <div class="modal-dialog modal-lg">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Gerenciar Participantes - <?= h($group['group_name']) ?></h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <!-- Adicionar Participante -->
                                        <form method="post" class="mb-4">
                                            <input type="hidden" name="action" value="add_participant">
                                            <input type="hidden" name="group_jid" value="<?= h($group['group_jid']) ?>">
                                            <div class="input-group">
                                                <input type="text" name="participant_phone" class="form-control" 
                                                       placeholder="Número do participante (ex: 5511999999999)" required>
                                                <button type="submit" class="btn btn-success">
                                                    <i class="fas fa-plus"></i> Adicionar
                                                </button>
                                            </div>
                                        </form>

                                        <p class="text-muted small">
                                            Para remover participantes, use a interface do WhatsApp diretamente.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php view_footer(); ?>

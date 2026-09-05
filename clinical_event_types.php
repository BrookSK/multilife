<?php
/**
 * Configuração dos TIPOS de evento clínico do paciente (item 7).
 * CRUD: listar, criar, editar (label), ativar/desativar, excluir e marcar se encerra o paciente.
 *
 * Cada tipo tem:
 *  - slug: identificador estável (usado no registro do evento)
 *  - name: rótulo exibido
 *  - triggers_closure: se 1, ao registrar esse evento o paciente é encerrado e os atendimentos finalizados
 *  - is_active: se aparece na lista de seleção
 *  - is_system: tipos base do sistema (podem ser editados/desativados, mas evite excluir 'obito')
 */

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('patients.manage');

$db = db();

// Garantir tabela
$db->exec("CREATE TABLE IF NOT EXISTS clinical_event_types (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    slug VARCHAR(120) NOT NULL,
    triggers_closure TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_clinical_event_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Semear tipos base uma única vez (se a tabela estiver vazia)
$hasAny = (int)$db->query("SELECT COUNT(*) FROM clinical_event_types")->fetchColumn();
if ($hasAny === 0) {
    $seed = [
        ['Internação', 'internacao', 0],
        ['Óbito', 'obito', 1],
        ['Alta', 'alta', 0],
        ['Retorno', 'retorno', 0],
        ['Transferência', 'transferencia', 0],
        ['Outro', 'outro', 0],
    ];
    $ins = $db->prepare("INSERT INTO clinical_event_types (name, slug, triggers_closure, is_active, is_system) VALUES (?, ?, ?, 1, 1)");
    foreach ($seed as $s) {
        try { $ins->execute([$s[0], $s[1], $s[2]]); } catch (Throwable $e) {}
    }
}

// Ações POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create') {
        $name = trim((string)($_POST['name'] ?? ''));
        $triggersClosure = isset($_POST['triggers_closure']) && (string)$_POST['triggers_closure'] === '1' ? 1 : 0;
        if ($name !== '') {
            $slug = preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($name));
            $slug = trim((string)$slug, '_');
            if ($slug === '') { $slug = 'evento'; }
            $slug .= '_' . substr(md5($name . microtime()), 0, 4);
            try {
                $db->prepare("INSERT INTO clinical_event_types (name, slug, triggers_closure, is_active, is_system) VALUES (?, ?, ?, 1, 0)")
                   ->execute([$name, $slug, $triggersClosure]);
                flash_set('success', 'Tipo de evento adicionado.');
            } catch (Throwable $e) {
                flash_set('error', 'Não foi possível adicionar o tipo.');
            }
        } else {
            flash_set('error', 'Informe um nome para o tipo de evento.');
        }
    } elseif ($action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $triggersClosure = isset($_POST['triggers_closure']) && (string)$_POST['triggers_closure'] === '1' ? 1 : 0;
        if ($id > 0 && $name !== '') {
            $db->prepare("UPDATE clinical_event_types SET name = ?, triggers_closure = ? WHERE id = ?")
               ->execute([$name, $triggersClosure, $id]);
            flash_set('success', 'Tipo de evento atualizado.');
        } else {
            flash_set('error', 'Informe um nome válido.');
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("UPDATE clinical_event_types SET is_active = 1 - is_active WHERE id = ?")->execute([$id]);
        flash_set('success', 'Tipo de evento atualizado.');
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM clinical_event_types WHERE id = ?")->execute([$id]);
        flash_set('success', 'Tipo de evento removido.');
    }
    header('Location: /clinical_event_types.php');
    exit;
}

$types = $db->query("SELECT * FROM clinical_event_types ORDER BY is_system DESC, name ASC")->fetchAll(PDO::FETCH_ASSOC);

view_header('Tipos de Evento Clínico');

echo '<div class="grid"><section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div><div style="font-size:22px;font-weight:900">Tipos de Evento Clínico</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Tipos usados ao registrar eventos clínicos do paciente (internação, óbito, alta, etc.).</div></div>';
echo '<a class="btn" href="/admin_settings.php">Voltar</a>';
echo '</div>';

// Form de criação
echo '<form method="post" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">';
echo '<input type="hidden" name="action" value="create">';
echo '<input name="name" placeholder="Novo tipo (ex: Alta hospitalar)" required style="flex:1;min-width:220px">';
echo '<label style="display:flex;align-items:center;gap:6px;font-size:13px;white-space:nowrap"><input type="checkbox" name="triggers_closure" value="1" style="width:auto"> Encerra o paciente</label>';
echo '<button class="btn btnPrimary" type="submit">Adicionar</button>';
echo '</form>';

echo '<div style="overflow:auto;margin-top:16px"><table>';
echo '<thead><tr><th>Tipo</th><th>Encerra paciente?</th><th>Origem</th><th>Status</th><th style="text-align:right">Ações</th></tr></thead><tbody>';
foreach ($types as $t) {
    $tid = (int)$t['id'];
    echo '<tr>';
    // Nome (exibição + edição inline)
    echo '<td style="font-weight:600">';
    echo '<span id="cetName' . $tid . '">' . h((string)$t['name']) . '</span>';
    echo '<form method="post" id="cetEditForm' . $tid . '" style="display:none;gap:8px;align-items:center;flex-wrap:wrap">';
    echo '<input type="hidden" name="action" value="edit">';
    echo '<input type="hidden" name="id" value="' . $tid . '">';
    echo '<input name="name" value="' . h((string)$t['name']) . '" required style="min-width:200px">';
    echo '<label style="display:flex;align-items:center;gap:6px;font-size:13px"><input type="checkbox" name="triggers_closure" value="1" ' . ((int)$t['triggers_closure'] === 1 ? 'checked' : '') . ' style="width:auto"> Encerra paciente</label>';
    echo '<button class="btn btnPrimary" type="submit">Salvar</button>';
    echo '<button class="btn" type="button" onclick="cetCancelEdit(' . $tid . ')">Cancelar</button>';
    echo '</form>';
    echo '</td>';
    echo '<td>' . ((int)$t['triggers_closure'] === 1 ? '<span class="badge badgeDanger">Sim</span>' : '<span style="color:hsl(var(--muted-foreground))">Não</span>') . '</td>';
    echo '<td>' . ((int)$t['is_system'] === 1 ? '<span class="badge badgeInfo">Sistema</span>' : 'Personalizado') . '</td>';
    echo '<td>' . ((int)$t['is_active'] === 1 ? '<span class="badge badgeSuccess">Ativo</span>' : '<span class="badge badgeDanger">Inativo</span>') . '</td>';
    echo '<td style="text-align:right" id="cetActions' . $tid . '">';
    echo '<button class="btn" type="button" onclick="cetStartEdit(' . $tid . ')">Editar</button> ';
    echo '<form method="post" style="display:inline"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="' . $tid . '"><button class="btn" type="submit">' . ((int)$t['is_active'] === 1 ? 'Desativar' : 'Ativar') . '</button></form> ';
    echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Excluir este tipo de evento?\')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . $tid . '"><button class="btn btnDanger" type="submit">Excluir</button></form>';
    echo '</td></tr>';
}
echo '</tbody></table></div>';

echo '<div style="margin-top:12px;font-size:12px;color:hsl(var(--muted-foreground))">Tipos marcados como <strong>“Encerra o paciente”</strong> finalizam automaticamente os atendimentos ativos ao serem registrados (ex.: Óbito). O histórico é sempre preservado.</div>';

echo '</section></div>';

// JS: edição inline
echo '<script>';
echo 'function cetStartEdit(id){var n=document.getElementById("cetName"+id),f=document.getElementById("cetEditForm"+id),a=document.getElementById("cetActions"+id);if(n)n.style.display="none";if(f)f.style.display="flex";if(a)a.style.opacity="0.4";}';
echo 'function cetCancelEdit(id){var n=document.getElementById("cetName"+id),f=document.getElementById("cetEditForm"+id),a=document.getElementById("cetActions"+id);if(n)n.style.display="inline";if(f)f.style.display="none";if(a)a.style.opacity="1";}';
echo '</script>';

view_footer();

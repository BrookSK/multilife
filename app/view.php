<?php

declare(strict_types=1);

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function view_header(string $title): void
{
    $user = auth_user();
    $flashError = flash_get('error');
    $flashSuccess = flash_get('success');

    $menuItems = [
        ['title' => 'Dashboard', 'path' => '/dashboard.php'],
        ['title' => 'Captação', 'path' => '/demands_list.php'],
        ['title' => 'Pré-admissão', 'path' => '/pre_admissao.php'],
        ['title' => 'Candidaturas', 'path' => '/professional_applications_list.php'],
        ['title' => 'Pacientes', 'path' => '/patients_list.php'],
        ['title' => 'Profissionais', 'path' => '/users_list.php?role=profissional'],
        ['title' => 'Financeiro', 'path' => '/finance_receivable_list.php'],
        ['title' => 'RH', 'path' => '/hr_employees_list.php'],
        ['title' => 'Comunicação', 'path' => '/chat_web.php'],
        ['title' => 'WhatsApp', 'path' => '/whatsapp_hub.php'],
        ['title' => 'Pendências', 'path' => '/pending_items_list.php'],
        ['title' => 'Integrações', 'path' => '/admin_integrations_hub.php'],
        ['title' => 'Permissões', 'path' => '/permissions_list.php'],
        ['title' => 'Configurações', 'path' => '/admin_settings.php'],
    ];

    echo '<!doctype html>';
    echo '<html lang="pt-BR">';
    echo '<head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h($title) . '</title>';
    echo '<style>';
    echo "@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');";
    echo ':root{--background:216 33% 97%;--foreground:210 36% 17%;--card:0 0% 100%;--card-foreground:210 36% 17%;--popover:0 0% 100%;--popover-foreground:210 36% 17%;--primary:180 65% 46%;--primary-foreground:0 0% 100%;--primary-dark:180 71% 36%;--primary-darker:180 71% 28%;--secondary:216 33% 97%;--secondary-foreground:210 36% 17%;--muted:216 33% 97%;--muted-foreground:216 18% 61%;--accent:180 65% 95%;--accent-foreground:180 71% 28%;--destructive:0 65% 55%;--destructive-foreground:0 0% 100%;--warning:36 90% 55%;--warning-foreground:0 0% 100%;--info:210 70% 55%;--info-foreground:0 0% 100%;--success:180 65% 46%;--success-foreground:0 0% 100%;--border:216 20% 90%;--input:216 20% 90%;--ring:180 65% 46%;--radius:0.625rem;--shadow-card:0 1px 3px 0 rgba(0,0,0,.06),0 1px 2px -1px rgba(0,0,0,.06);--shadow-card-hover:0 4px 12px 0 rgba(0,0,0,.08),0 2px 4px -1px rgba(0,0,0,.06);--shadow-elevated:0 10px 25px -5px rgba(0,0,0,.08),0 8px 10px -6px rgba(0,0,0,.04);--text:hsl(var(--foreground));--mutedText:hsl(var(--muted-foreground));--cardBorder:hsl(var(--border));}';
    echo '*{box-sizing:border-box;border-color:hsl(var(--border))}';
    echo 'html,body{height:100%}';
    echo 'body{margin:0;font-family:Inter,ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial;min-height:100vh;color:hsl(var(--foreground));background:hsl(var(--background));}';
    echo 'body:before{display:none}';
    echo 'body:after{display:none}';
    echo 'a{color:hsl(var(--foreground));text-decoration:none} a:hover{text-decoration:underline}';
    echo '.appShell{display:flex;min-height:100vh}';
    echo '.sidebar{position:fixed;left:0;top:0;z-index:40;height:100vh;width:260px;background:hsl(var(--card));border-right:1px solid hsl(var(--border));display:flex;flex-direction:column;transition:width .3s ease}';
    echo '.sidebar.isCollapsed{width:72px}';
    echo '.sidebarHeader{display:flex;align-items:center;gap:12px;padding:0 18px;height:64px;border-bottom:1px solid hsl(var(--border));flex:0 0 auto}';
    echo '.logoMark{display:flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:12px;background:hsla(var(--primary)/.10);flex:0 0 auto}';
    echo '.logoMark span{display:block;width:18px;height:18px;border-radius:6px;background:hsl(var(--primary))}';
    echo '.logoText{font-weight:800;font-size:15px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}';
    echo '.logoText em{font-style:normal;color:hsl(var(--primary))}';
    echo '.sidebarNav{flex:1 1 auto;overflow:auto;padding:14px 12px;display:flex;flex-direction:column;gap:6px}';
    echo '.navItem{display:flex;align-items:center;gap:12px;padding:10px 12px;border-radius:12px;font-size:13px;font-weight:600;color:hsl(var(--muted-foreground));transition:background .15s ease,color .15s ease}';
    echo '.navItem:hover{background:hsl(var(--accent));color:hsl(var(--accent-foreground));text-decoration:none}';
    echo '.navItem.isActive{background:hsl(var(--primary));color:hsl(var(--primary-foreground));box-shadow:0 1px 2px rgba(0,0,0,.06)}';
    echo '.navIcon{width:18px;height:18px;border-radius:6px;background:hsla(var(--primary)/.14);flex:0 0 auto}';
    echo '.sidebarUser{border-top:1px solid hsl(var(--border));padding:12px;flex:0 0 auto}';
    echo '.userRow{display:flex;align-items:center;gap:12px}';
    echo '.avatar{width:36px;height:36px;border-radius:12px;background:hsla(var(--primary)/.10);display:flex;align-items:center;justify-content:center;color:hsl(var(--primary));font-weight:800;font-size:12px;flex:0 0 auto}';
    echo '.userMeta{min-width:0}';
    echo '.userName{font-size:13px;font-weight:700;color:hsl(var(--foreground));white-space:nowrap;overflow:hidden;text-overflow:ellipsis}';
    echo '.userEmail{font-size:12px;color:hsl(var(--muted-foreground));white-space:nowrap;overflow:hidden;text-overflow:ellipsis}';
    echo '.collapseBtn{position:absolute;right:-12px;top:84px;width:26px;height:26px;border-radius:999px;border:1px solid hsl(var(--border));background:hsl(var(--card));display:flex;align-items:center;justify-content:center;box-shadow:0 1px 2px rgba(0,0,0,.06);cursor:pointer}';
    echo '.collapseBtn:hover{background:hsl(var(--accent))}';
    echo '.collapseChevron{width:0;height:0;border-top:5px solid transparent;border-bottom:5px solid transparent;border-right:6px solid hsl(var(--muted-foreground));transition:transform .2s ease}';
    echo '.sidebar.isCollapsed .collapseChevron{transform:rotate(180deg)}';
    echo '.mainCol{flex:1 1 auto;margin-left:260px;transition:margin-left .3s ease}';
    echo '.mainCol.isCollapsed{margin-left:72px}';
    echo '.topbar{position:sticky;top:0;z-index:30;height:64px;background:hsl(var(--card));border-bottom:1px solid hsl(var(--border));display:flex;align-items:center;justify-content:space-between;padding:0 24px}';
    echo '.topbarTitle{font-size:16px;font-weight:800;color:hsl(var(--foreground))}';
    echo '.topbarActions{display:flex;align-items:center;gap:14px}';
    echo '.notifDot{width:8px;height:8px;border-radius:999px;background:hsl(var(--destructive));display:inline-block;margin-left:-6px;margin-top:-10px}';
    echo '.contentPad{padding:24px}';
    echo '.top{max-width:1100px;margin:0 auto;padding:18px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px;position:relative;z-index:1}';
    echo '.brand{display:flex;align-items:center;gap:10px;font-weight:800;letter-spacing:-.2px}';
    echo '.pill{padding:6px 10px;border-radius:999px;background:hsla(var(--primary)/.10);border:1px solid hsla(var(--primary)/.20);font-size:12px;color:hsl(var(--primary-darker))}';
    echo '.nav{display:flex;gap:10px;align-items:center;flex-wrap:wrap}';
    echo '.btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 12px;border-radius:10px;border:1px solid hsl(var(--border));background:hsl(var(--card));color:hsl(var(--foreground));font-weight:600;font-size:13px;box-shadow:var(--shadow-card);transition:box-shadow .15s ease,transform .06s ease,background .15s ease}';
    echo '.btn:hover{box-shadow:var(--shadow-card-hover);text-decoration:none}';
    echo '.btn:active{transform:translateY(1px)}';
    echo '.btnPrimary{border-color:transparent;background:hsl(var(--primary));color:hsl(var(--primary-foreground))}';
    echo '.btnPrimary:hover{background:hsl(var(--primary-dark))}';
    echo '.wrap{max-width:1100px;margin:0 auto;padding:0 16px 26px;position:relative;z-index:1}';
    echo '.card{background:hsl(var(--card));border:1px solid hsl(var(--border));box-shadow:var(--shadow-elevated);border-radius:calc(var(--radius) + 6px);padding:18px;color:hsl(var(--card-foreground))}';
    echo '.grid{display:grid;grid-template-columns:repeat(12,1fr);gap:14px}';
    echo '.col6{grid-column:span 6} .col12{grid-column:span 12}';
    echo '.alert{margin:0 0 14px;padding:12px;border-radius:12px;font-size:13px;line-height:1.4;border:1px solid transparent}';
    echo '.alertError{background:hsla(var(--destructive)/.10);border-color:hsla(var(--destructive)/.20);color:hsl(var(--foreground))}';
    echo '.alertSuccess{background:hsla(var(--success)/.10);border-color:hsla(var(--success)/.20);color:hsl(var(--foreground))}';
    echo 'input,select,textarea{font-family:inherit}';
    echo 'input:not([type="checkbox"]):not([type="radio"]):not([type="file"]),select,textarea{width:100%;border-radius:10px;border:1px solid hsl(var(--input));background:hsla(var(--secondary)/.50);color:hsl(var(--foreground));padding:10px 12px;outline:none;font-size:14px;transition:background .15s ease,box-shadow .15s ease,border-color .15s ease}';
    echo 'textarea{min-height:96px;resize:vertical}';
    echo 'input:not([type="checkbox"]):not([type="radio"]):not([type="file"]):focus,select:focus,textarea:focus{background:hsl(var(--card));border-color:hsla(var(--ring)/.55);box-shadow:0 0 0 4px hsla(var(--ring)/.15)}';
    echo '::placeholder{color:hsl(var(--muted-foreground))}';

    echo 'table{width:100%;border-collapse:separate;border-spacing:0}';
    echo 'th,td{padding:12px 12px;border-bottom:1px solid hsl(var(--border));text-align:left;font-size:13px}';
    echo 'th{font-size:12px;color:hsl(var(--muted-foreground));font-weight:700}';
    echo 'tr:hover td{background:hsla(var(--accent)/.35)}';
    echo 'thead tr:hover td, thead tr:hover th{background:transparent}';

    echo '.badge{display:inline-flex;align-items:center;gap:6px;padding:3px 10px;border-radius:999px;border:1px solid hsla(var(--primary)/.20);background:hsla(var(--primary)/.10);color:hsl(var(--primary-darker));font-size:12px;font-weight:700}';
    echo '.badgeDanger{border-color:hsla(var(--destructive)/.20);background:hsla(var(--destructive)/.10);color:hsl(var(--destructive))}';
    echo '.badgeWarn{border-color:hsla(var(--warning)/.20);background:hsla(var(--warning)/.10);color:hsl(var(--warning))}';
    echo '.badgeInfo{border-color:hsla(var(--info)/.20);background:hsla(var(--info)/.10);color:hsl(var(--info))}';
    echo '.badgeSuccess{border-color:hsla(var(--success)/.20);background:hsla(var(--success)/.10);color:hsl(var(--success))}';

    echo 'label{display:grid;gap:7px;font-size:13px;font-weight:600;color:hsl(var(--foreground))}';
    echo 'label[style]{display:grid !important;gap:7px !important;font-size:13px !important;font-weight:600 !important;color:hsl(var(--foreground)) !important}';
    echo 'input[style]:not([type="checkbox"]):not([type="radio"]):not([type="file"]),select[style],textarea[style]{border-radius:10px !important;border:1px solid hsl(var(--input)) !important;background:hsla(var(--secondary)/.50) !important;color:hsl(var(--foreground)) !important;padding:10px 12px !important;outline:none !important;font-size:14px !important;transition:background .15s ease,box-shadow .15s ease,border-color .15s ease !important}';
    echo 'input[style]:not([type="checkbox"]):not([type="radio"]):not([type="file"]):focus,select[style]:focus,textarea[style]:focus{background:hsl(var(--card)) !important;border-color:hsla(var(--ring)/.55) !important;box-shadow:0 0 0 4px hsla(var(--ring)/.15) !important}';
    
    // Checkbox/Radio/File com style inline também
    echo 'input[type="checkbox"][style],input[type="radio"][style]{appearance:none !important;-webkit-appearance:none !important;cursor:pointer !important;transition:all .15s ease !important}';
    echo 'input[type="file"][style]{cursor:pointer !important;transition:all .15s ease !important}';
    echo 'textarea[style]{min-height:96px !important;resize:vertical !important}';
    echo 'input[readonly],select[disabled],textarea[readonly]{opacity:0.6;cursor:not-allowed}';
    
    // Checkbox moderno
    echo 'input[type="checkbox"]{appearance:none;-webkit-appearance:none;width:18px;height:18px;border:2px solid hsl(var(--input));border-radius:4px;background:hsl(var(--card));cursor:pointer;position:relative;transition:all .15s ease;margin:0}';
    echo 'input[type="checkbox"]:checked{background:hsl(var(--primary));border-color:hsl(var(--primary))}';
    echo 'input[type="checkbox"]:checked::after{content:"";position:absolute;left:5px;top:2px;width:4px;height:8px;border:solid hsl(var(--primary-foreground));border-width:0 2px 2px 0;transform:rotate(45deg)}';
    echo 'input[type="checkbox"]:focus{outline:none;box-shadow:0 0 0 3px hsla(var(--ring)/.15)}';
    echo 'input[type="checkbox"]:hover:not(:disabled){border-color:hsl(var(--primary))}';
    
    // Radio moderno
    echo 'input[type="radio"]{appearance:none;-webkit-appearance:none;width:18px;height:18px;border:2px solid hsl(var(--input));border-radius:50%;background:hsl(var(--card));cursor:pointer;position:relative;transition:all .15s ease;margin:0}';
    echo 'input[type="radio"]:checked{border-color:hsl(var(--primary));border-width:5px}';
    echo 'input[type="radio"]:focus{outline:none;box-shadow:0 0 0 3px hsla(var(--ring)/.15)}';
    echo 'input[type="radio"]:hover:not(:disabled){border-color:hsl(var(--primary))}';
    
    // File input moderno
    echo 'input[type="file"]{padding:8px 12px;border:1px solid hsl(var(--input));border-radius:10px;background:hsl(var(--card));color:hsl(var(--foreground));font-size:14px;cursor:pointer;transition:all .15s ease}';
    echo 'input[type="file"]:hover{border-color:hsl(var(--primary));background:hsla(var(--primary)/.05)}';
    echo 'input[type="file"]:focus{outline:none;border-color:hsla(var(--ring)/.55);box-shadow:0 0 0 4px hsla(var(--ring)/.15)}';
    echo 'input[type="file"]::file-selector-button{padding:6px 12px;margin-right:12px;border:none;border-radius:6px;background:hsl(var(--primary));color:hsl(var(--primary-foreground));font-weight:600;font-size:13px;cursor:pointer;transition:background .15s ease}';
    echo 'input[type="file"]::file-selector-button:hover{background:hsl(var(--primary-dark))}';
    echo 'form{display:grid;gap:14px}';
    echo 'form .grid{gap:14px}';
    echo 'fieldset{border:1px solid hsl(var(--border));border-radius:12px;padding:18px;margin:0}';
    echo 'legend{font-size:14px;font-weight:700;color:hsl(var(--foreground));padding:0 8px}';
    echo '.formSection{padding:18px;border-radius:12px;background:hsla(var(--muted)/.25);border:1px solid hsl(var(--border));margin-bottom:14px}';
    echo '.formSectionTitle{font-size:15px;font-weight:800;color:hsl(var(--foreground));margin-bottom:14px;padding-bottom:10px;border-bottom:1px solid hsl(var(--border))}';
    echo 'div[style*="color:rgba(234,240,255,.72)"],span[style*="color:rgba(234,240,255,.72)"]{color:hsl(var(--muted-foreground)) !important}';
    echo 'div[style*="color:rgba(234,240,255,.85)"],span[style*="color:rgba(234,240,255,.85)"]{color:hsl(var(--foreground)) !important}';
    echo 'div[style*="background:rgba(10,14,28,.55)"],span[style*="background:rgba(10,14,28,.55)"]{background:hsla(var(--secondary)/.50) !important}';
    echo 'div[style*="border-radius:14px"],input[style*="border-radius:14px"],textarea[style*="border-radius:14px"],select[style*="border-radius:14px"]{border-radius:10px !important}';
    echo 'button.btn[style]{box-shadow:var(--shadow-card) !important}';
    echo '.helpText{font-size:12px;color:hsl(var(--muted-foreground));margin-top:4px;line-height:1.4}';

    echo '.kanbanScroll{overflow:auto;padding-bottom:6px}';
    echo '.kanbanRow{display:flex;gap:16px;min-width:1600px;padding-bottom:10px}';
    echo '.kanbanCol{flex:1 1 0;min-width:190px}';
    echo '.kanbanColHead{display:flex;align-items:center;gap:8px;margin-bottom:10px;padding:0 4px}';
    echo '.kanbanEmoji{font-size:16px}';
    echo '.kanbanTitle{text-transform:uppercase;letter-spacing:.08em;font-size:11px;font-weight:800;color:hsl(var(--foreground))}';
    echo '.kanbanCount{margin-left:auto;font-size:12px;font-weight:700;color:hsl(var(--muted-foreground))}';
    echo '.kanbanLane{background:hsla(var(--muted)/.40);border:1px solid hsl(var(--border));border-radius:14px;padding:10px;min-height:200px;display:flex;flex-direction:column;gap:10px}';
    echo '.kanbanEmpty{flex:1 1 auto;display:flex;align-items:center;justify-content:center;height:96px;color:hsl(var(--muted-foreground));font-size:12px}';
    echo '.kanbanCard{border:1px solid hsl(var(--border));box-shadow:var(--shadow-card);border-radius:14px;background:hsl(var(--card));transition:box-shadow .15s ease;display:block}';
    echo '.kanbanCard:hover{box-shadow:var(--shadow-card-hover);text-decoration:none}';
    echo '.kanbanCardBody{padding:12px}';
    echo '.kanbanCardTop{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:6px}';
    echo '.kanbanCardTitle{font-size:13px;font-weight:800;color:hsl(var(--foreground));line-height:1.25}';
    echo '.kanbanMeta{font-size:12px;color:hsl(var(--muted-foreground));margin-bottom:8px}';
    echo '.fab{position:fixed;right:24px;bottom:24px;width:56px;height:56px;border-radius:999px;background:hsl(var(--primary));color:hsl(var(--primary-foreground));border:0;display:flex;align-items:center;justify-content:center;box-shadow:var(--shadow-elevated);font-weight:900;font-size:22px;cursor:pointer}';
    echo '.fab:hover{background:hsl(var(--primary-dark))}';

    echo '.kpiGrid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:16px}';
    echo '.kpiCard{border:1px solid hsl(var(--border));box-shadow:var(--shadow-card);border-radius:calc(var(--radius) + 6px);background:hsl(var(--card));transition:box-shadow .15s ease}';
    echo '.kpiCard:hover{box-shadow:var(--shadow-card-hover)}';
    echo '.kpiBody{padding:16px}';
    echo '.kpiTop{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:10px}';
    echo '.kpiIcon{width:34px;height:34px;border-radius:12px;display:flex;align-items:center;justify-content:center;background:hsla(var(--primary)/.10);color:hsl(var(--primary));font-weight:900;font-size:14px}';
    echo '.kpiChange{font-size:12px;font-weight:700;color:hsl(var(--muted-foreground))}';
    echo '.kpiValue{font-size:20px;font-weight:900;color:hsl(var(--foreground));line-height:1.1}';
    echo '.kpiLabel{margin-top:6px;font-size:12px;color:hsl(var(--muted-foreground));line-height:1.25}';
    echo '.chartBox{height:240px;border-radius:calc(var(--radius) + 6px);background:linear-gradient(135deg,hsla(var(--primary)/.12),transparent 55%),linear-gradient(180deg,hsla(var(--primary)/.04),transparent);border:1px dashed hsla(var(--primary)/.25)}';
    echo '.chartBoxInner{height:100%;display:flex;align-items:center;justify-content:center;color:hsl(var(--muted-foreground));font-size:12px;font-weight:700}';

    echo '@media(max-width:1280px){.kpiGrid{grid-template-columns:repeat(3,minmax(0,1fr))}}';
    echo '@media(max-width:780px){.kpiGrid{grid-template-columns:repeat(1,minmax(0,1fr))}}';

    echo '@media(max-width:860px){.col6{grid-column:span 12}}';
    echo '@media(max-width:980px){.sidebar{position:static;height:auto;width:100%}.mainCol{margin-left:0}.mainCol.isCollapsed{margin-left:0}.appShell{flex-direction:column}.collapseBtn{display:none}.topbar{position:static}}';
    echo '</style>';
    echo '</head>';
    echo '<body>';

    if ($user !== null) {
        $initials = '';
        $name = trim((string)($user['name'] ?? ''));
        if ($name !== '') {
            $parts = preg_split('/\s+/', $name);
            if (is_array($parts) && count($parts) > 0) {
                $initials = mb_strtoupper(mb_substr((string)$parts[0], 0, 1));
                if (count($parts) > 1) {
                    $initials .= mb_strtoupper(mb_substr((string)$parts[count($parts) - 1], 0, 1));
                }
            }
        }
        if ($initials === '') {
            $initials = 'AD';
        }

        $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $path = is_string($path) ? $path : '';

        echo '<div class="appShell">';
        echo '<aside class="sidebar" id="appSidebar">';
        echo '<div class="sidebarHeader">';
        echo '<div class="logoMark" aria-hidden="true"><span></span></div>';
        echo '<div class="logoText" id="logoText">Multi<em>Life</em> Care</div>';
        echo '</div>';

        echo '<nav class="sidebarNav">';
        foreach ($menuItems as $it) {
            $isActive = $path === $it['path'];
            $cls = 'navItem' . ($isActive ? ' isActive' : '');
            echo '<a class="' . $cls . '" href="' . h($it['path']) . '" title="' . h($it['title']) . '">';
            echo '<span class="navIcon" aria-hidden="true"></span>';
            echo '<span class="navText">' . h($it['title']) . '</span>';
            echo '</a>';
        }
        echo '</nav>';

        echo '<div class="sidebarUser">';
        echo '<div class="userRow">';
        echo '<div class="avatar">' . h($initials) . '</div>';
        echo '<div class="userMeta">';
        echo '<div class="userName">' . h((string)$user['name']) . '</div>';
        echo '<div class="userEmail">' . h((string)$user['email']) . '</div>';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        echo '<button class="collapseBtn" type="button" id="sidebarCollapse" aria-label="Recolher menu">';
        echo '<span class="collapseChevron" aria-hidden="true"></span>';
        echo '</button>';

        echo '</aside>';

        echo '<div class="mainCol" id="mainCol">';
        echo '<header class="topbar">';
        echo '<div class="topbarTitle">' . h($title) . '</div>';
        echo '<div class="topbarActions">';
        echo '<a class="btn" href="/logout.php">Sair</a>';
        echo '</div>';
        echo '</header>';

        echo '<main class="contentPad">';
    } else {
        echo '<header class="top">';
        echo '<div class="brand">Multilife <span class="pill">Care</span></div>';
        echo '<nav class="nav">';
        echo '<a class="btn" href="/">Início</a>';
        echo '<a class="btn btnPrimary" href="/login.php">Entrar</a>';
        echo '</nav>';
        echo '</header>';

        echo '<main class="wrap">';
    }

    if ($flashError !== '') {
        echo '<div class="alert alertError" role="alert">' . h($flashError) . '</div>';
    }
    if ($flashSuccess !== '') {
        echo '<div class="alert alertSuccess" role="alert">' . h($flashSuccess) . '</div>';
    }
}

function view_footer(): void
{
    $user = auth_user();
    echo '</main>';

    if ($user !== null) {
        echo '</div>';
        echo '</div>';
        echo '<script>';
        echo '(function(){try{var sb=document.getElementById("appSidebar");var mc=document.getElementById("mainCol");var btn=document.getElementById("sidebarCollapse");if(!sb||!mc||!btn)return;var k="ml_sidebar_collapsed";var set=function(v){if(v){sb.classList.add("isCollapsed");mc.classList.add("isCollapsed");}else{sb.classList.remove("isCollapsed");mc.classList.remove("isCollapsed");}};set(localStorage.getItem(k)==="1");btn.addEventListener("click",function(){var next=!sb.classList.contains("isCollapsed");set(next);localStorage.setItem(k,next?"1":"0");});}catch(e){}})();';
        echo '</script>';
    }

    echo '</body>';
    echo '</html>';
}

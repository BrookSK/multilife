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
        ['title' => 'Financeiro', 'path' => '/finance_dashboard.php', 'submenu' => [
            ['title' => 'Dashboard', 'path' => '/finance_dashboard.php'],
            ['title' => 'Contas a Receber', 'path' => '/finance_receivable_list.php'],
            ['title' => 'Contas a Pagar', 'path' => '/finance_payable_list.php'],
        ]],
        ['title' => 'RH', 'path' => '/hr_employees_list.php'],
        ['title' => 'WhatsApp', 'path' => '/whatsapp_hub.php', 'submenu' => [
            ['title' => 'Chat ao Vivo', 'path' => '/chat_web.php'],
        ]],
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
    echo ':root{--background:216 33% 97%;--foreground:0 0% 0%;--card:0 0% 100%;--card-foreground:0 0% 0%;--popover:0 0% 100%;--popover-foreground:0 0% 0%;--primary:180 65% 46%;--primary-foreground:0 0% 100%;--primary-dark:180 71% 36%;--primary-darker:180 71% 28%;--secondary:216 33% 97%;--secondary-foreground:0 0% 0%;--muted:216 33% 97%;--muted-foreground:0 0% 35%;--accent:180 65% 95%;--accent-foreground:180 71% 28%;--destructive:0 65% 55%;--destructive-foreground:0 0% 100%;--warning:36 90% 55%;--warning-foreground:0 0% 100%;--info:210 70% 55%;--info-foreground:0 0% 100%;--success:180 65% 46%;--success-foreground:0 0% 100%;--border:216 20% 90%;--input:216 20% 90%;--ring:180 65% 46%;--radius:0.625rem;--shadow-card:0 1px 3px 0 rgba(0,0,0,.06),0 1px 2px -1px rgba(0,0,0,.06);--shadow-card-hover:0 4px 12px 0 rgba(0,0,0,.08),0 2px 4px -1px rgba(0,0,0,.06);--shadow-elevated:0 10px 25px -5px rgba(0,0,0,.08),0 8px 10px -6px rgba(0,0,0,.04);--text:hsl(var(--foreground));--mutedText:hsl(var(--muted-foreground));--cardBorder:hsl(var(--border));}';
    echo '*{box-sizing:border-box;border-color:hsl(var(--border))}';
    echo 'html,body{height:100%}';
    echo 'body{margin:0;font-family:Inter,ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial;min-height:100vh;color:hsl(var(--foreground));background:hsl(var(--background));font-size:17px;}';
    echo 'body:before{display:none}';
    echo 'body:after{display:none}';
    echo 'a{color:hsl(var(--foreground));text-decoration:none} a:hover{text-decoration:underline}';
    echo '.appShell{display:flex;min-height:100vh}';
    echo '.sidebar{position:fixed;left:0;top:0;z-index:40;height:100vh;width:260px;background:hsl(var(--card));border-right:1px solid hsl(var(--border));display:flex;flex-direction:column;transition:width .3s ease}';
    echo '.sidebar.isCollapsed{width:72px}';
    echo '.sidebarHeader{display:flex;align-items:center;gap:12px;padding:0 18px;height:64px;border-bottom:1px solid hsl(var(--border));flex:0 0 auto}';
    echo '.logoMark{display:flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:12px;background:transparent;border:1px solid hsl(var(--border));flex:0 0 auto}';
    echo '.logoMark span{display:block;width:18px;height:18px;border-radius:6px;background:hsl(var(--primary))}';
    echo '.logoText{font-weight:800;font-size:15px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}';
    echo '.logoText em{font-style:normal;color:hsl(var(--primary))}';
    echo '.sidebarNav{flex:1 1 auto;overflow-x:visible;overflow-y:auto;padding:14px 12px;display:flex;flex-direction:column;gap:6px}';
    echo '.navItem{display:flex;align-items:center;gap:12px;padding:10px 12px;border-radius:12px;font-size:15px;font-weight:600;color:hsl(var(--muted-foreground));transition:background .15s ease,color .15s ease}';
    echo '.navItem:hover{background:hsl(var(--accent));color:hsl(var(--accent-foreground));text-decoration:none}';
    echo '.navItem.isActive{background:hsl(var(--primary));color:hsl(var(--primary-foreground));box-shadow:0 1px 2px rgba(0,0,0,.06)}';
    echo '.navIcon{width:22px;height:22px;border-radius:6px;background:hsl(var(--primary));flex:0 0 auto;display:flex;align-items:center;justify-content:center;color:white;font-size:11px;font-weight:700}';
    echo '.navChevron{font-size:10px;margin-left:auto;transition:transform .2s ease;color:currentColor}';
    echo '.navItemWithSubmenu{position:relative;overflow:visible}';
    echo '.navItemWithSubmenu:hover .navSubmenu,.navItemWithSubmenu:focus-within .navSubmenu{display:block;opacity:1;visibility:visible}';
    echo '.navSubmenu{display:block;opacity:0;visibility:hidden;position:absolute;left:calc(100% + 8px);top:0;min-width:220px;background:hsl(var(--card));border:1px solid hsl(var(--border));border-radius:12px;box-shadow:0 4px 16px rgba(0,0,0,.15);padding:8px;z-index:9999;transition:opacity .2s ease,visibility .2s ease;pointer-events:auto}';
    echo '.navSubmenu:hover{opacity:1;visibility:visible}';
    echo '.navSubItem{display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:8px;color:hsl(var(--muted-foreground));transition:background .15s ease,color .15s ease;text-decoration:none;font-size:14px;font-weight:500;white-space:nowrap}';
    echo '.navSubItem:hover{background:hsla(var(--primary)/.08);color:hsl(var(--foreground));text-decoration:none}';
    echo '.navSubItem.isActive{background:hsl(var(--primary));color:hsl(var(--primary-foreground))}';
    echo '.navSubItem .navIcon{width:18px;height:18px}';
    echo '.navSubItem .navIcon svg{width:14px;height:14px}';
    echo '.sidebarUser{border-top:1px solid hsl(var(--border));padding:12px;flex:0 0 auto}';
    echo '.userRow{display:flex;align-items:center;gap:12px}';
    echo '.avatar{width:36px;height:36px;border-radius:12px;background:transparent;border:1px solid hsl(var(--border));display:flex;align-items:center;justify-content:center;color:hsl(var(--foreground));font-weight:800;font-size:12px;flex:0 0 auto}';
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
    echo '.notifBell{position:relative;padding:8px;border-radius:8px;transition:background .15s ease}';
    echo '.notifBell:hover{background:hsl(var(--accent))}';
    echo '.notifBell svg{color:hsl(var(--foreground))}';
    echo '.notifBadge{position:absolute;top:4px;right:4px;background:hsl(var(--destructive));color:white;font-size:10px;font-weight:700;padding:2px 5px;border-radius:999px;min-width:16px;text-align:center}';
    echo '.notifDropdown{position:absolute;top:calc(100% + 8px);right:0;width:380px;max-height:500px;overflow-y:auto;background:hsl(var(--card));border:1px solid hsl(var(--border));border-radius:12px;box-shadow:var(--shadow-elevated);display:none;z-index:50}';
    echo '.notifDropdown.isOpen{display:block}';
    echo '.notifHeader{padding:14px 16px;border-bottom:1px solid hsl(var(--border));font-weight:700;font-size:14px;display:flex;align-items:center;justify-content:space-between}';
    echo '.notifList{max-height:400px;overflow-y:auto}';
    echo '.notifItem{padding:12px 16px;border-bottom:1px solid hsl(var(--border));cursor:pointer;transition:background .15s ease}';
    echo '.notifItem:hover{background:hsl(var(--accent))}';
    echo '.notifItem.unread{background:hsla(var(--primary)/.05)}';
    echo '.notifTitle{font-weight:600;font-size:13px;margin-bottom:4px;color:hsl(var(--foreground))}';
    echo '.notifMessage{font-size:12px;color:hsl(var(--muted-foreground));line-height:1.4}';
    echo '.notifTime{font-size:11px;color:hsl(var(--muted-foreground));margin-top:4px}';
    echo '.notifEmpty{padding:40px 16px;text-align:center;color:hsl(var(--muted-foreground));font-size:13px}';
    echo '.notifDot{width:8px;height:8px;border-radius:999px;background:hsl(var(--destructive));display:inline-block;margin-left:-6px;margin-top:-10px}';
    echo '.contentPad{padding:24px}';
    echo '.top{max-width:1100px;margin:0 auto;padding:18px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px;position:relative;z-index:1}';
    echo '.brand{display:flex;align-items:center;gap:10px;font-weight:800;letter-spacing:-.2px}';
    echo '.pill{padding:6px 10px;border-radius:999px;background:transparent;border:1px solid hsl(var(--border));font-size:15px;color:hsl(var(--foreground))}';
    echo '.nav{display:flex;gap:10px;align-items:center;flex-wrap:wrap}';
    echo '.btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 12px;border-radius:10px;border:1px solid hsl(var(--border));background:hsl(var(--card));color:hsl(var(--foreground));font-weight:600;font-size:13px;box-shadow:var(--shadow-card);transition:box-shadow .15s ease,transform .06s ease,background .15s ease}';
    echo '.btn:hover{box-shadow:var(--shadow-card-hover);text-decoration:none}';
    echo '.btn:active{transform:translateY(1px)}';
    echo '.btnPrimary{border-color:transparent;background:hsl(var(--primary));color:hsl(var(--primary-foreground))}';
    echo '.btnPrimary:hover{background:hsl(var(--primary-dark))}';
    echo '.wrap{margin:0 auto;padding:0 16px 26px;position:relative;z-index:1}';
    echo '.card{background:hsl(var(--card));border:1px solid hsl(var(--border));box-shadow:var(--shadow-elevated);border-radius:calc(var(--radius) + 6px);padding:18px;color:hsl(var(--card-foreground))}';
    echo '.grid{display:grid;grid-template-columns:repeat(12,1fr);gap:14px}';
    echo '.col6{grid-column:span 6} .col12{grid-column:span 12}';
    echo '.alert{margin:0 0 14px;padding:12px;border-radius:12px;font-size:13px;line-height:1.4;border:1px solid transparent}';
    echo '.alertError{background:hsla(var(--destructive)/.10);border-color:hsla(var(--destructive)/.20);color:hsl(var(--foreground))}';
    echo '.alertSuccess{background:hsla(var(--success)/.10);border-color:hsla(var(--success)/.20);color:hsl(var(--foreground))}';
    echo 'input,select,textarea{font-family:inherit}';
    echo 'input:not([type="checkbox"]):not([type="radio"]):not([type="file"]),select,textarea{width:100%;border-radius:10px;border:1px solid hsl(var(--input));background:hsl(var(--card));color:hsl(var(--foreground));padding:10px 12px;outline:none;font-size:17px;transition:background .15s ease,box-shadow .15s ease,border-color .15s ease}';
    echo 'textarea{min-height:96px;resize:vertical}';
    echo 'input:not([type="checkbox"]):not([type="radio"]):not([type="file"]):focus,select:focus,textarea:focus{background:hsl(var(--card));border-color:hsla(var(--ring)/.55);box-shadow:0 0 0 4px hsla(var(--ring)/.15)}';
    echo '::placeholder{color:hsl(var(--muted-foreground))}';

    echo 'table{width:100%;border-collapse:separate;border-spacing:0}';
    echo 'th,td{padding:12px 12px;border-bottom:1px solid hsl(var(--border));text-align:left;font-size:16px;background:transparent}';
    echo 'th{font-size:15px;color:hsl(var(--foreground));font-weight:700;background:transparent}';
    echo 'tr:hover td{background:hsl(var(--background))}';
    echo 'thead tr:hover td, thead tr:hover th{background:transparent}';

    echo '.badge{display:inline-flex;align-items:center;gap:6px;padding:3px 10px;border-radius:999px;border:1px solid hsl(var(--border));background:transparent;color:hsl(var(--foreground));font-size:15px;font-weight:700}';
    echo '.badgeDanger{border-color:hsl(var(--border));background:transparent;color:hsl(var(--foreground))}';
    echo '.badgeWarn{border-color:hsl(var(--border));background:transparent;color:hsl(var(--foreground))}';
    echo '.badgeInfo{border-color:hsl(var(--border));background:transparent;color:hsl(var(--foreground))}';
    echo '.badgeSuccess{border-color:hsl(var(--border));background:transparent;color:hsl(var(--foreground))}';

    echo 'label{display:grid;gap:7px;font-size:16px;font-weight:600;color:hsl(var(--foreground))}';
    echo 'label[style]{display:grid !important;gap:7px !important;font-size:16px !important;font-weight:600 !important;color:hsl(var(--foreground)) !important}';
    echo 'input[style]:not([type="checkbox"]):not([type="radio"]):not([type="file"]),select[style],textarea[style]{border-radius:10px !important;border:1px solid hsl(var(--input)) !important;background:hsl(var(--card)) !important;color:hsl(var(--foreground)) !important;padding:10px 12px !important;outline:none !important;font-size:17px !important;transition:background .15s ease,box-shadow .15s ease,border-color .15s ease !important}';
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
    echo '.formSection{padding:18px;border-radius:12px;background:transparent;border:1px solid hsl(var(--border));margin-bottom:14px}';
    echo '.formSectionTitle{font-size:18px;font-weight:800;color:hsl(var(--foreground));margin-bottom:14px;padding-bottom:10px;border-bottom:1px solid hsl(var(--border))}';
    echo 'div[style*="color:rgba(234,240,255,.72)"],span[style*="color:rgba(234,240,255,.72)"]{color:hsl(var(--muted-foreground)) !important}';
    echo 'div[style*="color:rgba(234,240,255,.85)"],span[style*="color:rgba(234,240,255,.85)"]{color:hsl(var(--foreground)) !important}';
    echo 'div[style*="background:rgba(10,14,28,.55)"],span[style*="background:rgba(10,14,28,.55)"]{background:transparent !important}';
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
    echo '.kpiIcon{width:34px;height:34px;border-radius:12px;display:flex;align-items:center;justify-content:center;background:transparent;border:1px solid hsl(var(--border));color:hsl(var(--foreground));font-weight:900;font-size:14px}';
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
        
        $logoUrl = admin_setting_get('app.logo_url');
        if (!empty($logoUrl)) {
            echo '<img src="' . h($logoUrl) . '" alt="Logo" style="max-height:56px;max-width:100%;object-fit:contain">';
        } else {
            echo '<div class="logoMark" aria-hidden="true"><span></span></div>';
            echo '<div class="logoText" id="logoText">Multi<em>Life</em> Care</div>';
        }
        
        echo '</div>';

        echo '<nav class="sidebarNav">';
        
        $icons = [
            'Dashboard' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>',
            'Captação' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72 12.84 12.84 0 00.7 2.81 2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45 12.84 12.84 0 002.81.7A2 2 0 0122 16.92z"/></svg>',
            'Pré-admissão' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>',
            'Candidaturas' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>',
            'Pacientes' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
            'Profissionais' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M16 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="8.5" cy="7" r="4"/><polyline points="17 11 19 13 23 9"/></svg>',
            'Financeiro' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>',
            'Dashboard' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>',
            'Contas a Receber' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>',
            'Contas a Pagar' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>',
            'RH' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>',
            'WhatsApp' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z"/></svg>',
            'Chat ao Vivo' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>',
            'Pendências' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
            'Integrações' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71"/></svg>',
            'Permissões' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>',
            'Configurações' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="3"/><path d="M12 1v6m0 6v6M5.64 5.64l4.24 4.24m4.24 4.24l4.24 4.24M1 12h6m6 0h6M5.64 18.36l4.24-4.24m4.24-4.24l4.24-4.24"/></svg>',
        ];
        
        foreach ($menuItems as $it) {
            $hasSubmenu = isset($it['submenu']) && is_array($it['submenu']);
            $isActive = $path === $it['path'];
            
            // Verificar se algum item do submenu está ativo
            $submenuActive = false;
            if ($hasSubmenu) {
                foreach ($it['submenu'] as $subItem) {
                    if ($path === $subItem['path']) {
                        $submenuActive = true;
                        break;
                    }
                }
            }
            
            $cls = 'navItem' . ($isActive || $submenuActive ? ' isActive' : '');
            $icon = isset($icons[$it['title']]) ? $icons[$it['title']] : '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="2"/></svg>';
            
            if ($hasSubmenu) {
                echo '<div class="navItemWithSubmenu">';
                echo '<a class="' . $cls . '" href="' . h($it['path']) . '" title="' . h($it['title']) . '">';
                echo '<span class="navIcon" aria-hidden="true">' . $icon . '</span>';
                echo '<span class="navText">' . h($it['title']) . '</span>';
                echo '<span class="navChevron">▼</span>';
                echo '</a>';
                
                echo '<div class="navSubmenu">';
                foreach ($it['submenu'] as $subItem) {
                    $subActive = $path === $subItem['path'];
                    $subCls = 'navSubItem' . ($subActive ? ' isActive' : '');
                    $subIcon = isset($icons[$subItem['title']]) ? $icons[$subItem['title']] : '';
                    echo '<a class="' . $subCls . '" href="' . h($subItem['path']) . '">';
                    if ($subIcon) {
                        echo '<span class="navIcon" aria-hidden="true">' . $subIcon . '</span>';
                    }
                    echo '<span class="navText">' . h($subItem['title']) . '</span>';
                    echo '</a>';
                }
                echo '</div>';
                echo '</div>';
            } else {
                echo '<a class="' . $cls . '" href="' . h($it['path']) . '" title="' . h($it['title']) . '">';
                echo '<span class="navIcon" aria-hidden="true">' . $icon . '</span>';
                echo '<span class="navText">' . h($it['title']) . '</span>';
                echo '</a>';
            }
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
        
        // Buscar notificações não lidas
        $notifStmt = db()->prepare('SELECT COUNT(*) as count FROM notifications WHERE user_id = :uid AND is_read = 0');
        $notifStmt->execute(['uid' => $user['id']]);
        $notifCount = (int)$notifStmt->fetchColumn();
        
        echo '<div class="notifBell" id="notifBell" style="position:relative;cursor:pointer">';
        echo '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>';
        if ($notifCount > 0) {
            echo '<span class="notifBadge">' . ($notifCount > 9 ? '9+' : $notifCount) . '</span>';
        }
        echo '</div>';
        
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
        // Dropdown de notificações
        echo '<div class="notifDropdown" id="notifDropdown">';
        echo '<div class="notifHeader">';
        echo '<span>Notificações</span>';
        echo '<a href="#" onclick="markAllAsRead(); return false;" style="font-size:12px;font-weight:400;color:hsl(var(--primary))">Marcar todas como lidas</a>';
        echo '</div>';
        echo '<div class="notifList" id="notifList">';
        echo '<div class="notifEmpty">Carregando...</div>';
        echo '</div>';
        echo '</div>';
        
        echo '</div>';
        echo '</div>';
        echo '<script>';
        echo '(function(){try{var sb=document.getElementById("appSidebar");var mc=document.getElementById("mainCol");var btn=document.getElementById("sidebarCollapse");if(!sb||!mc||!btn)return;var k="ml_sidebar_collapsed";var set=function(v){if(v){sb.classList.add("isCollapsed");mc.classList.add("isCollapsed");}else{sb.classList.remove("isCollapsed");mc.classList.remove("isCollapsed");}};set(localStorage.getItem(k)==="1");btn.addEventListener("click",function(){var next=!sb.classList.contains("isCollapsed");set(next);localStorage.setItem(k,next?"1":"0");});}catch(e){}})();';
        
        // JavaScript de notificações
        echo 'const notifBell=document.getElementById("notifBell");';
        echo 'const notifDropdown=document.getElementById("notifDropdown");';
        echo 'let notifOpen=false;';
        echo 'if(notifBell){';
        echo 'notifBell.addEventListener("click",function(e){';
        echo 'e.stopPropagation();notifOpen=!notifOpen;';
        echo 'if(notifOpen){notifDropdown.classList.add("isOpen");loadNotifications();}';
        echo 'else{notifDropdown.classList.remove("isOpen");}';
        echo '});';
        echo 'document.addEventListener("click",function(e){';
        echo 'if(!notifDropdown.contains(e.target)&&!notifBell.contains(e.target)){';
        echo 'notifDropdown.classList.remove("isOpen");notifOpen=false;}';
        echo '});';
        echo 'function loadNotifications(){';
        echo 'fetch("/notifications_get.php").then(r=>r.json()).then(data=>{';
        echo 'const list=document.getElementById("notifList");';
        echo 'if(data.notifications.length===0){list.innerHTML="<div class=\\"notifEmpty\\">Nenhuma notificação</div>";return;}';
        echo 'list.innerHTML="";';
        echo 'data.notifications.forEach(n=>{';
        echo 'const item=document.createElement("div");';
        echo 'item.className="notifItem"+(n.is_read?"":" unread");';
        echo 'item.innerHTML=`<div class="notifTitle">${n.title}</div><div class="notifMessage">${n.message}</div><div class="notifTime">${n.created_at}</div>`;';
        echo 'item.addEventListener("click",function(){markAsRead(n.id);if(n.link)window.location.href=n.link;});';
        echo 'list.appendChild(item);';
        echo '});';
        echo '});';
        echo '}';
        echo 'function markAsRead(id){';
        echo 'fetch("/notifications_mark_read.php",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({id:id})}).then(()=>location.reload());';
        echo '}';
        echo 'function markAllAsRead(){';
        echo 'fetch("/notifications_mark_all_read.php",{method:"POST"}).then(()=>location.reload());';
        echo '}';
        echo 'setInterval(function(){';
        echo 'fetch("/notifications_count.php").then(r=>r.json()).then(data=>{';
        echo 'const badge=document.querySelector(".notifBadge");';
        echo 'if(data.count>0){';
        echo 'if(!badge){const newBadge=document.createElement("span");newBadge.className="notifBadge";newBadge.textContent=data.count>9?"9+":data.count;notifBell.appendChild(newBadge);}';
        echo 'else{badge.textContent=data.count>9?"9+":data.count;}';
        echo '}else if(badge){badge.remove();}';
        echo '});';
        echo '},30000);';
        echo '}';
        echo '</script>';
    }

    echo '</body>';
    echo '</html>';
}

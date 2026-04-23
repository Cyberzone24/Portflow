<?php
    $active_page = basename($_SERVER['PHP_SELF'], ".php");
    
    if (isset($_GET['signout'])) {
        // destroy session
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['loggedin'] = FALSE;
        session_unset();
        session_destroy();
        $signout = defined('PORTFLOW_HOSTNAME') ? PORTFLOW_HOSTNAME : $_SERVER['HTTP_HOST'];
        header('Location: ' . $signout);
        die();
    }

    // import alert function
    include_once __DIR__ . '/alert.php';

    // import lanuage file
    include_once __DIR__ . '/lang.php';

    // import auth for permission checks
    include_once __DIR__ . '/core/auth.php';
    use Portflow\Core\Auth;
    $auth = new Auth();

    $navActiveClass = 'pf-nav-pill pf-nav-pill-active';
    $navInactiveClass = 'pf-nav-pill';
    $itamClass = ($active_page == 'itam') ? $navActiveClass : $navInactiveClass;
    $automationClass = ($active_page == 'automation') ? $navActiveClass : $navInactiveClass;
    $portviewClass = ($active_page == 'portview') ? $navActiveClass : $navInactiveClass;
    $reportsClass = ($active_page == 'reports') ? $navActiveClass : $navInactiveClass;
    
    // Check if user has access to automation
    $hasAutomationAccess = (isset($_SESSION['uuid']) && $auth->checkResourceAccess($_SESSION['uuid'], 'automation')) ? true : false;

    $sessionSettingsRaw = $_SESSION['settings'] ?? '{}';
    if (is_array($sessionSettingsRaw)) {
        $sessionSettings = $sessionSettingsRaw;
    } elseif (is_string($sessionSettingsRaw) && $sessionSettingsRaw !== '') {
        $decodedSessionSettings = json_decode($sessionSettingsRaw, true);
        $sessionSettings = is_array($decodedSessionSettings) ? $decodedSessionSettings : [];
    } else {
        $sessionSettings = [];
    }

    $appearance = isset($sessionSettings['appearance']) && is_array($sessionSettings['appearance'])
        ? $sessionSettings['appearance']
        : [];

    $selectedTheme = strtolower((string)($appearance['theme'] ?? 'light'));
    if (!in_array($selectedTheme, ['light', 'dark', 'contrast'], true)) {
        $selectedTheme = 'light';
    }

    $selectedFont = (string)($appearance['font_family'] ?? 'jetbrains');
    if (!in_array($selectedFont, ['jetbrains', 'source_sans', 'fira_sans'], true)) {
        $selectedFont = 'jetbrains';
    }

    $selectedFontSize = (string)($appearance['font_size'] ?? 'normal');
    if (!in_array($selectedFontSize, ['small', 'normal', 'large'], true)) {
        $selectedFontSize = 'normal';
    }

    $themeMap = [
        'light' => [
            '500' => '#3b82f6',
            '600' => '#2563eb',
            '700' => '#1d4ed8',
            'bg' => '#bfc4cd',
            'surface' => '#f8fafc',
            'surface_alt' => '#ffffff',
            'surface_soft' => '#f1f5f9',
            'text' => '#0f172a',
            'muted' => '#64748b',
            'border' => '#cbd5e1',
            'hover' => '#e2e8f0'
        ],
        'dark' => [
            '500' => '#60a5fa',
            '600' => '#3b82f6',
            '700' => '#2563eb',
            'bg' => '#000000',
            'surface' => 'oklch(12.9% 0.042 264.695)',
            'surface_alt' => 'oklch(12.9% 0.042 264.695)',
            'surface_soft' => '#1f314a',
            'text' => '#f1f5f9',
            'muted' => '#c5d1df',
            'border' => '#3b516f',
            'hover' => 'oklch(20.8% 0.042 265.755)'
        ],
        'contrast' => [
            '500' => '#f59e0b',
            '600' => '#d97706',
            '700' => '#b45309',
            'bg' => '#000000',
            'surface' => '#050505',
            'surface_alt' => '#0d0d0d',
            'surface_soft' => '#181818',
            'text' => '#ffffff',
            'muted' => '#f3f4f6',
            'border' => '#f59e0b',
            'hover' => '#242424'
        ]
    ];

    $fontMap = [
        'jetbrains' => "'JetBrains Mono', monospace",
        'source_sans' => "'Source Sans 3', sans-serif",
        'fira_sans' => "'Fira Sans', sans-serif"
    ];

    $fontSizeMap = ['small' => '92.5%', 'normal' => '100%', 'large' => '110%'];

    $themeColors = $themeMap[$selectedTheme];
    $fontStack = $fontMap[$selectedFont];
    $fontSizeBase = $fontSizeMap[$selectedFontSize];
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600;700&family=Source+Sans+3:wght@400;600;700&family=Fira+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
    :root {
        --pf-accent-500: <?php echo $themeColors['500']; ?>;
        --pf-accent-600: <?php echo $themeColors['600']; ?>;
        --pf-accent-700: <?php echo $themeColors['700']; ?>;
        --pf-bg: <?php echo $themeColors['bg']; ?>;
        --pf-surface: <?php echo $themeColors['surface']; ?>;
        --pf-surface-alt: <?php echo $themeColors['surface_alt']; ?>;
        --pf-surface-soft: <?php echo $themeColors['surface_soft']; ?>;
        --pf-text: <?php echo $themeColors['text']; ?>;
        --pf-muted: <?php echo $themeColors['muted']; ?>;
        --pf-border: <?php echo $themeColors['border']; ?>;
        --pf-hover: <?php echo $themeColors['hover']; ?>;
        --pf-font-family: <?php echo $fontStack; ?>;
        --pf-font-size-base: <?php echo $fontSizeBase; ?>;
    }

    html {
        font-size: var(--pf-font-size-base);
    }

    html *, body {
        font-family: var(--pf-font-family);
    }

    body {
        background: var(--pf-bg);
        color: var(--pf-text);
    }

    .bg-white,
    .bg-slate-50,
    .bg-gray-50 {
        background-color: var(--pf-surface-alt) !important;
    }

    .bg-gray-100,
    .bg-slate-100,
    .bg-gray-200,
    .bg-slate-200 {
        background-color: var(--pf-surface-soft) !important;
    }

    .bg-gray-300,
    .bg-slate-300 {
        background-color: var(--pf-bg) !important;
    }

    .text-slate-900,
    .text-gray-900,
    .text-gray-800,
    .text-gray-700 {
        color: var(--pf-text) !important;
    }

    .text-slate-600,
    .text-slate-500,
    .text-gray-600,
    .text-gray-500,
    .text-gray-400 {
        color: var(--pf-muted) !important;
    }

    .border,
    .border-gray-100,
    .border-gray-200,
    .border-gray-300,
    .border-slate-100,
    .border-slate-200,
    .border-slate-300 {
        border-color: var(--pf-border) !important;
    }

    input,
    select,
    textarea {
        background-color: var(--pf-surface-alt) !important;
        color: var(--pf-text) !important;
        border-color: var(--pf-border) !important;
    }

    input::placeholder,
    textarea::placeholder {
        color: var(--pf-muted) !important;
    }

    table,
    .settings-table-wrap,
    .itam-table-wrap,
    .automation-main-card,
    .automation-main-card-result,
    .automation-loading-box,
    .settings-surface,
    .settings-sidebar,
    .settings-content,
    .itam-sidebar,
    .itam-content,
    .automation-sidebar,
    .automation-content {
        background-color: var(--pf-surface-alt) !important;
        border-color: var(--pf-border) !important;
        color: var(--pf-text) !important;
    }

    .itam-sidebar,
    .automation-sidebar,
    .settings-sidebar,
    .pf-mobile-panel {
        background-color: var(--pf-surface) !important;
        border-color: var(--pf-border) !important;
    }

    .itam-nav-item,
    .automation-side-item,
    .settings-nav-item,
    .settings-nav-subitem,
    .pf-nav-pill {
        background-color: var(--pf-surface-alt) !important;
        border-color: var(--pf-border) !important;
        color: var(--pf-text) !important;
    }

    .itam-nav-item-active,
    .automation-side-item-active,
    .settings-nav-item-active,
    .settings-nav-subitem-active,
    .pf-nav-pill-active {
        background-color: var(--pf-accent-600) !important;
        border-color: var(--pf-accent-600) !important;
        color: #ffffff !important;
    }

    .itam-table th,
    .itam-table td,
    .settings-content th,
    .settings-content td,
    .automation-content th,
    .automation-content td {
        color: var(--pf-text) !important;
    }

    .itam-content-copy,
    .settings-content p,
    .automation-content p,
    .automation-content .text-gray-600,
    .settings-content .text-gray-600,
    .itam-content .text-gray-600 {
        color: var(--pf-muted) !important;
    }

    thead,
    .itam-table thead tr,
    .settings-content thead {
        background-color: var(--pf-surface-soft) !important;
    }

    tr:hover,
    .settings-data-row:hover,
    .itam-nav-item:hover,
    .automation-side-item:hover,
    .settings-nav-item:hover,
    .settings-nav-subitem:hover,
    .pf-nav-pill:hover {
        background-color: var(--pf-hover) !important;
    }

    .bg-blue-500,
    .bg-blue-600 {
        background-color: var(--pf-accent-500) !important;
    }

    .bg-blue-700,
    .bg-blue-800 {
        background-color: var(--pf-accent-700) !important;
    }

    .hover\:bg-blue-600:hover,
    .hover\:bg-blue-700:hover,
    .hover\:bg-blue-800:hover {
        background-color: var(--pf-accent-700) !important;
    }

    .text-blue-500,
    .text-blue-600,
    .text-blue-700 {
        color: var(--pf-accent-700) !important;
    }

    .border-blue-500,
    .border-blue-600,
    .border-blue-700 {
        border-color: var(--pf-accent-600) !important;
    }
    </style>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $lang['portflow']; ?></title>
    <link rel="icon" href="./includes/img/portflow.ico" type="image/x-icon">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js" integrity="sha512-v2CJ7UaYy4JwqLDIrZUI/4hqeoQieOmAZNXBeQyjo21dadnwR+8ZaIJVT8EE2iyI61OV8e6M8PP2/4hpQINQ/g==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script src="./includes/js/lucide.min.js"></script>
    <script>
        // Expose the server-configured Portflow hostname + API base to all client modules.
        window.PORTFLOW_HOSTNAME = <?php echo json_encode(rtrim((string) (defined('PORTFLOW_HOSTNAME') ? PORTFLOW_HOSTNAME : ''), '/')); ?>;
        window.PORTFLOW_API_BASE = window.PORTFLOW_HOSTNAME + '/api';
    </script>
    <script src="./includes/js/PortflowSwitch2D.js?v=<?php echo @filemtime(__DIR__ . '/js/PortflowSwitch2D.js') ?: time(); ?>"></script>
    <script src="./includes/js/PortflowCableTrace.js?v=<?php echo @filemtime(__DIR__ . '/js/PortflowCableTrace.js') ?: time(); ?>"></script>
    <style>
        .pf-header-shell {
            margin: 0.75rem;
            padding: 0.65rem 1rem;
            border-radius: 1rem;
            background: var(--pf-surface);
            border: 1px solid var(--pf-border);
            box-shadow: 0 6px 16px rgba(15, 23, 42, 0.08);
        }

        .pf-logo {
            height: 2.9rem;
            width: auto;
        }

        .pf-nav-pill {
            display: inline-flex;
            align-items: center;
            border: 1px solid var(--pf-border);
            border-radius: 9999px;
            background: var(--pf-surface-alt);
            color: var(--pf-text);
            font-weight: 600;
            padding: 0.48rem 1.05rem;
            white-space: nowrap;
            transition: 140ms ease;
        }

        .pf-nav-pill:hover {
            background: var(--pf-hover);
        }

        .pf-nav-pill-active {
            background: var(--pf-accent-600);
            border-color: var(--pf-accent-600);
            color: #ffffff;
        }

        .pf-icon-btn {
            height: 2.5rem;
            width: 2.5rem;
            border-radius: 9999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            box-shadow: 0 8px 18px rgba(15, 23, 42, 0.15);
        }

        .pf-icon-btn-gray {
            background: #94a3b8;
        }

        .pf-icon-btn-gray:hover {
            background: #64748b;
        }

        .pf-icon-btn-signout:hover {
            background: #ef4444;
        }

        .pf-icon-btn-blue {
            background: var(--pf-accent-500);
        }

        .pf-icon-btn-blue:hover {
            background: var(--pf-accent-700);
        }

        .pf-desktop-header {
            display: none;
        }

        .pf-mobile-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
        }

        .pf-mobile-drawer {
            position: fixed;
            inset: 0;
            z-index: 60;
            background: rgba(15, 23, 42, 0.35);
            opacity: 0;
            pointer-events: none;
            transition: opacity 170ms ease;
        }

        .pf-mobile-drawer.open {
            opacity: 1;
            pointer-events: auto;
        }

        .pf-mobile-panel {
            width: min(94vw, 22rem);
            height: 100%;
            background: var(--pf-surface);
            border-right: 1px solid var(--pf-border);
            padding: 1rem;
            transform: translateX(-100%);
            transition: transform 170ms ease;
            overflow-y: auto;
        }

        .pf-mobile-drawer.open .pf-mobile-panel {
            transform: translateX(0);
        }

        .pf-mobile-group {
            display: grid;
            gap: 0.7rem;
        }

        .pf-mobile-section {
            margin-top: 0.7rem;
            padding-top: 0.85rem;
            border-top: 1px solid var(--pf-border);
            display: grid;
            gap: 0.7rem;
        }

        @media (min-width: 1024px) {
            .pf-header-shell {
                margin: 0.75rem 1rem;
                padding: 0.75rem 1.1rem;
            }

            .pf-mobile-header {
                display: none;
            }

            .pf-desktop-header {
                display: grid;
                grid-template-columns: 1fr auto 1fr;
                align-items: center;
                gap: 0.75rem;
            }
        }
    </style>
</head>
<body class="static flex flex-col w-full min-h-screen item-center bg-gray-300">
<header class="pf-header-shell">
    <div class="pf-mobile-header lg:hidden">
        <!-- Logo -->
        <img class="pf-logo" src=<?php echo $selectedTheme === 'light' ? '"./includes/img/portflow.png"' : '"./includes/img/portflow-dark.png"'; ?> alt="Portflow">
        <button id="pfMobileMenuButton" class="pf-icon-btn pf-icon-btn-gray" title="Menue oeffnen" type="button">
            <i data-lucide="menu"></i>
        </button>
    </div>

    <div class="pf-desktop-header hidden lg:grid">
        <div class="flex items-center min-w-0">
            <!-- Logo -->
            <img class="pf-logo" src=<?php echo $selectedTheme === 'light' ? '"./includes/img/portflow.png"' : '"./includes/img/portflow-dark.png"'; ?> alt="Portflow">
        </div>

        <nav class="flex items-center justify-center gap-2">
            <a class="<?= $itamClass; ?>" href="itam.php" title="<?php echo $lang['it asset-management']; ?>"><?php echo $lang['itam']; ?></a>
            <?php if ($hasAutomationAccess) : ?>
            <a class="<?= $automationClass; ?>" href="automation.php" title="<?php echo $lang['automation']; ?>"><?php echo $lang['automation']; ?></a>
            <?php endif; ?>
            <a class="<?= $portviewClass; ?>" href="portview.php" title="<?php echo $lang['portview']; ?>"><?php echo $lang['portview']; ?></a>
            <a class="<?= $reportsClass; ?>" href="reports.php" title="<?php echo $lang['reports'] ?? 'Reports'; ?>"><?php echo $lang['reports'] ?? 'Reports'; ?></a>
        </nav>

        <div class="flex items-center justify-end gap-2">
            <a href="settings.php" title="<?php echo $lang['settings']; ?>" class="pf-icon-btn pf-icon-btn-blue duration-500 hover:rotate-180">
                <i data-lucide="settings"></i>
            </a>
            <a href="?signout" title="<?php echo $lang['logout']; ?>" class="pf-icon-btn pf-icon-btn-gray pf-icon-btn-signout">
                <i data-lucide="log-out"></i>
            </a>
        </div>
    </div>
</header>

<div id="pfMobileDrawer" class="pf-mobile-drawer lg:hidden">
    <aside class="pf-mobile-panel">
        <div class="flex items-center justify-between mb-4">
            <div>
                <div class="text-xl font-bold text-slate-900"><?php echo $lang['navigation'] ?? 'Navigation'; ?></div>
                <div class="text-sm text-slate-500"><?php echo $lang['module and subitems'] ?? 'Module und Unterpunkte'; ?></div>
            </div>
            <button id="pfMobileMenuClose" class="pf-icon-btn pf-icon-btn-gray" title="Menue schliessen" type="button">
                <i data-lucide="x"></i>
            </button>
        </div>

        <nav class="pf-mobile-group">
            <a class="<?= $itamClass; ?>" href="itam.php" title="<?php echo $lang['it asset-management']; ?>">
                <span><?php echo $lang['itam']; ?></span>
            </a>
            <?php if ($hasAutomationAccess) : ?>
            <a class="<?= $automationClass; ?>" href="automation.php" title="<?php echo $lang['automation']; ?>">
                <span><?php echo $lang['automation']; ?></span>
            </a>
            <?php endif; ?>
            <a class="<?= $portviewClass; ?>" href="portview.php" title="<?php echo $lang['portview']; ?>">
                <span><?php echo $lang['portview']; ?></span>
            </a>
            <a class="<?= $reportsClass; ?>" href="reports.php" title="<?php echo $lang['reports'] ?? 'Reports'; ?>">
                <span><?php echo $lang['reports'] ?? 'Reports'; ?></span>
            </a>

            <div class="pf-mobile-section">
                <a class="pf-nav-pill" href="settings.php" title="<?php echo $lang['settings']; ?>">
                    <span><?php echo $lang['settings']; ?></span>
                </a>
                <a class="pf-nav-pill" href="?signout" title="<?php echo $lang['logout']; ?>">
                    <span><?php echo $lang['logout']; ?></span>
                </a>
            </div>
        </nav>
    </aside>
</div>

<script>
    $(function () {
        var $drawer = $('#pfMobileDrawer');

        function openDrawer(open) {
            $drawer.toggleClass('open', open);
            $('body').css('overflow', open ? 'hidden' : '');
        }

        $('#pfMobileMenuButton').on('click', function () {
            openDrawer(true);
        });

        $('#pfMobileMenuClose').on('click', function () {
            openDrawer(false);
        });

        $drawer.on('click', function (event) {
            if (event.target === this) {
                openDrawer(false);
            }
        });
    });
</script>
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
    
    // Check if user has access to automation
    $hasAutomationAccess = (isset($_SESSION['uuid']) && $auth->checkResourceAccess($_SESSION['uuid'], 'automation')) ? true : false;
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <link href='https://fonts.googleapis.com/css?family=JetBrains Mono' rel='stylesheet'>
    <style>
    html * {
        font-family: 'JetBrains Mono';
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
    <style>
        .pf-header-shell {
            margin: 0.75rem;
            padding: 0.65rem 1rem;
            border-radius: 1rem;
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            box-shadow: 0 6px 16px rgba(15, 23, 42, 0.08);
        }

        .pf-logo {
            height: 2.9rem;
            width: auto;
        }

        .pf-nav-pill {
            display: inline-flex;
            align-items: center;
            border: 1px solid #cbd5e1;
            border-radius: 9999px;
            background: #ffffff;
            color: #1e293b;
            font-weight: 600;
            padding: 0.48rem 1.05rem;
            white-space: nowrap;
            transition: 140ms ease;
        }

        .pf-nav-pill:hover {
            background: #f1f5f9;
        }

        .pf-nav-pill-active {
            background: #2563eb;
            border-color: #2563eb;
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
            background: #3b82f6;
        }

        .pf-icon-btn-blue:hover {
            background: #2563eb;
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
            background: #f8fafc;
            border-right: 1px solid #cbd5e1;
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
            border-top: 1px solid #cbd5e1;
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
        <img class="pf-logo" src="./includes/img/portflow.png" alt="Portflow">
        <button id="pfMobileMenuButton" class="pf-icon-btn pf-icon-btn-gray" title="Menue oeffnen" type="button">
            <i data-lucide="menu"></i>
        </button>
    </div>

    <div class="pf-desktop-header hidden lg:grid">
        <div class="flex items-center min-w-0">
            <img class="pf-logo" src="./includes/img/portflow.png" alt="Portflow">
        </div>

        <nav class="flex items-center justify-center gap-2">
            <a class="<?= $itamClass; ?>" href="itam.php" title="<?php echo $lang['it asset-management']; ?>"><?php echo $lang['itam']; ?></a>
            <?php if ($hasAutomationAccess) : ?>
            <a class="<?= $automationClass; ?>" href="automation.php" title="<?php echo $lang['automation']; ?>"><?php echo $lang['automation']; ?></a>
            <?php endif; ?>
            <a class="<?= $portviewClass; ?>" href="portview.php" title="<?php echo $lang['portview']; ?>"><?php echo $lang['portview']; ?></a>
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
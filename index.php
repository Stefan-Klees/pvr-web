<?php
// index.php
require_once __DIR__ . '/modules/auth.php';
Auth::checkWebAccess();
require_once 'config.php';
require_once 'modules/TVHeadendAPI.php';

$page = $_GET['page'] ?? 'epg';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Street68 PVR Manager</title>

    
    <link rel="stylesheet" href="/css/style.css?v=2">

    <style>
        :root { --bg: #121212; --card: #1e1e1e; --accent: #00adb5; --text: #eeeeee; }
        body { font-family: system-ui, sans-serif; background: var(--bg); color: var(--text); margin: 0; padding: 10px; }
        nav { display: flex; gap: 10px; margin-bottom: 20px; }
        nav a { background: var(--card); color: var(--text); text-decoration: none; padding: 12px 16px; border-radius: 8px; flex: 1; text-align: center; font-weight: bold; }
        nav a.active { background: var(--accent); color: #000; }
        .container { max-width: 900px; margin: 0 auto; }
    </style>
</head>
<body>
    <div class="container">
        <h2>PVR Manager - street68.net</h2>
        <nav>
            <a href="?page=epg" class="<?= $page === 'epg' ? 'active' : '' ?>">EPG</a>
            <a href="?page=upcoming" class="<?= $page === 'upcoming' ? 'active' : '' ?>">Timer</a>
            <a href="?page=finished" class="<?= $page === 'finished' ? 'active' : '' ?>">Archiv</a>
        </nav>

        <main>
            <?php
            $viewFile = "view/{$page}.php";
            if (file_exists($viewFile)) {
                include $viewFile;
            } else {
                include 'view/epg.php';
            }
            ?>
        </main>
    </div>
</body>
</html>
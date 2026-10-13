<?php
// login.php
require_once __DIR__ . '/modules/auth.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $enteredPass = $_POST['password'] ?? '';

    if (hash_equals(AUTH_PASSWORD, $enteredPass)) {
        $_SESSION['pvr_authenticated'] = true;
        header('Location: index.php');
        exit;
    } else {
        $error = 'Falsches Passwort';
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PVR Login</title>
    <style>
        :root {
            --bg: #141414;
            --card: #1f1f1f;
            --accent: #00adb5;
            --text: #e0e0e0;
        }
        body {
            background-color: var(--bg);
            color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            margin: 0;
        }
        .login-card {
            background-color: var(--card);
            border: 1px solid #2a2a2a;
            border-radius: 12px;
            padding: 30px;
            width: 100%;
            max-width: 360px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.5);
            text-align: center;
        }
        h2 {
            margin-top: 0;
            color: #fff;
            margin-bottom: 20px;
        }
        input[type="password"] {
            width: 100%;
            padding: 12px;
            background: #141414;
            border: 1px solid #333;
            border-radius: 6px;
            color: #fff;
            font-size: 1em;
            margin-bottom: 15px;
            box-sizing: border-box;
            outline: none;
        }
        input[type="password"]:focus {
            border-color: var(--accent);
        }
        button {
            width: 100%;
            padding: 12px;
            background: var(--accent);
            border: none;
            border-radius: 6px;
            color: #fff;
            font-size: 1em;
            font-weight: bold;
            cursor: pointer;
            transition: background 0.2s;
        }
        button:hover {
            background: #00848a;
        }
        .error {
            color: #ff4d4d;
            margin-bottom: 15px;
            font-size: 0.9em;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <h2>PVR Login</h2>
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="POST">
            <input type="password" name="password" placeholder="Passwort eingeben" autofocus required>
            <button type="submit">Anmelden</button>
        </form>
    </div>
</body>
</html>
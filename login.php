<?php
require __DIR__ . '/src/bootstrap.php';
Auth::startSession();

if (Auth::check()) {
    header('Location: index.php');
    exit;
}

$error = '';
$notice = '';
$setup = Auth::needsSetup();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::csrfCheck($_POST['csrf'] ?? null)) {
        $error = 'Sessione scaduta, riprova.';
    } elseif (($_POST['do'] ?? '') === 'setup') {
        $u = (string)($_POST['username'] ?? '');
        $p1 = (string)($_POST['password'] ?? '');
        $p2 = (string)($_POST['password2'] ?? '');
        if (strlen($p1) < 6) {
            $error = 'La password deve avere almeno 6 caratteri.';
        } elseif ($p1 !== $p2) {
            $error = 'Le due password non coincidono.';
        } elseif (Auth::setupPassword($u, $p1)) {
            $notice = 'Password impostata. Ora accedi.';
            $setup = false;
        } else {
            $error = 'Impossibile salvare (permessi sulla cartella data/?).';
        }
    } else {
        $u = (string)($_POST['username'] ?? '');
        $p = (string)($_POST['password'] ?? '');
        if (Auth::attempt($u, $p)) {
            header('Location: index.php');
            exit;
        }
        $error = 'Credenziali non valide.';
    }
}

$csrf = Auth::csrfToken();
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Freezanz Controller · Accesso</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="login-page">
<div class="card login-card">
    <h1>❄️ Freezanz Controller</h1>
    <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($notice): ?><div class="alert ok"><?= e($notice) ?></div><?php endif; ?>

    <?php if ($setup): ?>
        <p class="muted">Primo avvio: imposta le credenziali della dashboard.</p>
        <form method="post" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="do" value="setup">
            <label>Username
                <input type="text" name="username" value="<?= e(Auth::creds()['user']) ?>" required>
            </label>
            <label>Password (min 6 caratteri)
                <input type="password" name="password" required>
            </label>
            <label>Ripeti password
                <input type="password" name="password2" required>
            </label>
            <button type="submit">Crea accesso</button>
        </form>
    <?php else: ?>
        <form method="post" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <label>Username
                <input type="text" name="username" required autofocus>
            </label>
            <label>Password
                <input type="password" name="password" required>
            </label>
            <button type="submit">Entra</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>

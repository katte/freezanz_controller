<?php
require __DIR__ . '/src/bootstrap.php';
Auth::requireLogin();

$cfg = config();
$flash = '';
$flashType = 'ok';
$errors = [];

// ---- Gestione azioni (POST) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::csrfCheck($_POST['csrf'] ?? null)) {
        $flash = 'Sessione scaduta, riprova.';
        $flashType = 'error';
    } else {
        $action = $_POST['action'] ?? '';
        switch ($action) {
            case 'save_schedule':
                $res = Scheduler::validate([
                    'enabled' => isset($_POST['enabled']),
                    'week' => $_POST['week'] ?? [],
                ]);
                if ($res['errors']) {
                    $errors = $res['errors'];
                    $flash = 'Schedule NON salvato: correggi gli errori.';
                    $flashType = 'error';
                } else {
                    Store::writeJson('schedule.json', $res['schedule']);
                    Store::log('Schedule aggiornato dalla dashboard (' . ($_SESSION['auth_user'] ?? '?') . ').');
                    $flash = 'Schedule salvato.';
                }
                break;

            case 'run_now':
                $r = Controller::run();
                $flash = 'Controllo eseguito: ' . strtoupper($r['action'] ?? '?')
                    . (isset($r['message']) ? ' — ' . $r['message'] : '');
                $flashType = ($r['action'] === 'error') ? 'error' : 'ok';
                break;

            case 'test_login':
                $client = new MyFreezanzClient($cfg);
                $lr = $client->testConnection();
                $flash = 'Test login myfreezanz: ' . $lr['message'];
                $flashType = $lr['ok'] ? 'ok' : 'error';
                break;

            case 'send_start':
            case 'send_stop':
                $cmd = $action === 'send_start' ? 'start' : 'stop';
                $client = new MyFreezanzClient($cfg);
                $sr = $client->sendCommand($cmd);
                if ($sr['ok']) {
                    // Aggiorna lo stato noto per coerenza con lo schedule.
                    Store::writeJson('state.json', [
                        'last_state' => $cmd === 'start',
                        'last_command' => $cmd,
                        'last_change' => now_app()->format('Y-m-d H:i:s'),
                        'updated_at' => now_app()->format('Y-m-d H:i:s'),
                    ]);
                    Store::log("Invio manuale '$cmd' dalla dashboard -> " . $sr['message']);
                }
                $flash = "Invio manuale '$cmd': " . $sr['message'];
                $flashType = $sr['ok'] ? 'ok' : 'error';
                break;
        }
    }
    // PRG: evita re-invii al refresh, mantenendo eventuali errori in sessione.
    if (!$errors) {
        $_SESSION['flash'] = $flash;
        $_SESSION['flash_type'] = $flashType;
        header('Location: index.php');
        exit;
    }
}

if (empty($flash) && !empty($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    $flashType = $_SESSION['flash_type'] ?? 'ok';
    unset($_SESSION['flash'], $_SESSION['flash_type']);
}

// ---- Dati per la vista ----
$schedule = Store::readJson('schedule.json', Scheduler::defaultSchedule());
$state = Store::readJson('state.json', ['last_state' => null]);
$now = now_app();
$target = Scheduler::isOn($schedule, $now);
$next = Scheduler::nextTransition($schedule, $now);
$csrf = Auth::csrfToken();

$commandConfigured = !empty($cfg['command']['url']) && !empty($cfg['command']['start']) && !empty($cfg['command']['stop']);
$freezanzConfigured = !empty($cfg['freezanz_user']) && !empty($cfg['freezanz_pass']);

// URL del cron per cron-jobs.org
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'TUOSITO';
$dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$cronUrl = $scheme . '://' . $host . $dir . '/cron.php?key=' . rawurlencode((string)($cfg['cron_secret'] ?? ''));

$dayLabels = ['mon' => 'Lunedì', 'tue' => 'Martedì', 'wed' => 'Mercoledì', 'thu' => 'Giovedì', 'fri' => 'Venerdì', 'sat' => 'Sabato', 'sun' => 'Domenica'];
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Freezanz Controller</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar">
    <div class="brand">❄️ Freezanz Controller</div>
    <div class="topbar-right">
        <span class="muted"><?= e($_SESSION['auth_user'] ?? '') ?></span>
        <a class="btn ghost" href="logout.php">Esci</a>
    </div>
</header>

<main class="container">

<?php if ($flash): ?>
    <div class="alert <?= e($flashType) ?>"><?= e($flash) ?></div>
<?php endif; ?>
<?php foreach ($errors as $err): ?>
    <div class="alert error"><?= e($err) ?></div>
<?php endforeach; ?>

<?php if (!$freezanzConfigured || !$commandConfigured): ?>
    <div class="alert warn">
        <strong>Configurazione incompleta.</strong>
        <?php if (!$freezanzConfigured): ?> Mancano le credenziali myfreezanz in <code>config.php</code>.<?php endif; ?>
        <?php if (!$commandConfigured): ?> L'endpoint del comando Start/Stop non è ancora configurato (serve la cattura HAR — vedi README).<?php endif; ?>
    </div>
<?php endif; ?>

<!-- STATO ATTUALE -->
<section class="card">
    <h2>Stato attuale</h2>
    <div class="grid status-grid">
        <div>
            <div class="label">Schedule</div>
            <div class="value"><?= !empty($schedule['enabled']) ? '<span class="pill on">ATTIVO</span>' : '<span class="pill off">DISATTIVO</span>' ?></div>
        </div>
        <div>
            <div class="label">Stato desiderato ora</div>
            <div class="value"><?= $target ? '<span class="pill on">ON</span>' : '<span class="pill off">OFF</span>' ?></div>
        </div>
        <div>
            <div class="label">Ultimo comando inviato</div>
            <div class="value">
                <?php if (($state['last_state'] ?? null) === null): ?>
                    <span class="muted">nessuno</span>
                <?php else: ?>
                    <?= strtoupper(e($state['last_command'] ?? '')) ?>
                    <span class="muted small">(<?= e($state['last_change'] ?? '') ?>)</span>
                <?php endif; ?>
            </div>
        </div>
        <div>
            <div class="label">Prossima transizione</div>
            <div class="value">
                <?php if ($next): ?>
                    <?= $next['state'] ? 'ON' : 'OFF' ?> il <?= e($next['at']->format('D d/m H:i')) ?>
                <?php else: ?>
                    <span class="muted">—</span>
                <?php endif; ?>
            </div>
        </div>
        <div>
            <div class="label">Ora server (<?= e($cfg['timezone']) ?>)</div>
            <div class="value"><?= e($now->format('D d/m/Y H:i:s')) ?></div>
        </div>
    </div>

    <form method="post" class="actions">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <button class="btn" name="action" value="run_now">▶ Esegui controllo ora</button>
        <button class="btn ghost" name="action" value="test_login" <?= $freezanzConfigured ? '' : 'disabled' ?>>Testa login myfreezanz</button>
        <button class="btn ghost" name="action" value="send_start" <?= $commandConfigured ? '' : 'disabled' ?>>Invia START ora</button>
        <button class="btn ghost" name="action" value="send_stop" <?= $commandConfigured ? '' : 'disabled' ?>>Invia STOP ora</button>
    </form>
</section>

<!-- SCHEDULE SETTIMANALE -->
<section class="card">
    <h2>Schedule settimanale</h2>
    <p class="muted">Per ogni giorno scegli la modalità: <strong>Fasce orarie</strong> (ON nelle fasce indicate, OFF fuori), <strong>Tutto il giorno ON</strong> o <strong>Tutto il giorno OFF</strong>. Una fascia con orario di fine minore dell'inizio (es. 22:00 → 06:00) attraversa la mezzanotte.</p>
    <form method="post" id="scheduleForm">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="save_schedule">

        <label class="switch">
            <input type="checkbox" name="enabled" <?= !empty($schedule['enabled']) ? 'checked' : '' ?>>
            Schedule attivo (se disattivo, il cron non invia comandi)
        </label>

        <div class="days">
        <?php foreach ($dayLabels as $key => $label): ?>
            <?php
                $dayCfg = Scheduler::normalizeDay($schedule['week'][$key] ?? []);
                $mode = $dayCfg['mode'];
                $rows = $dayCfg['windows'];
            ?>
            <div class="day mode-<?= e($mode) ?>" data-day="<?= e($key) ?>" data-next-idx="<?= count($rows) ?>">
                <div class="day-head">
                    <strong><?= e($label) ?></strong>
                </div>
                <div class="day-mode">
                    <select name="week[<?= e($key) ?>][mode]" class="mode-select">
                        <option value="custom" <?= $mode === 'custom' ? 'selected' : '' ?>>Fasce orarie</option>
                        <option value="on"  <?= $mode === 'on'  ? 'selected' : '' ?>>Tutto il giorno ON</option>
                        <option value="off" <?= $mode === 'off' ? 'selected' : '' ?>>Tutto il giorno OFF</option>
                    </select>
                </div>
                <div class="rows-wrap">
                    <div class="rows">
                        <?php if (!$rows): ?>
                            <div class="row empty muted">nessuna fascia (giorno OFF)</div>
                        <?php else: ?>
                            <?php foreach ($rows as $i => $w): ?>
                                <?php $wEnabled = Scheduler::windowEnabled($w); ?>
                                <div class="row<?= $wEnabled ? '' : ' disabled' ?>">
                                    <label class="row-toggle" title="Abilita / disabilita questa fascia">
                                        <input type="checkbox" name="week[<?= e($key) ?>][enabled][<?= $i ?>]" value="1" <?= $wEnabled ? 'checked' : '' ?>>
                                    </label>
                                    ON <input type="time" name="week[<?= e($key) ?>][on][<?= $i ?>]" value="<?= e($w['on'] ?? '') ?>">
                                    OFF <input type="time" name="week[<?= e($key) ?>][off][<?= $i ?>]" value="<?= e($w['off'] ?? '') ?>">
                                    <button type="button" class="btn tiny ghost del-row">✕</button>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="btn tiny add-row">+ fascia</button>
                </div>
            </div>
        <?php endforeach; ?>
        </div>

        <div class="actions">
            <button class="btn" type="submit">💾 Salva schedule</button>
        </div>
    </form>
</section>

<!-- CRON URL -->
<section class="card">
    <h2>Attivazione cron (cron-jobs.org)</h2>
    <p class="muted">Crea su <a href="https://cron-jobs.org" target="_blank" rel="noopener">cron-jobs.org</a> un job che, <strong>ogni minuto</strong>, apre questo URL:</p>
    <div class="cron-url">
        <input type="text" readonly value="<?= e($cronUrl) ?>" onclick="this.select()">
    </div>
    <p class="muted small">Tienilo segreto: chi conosce questo URL può far girare il controllo. Puoi cambiare la chiave in <code>config.php</code> (<code>cron_secret</code>).</p>
</section>

<!-- LOG -->
<section class="card">
    <h2>Log recenti</h2>
    <pre class="log"><?php
        $lines = Store::tailLog(40);
        echo $lines ? e(implode("\n", array_reverse($lines))) : 'nessuna attività registrata';
    ?></pre>
</section>

</main>

<template id="rowTpl">
    <div class="row">
        <label class="row-toggle" title="Abilita / disabilita questa fascia">
            <input type="checkbox" value="1" checked data-name-enabled>
        </label>
        ON <input type="time" data-name-on>
        OFF <input type="time" data-name-off>
        <button type="button" class="btn tiny ghost del-row">✕</button>
    </div>
</template>

<script>
document.addEventListener('click', function (ev) {
    var t = ev.target;
    if (t.classList.contains('add-row')) {
        var day = t.closest('.day');
        var key = day.getAttribute('data-day');
        var rows = day.querySelector('.rows');
        var empty = rows.querySelector('.row.empty');
        if (empty) empty.remove();
        // Indice progressivo per riga: mai riusato, cosi' on/off/enabled restano allineati.
        var idx = parseInt(day.dataset.nextIdx || '0', 10);
        day.dataset.nextIdx = idx + 1;
        var tpl = document.getElementById('rowTpl').content.cloneNode(true);
        tpl.querySelector('[data-name-on]').name = 'week[' + key + '][on][' + idx + ']';
        tpl.querySelector('[data-name-off]').name = 'week[' + key + '][off][' + idx + ']';
        tpl.querySelector('[data-name-enabled]').name = 'week[' + key + '][enabled][' + idx + ']';
        rows.appendChild(tpl);
    }
    if (t.classList.contains('del-row')) {
        t.closest('.row').remove();
    }
});

// Riflette visivamente lo stato della checkbox sulla riga (fascia attenuata se OFF).
document.addEventListener('change', function (ev) {
    var cb = ev.target;
    if (cb.matches('.row-toggle input[type="checkbox"]')) {
        cb.closest('.row').classList.toggle('disabled', !cb.checked);
    }
});

// Mostra le fasce solo in modalità "Fasce orarie".
function applyDayMode(day) {
    var sel = day.querySelector('.mode-select');
    var wrap = day.querySelector('.rows-wrap');
    var isCustom = sel.value === 'custom';
    if (wrap) wrap.style.display = isCustom ? '' : 'none';
    day.classList.remove('mode-custom', 'mode-on', 'mode-off');
    day.classList.add('mode-' + sel.value);
}
document.querySelectorAll('.day').forEach(applyDayMode);
document.addEventListener('change', function (ev) {
    if (ev.target.classList.contains('mode-select')) {
        applyDayMode(ev.target.closest('.day'));
    }
});
</script>
</body>
</html>

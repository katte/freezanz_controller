# Freezanz Controller

Piccola applicazione PHP che, in base a uno **schedule settimanale**, invia i comandi
**Start/Stop** alla macchina su `myfreezanz.it`.

Funziona su qualsiasi hosting PHP condiviso. Un servizio esterno (**cron-jobs.org**)
chiama `cron.php` **ogni minuto**; l'applicazione confronta lo stato desiderato dallo
schedule con l'ultimo comando inviato e, **solo alla transizione** (OFF→ON o ON→OFF),
effettua il login e invia il comando.

Lo schedule si modifica da una **dashboard web** protetta da username e password.

---

## 1. Requisiti

- PHP 7.4+ (va bene anche 8.x)
- Estensione **cURL** attiva (quasi sempre presente)
- Hosting con Apache/LiteSpeed (usa i file `.htaccess`) o IIS (usa i `web.config`)

## 2. Struttura

```
config.php            <- credenziali e impostazioni (DA COMPILARE)
config.example.php    <- modello di riferimento
index.php             <- dashboard
login.php / logout.php
cron.php              <- endpoint chiamato da cron-jobs.org
assets/style.css
src/                  <- logica (classi PHP)
data/                 <- schedule.json, state.json, log (creati da soli, protetti)
doc/                  <- il file .har di analisi
```

## 3. Installazione

1. Carica **tutta la cartella** nell'hosting (es. `public_html/freezanz/`).
2. Assicurati che la cartella `data/` sia **scrivibile** dal web server
   (permessi `755`/`775`; se necessario `chmod 775 data`).
3. Apri `config.php` e compila:
   - `freezanz_user` / `freezanz_pass` → le tue credenziali di myfreezanz.it
   - `cron_secret` → è già impostata una chiave casuale; puoi cambiarla
   - `timezone` → `Europe/Rome` (già impostato)
4. Vai su `https://TUOSITO/freezanz/login.php`. Al **primo accesso** ti chiede di
   impostare username e password della dashboard (salvati in `data/auth.json`).
   In alternativa puoi impostarli in `config.php` (`dashboard_user` +
   `dashboard_pass_hash`, generato con
   `php -r "echo password_hash('LATUAPASSWORD', PASSWORD_DEFAULT);"`).

## 4. Impostare lo schedule

Nella dashboard, sezione **Schedule settimanale**, ogni giorno ha una modalità:

- **Fasce orarie**: aggiungi una o più **fasce ON** (accensione → spegnimento);
  fuori dalle fasce la macchina è **OFF**. Una fascia con fine < inizio
  (es. `22:00 → 06:00`) attraversa la mezzanotte.
- **Tutto il giorno ON**: l'intera giornata è ON (le fasce vengono ignorate).
- **Tutto il giorno OFF**: l'intera giornata è OFF (le fasce vengono ignorate).

Poi spunta **"Schedule attivo"** e premi **Salva**.

Con il pulsante **"Esegui controllo ora"** provi subito la logica senza aspettare il cron.

## 5. Attivare cron-jobs.org

1. Nella dashboard, sezione **Attivazione cron**, copia l'URL mostrato:
   `https://TUOSITO/freezanz/cron.php?key=LACHIAVE`
2. Su [cron-jobs.org](https://cron-jobs.org) crea un account e un nuovo **Cronjob**:
   - URL: quello copiato
   - Schedule: **ogni minuto** (Every 1 minute)
   - Salva.

Il job restituisce un testo di stato (`NOOP`, `SENT`, `DISABLED`, `ERROR`,
`NOT_CONFIGURED`) visibile nella cronologia di cron-jobs.org.

## 6. Comando Start/Stop (già configurato)

Il 30/09 myfreezanz.it ha cambiato interfaccia. Dal nuovo HAR sono stati ricavati
login e comandi, **già inseriti** in `config.php`.

**Login** (gestito da `MyFreezanzClient::login()`):

- `GET  https://myfreezanz.it/new/login/index.php` → cookie `PHPSESSID`
- `POST https://myfreezanz.it/new/login/index.php` con `username=..&password=..&lingua=it`
- Successo = redirect `302` a `../cliente/admin-cliente.php` (seguito, come fa il browser);
  se si resta sulla pagina di login le credenziali sono state rifiutate.
- La pagina ora chiede l'**email**: il vecchio username funziona ancora; se smette,
  metti l'email in `freezanz_user`.

**Comandi** (`POST https://myfreezanz.it/new/impianti/dati.php`, AJAX):

- **START** ("MIST") → `comando=INVIA_COMANDO_UTY&matricola=03624&uty=02`
- **STOP** → `comando=INVIA_COMANDO_UTY&matricola=03624&uty=01`
- Il server **accoda** il comando e risponde `{"success":true,"message":"Comando inserito con successo",...}`;
  la macchina lo ritira poco dopo.
- Stato macchina (usato da "Testa login") → `comando=CARICA_STATI_MACCHINA&matricola=03624`

```php
'command' => [
    'url'    => 'https://myfreezanz.it/new/impianti/dati.php',
    'method' => 'POST',
    'start'  => ['comando' => 'INVIA_COMANDO_UTY', 'matricola' => '{MACHINE}', 'uty' => '02'],
    'stop'   => ['comando' => 'INVIA_COMANDO_UTY', 'matricola' => '{MACHINE}', 'uty' => '01'],
    'status' => ['comando' => 'CARICA_STATI_MACCHINA', 'matricola' => '{MACHINE}'],
    'prime_url' => '',
    'success_json' => 'success',
],
'machine_id' => '03624',
```

`{MACHINE}` viene sostituito automaticamente con `machine_id` (la matricola).

Consiglio: dopo ogni modifica, dalla dashboard premi **"Testa login myfreezanz"**
(fa login e legge lo stato macchina, senza inviare comandi) e poi **"Invia STOP ora"**
per una prova reale end-to-end.

## 7. Come funziona (logica)

- `cron.php` (ogni minuto) → `Controller::run()`
- Calcola lo **stato desiderato** dallo schedule (`Scheduler::isOn`) per l'ora corrente.
- Legge `data/state.json` (ultimo stato inviato).
- Se **coincidono** → non fa nulla (`NOOP`): niente login, carico minimo.
- Se **cambiano** → login a myfreezanz e invio `start`/`stop`.
  - Successo → aggiorna `state.json` e scrive nel log.
  - Errore → **non** aggiorna lo stato, così riproverà al minuto successivo.

## 8. Sicurezza

- `data/` è protetta da `.htaccess`/`web.config` (nessun accesso web a schedule, stato,
  log, credenziali dashboard).
- La dashboard usa sessioni PHP, hashing della password (`password_hash`) e token CSRF.
- `cron.php` richiede la chiave segreta `cron_secret`.
- Usa **HTTPS** sull'hosting (per proteggere login e comandi).

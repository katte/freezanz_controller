<?php
/**
 * Configurazione Freezanz Controller.
 *
 * Copia questo file in "config.php" e compila i valori.
 * config.php NON deve essere condiviso (contiene credenziali).
 */
return [

    // ============================================================
    //  DASHBOARD  (la piccola interfaccia web per lo schedule)
    // ============================================================
    'dashboard_user' => 'admin',

    // Hash della password della dashboard.
    // Generalo con:  php -r "echo password_hash('LATUAPASSWORD', PASSWORD_DEFAULT);"
    // Oppure lascia '' : al primo accesso a login.php comparira' un setup guidato.
    'dashboard_pass_hash' => '',

    // ============================================================
    //  SICUREZZA ENDPOINT CRON
    // ============================================================
    // Chiave segreta che cron-jobs.org deve passare nell'URL:
    //   https://TUOSITO/cron.php?key=QUESTA_CHIAVE
    'cron_secret' => 'CAMBIA_QUESTA_CHIAVE',

    // ============================================================
    //  CREDENZIALI myfreezanz.it
    // ============================================================
    'freezanz_user' => '',   // email (o il vecchio username, se ancora accettato)
    'freezanz_pass' => '',
    'freezanz_base' => 'https://myfreezanz.it/new/',

    // ============================================================
    //  FUSO ORARIO usato per interpretare lo schedule
    // ============================================================
    'timezone' => 'Europe/Rome',

    // ============================================================
    //  COMANDO MACCHINA
    // ============================================================
    // Valori reali ricavati dall'HAR del 30/09 (nuova interfaccia):
    // START = "MIST" -> uty=02 ; STOP -> uty=01.
    // La stringa {MACHINE} viene sostituita con il valore di 'machine_id'.
    'command' => [
        'url'    => 'https://myfreezanz.it/new/impianti/dati.php',
        'method' => 'POST',    // 'POST' o 'GET'
        'start'  => ['comando' => 'INVIA_COMANDO_UTY', 'matricola' => '{MACHINE}', 'uty' => '02'],
        'stop'   => ['comando' => 'INVIA_COMANDO_UTY', 'matricola' => '{MACHINE}', 'uty' => '01'],

        // (opzionale) lettura dello stato macchina, usata da "Testa login myfreezanz".
        'status' => ['comando' => 'CARICA_STATI_MACCHINA', 'matricola' => '{MACHINE}'],

        // (opzionale) pagina da visitare prima del comando per inizializzare
        // la sessione / ottenere un eventuale token.
        'prime_url' => '',

        // (opzionale) esito atteso per risposte JSON: il comando è considerato
        // riuscito solo se questo campo della risposta vale true.
        'success_json' => 'success',

        // (opzionale) esito atteso per risposte testuali: il comando è
        // considerato riuscito solo se la risposta (trim) coincide.
        'success_equals' => '',
    ],

    // Seriale / matricola della macchina, usato al posto di {MACHINE}.
    'machine_id' => '',
];

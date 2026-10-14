<?php
declare(strict_types=1);

/**
 * Client HTTP verso myfreezanz.it: login e invio comando alla macchina.
 *
 * Login (nuova interfaccia, ricavato dall'HAR del 30/09):
 *   GET  {base}login/index.php                      -> cookie PHPSESSID
 *   POST {base}login/index.php  con  username=..&password=..&lingua=it
 *   Successo = redirect 302 verso ../cliente/admin-cliente.php;
 *   in caso di errore si resta sulla pagina di login.
 *
 * Invio comando: configurato in config.php -> 'command'
 *   POST {base}impianti/dati.php  comando=INVIA_COMANDO_UTY&matricola=..&uty=02|01
 *   Risposta JSON {"success":true,...}.
 */
final class MyFreezanzClient
{
    private array $cfg;
    private string $cookieFile;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
        // Cookie jar per riusare la sessione tra login e comando.
        $this->cookieFile = sys_get_temp_dir()
            . '/freezanz_cookies_' . md5(($cfg['freezanz_user'] ?? '') . APP_ROOT) . '.txt';
    }

    private function base(): string
    {
        return rtrim($this->cfg['freezanz_base'] ?? 'https://myfreezanz.it/new/', '/') . '/';
    }

    /**
     * Esegue una richiesta HTTP con cURL, riusando il cookie jar.
     * @param string[] $headers header aggiuntivi ("Nome: valore")
     * @return array{ok:bool, status:int, body:string, error:string, url:string}
     */
    private function http(string $url, string $method, array $fields = [], string $referer = '', array $headers = []): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'Estensione cURL non disponibile su questo hosting.', 'url' => $url];
        }
        $ch = curl_init();
        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_COOKIEFILE => $this->cookieFile,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (FreezanzController)',
            CURLOPT_ENCODING => '', // accetta gzip/deflate: la pagina cliente e' pesante
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($referer !== '') {
            $opts[CURLOPT_REFERER] = $referer;
        }
        if (!empty($headers)) {
            $opts[CURLOPT_HTTPHEADER] = $headers;
        }
        if (strtoupper($method) === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = http_build_query($fields);
        } elseif (!empty($fields)) {
            $sep = str_contains($url, '?') ? '&' : '?';
            $opts[CURLOPT_URL] = $url . $sep . http_build_query($fields);
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effective = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $err = curl_error($ch);
        unset($ch); // chiude l'handle e salva il cookie jar (curl_close e' deprecata da PHP 8.5)

        if ($body === false) {
            return ['ok' => false, 'status' => $status, 'body' => '', 'error' => $err ?: 'Errore cURL', 'url' => $effective];
        }
        return ['ok' => $status >= 200 && $status < 400, 'status' => $status, 'body' => (string)$body, 'error' => '', 'url' => $effective];
    }

    /** POST AJAX verso l'endpoint dati, come fa la pagina cliente. */
    private function ajax(string $url, array $payload): array
    {
        return $this->http($url, 'POST', $payload, $this->base() . 'cliente/admin-cliente.php', [
            'X-Requested-With: XMLHttpRequest',
            'Accept: application/json, text/javascript, */*; q=0.01',
        ]);
    }

    /** Sostituisce {MACHINE} con la matricola nei valori del payload. */
    private function fillPayload(array $tpl): array
    {
        $machine = (string)($this->cfg['machine_id'] ?? '');
        $payload = [];
        foreach ($tpl as $k => $v) {
            $payload[$k] = is_string($v) ? str_replace('{MACHINE}', $machine, $v) : $v;
        }
        return $payload;
    }

    /**
     * Login a myfreezanz.
     * @return array{ok:bool, message:string}
     */
    public function login(): array
    {
        $user = (string)($this->cfg['freezanz_user'] ?? '');
        $pass = (string)($this->cfg['freezanz_pass'] ?? '');
        if ($user === '' || $pass === '') {
            return ['ok' => false, 'message' => 'Credenziali myfreezanz mancanti in config.php.'];
        }
        // Cookie jar pulito ad ogni login "usa e getta".
        @unlink($this->cookieFile);

        $url = $this->base() . 'login/index.php';

        // 1) Pagina di login: ottiene il cookie di sessione, come il browser.
        $this->http($url, 'GET');

        // 2) Invio form. Il redirect viene seguito fino alla pagina cliente,
        //    che inizializza la sessione lato server.
        $res = $this->http($url, 'POST', [
            'username' => $user,
            'password' => $pass,
            'lingua' => (string)($this->cfg['freezanz_lang'] ?? 'it'),
        ], $this->base() . 'login/');

        if (!$res['ok']) {
            return ['ok' => false, 'message' => 'Login HTTP ' . $res['status'] . ' ' . $res['error']];
        }
        // Se dopo il POST siamo ancora sulla pagina di login, le credenziali sono state rifiutate.
        $path = (string)parse_url($res['url'], PHP_URL_PATH);
        if (stripos($path, '/login') !== false) {
            $detail = '';
            if (preg_match('/class="error-text"[^>]*>(.*?)<\//s', $res['body'], $m)) {
                $detail = ' Messaggio: ' . trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8'));
            }
            return ['ok' => false, 'message' => 'Login rifiutato (username/email o password errati).' . $detail];
        }
        return ['ok' => true, 'message' => 'Login riuscito.'];
    }

    /**
     * Legge lo stato attuale della macchina (sessione gia' autenticata).
     * @return array{ok:bool, message:string}
     */
    public function machineStatus(): array
    {
        $cmdCfg = $this->cfg['command'] ?? [];
        $url = trim((string)($cmdCfg['url'] ?? ''));
        $tpl = $cmdCfg['status'] ?? [];
        if ($url === '' || empty($tpl)) {
            return ['ok' => false, 'message' => 'Lettura stato non configurata.'];
        }
        $res = $this->ajax($url, $this->fillPayload($tpl));
        $json = json_decode(trim($res['body']), true);
        if (!$res['ok'] || !is_array($json) || ($json['success'] ?? false) !== true) {
            return ['ok' => false, 'message' => 'Stato macchina non leggibile (HTTP ' . $res['status'] . ').'];
        }
        $label = (string)($json['stato_macchina']['label'] ?? '?');
        $sub = (string)($json['sub_stato_macchina']['label'] ?? '');
        $ts = (string)($json['timestamp'] ?? '');
        return [
            'ok' => true,
            'message' => 'Stato macchina: ' . $label . ($sub !== '' ? " ($sub)" : '')
                . ($ts !== '' ? ", aggiornato $ts" : '') . '.',
        ];
    }

    /**
     * Login + lettura stato macchina: verifica che la sessione funzioni davvero.
     * @return array{ok:bool, message:string}
     */
    public function testConnection(): array
    {
        $login = $this->login();
        if (!$login['ok']) {
            return $login;
        }
        $status = $this->machineStatus();
        return ['ok' => $status['ok'], 'message' => $login['message'] . ' ' . $status['message']];
    }

    /**
     * Invia un comando ('start' o 'stop') dopo aver effettuato il login.
     * @return array{ok:bool, message:string, code?:string}
     */
    public function sendCommand(string $command): array
    {
        $command = strtolower($command);
        if (!in_array($command, ['start', 'stop'], true)) {
            return ['ok' => false, 'message' => "Comando sconosciuto: $command"];
        }

        $cmdCfg = $this->cfg['command'] ?? [];
        $url = trim((string)($cmdCfg['url'] ?? ''));
        $payloadTpl = $cmdCfg[$command] ?? [];

        // Endpoint non ancora configurato: nessun tentativo, esito esplicito.
        if ($url === '' || empty($payloadTpl)) {
            return [
                'ok' => false,
                'code' => 'not_configured',
                'message' => "Endpoint comando '$command' non configurato. "
                    . "Completa la sezione 'command' in config.php (vedi README).",
            ];
        }

        // 1) Login.
        $login = $this->login();
        if (!$login['ok']) {
            return ['ok' => false, 'message' => 'Comando non inviato: ' . $login['message']];
        }

        // 2) (opzionale) pagina di prime per token/sessione.
        $prime = trim((string)($cmdCfg['prime_url'] ?? ''));
        if ($prime !== '') {
            $this->http($prime, 'GET');
        }

        // 3) Sostituzione {MACHINE} nel payload e invio.
        $payload = $this->fillPayload($payloadTpl);
        $method = strtoupper((string)($cmdCfg['method'] ?? 'POST'));
        $res = $method === 'POST'
            ? $this->ajax($url, $payload)
            : $this->http($url, $method, $payload, $this->base());

        if (!$res['ok']) {
            return ['ok' => false, 'message' => "Comando '$command' HTTP " . $res['status'] . ' ' . $res['error']];
        }

        $body = trim($res['body']);

        // Risposta JSON: il comando e' accettato se il campo indicato vale true.
        $jsonKey = (string)($cmdCfg['success_json'] ?? '');
        if ($jsonKey !== '') {
            $json = json_decode($body, true);
            if (!is_array($json) || ($json[$jsonKey] ?? null) !== true) {
                $why = is_array($json)
                    ? (string)($json['error'] ?? $json['message'] ?? substr($body, 0, 120))
                    : 'risposta non JSON: ' . substr($body, 0, 120);
                return ['ok' => false, 'message' => "Comando '$command' NON accettato dal server ($why)."];
            }
            return [
                'ok' => true,
                'message' => "Comando '$command' inviato (HTTP " . $res['status'] . ', "'
                    . substr((string)($json['message'] ?? 'ok'), 0, 60) . '").',
            ];
        }

        // Risposta testuale: se configurato, verifica che coincida con l'esito atteso.
        $expected = $cmdCfg['success_equals'] ?? null;
        if ($expected !== null && $expected !== '' && $body !== (string)$expected) {
            return [
                'ok' => false,
                'message' => "Comando '$command' NON accettato dal server. "
                    . "Atteso '$expected', ricevuto '" . substr($body, 0, 120) . "'.",
            ];
        }

        return [
            'ok' => true,
            'message' => "Comando '$command' inviato (HTTP " . $res['status'] . ', risposta "'
                . substr($body, 0, 60) . '").',
        ];
    }
}

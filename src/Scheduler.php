<?php
declare(strict_types=1);

/**
 * Logica dello schedule settimanale ricorrente.
 *
 * Formato schedule (data/schedule.json):
 * {
 *   "enabled": true,
 *   "week": {
 *     "mon": { "mode": "custom", "windows": [ {"on":"08:00","off":"18:00"} ] },
 *     "tue": { "mode": "on",  "windows": [] },   // tutto il giorno ON
 *     "wed": { "mode": "off", "windows": [] },   // tutto il giorno OFF
 *     ... "sun": {...}
 *   }
 * }
 *
 * Modalita' per giorno:
 *   - "custom": usa le fasce orarie (windows). Fuori dalle fasce = OFF.
 *   - "on":  tutta la giornata ON  (le windows vengono ignorate).
 *   - "off": tutta la giornata OFF (le windows vengono ignorate).
 *
 * In modalita' "custom":
 *   - finestra con on < off: stesso giorno.
 *   - finestra con on > off: attraversa la mezzanotte (es. 22:00 -> 06:00).
 *   - on == off: ignorata.
 *
 * Compatibilita': se week[day] e' una semplice lista di finestre (vecchio
 * formato) viene interpretata come modalita' "custom".
 */
final class Scheduler
{
    /** Chiavi dei giorni, indicizzate come ISO-8601 (1 = lun ... 7 = dom). */
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    public const MODES = ['custom', 'on', 'off'];

    public static function dayKey(int $isoDow): string
    {
        return self::DAYS[$isoDow - 1];
    }

    /** Schedule vuoto di default (disabilitato finche' non configurato). */
    public static function defaultSchedule(): array
    {
        $week = [];
        foreach (self::DAYS as $d) {
            $week[$d] = ['mode' => 'custom', 'windows' => []];
        }
        return ['enabled' => false, 'week' => $week];
    }

    /**
     * Normalizza la configurazione di un giorno accettando sia il nuovo
     * formato ({mode, windows}) sia il vecchio (lista di finestre).
     * @return array{mode:string, windows:array}
     */
    public static function normalizeDay($raw): array
    {
        if (is_array($raw) && isset($raw['mode'])) {
            $mode = in_array($raw['mode'], self::MODES, true) ? $raw['mode'] : 'custom';
            $windows = (isset($raw['windows']) && is_array($raw['windows'])) ? $raw['windows'] : [];
            return ['mode' => $mode, 'windows' => $windows];
        }
        // Vecchio formato: lista di finestre => modalita' custom.
        if (is_array($raw)) {
            return ['mode' => 'custom', 'windows' => $raw];
        }
        return ['mode' => 'custom', 'windows' => []];
    }

    /**
     * Una finestra e' considerata attiva se non ha il flag "enabled" (vecchio
     * formato = sempre attiva) oppure se il flag e' truthy.
     */
    public static function windowEnabled($w): bool
    {
        if (!is_array($w) || !array_key_exists('enabled', $w)) {
            return true;
        }
        return !empty($w['enabled']);
    }

    /** Converte "HH:MM" in minuti dall'inizio del giorno, o null se invalido. */
    public static function toMinutes(string $hhmm): ?int
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($hhmm), $m)) {
            return null;
        }
        $h = (int)$m[1];
        $min = (int)$m[2];
        if ($h > 23 || $min > 59) {
            return null;
        }
        return $h * 60 + $min;
    }

    /** Stato desiderato (true = ON) allo istante $now. */
    public static function isOn(array $schedule, DateTimeInterface $now): bool
    {
        if (empty($schedule['enabled'])) {
            return false;
        }
        $week = $schedule['week'] ?? [];
        $nowMin = ((int)$now->format('H')) * 60 + (int)$now->format('i');
        $todayDow = (int)$now->format('N');

        $today = self::normalizeDay($week[self::dayKey($todayDow)] ?? []);

        // Flag per-giorno: forzano l'intera giornata, ignorando tutto il resto.
        if ($today['mode'] === 'on') {
            return true;
        }
        if ($today['mode'] === 'off') {
            return false;
        }

        // Modalita' custom: finestre che iniziano oggi.
        foreach ($today['windows'] as $w) {
            if (!self::windowEnabled($w)) {
                continue;
            }
            $on = self::toMinutes($w['on'] ?? '');
            $off = self::toMinutes($w['off'] ?? '');
            if ($on === null || $off === null || $on === $off) {
                continue;
            }
            if ($on < $off) {
                if ($nowMin >= $on && $nowMin < $off) {
                    return true;
                }
            } else { // attraversa la mezzanotte: coperto [on, 24:00)
                if ($nowMin >= $on) {
                    return true;
                }
            }
        }

        // Coda notturna di finestre iniziate ieri: [00:00, off).
        // Solo se ieri era in modalita' custom (on/off non hanno finestre).
        $yesterday = self::normalizeDay($week[self::dayKey($todayDow === 1 ? 7 : $todayDow - 1)] ?? []);
        if ($yesterday['mode'] === 'custom') {
            foreach ($yesterday['windows'] as $w) {
                if (!self::windowEnabled($w)) {
                    continue;
                }
                $on = self::toMinutes($w['on'] ?? '');
                $off = self::toMinutes($w['off'] ?? '');
                if ($on === null || $off === null || $on === $off) {
                    continue;
                }
                if ($on > $off && $nowMin < $off) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Prossima transizione a partire da $now (scansione al minuto, max 8 giorni).
     * Restituisce ['at' => DateTimeImmutable, 'state' => bool] oppure null.
     */
    public static function nextTransition(array $schedule, DateTimeImmutable $now): ?array
    {
        if (empty($schedule['enabled'])) {
            return null;
        }
        $current = self::isOn($schedule, $now);
        $cursor = $now->setTime((int)$now->format('H'), (int)$now->format('i'), 0)
                      ->modify('+1 minute');
        $limit = 8 * 24 * 60;
        for ($i = 0; $i < $limit; $i++) {
            $state = self::isOn($schedule, $cursor);
            if ($state !== $current) {
                return ['at' => $cursor, 'state' => $state];
            }
            $cursor = $cursor->modify('+1 minute');
        }
        return null;
    }

    /**
     * Normalizza e valida uno schedule ricevuto dal form.
     * Restituisce ['schedule' => array, 'errors' => string[]].
     */
    public static function validate(array $input): array
    {
        $errors = [];
        $week = [];
        $inWeek = $input['week'] ?? [];
        foreach (self::DAYS as $d) {
            $dayIn = $inWeek[$d] ?? [];
            $mode = $dayIn['mode'] ?? 'custom';
            if (!in_array($mode, self::MODES, true)) {
                $mode = 'custom';
            }

            $windows = [];
            $ons = (array)($dayIn['on'] ?? []);
            $offs = (array)($dayIn['off'] ?? []);
            // Le checkbox non selezionate non vengono inviate: usiamo gli indici
            // di on/off per sapere quali fasce esistono e leggiamo "enabled" per
            // indice (presente => attiva, assente => disattivata).
            $enableds = (array)($dayIn['enabled'] ?? []);
            $keys = array_keys($ons + $offs);
            sort($keys, SORT_NUMERIC);
            foreach ($keys as $i) {
                $on = trim((string)($ons[$i] ?? ''));
                $off = trim((string)($offs[$i] ?? ''));
                $enabled = isset($enableds[$i]);
                if ($on === '' && $off === '') {
                    continue; // riga vuota: ignora
                }
                $onMin = self::toMinutes($on);
                $offMin = self::toMinutes($off);
                if ($onMin === null || $offMin === null) {
                    // Segnala l'errore solo se la fascia e' attiva e in uso.
                    if ($mode === 'custom' && $enabled) {
                        $errors[] = "Orario non valido su " . strtoupper($d) . " ($on - $off). Usa HH:MM.";
                    }
                    continue;
                }
                if ($onMin === $offMin) {
                    if ($mode === 'custom' && $enabled) {
                        $errors[] = "Finestra a durata zero su " . strtoupper($d) . " ($on).";
                    }
                    continue;
                }
                $windows[] = ['on' => $on, 'off' => $off, 'enabled' => $enabled];
            }

            $week[$d] = ['mode' => $mode, 'windows' => $windows];
        }
        $schedule = [
            'enabled' => !empty($input['enabled']),
            'week' => $week,
        ];
        return ['schedule' => $schedule, 'errors' => $errors];
    }
}

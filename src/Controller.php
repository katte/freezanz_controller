<?php
declare(strict_types=1);

/**
 * Cuore del sistema: confronta lo stato desiderato (dallo schedule) con
 * l'ultimo stato inviato e, SOLO alla transizione, invia Start/Stop.
 *
 * Usato sia da cron.php (ogni minuto) sia dal pulsante "Esegui ora" della dashboard.
 */
final class Controller
{
    /**
     * @param bool $force  se true invia il comando anche se lo stato non e' cambiato.
     * @return array riepilogo dell'operazione (per log/output).
     */
    public static function run(bool $force = false): array
    {
        $cfg = config();
        $now = now_app();

        $schedule = Store::readJson('schedule.json', Scheduler::defaultSchedule());
        $state = Store::readJson('state.json', ['last_state' => null]);

        $target = Scheduler::isOn($schedule, $now);
        $result = [
            'time' => $now->format('Y-m-d H:i:s'),
            'enabled' => !empty($schedule['enabled']),
            'target' => $target,
            'last_state' => $state['last_state'] ?? null,
        ];

        if (empty($schedule['enabled'])) {
            $result['action'] = 'disabled';
            return $result;
        }

        $needSend = $force || ($state['last_state'] ?? null) !== $target;
        if (!$needSend) {
            $result['action'] = 'noop';
            return $result;
        }

        $command = $target ? 'start' : 'stop';
        $result['command'] = $command;

        $client = new MyFreezanzClient($cfg);
        $res = $client->sendCommand($command);

        if (($res['code'] ?? '') === 'not_configured') {
            // Non ancora configurato: non tocca lo stato, non spamma il log.
            $result['action'] = 'not_configured';
            $result['message'] = $res['message'];
            return $result;
        }

        if ($res['ok']) {
            $newState = [
                'last_state' => $target,
                'last_command' => $command,
                'last_change' => $now->format('Y-m-d H:i:s'),
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ];
            Store::writeJson('state.json', $newState);
            Store::log("OK  invio '$command' -> " . $res['message']);
            $result['action'] = 'sent';
            $result['ok'] = true;
            $result['message'] = $res['message'];
        } else {
            // Fallimento: NON aggiorna last_state, cosi' riprova al minuto dopo.
            Store::log("ERR invio '$command' -> " . $res['message']);
            $result['action'] = 'error';
            $result['ok'] = false;
            $result['message'] = $res['message'];
        }
        return $result;
    }
}

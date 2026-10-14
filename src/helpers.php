<?php
declare(strict_types=1);

/** Escape HTML per l'output nelle pagine. */
function e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/** Timezone configurato come oggetto DateTimeZone. */
function app_tz(): DateTimeZone
{
    return new DateTimeZone(config()['timezone'] ?? 'Europe/Rome');
}

/** "Ora" nel fuso orario dell'applicazione. */
function now_app(): DateTimeImmutable
{
    return new DateTimeImmutable('now', app_tz());
}

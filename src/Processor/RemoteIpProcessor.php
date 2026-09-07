<?php

declare(strict_types=1);

namespace Xakki\LogSymfony\Processor;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Attaches the requesting client's IP as `context.remote_ip`. Ported from LaraLog's
 * `BaseContext` (which read `request()->ip()` off the framework's request object and pushed
 * it into the Laravel logger's persistent context via `withContext()` — i.e. `context`, not
 * `extra`); here it is a plain Monolog processor reading straight off `$_SERVER`, so it
 * needs no framework request object.
 *
 * `remote_ip` has REQUEST lifetime, not process lifetime — a long-lived worker (queue
 * consumer, CLI daemon) handles many requests without restarting, so a value pinned in
 * `extra` (process-stable, spec §4.2, docs/LoggingRules.md §4.2) would go stale after the
 * first one. `context` (per-event, §4.3) is correct — see docs/LoggingRules.md §4.2/§4.3.
 *
 * `X-Forwarded-For` is attacker-controlled unless a trusted reverse proxy strips/sets it, so
 * it is used ONLY when `$trustForwardedFor` is explicitly turned on by the host.
 */
final class RemoteIpProcessor implements ProcessorInterface
{
    public function __construct(private readonly bool $trustForwardedFor = false)
    {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $ip = $this->resolveIp();
        if ($ip === null) {
            return $record;
        }

        // LogRecord::$context is readonly (unlike $extra) — mutate a local copy and hand
        // Monolog a new record via with().
        $context = $record->context;
        $context['remote_ip'] = $ip;

        return $record->with(context: $context);
    }

    private function resolveIp(): ?string
    {
        if ($this->trustForwardedFor && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $forwardedFor = (string) $_SERVER['HTTP_X_FORWARDED_FOR'];
            $first = trim(explode(',', $forwardedFor)[0]);
            if ($first !== '') {
                return $first;
            }
        }

        if (!empty($_SERVER['REMOTE_ADDR'])) {
            return (string) $_SERVER['REMOTE_ADDR'];
        }

        return null;
    }
}

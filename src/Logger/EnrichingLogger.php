<?php

declare(strict_types=1);

namespace Xakki\LogSymfony\Logger;

use Psr\Log\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Psr\Log\LogLevel;
use Xakki\LogSymfony\ContextEnricher;

/**
 * Optional PSR-3 decorator wrapping an inner {@see LoggerInterface}. Its value over the
 * {@see \Xakki\LogSymfony\Processor\ContextEnrichProcessor} is accurate call-site `file`/
 * `trace`: it runs {@see ContextEnricher::enrich()} directly at the application's call site
 * (one frame away), before the message ever reaches Monolog's handler chain, so no
 * Monolog-internal frames have to be filtered out of the resolved trace at all.
 *
 * Reuses {@see ContextEnricher} — the enrichment logic itself is NOT duplicated here.
 */
final class EnrichingLogger implements LoggerInterface
{
    use LoggerTrait;

    public function __construct(
        private readonly LoggerInterface $inner,
        private readonly ContextEnricher $enricher,
    ) {
    }

    /**
     * @param mixed $level PSR-3 level (string name, e.g. `LogLevel::ERROR`).
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $levelName = self::normalizeLevel($level);
        $enrichedMessage = $this->enricher->enrich($levelName, $message, $context);
        $this->inner->log($level, $enrichedMessage, $context);
    }

    /**
     * Validates `$level` against the eight PSR-3 level names and returns it lowercased —
     * same acceptance/rejection behaviour as the old Monolog-`Level`-returning conversion
     * this replaces, just without building a `Monolog\Level` instance nobody but this
     * class (and, transitively, {@see ContextEnricher}) ever needed.
     *
     * @throws InvalidArgumentException When `$level` is not a recognised PSR-3 level.
     */
    private static function normalizeLevel(mixed $level): string
    {
        $levelName = strtolower((string) $level);

        return match ($levelName) {
            LogLevel::DEBUG, LogLevel::INFO, LogLevel::NOTICE, LogLevel::WARNING,
            LogLevel::ERROR, LogLevel::CRITICAL, LogLevel::ALERT, LogLevel::EMERGENCY => $levelName,
            default => throw new InvalidArgumentException('Unknown log level: ' . (string) $level),
        };
    }
}

<?php

declare(strict_types=1);

namespace Xakki\LogSymfony\Processor;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Xakki\LogSymfony\ContextEnricher;

/**
 * Thin Monolog processor wrapping {@see ContextEnricher} — this is the primary integration
 * point (spec §4): register it as a `monolog.processor` and every record gets `log_type`,
 * `request_id`, `file`/`trace`, `message_len`, type-corrected context and credential
 * redaction.
 *
 * `LogRecord::$context` and `::$message` are readonly, so unlike LaraLog's
 * `LogManager::appendContext(&$context)` this cannot mutate in place — it enriches a local
 * copy and returns a new record via `LogRecord::with()`.
 *
 * {@see ContextEnricher} itself is PSR-3-only (no Monolog dependency) — this class is the
 * Monolog-specific edge of the pipeline: it converts `$record->level` (`Monolog\Level`) to
 * its PSR-3 name (`Level::toPsrLogLevel()`) before calling into the shared enrichment logic.
 */
final class ContextEnrichProcessor implements ProcessorInterface
{
    public function __construct(private readonly ContextEnricher $enricher)
    {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $context = $record->context;
        $message = $this->enricher->enrich($record->level->toPsrLogLevel(), $record->message, $context);

        return $record->with(message: $message, context: $context);
    }
}

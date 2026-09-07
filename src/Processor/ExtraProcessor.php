<?php

declare(strict_types=1);

namespace Xakki\LogSymfony\Processor;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Xakki\LogSymfony\LoggerConfig;

class ExtraProcessor implements ProcessorInterface
{
    /**
     * Process-stable fields (spec §4.2) — built ONCE from `LoggerConfig::$extra`, the whole
     * array verbatim (empty values dropped). The host app is responsible for reading env()
     * into `LoggerConfig` once at build time; this library never reads the environment itself.
     *
     * @var array<string, mixed>
     */
    private array $extra;

    public function __construct(LoggerConfig $config)
    {
        $extra = array_filter(
            $config->extra,
            static fn ($v): bool => $v !== null && $v !== '',
        );

        // argv is stable for the process lifetime -> compute here, not per record.
        if (!empty($_SERVER['argv'])) {
            $extra['console_argv'] = implode(' ', $_SERVER['argv']);
        }

        $this->extra = $extra;
    }

    /**
     * @inheritDoc
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        foreach ($this->extra as $key => $value) {
            $record->extra[$key] = $value;
        }

        return $record;
    }
}

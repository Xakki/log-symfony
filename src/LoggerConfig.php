<?php

declare(strict_types=1);

namespace Xakki\LogSymfony;

/**
 * Typed, immutable configuration for this library. The host application builds ONE of these
 * (typically once, at DI-container build time) and passes it into every collaborator that
 * needs it — {@see ContextEnricher}, {@see FileTrace}, {@see Processor\ExtraProcessor}. This
 * library never calls `config()`/`env()`/`getenv()` itself; reading the environment is the
 * host's job.
 *
 * Defaults mirror the LaraLog reference implementation's `config/logger.php`, with one
 * deliberate change: {@see self::$traceExcludedPartials} — see the class doc on
 * {@see FileTrace} for why the Laravel default does not carry over as-is.
 */
final readonly class LoggerConfig
{
    /**
     * @param array<string, scalar|null> $extra Stable per-process fields (spec §4.2),
     *     copied verbatim onto every record by {@see Processor\ExtraProcessor} (empty
     *     values dropped).
     * @param string[] $traceExcludedPartials Frames whose file path contains any of these
     *     substrings are stripped from `file`/`trace`.
     * @param array<string, int> $traceDepth Stack-trace frame count per level bucket, keyed
     *     `warning`/`error`/`critical` (also covers alert/emergency).
     * @param string[] $redact Extra credential-redaction needles, merged on top of
     *     {@see Redactor}'s built-in list.
     * @param array<string, array<string, string>> $contextTypeRules Project-level
     *     type-coercion rules, merged onto {@see ContextEnricher::$contextTypeRules}.
     *     Buckets: suffix|reserved|exact|word|prefix.
     */
    public function __construct(
        public int $messageLimit = 3024,
        public bool $allowMemory = false,
        public array $extra = [],
        public array $traceExcludedPartials = ['Monolog/', 'xakki/log-symfony/', 'vendor/'],
        public array $traceDepth = ['warning' => 5, 'error' => 10, 'critical' => 20],
        public int $traceArgLimit = 128,
        public array $redact = [],
        public bool $snakeCase = false,
        public array $contextTypeRules = [],
        public bool $captureHandlers = false,
    ) {
    }

    /**
     * @param array<string, mixed> $config Same key names as the constructor's named args.
     */
    public static function fromArray(array $config): self
    {
        /** @var array<string, scalar|null> $extra */
        $extra = (array) ($config['extra'] ?? []);
        /** @var string[] $traceExcludedPartials */
        $traceExcludedPartials = (array) ($config['traceExcludedPartials'] ?? ['Monolog/', 'xakki/log-symfony/', 'vendor/']);
        /** @var array<string, int> $traceDepth */
        $traceDepth = (array) ($config['traceDepth'] ?? ['warning' => 5, 'error' => 10, 'critical' => 20]);
        /** @var string[] $redact */
        $redact = (array) ($config['redact'] ?? []);
        /** @var array<string, array<string, string>> $contextTypeRules */
        $contextTypeRules = (array) ($config['contextTypeRules'] ?? []);

        return new self(
            messageLimit: (int) ($config['messageLimit'] ?? 3024),
            allowMemory: (bool) ($config['allowMemory'] ?? false),
            extra: $extra,
            traceExcludedPartials: $traceExcludedPartials,
            traceDepth: $traceDepth,
            traceArgLimit: (int) ($config['traceArgLimit'] ?? 128),
            redact: $redact,
            snakeCase: (bool) ($config['snakeCase'] ?? false),
            contextTypeRules: $contextTypeRules,
            captureHandlers: (bool) ($config['captureHandlers'] ?? false),
        );
    }
}

<?php

declare(strict_types=1);

namespace Xakki\LogSymfony;

/**
 * Call-site file/line resolution and stack-trace rendering (spec §3.7 / §4.6). Ported from
 * LaraLog's `TraitFileTrace` — a trait made sense there because it was mixed directly into
 * `LogManager`; here it is an ordinary collaborator, constructed once and injected into
 * {@see ContextEnricher} (via the Monolog processor) and {@see Logger\EnrichingLogger}.
 *
 * A PHP backtrace frame's `file`/`line` describe the CALL SITE, not the callee — verified
 * empirically (see the stack-depth test/mutation in `tests/Unit/StackDepthTest.php`) while
 * picking `LoggerConfig::$traceExcludedPartials`' default. Concretely, when
 * {@see Processor\ContextEnrichProcessor} is called from deep inside
 * `Monolog\Logger::addRecord()`, the frames needing exclusion are `Monolog\Logger`'s OWN
 * source files (`vendor/monolog/monolog/src/Monolog/...`) — this package's own frames never
 * show up as `file` entries on that path at all, because the call into `enrich()` always
 * originates from Monolog's code. `'Monolog/'` and `'vendor/'` cover that.
 *
 * `checkExcludePart()` additionally always excludes any frame whose file lives under THIS
 * class's own directory (`__DIR__`, i.e. this package's `src/`) — this is what matters on
 * the OTHER call path, {@see Logger\EnrichingLogger}: there, `enrich()` is called directly
 * from this package's own `Logger/EnrichingLogger.php` (not from Monolog), so neither
 * `'Monolog/'` nor `'vendor/'` would catch it on a dev checkout; the `__DIR__` check does.
 * `'xakki/log-symfony/'` (the real Composer package path segment, `vendor/xakki/log-symfony/`)
 * is a further defensive entry for a downstream app that vendors this package under some
 * OTHER non-`vendor/` autoload root (e.g. a monorepo `packages/` layout) — redundant with
 * `'vendor/'` in a normal Composer install, but not proven to be exercised by any of this
 * repo's own tests, unlike the two entries above.
 */
final class FileTrace
{
    public function __construct(
        private readonly LoggerConfig $config,
        private readonly Redactor $redactor,
        private readonly ?string $projectDir = null,
    ) {
    }

    /**
     * @param array<int,array{function?: string, line?: int, file?:string, class?: class-string,
     *     type?: string, args?: mixed[], object?: object}> $trace
     */
    public function getFileLine(array $trace): string
    {
        foreach ($trace as $item) {
            if (!empty($item['file'])) {
                if ($this->checkExcludePart($item['file'])) {
                    continue;
                }
                return $this->getRelativeFilePath($item['file']) . ':' . ($item['line'] ?? '');
            }
        }
        return '';
    }

    public function checkExcludePart(string $str): bool
    {
        if (str_contains($str, __DIR__)) {
            return true;
        }
        foreach ($this->config->traceExcludedPartials as $partial) {
            if (str_contains($str, $partial)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Strips {@see self::$projectDir} (the host's `%kernel.project_dir%`) from an absolute
     * path. With no project dir configured, the path is returned unchanged.
     */
    public function getRelativeFilePath(string $file): string
    {
        if ($this->projectDir === null || $this->projectDir === '') {
            return $file;
        }
        return str_replace($this->projectDir, '', $file);
    }

    /**
     * @param array<int,array{function?: string, line?: int, file?:string, class?: class-string,
     *     type?: string, args?: mixed[], object?: object}> $trace
     */
    public function traceToString(array $trace, int $limit = 50, bool $checkExcludePart = true): string
    {
        $i = 0;
        $newTrace = [];
        $skippedLine = 0;
        foreach ($trace as &$item) {
            $f = false;
            if (!empty($item['file']) && $this->checkExcludePart($item['file'])) {
                $f = true;
                if ($checkExcludePart) {
                    $skippedLine++;
                    continue;
                }
            }

            if ($skippedLine > 0) {
                $newTrace[] = str_repeat('.', $skippedLine);
                $skippedLine = 0;
            }

            $str = '#' . $i++ . ' ';
            if (!empty($item['file'])) {
                $str .= $this->getRelativeFilePath($item['file']) . ':' . ($item['line'] ?? '');
            }

            if (!$f) {
                if (!empty($item['class']) || !empty($item['function'])) {
                    $str .= $this->renderTraceWithClass($item);
                }
            }
            $newTrace[] = $str;
            if ($i >= $limit) {
                $newTrace[] = '***';
                break;
            }
        }

        if ($skippedLine > 0) {
            $newTrace[] = str_repeat('.', $skippedLine);
        }
        return implode(PHP_EOL, $newTrace);
    }

    /**
     * @param array{function?: string, line?: int, file?:string, class?: class-string,
     *      type?: string, args?: mixed[], object?: object} $item
     */
    private function renderTraceWithClass(array $item): string
    {
        $args = '';
        if (!empty($item['args'])) {
            $limit = $this->config->traceArgLimit;
            foreach ($item['args'] as &$arg) {
                if (is_object($arg)) {
                    $arg = get_class($arg);
                } elseif (is_array($arg)) {
                    $arg = '[...' . count($arg) . ']';
                } elseif (is_bool($arg)) {
                    $arg = $arg ? 'T' : 'F';
                } elseif (is_string($arg)) {
                    // Args are positional (no name) -> value-pattern redaction (§2.1).
                    $arg = '"' . mb_substr($this->redactor->redactValue($arg), 0, $limit) . '"';
                } else {
                    $arg = mb_substr($this->redactor->redactValue(var_export($arg, true)), 0, $limit);
                }
                $args .= ($arg ? ', ' : '') . $arg;
            }
        }
        return ' | ' . (!empty($item['class']) ? $item['class'] . '::' : '')
            . (!empty($item['function']) ? $item['function'] . '(' . $args . ')' : '');
    }
}

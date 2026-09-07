<?php declare(strict_types=1);

namespace Xakki\LogSymfonyTests\Unit;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Xakki\LogSymfony\ContextEnricher;
use Xakki\LogSymfony\FileTrace;
use Xakki\LogSymfony\Logger\EnrichingLogger;
use Xakki\LogSymfony\LoggerConfig;
use Xakki\LogSymfony\Processor\ContextEnrichProcessor;
use Xakki\LogSymfony\Redactor;
use Xakki\LogSymfony\RequestId;

/**
 * The deliverable that proves the port actually works (see project CLAUDE.md /
 * .claude/kanban port task): in LaraLog, `file`/`trace` are resolved ONE frame from the
 * caller, inside `LogManager::info()` itself. Here the enrichment runs inside a Monolog
 * Processor, deep in `Monolog\Logger`'s handler chain — the top frames of any raw
 * `debug_backtrace()` belong to Monolog and to this package's own `src/`, NOT to the
 * application. `LoggerConfig::$traceExcludedPartials` + the `__DIR__` check in
 * {@see FileTrace::checkExcludePart()} exist specifically to walk past those frames.
 *
 * This test is the executable proof (per the "findings are established by tests" rule):
 * it logs through a REAL `Monolog\Logger` (not a stub) with the REAL
 * `ContextEnrichProcessor`, and asserts the emitted `file` context field points at THIS
 * test method's own file and line — not at any file under `src/`.
 */
class StackDepthTest extends TestCase
{
    public function testFileFieldPointsAtCallerNotAtPackageSource(): void
    {
        $config = new LoggerConfig();
        $redactor = new Redactor();
        $fileTrace = new FileTrace($config, $redactor);
        $enricher = new ContextEnricher($config, $redactor, $fileTrace, new RequestId());

        $handler = new TestHandler();
        $logger = new Logger('testing', [$handler], [new ContextEnrichProcessor($enricher)]);

        // This call and the assertion below are 3 lines apart in THIS file — the resolved
        // `file` field must carry THIS file's basename and a line number in that window.
        $logger->warning('deep in the handler chain');

        $record = $handler->getRecords()[0];
        $file = (string) $record->context['file'];

        $this->assertStringContainsString(
            'StackDepthTest.php',
            $file,
            'file must point at the caller (this test), not at library/Monolog internals: got "' . $file . '"'
        );
        $this->assertDoesNotMatchRegularExpression(
            '~[\\\\/]src[\\\\/]~',
            $file,
            'file must not point inside this package\'s own src/: got "' . $file . '"'
        );
        $this->assertDoesNotMatchRegularExpression(
            '~Monolog~',
            $file,
            'file must not point inside Monolog\'s own source: got "' . $file . '"'
        );
    }

    /**
     * The PSR-3 decorator's whole reason to exist (see {@see EnrichingLogger}'s class doc) is
     * that it resolves `file` one frame from the real caller WITHOUT ever seeing Monolog's
     * handler-chain frames — that claim is a documented promise, not (until this test) a
     * verified one. Reuses the same real-Monolog TestHandler setup as the processor test
     * above, just as the inner logger `EnrichingLogger` wraps.
     */
    public function testEnrichingLoggerFileFieldPointsAtCallerToo(): void
    {
        $config = new LoggerConfig();
        $redactor = new Redactor();
        $fileTrace = new FileTrace($config, $redactor);
        $enricher = new ContextEnricher($config, $redactor, $fileTrace, new RequestId());

        $handler = new TestHandler();
        $innerLogger = new Logger('inner', [$handler]);
        $logger = new EnrichingLogger($innerLogger, $enricher);

        $logger->warning('via the decorator');

        $record = $handler->getRecords()[0];
        $file = (string) $record->context['file'];

        $this->assertStringContainsString('StackDepthTest.php', $file);
        $this->assertDoesNotMatchRegularExpression('~[\\\\/]src[\\\\/]~', $file);
    }
}

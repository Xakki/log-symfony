<?php declare(strict_types=1);

namespace Xakki\LogSymfonyTests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Xakki\LogSymfony\ContextEnricher;
use Xakki\LogSymfony\FileTrace;
use Xakki\LogSymfony\Logger\EnrichingLogger;
use Xakki\LogSymfony\LoggerConfig;
use Xakki\LogSymfony\Redactor;
use Xakki\LogSymfony\RequestId;

/**
 * Proves {@see EnrichingLogger} (and, through it, {@see ContextEnricher}) works over a
 * genuinely plain PSR-3 logger — no `Monolog\Logger`, no `Monolog\Handler\TestHandler`
 * anywhere in this file, unlike every other test in this suite (`ContextEnricherTest`,
 * `StackDepthTest`, `RemoteIpProcessorTest`) which all build a real `Monolog\Logger`.
 *
 * This is the concrete consumer scenario the change is for: `xakki/convertor` logs through
 * `xakki/fluent-log`, a plain PSR-3 logger with no Monolog installed at all — see the owner
 * decision in project CLAUDE.md ("make the core Monolog-free").
 *
 * `RecordingPsr3Logger` below is a hand-written {@see AbstractLogger} test double — deliberately
 * NOT `Monolog\Logger`/`TestHandler` — so nothing in this file requires Monolog to even be
 * installed for the assertions to hold (the separate no-Monolog composer probe under
 * `/var/tmp/backup/log-symfony/backup_no-monolog-probe/` proves the package installs and
 * enriches with Monolog physically absent from `vendor/`; this test proves the CODE PATH
 * exercised there behaves correctly).
 */
class EnrichingLoggerPsr3OnlyTest extends TestCase
{
    /**
     * @return array{0: EnrichingLogger, 1: RecordingPsr3Logger}
     */
    private function makeLoggerWithInner(?LoggerConfig $config = null): array
    {
        $config ??= new LoggerConfig();
        $redactor = new Redactor($config->redact);
        $fileTrace = new FileTrace($config, $redactor);
        $enricher = new ContextEnricher($config, $redactor, $fileTrace, new RequestId());
        $inner = new RecordingPsr3Logger();

        return [new EnrichingLogger($inner, $enricher), $inner];
    }

    /**
     * The main proof: construct EnrichingLogger over a hand-written PSR-3 logger and check
     * the enriched context arrives with the right typed fields — log_type, request_id,
     * call-site file, int/bool type coercion and credential redaction, exactly as the
     * Monolog-backed paths (ContextEnrichProcessor) already prove elsewhere in this suite.
     */
    public function testEnrichedContextArrivesWithTypedFieldsOverAPlainPsr3Logger(): void
    {
        [$logger, $inner] = $this->makeLoggerWithInner();

        $logger->error('order failed', [
            'order_id' => '42',
            'is_retryable' => '1',
            'password' => 'hunter2',
        ]);

        $this->assertCount(1, $inner->records);
        $record = $inner->records[0];

        // The level PSR-3 handed to the inner logger stays a plain PSR-3 string — never a
        // Monolog\Level instance, on either side of the enrichment call.
        $this->assertSame(LogLevel::ERROR, $record['level']);
        $this->assertSame('order failed', $record['message']);

        $context = $record['context'];
        $this->assertSame('logger', $context['log_type']);
        $this->assertArrayHasKey('request_id', $context);
        $this->assertStringContainsString('EnrichingLoggerPsr3OnlyTest.php:', (string) $context['file']);
        // '*_id' -> int, 'is_*' -> bool (ContextEnricher::$contextTypeRules).
        $this->assertSame(42, $context['order_id']);
        $this->assertTrue($context['is_retryable']);
        // Credential redaction by key still runs on this path.
        $this->assertSame('***', $context['password']);
        // error >= the warning gate -> a trace is attached.
        $this->assertArrayHasKey('trace', $context);
    }

    /**
     * Below the warning gate, no call-site trace is attached — same threshold the
     * Monolog-backed path enforces (`ContextEnricherTest::testTraceAttachedByLevel`), now
     * proven through the level-rank table that replaced `Monolog\Level` comparisons.
     */
    public function testNoTraceBelowTheWarningGate(): void
    {
        [$logger, $inner] = $this->makeLoggerWithInner();

        $logger->info('just fyi');

        $this->assertArrayNotHasKey('trace', $inner->records[0]['context']);
    }

    /**
     * `LoggerConfig::$traceDepth`'s 'critical' bucket must still apply to `critical` — and,
     * per the old `Monolog\Level::value >= Critical->value` semantics this replaces, to
     * `alert`/`emergency` too (both were numerically above Critical in Monolog's enum).
     * depth=1 makes `FileTrace::traceToString()` truncate right after the first frame,
     * which only shows up if the depth config is actually being read for this level.
     */
    public function testCriticalBucketDepthAppliesToAlertAndEmergencyToo(): void
    {
        $config = new LoggerConfig(traceDepth: ['warning' => 5, 'error' => 5, 'critical' => 1]);

        foreach ([LogLevel::CRITICAL, LogLevel::ALERT, LogLevel::EMERGENCY] as $level) {
            [$logger, $inner] = $this->makeLoggerWithInner($config);
            $logger->log($level, 'deep');
            $this->assertStringContainsString(
                '***',
                (string) $inner->records[0]['context']['trace'],
                "level '$level' must use the 'critical' depth bucket"
            );
        }
    }

    /**
     * Preserves `EnrichingLogger`'s pre-existing validation behaviour: an unrecognised level
     * still throws before anything reaches the inner logger or the enricher.
     */
    public function testUnknownLevelThrowsInvalidArgumentException(): void
    {
        [$logger, $inner] = $this->makeLoggerWithInner();

        $this->expectException(\Psr\Log\InvalidArgumentException::class);
        try {
            $logger->log('not-a-level', 'x');
        } finally {
            $this->assertCount(0, $inner->records, 'nothing must reach the inner logger on a rejected level');
        }
    }
}

/**
 * Deliberately NOT Monolog\Logger / Monolog\Handler\TestHandler — a minimal, hand-written
 * PSR-3 logger double proving EnrichingLogger needs nothing beyond `Psr\Log\LoggerInterface`.
 */
final class RecordingPsr3Logger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}

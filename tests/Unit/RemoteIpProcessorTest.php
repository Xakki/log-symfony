<?php declare(strict_types=1);

namespace Xakki\LogSymfonyTests\Unit;

use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Xakki\LogSymfony\Processor\RemoteIpProcessor;

/**
 * FIX 2 regression test. `remote_ip` has REQUEST lifetime (it changes between requests
 * handled by the same long-lived worker), so it belongs in `context` (per-event,
 * docs/LoggingRules.md §4.3) — the same place the reference implementation
 * (log-laravel's `BaseContext`, which uses Monolog's `withContext()`) puts it. The
 * processor used to write it into `extra` (process-stable, §4.2) instead.
 */
class RemoteIpProcessorTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $serverBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        parent::tearDown();
    }

    private function makeRecord(): LogRecord
    {
        return new LogRecord(
            new \DateTimeImmutable(),
            'test',
            Level::Info,
            'hello',
        );
    }

    public function testRemoteIpLandsInContextNotExtra(): void
    {
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';

        $processor = new RemoteIpProcessor();
        $out = $processor->__invoke($this->makeRecord());

        $this->assertSame('203.0.113.7', $out->context['remote_ip']);
        $this->assertArrayNotHasKey('remote_ip', $out->extra);
    }

    public function testUntrustedForwardedForIsIgnoredByDefault(): void
    {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.9';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';

        $processor = new RemoteIpProcessor(trustForwardedFor: false);
        $out = $processor->__invoke($this->makeRecord());

        $this->assertSame('203.0.113.7', $out->context['remote_ip']);
    }

    public function testTrustedForwardedForIsUsedWhenEnabled(): void
    {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.9, 203.0.113.7';
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';

        $processor = new RemoteIpProcessor(trustForwardedFor: true);
        $out = $processor->__invoke($this->makeRecord());

        $this->assertSame('198.51.100.9', $out->context['remote_ip']);
    }

    public function testNoKnownAddressLeavesContextUntouched(): void
    {
        unset($_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['REMOTE_ADDR']);

        $processor = new RemoteIpProcessor();
        $out = $processor->__invoke($this->makeRecord());

        $this->assertArrayNotHasKey('remote_ip', $out->context);
    }
}

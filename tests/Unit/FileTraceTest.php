<?php declare(strict_types=1);

namespace Xakki\LogSymfonyTests\Unit;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Xakki\LogSymfony\ContextEnricher;
use Xakki\LogSymfony\FileTrace;
use Xakki\LogSymfony\LoggerConfig;
use Xakki\LogSymfony\Processor\ContextEnrichProcessor;
use Xakki\LogSymfony\Redactor;
use Xakki\LogSymfony\RequestId;

/**
 * FIX 4c regression test. `FileTrace.php:76-82` (`getRelativeFilePath()`'s `$projectDir`
 * stripping) is never exercised by the rest of the suite: every other test constructs
 * `FileTrace($config, $redactor)` without the third constructor argument, so `$projectDir`
 * is always null and the method's early-return branch always fires.
 */
class FileTraceTest extends TestCase
{
    public function testGetRelativeFilePathStripsTheConfiguredProjectDir(): void
    {
        $projectDir = dirname(__DIR__, 2);
        $fileTrace = new FileTrace(new LoggerConfig(), new Redactor(), $projectDir);

        $relative = $fileTrace->getRelativeFilePath(__FILE__);

        $this->assertStringNotContainsString($projectDir, $relative);
        $this->assertStringContainsString('FileTraceTest.php', $relative);
    }

    public function testGetRelativeFilePathLeavesThePathUnchangedWithNoProjectDirConfigured(): void
    {
        $fileTrace = new FileTrace(new LoggerConfig(), new Redactor());

        $this->assertSame(__FILE__, $fileTrace->getRelativeFilePath(__FILE__));
    }

    /**
     * End-to-end: the SAME relativisation is visible on the `file` field emitted by a real
     * `Monolog\Logger` call (mirrors `StackDepthTest`'s setup), not just on a hand-fed path.
     */
    public function testEnrichedFileFieldIsRelativisedAgainstTheProjectDir(): void
    {
        $projectDir = dirname(__DIR__, 2);
        $config = new LoggerConfig();
        $redactor = new Redactor();
        $fileTrace = new FileTrace($config, $redactor, $projectDir);
        $enricher = new ContextEnricher($config, $redactor, $fileTrace, new RequestId());

        $handler = new TestHandler();
        $logger = new Logger('testing', [$handler], [new ContextEnrichProcessor($enricher)]);
        $logger->warning('with a project dir configured');

        $file = (string) $handler->getRecords()[0]->context['file'];

        $this->assertStringNotContainsString($projectDir, $file);
        $this->assertStringContainsString('FileTraceTest.php', $file);
    }
}

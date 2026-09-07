<?php declare(strict_types=1);

namespace Xakki\LogSymfonyTests\Unit;

use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Xakki\LogSymfony\ContextEnricher;
use Xakki\LogSymfony\FileTrace;
use Xakki\LogSymfony\LoggerConfig;
use Xakki\LogSymfony\Processor\ContextEnrichProcessor;
use Xakki\LogSymfony\Processor\ExtraProcessor;
use Xakki\LogSymfony\Redactor;
use Xakki\LogSymfony\RequestId;

/**
 * Ported from LaraLog's tests/Unit/LogManagerTest.php. LaraLog booted a full Laravel app and
 * asserted on a log FILE written by the `single` channel; here there is no framework and no
 * channel system — a real Monolog\Logger is built directly with a TestHandler + the
 * ContextEnrichProcessor under test, and every assertion reads typed fields off the captured
 * LogRecord (via TestHandler::getRecords()) instead of grepping formatted JSON lines.
 */
class ContextEnricherTest extends TestCase
{
    /** @var array<string, array<string, string>> */
    private array $contextTypeRulesBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        // addContextTypeRules() mutates a static table; snapshot it so a project-rule test
        // can't leak its entries into the next one (mirrors the Laravel version's setUp).
        $this->contextTypeRulesBackup = ContextEnricher::$contextTypeRules;
    }

    protected function tearDown(): void
    {
        ContextEnricher::$contextTypeRules = $this->contextTypeRulesBackup;
        parent::tearDown();
    }

    private function makeEnricher(?LoggerConfig $config = null): ContextEnricher
    {
        $config ??= new LoggerConfig();
        $redactor = new Redactor($config->redact);
        $fileTrace = new FileTrace($config, $redactor);

        return new ContextEnricher($config, $redactor, $fileTrace, new RequestId());
    }

    /**
     * @return array{0: Logger, 1: TestHandler}
     */
    private function makeLogger(?LoggerConfig $config = null): array
    {
        $handler = new TestHandler();
        $logger = new Logger('testing', [$handler], [new ContextEnrichProcessor($this->makeEnricher($config))]);

        return [$logger, $handler];
    }

    public function testInit(): void
    {
        [$logger, $handler] = $this->makeLogger();
        $logger->warning('Test message', ['test' => 'context message']);

        $record = $handler->getRecords()[0];
        $this->assertSame('Test message', $record->message);
        $this->assertSame('context message', $record->context['test']);
        $this->assertSame(12, $record->context['message_len']);
        $this->assertSame('logger', $record->context['log_type']);
        $this->assertArrayHasKey('request_id', $record->context);
        $this->assertStringContainsString('ContextEnricherTest.php:', (string) $record->context['file']);
    }

    /**
     * FIX 4b regression test. `ContextEnricher.php:254` (the `return mb_substr(...)` at the
     * end of `enrich()`) is never exercised past the default 3024-char limit — replacing it
     * with `return (string) $message;` keeps the suite green. `message_len` reports the
     * PRE-truncation length in both this port and the reference (`LogManager.php:331` sets
     * `message_len` from the untruncated `$message` BEFORE the truncated return at line 347
     * — verified against `/home/xakki/log-laravel/src/LogManager.php`), so that must hold
     * here too.
     */
    public function testMessageIsTruncatedToMessageLimitAndMessageLenReportsThePreTruncationLength(): void
    {
        $messageLimit = 20;
        $config = new LoggerConfig(messageLimit: $messageLimit);
        [$logger, $handler] = $this->makeLogger($config);

        $originalLength = $messageLimit + 500;
        $longMessage = str_repeat('a', $originalLength);
        $logger->warning($longMessage);

        $record = $handler->getRecords()[0];

        $this->assertSame($messageLimit, mb_strlen($record->message));
        $this->assertSame($originalLength, $record->context['message_len']);
    }

    public function testLogTypeExceptionOnExplicitException(): void
    {
        [$logger, $handler] = $this->makeLogger();
        $logger->error('boom', ['exception' => new \RuntimeException('x')]);

        $record = $handler->getRecords()[0];
        $this->assertSame('exception', $record->context['log_type']);
        $this->assertSame('RuntimeException', $record->context['exception']);
    }

    public function testCredentialRedactionByKey(): void
    {
        [$logger, $handler] = $this->makeLogger();
        $logger->warning('login', [
            'password' => 'hunter2',
            'api_key' => 'sk-live-123',
            'order_id' => '42',
        ]);

        $record = $handler->getRecords()[0];
        $this->assertSame('***', $record->context['password']);
        $this->assertSame('***', $record->context['api_key']);
        // Non-secret fields still pass through. '*_id' is an INT by convention (auto-increment
        // MariaDB identifiers, wanted for range queries and aggregations).
        $this->assertSame(42, $record->context['order_id']);
        $encoded = (string) json_encode($record->context);
        $this->assertStringNotContainsString('hunter2', $encoded);
        $this->assertStringNotContainsString('sk-live-123', $encoded);
    }

    public function testSnakeCaseOptIn(): void
    {
        [$logger, $handler] = $this->makeLogger(new LoggerConfig(snakeCase: true));
        $logger->warning('msg', ['orderId' => '7']);

        $record = $handler->getRecords()[0];
        $this->assertSame(7, $record->context['order_id']);
        $this->assertArrayNotHasKey('orderId', $record->context);
    }

    /**
     * FIX 3 regression test. `toSnakeCase()` is ported from Laravel `Str::snake()`
     * (`vendor/laravel/framework/.../Str.php` in the reference repo) — including the
     * `ucwords()` pass that runs BEFORE whitespace is stripped. The earlier port stripped
     * whitespace first, so a key with a space lost its word boundary entirely:
     * 'hello world' -> 'helloworld' instead of 'hello_world'.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function snakeCaseProvider(): array
    {
        return [
            'camelCase key (already matched Str::snake before this fix)' => ['userId', 'user_id'],
            'two words separated by a space' => ['hello world', 'hello_world'],
            'three words separated by spaces' => ['some key name', 'some_key_name'],
            'already snake_case stays unchanged' => ['already_snake', 'already_snake'],
            // Every capital letter is its own "word" boundary under Str::snake() —
            // consecutive acronym letters each get their own delimiter. Counter-intuitive,
            // but this is genuinely what the reference implementation does; verified by
            // running this test, not hand-derived (see project's testing rule).
            'consecutive acronym capitals' => ['HTTPResponse', 'h_t_t_p_response'],
            'empty string stays empty' => ['', ''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('snakeCaseProvider')]
    public function testSnakeCaseMatchesLaravelStrSnake(string $input, string $expected): void
    {
        $enricher = $this->makeEnricher(new LoggerConfig(snakeCase: true));
        $context = [$input => 'x'];
        $enricher->contextTypeCorrector($context);

        $this->assertSame([$expected], array_keys($context));
    }

    public function testTraceAttachedByLevel(): void
    {
        [$logger, $handler] = $this->makeLogger();
        $logger->notice('just a note');
        $logger->error('a problem');

        $records = $handler->getRecords();
        $this->assertArrayNotHasKey('trace', $records[0]->context);
        $this->assertArrayHasKey('trace', $records[1]->context);
    }

    public function testTraceDepthIsConfigurable(): void
    {
        // depth 1 -> traceToString appends '***' right after the first rendered (unexcluded)
        // frame. If the depth config were ignored (hardcoded), this shallow stack would NOT
        // be truncated.
        $config = new LoggerConfig(traceDepth: ['warning' => 5, 'error' => 1, 'critical' => 20]);
        [$logger, $handler] = $this->makeLogger($config);
        $logger->error('deep');

        $record = $handler->getRecords()[0];
        $this->assertStringContainsString('***', (string) $record->context['trace']);
    }

    public function testSkipTraceMarkerSuppressesCallSiteTrace(): void
    {
        // Baseline: without the marker, an error-level log gets a trace.
        [$logger, $handler] = $this->makeLogger();
        $logger->error('a problem');
        $logger->error('a problem', [\LOGGER_SKIP_TRACE => true]);

        $records = $handler->getRecords();
        $this->assertArrayHasKey('trace', $records[0]->context);
        $this->assertArrayNotHasKey('trace', $records[1]->context);
    }

    public function testSkipTraceMarkerSuppressesExceptionTrace(): void
    {
        [$logger, $handler] = $this->makeLogger();
        $logger->error('boom', [
            'exception' => new \RuntimeException('x'),
            \LOGGER_SKIP_TRACE => true,
        ]);

        $record = $handler->getRecords()[0];
        $this->assertSame('RuntimeException', $record->context['exception']);
        $this->assertArrayNotHasKey('trace', $record->context);
    }

    public function testSkipTraceMarkerKeyDoesNotLeakIntoContext(): void
    {
        [$logger, $handler] = $this->makeLogger();
        $logger->warning('msg', [\LOGGER_SKIP_TRACE => true]);

        $record = $handler->getRecords()[0];
        $this->assertArrayNotHasKey('skipTrace', $record->context);
        $this->assertArrayNotHasKey('skip_trace', $record->context);
    }

    public function testSkipTraceWinsOverConfiguredTraceDepth(): void
    {
        // Even with an explicit, generous configured depth, a truthy skip-trace marker must
        // still suppress the trace entirely.
        $config = new LoggerConfig(traceDepth: ['warning' => 5, 'error' => 10, 'critical' => 20]);
        [$logger, $handler] = $this->makeLogger($config);
        $logger->critical('deep', [\LOGGER_SKIP_TRACE => true]);

        $this->assertArrayNotHasKey('trace', $handler->getRecords()[0]->context);
    }

    public function testSkipTraceFalsyStringDoesNotSuppressTrace(): void
    {
        // Truthiness is decided via ContextEnricher's own bool convention
        // (CONTEXT_FALSE_VALUES), matching how every other bool-shaped context field in this
        // library is interpreted — a string 'false' opts back IN to tracing rather than
        // opting out.
        [$logger, $handler] = $this->makeLogger();
        $logger->error('a problem', [\LOGGER_SKIP_TRACE => 'false']);

        $this->assertArrayHasKey('trace', $handler->getRecords()[0]->context);
    }

    public function testExtraProcessorDumpsConfigExtra(): void
    {
        // ExtraProcessor copies LoggerConfig::$extra verbatim; empty values are dropped.
        $config = new LoggerConfig(extra: [
            'tier' => 'prod',
            'release_tag' => 'v1.2.3',
            'log_ver' => '0.3',
        ]);

        $record = new LogRecord(
            new \DateTimeImmutable(),
            'test',
            Level::Info,
            'hello',
        );
        $out = (new ExtraProcessor($config))($record);

        $this->assertSame('prod', $out->extra['tier']);
        $this->assertSame('v1.2.3', $out->extra['release_tag']);
        $this->assertSame('0.3', $out->extra['log_ver']);
    }

    /**
     * The convention (docs/ContextFieldNaming.ru.md), shared verbatim with the sibling
     * logger xakki/phperrorcatcher. String is the default; only the listed segments cast.
     *
     * @return array<string, array{0: string, 1: mixed, 2: mixed}>
     */
    public static function contextTypeProvider(): array
    {
        return [
            // --- 'id' is an int WORD: the bare key and every '*_id' ---
            'bare id is int' => ['id', '42', 42],
            'user_id is int' => ['user_id', '17', 17],
            'order_id is int' => ['order_id', '42', 42],
            'smq_id from a string call site' => ['smq_id', '2921172', 2921172],
            'smq_id from an int call site' => ['smq_id', 2921172, 2921172],
            'smq_id zero' => ['smq_id', 0, 0],
            'smq_id null stays null' => ['smq_id', null, null],
            // request_id is pinned to string in 'reserved' — the library writes a UUID there.
            // It must not rely on the string FALLBACK: an all-digit value would flip the field.
            'request_id uuid stays string' => ['request_id', '3f2a1c8e-5b6d-4e7f-9a01-2b3c4d5e6f70', '3f2a1c8e-5b6d-4e7f-9a01-2b3c4d5e6f70'],
            'request_id all digits still string' => ['request_id', '2921172', '2921172'],
            'request_id int still string' => ['request_id', 2921172, '2921172'],
            // TRAP 1: leading zeros are destroyed. Remedy — pin the key to string via config.
            'id loses leading zeros' => ['order_id', '00123', 123],
            'id loses leading zeros (007)' => ['order_id', '007', 7],
            // TRAP 2: an id past PHP_INT_MAX falls back to string, so THAT key is int for
            // short values and string for long ones. Remedy — the same config pin.
            'oversized id falls back to string' => ['order_id', '99999999999999999999', '99999999999999999999'],
            // Only the whole last SEGMENT counts: these merely end in the letters 'id'.
            'uuid is not id' => ['req_uuid', '5', '5'],
            'android is not id' => ['android', '5', '5'],
            'camelCase orderid is not id' => ['orderid', '5', '5'],

            // --- string is the DEFAULT: codes, hashes, names keep leading zeros ---
            'code stays string' => ['error_code', '0500', '0500'],
            'unknown key stays string' => ['whatever', '42', '42'],
            'int under no rule stringifies' => ['whatever', 42, '42'],
            'bool under no rule stringifies' => ['whatever', true, 'true'],

            // --- int words ---
            'count' => ['retry_count', '7', 7],
            'cnt' => ['row_cnt', '7', 7],
            'total' => ['item_total', '3', 3],
            'sum is int' => ['cart_sum', '42', 42],
            'bare sum is int' => ['sum', '42', 42],
            'sum truncates a money value' => ['cart_sum', '19.99', 19],
            'seconds' => ['wait_seconds', '2', 2],
            'time' => ['time', '1754300000', 1754300000],
            'time truncates microtime' => ['exec_time', 1.75, 1],
            'status' => ['status', '500', 500],
            'ttl' => ['cache_ttl', '600', 600],
            'size' => ['file_size', '1024', 1024],

            // --- float words ---
            'money' => ['order_money', '99.50', 99.50],
            'bare money' => ['money', '5', 5.0],
            'amount' => ['pay_amount', '100.10', 100.10],
            'price' => ['item_price', '19.99', 19.99],
            'duration' => ['job_duration', 3, 3.0],
            'rate' => ['hit_rate', '0.75', 0.75],
            'percent' => ['cpu_percent', '12.5', 12.5],
            'mctime is exact float' => ['mctime', '1.000001', 1.000001],

            // --- bool: last segment and first segment ---
            'success' => ['success', 1, true],
            'flag' => ['debug_flag', '', false],
            'enabled' => ['cache_enabled', 'true', true],
            'is prefix' => ['is_admin', 1, true],
            'has prefix' => ['has_access', 0, false],
            // 'word' outranks 'prefix', so a typed last segment BEATS a bool prefix:
            // 'is_bot_id' is an int, not a bool. Counter-intuitive but deliberate.
            'id word beats the is prefix' => ['is_bot_id', '5', 5],
            'legacy F is false' => ['is_bot', 'F', false],

            // --- escape hatch: '_int' / '_float' / '_bool' win over every other bucket ---
            'int suffix' => ['anything_int', '42', 42],
            'float suffix' => ['anything_float', '42', 42.0],
            'int suffix beats price' => ['price_int', '19.99', 19],
            'bool suffix beats count' => ['count_bool', '1', true],
            'float suffix beats seconds' => ['seconds_float', '2', 2.0],
            'suffix beats the is prefix' => ['is_ready_int', '1', 1],
            'bool suffix beats the id word' => ['some_id_bool', '1', true],
            'bool suffix beats the id word (off)' => ['some_id_bool', 'off', false],
            'float suffix beats the id word' => ['some_id_float', '42', 42.0],

            // --- whole SEGMENTS only, never substrings ---
            'recount is not count' => ['recount', '3', '3'],
            'report is not port' => ['report', '3', '3'],
            'datetime is not time' => ['datetime', '3', '3'],

            // --- uncastable falls back to string, null is preserved ---
            'uncastable int' => ['retry_count', 'many', 'many'],
            'hex is not decimal' => ['retry_count', '0x1A', '0x1A'],
            'null stays null' => ['retry_count', null, null],

            // --- exact pins for keys whose segments carry no type ---
            'memory_usage' => ['memory_usage', '2097152', 2097152],
            'memory_peak' => ['memory_peak', '4194304', 4194304],
            'legacy millisecond pinned int' => ['millisecond', '250', 250],
            'other ms spellings stay string' => ['exec_ms', '250', '250'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('contextTypeProvider')]
    public function testContextTypeConvention(string $key, mixed $input, mixed $expected): void
    {
        $enricher = $this->makeEnricher();
        $context = [$key => $input];
        $enricher->contextTypeCorrector($context);

        $this->assertSame($expected, $context[$key]);
    }

    /**
     * The escape hatch is the TOP of the chain: it beats 'reserved' (library-owned fields)
     * and 'exact' (the host override level), so a '*_int' name always wins.
     */
    public function testTypeSuffixBeatsReservedAndExact(): void
    {
        ContextEnricher::$contextTypeRules['reserved']['owned_int'] = ContextEnricher::CONTEXT_TYPE_STRING;
        ContextEnricher::$contextTypeRules['reserved']['skipped_bool'] = ContextEnricher::CONTEXT_TYPE_SKIP;
        ContextEnricher::$contextTypeRules['exact']['pinned_float'] = ContextEnricher::CONTEXT_TYPE_STRING;

        $enricher = $this->makeEnricher();
        $context = ['owned_int' => '42', 'skipped_bool' => '1', 'pinned_float' => '1.5'];
        $enricher->contextTypeCorrector($context);

        $this->assertSame(42, $context['owned_int']);
        $this->assertTrue($context['skipped_bool']);
        $this->assertSame(1.5, $context['pinned_float']);
    }

    /**
     * Resolution order end to end: suffix > reserved > exact > word > prefix > string.
     */
    public function testResolutionOrder(): void
    {
        ContextEnricher::$contextTypeRules['exact']['is_count'] = ContextEnricher::CONTEXT_TYPE_STRING;

        $enricher = $this->makeEnricher();
        $context = [
            'price_int' => '19.99',   // 1. suffix beats the 'price' word
            'exception_code' => '7',  // 2. reserved
            'is_count' => '5',        // 3. exact beats the 'count' word and the 'is' prefix
            'has_total' => '3',       // 4. word beats the 'has' prefix
            'is_admin' => '1',        // 5. prefix, nothing else matched
            'whatever' => '9',        // 6. no rule -> string
        ];
        $enricher->contextTypeCorrector($context);

        $this->assertSame(19, $context['price_int']);
        $this->assertSame(7, $context['exception_code']);
        $this->assertSame('5', $context['is_count']);
        $this->assertSame(3, $context['has_total']);
        $this->assertTrue($context['is_admin']);
        $this->assertSame('9', $context['whatever']);
    }

    /**
     * camelCase never matches a segment — it needs a lowercase 'exact' entry (or snake_case).
     */
    public function testCamelCaseNeedsAnExactRule(): void
    {
        $enricher = $this->makeEnricher();
        $context = ['orderQty' => '5'];
        $enricher->contextTypeCorrector($context);
        $this->assertSame('5', $context['orderQty']);

        ContextEnricher::addContextTypeRules(['exact' => ['orderqty' => ContextEnricher::CONTEXT_TYPE_INT]]);
        $context = ['orderQty' => '5'];
        $enricher->contextTypeCorrector($context);
        $this->assertSame(5, $context['orderQty']);
    }

    /**
     * The documented remedy for BOTH named traps of the int 'id' rule, proven executable:
     * there is no '_string' escape hatch, so a key carrying zero-padded or oversized ids can
     * only be pinned back to string through LoggerConfig::$contextTypeRules 'exact'.
     */
    public function testIdTrapsAreFixedByPinningTheKeyToString(): void
    {
        $enricher = $this->makeEnricher();

        // Unpinned: the traps themselves, asserted so they can never regress silently.
        $context = ['order_id' => '00123', 'other_id' => '99999999999999999999'];
        $enricher->contextTypeCorrector($context);
        $this->assertSame(123, $context['order_id']);
        $this->assertSame('99999999999999999999', $context['other_id']);

        ContextEnricher::addContextTypeRules([
            'exact' => [
                'legacy_id' => ContextEnricher::CONTEXT_TYPE_STRING,
                'legacy_id_long' => ContextEnricher::CONTEXT_TYPE_STRING,
            ],
        ]);
        $context = ['legacy_id' => '00123', 'legacy_id_long' => '99999999999999999999'];
        $enricher->contextTypeCorrector($context);

        // Pinned: leading zeros survive, and the key is a string for EVERY value length.
        $this->assertSame('00123', $context['legacy_id']);
        $this->assertSame('99999999999999999999', $context['legacy_id_long']);
    }

    /**
     * An unruled array/object is NOT flattened (would break nested-path queries); under a
     * scalar rule it is json-encoded, because (int) [1,2] === 1 loses the data silently.
     */
    public function testStructuresAreEncodedOnlyUnderAScalarRule(): void
    {
        $enricher = $this->makeEnricher();
        $context = ['payload' => [1, 2], 'retry_count' => [1, 2]];
        $enricher->contextTypeCorrector($context);

        $this->assertSame([1, 2], $context['payload']);
        $this->assertSame('[1,2]', $context['retry_count']);
    }

    public function testProjectRulesFromConfigAreMerged(): void
    {
        $config = new LoggerConfig(contextTypeRules: ['word' => ['rub' => ContextEnricher::CONTEXT_TYPE_FLOAT]]);
        $enricher = $this->makeEnricher($config);

        $context = ['order_rub' => '99.50'];
        $enricher->contextTypeCorrector($context);

        $this->assertSame(99.50, $context['order_rub']);
    }

    /**
     * FIX 1 regression test. `$contextTypeRules`/`$contextTypeRulesLoaded` used to be
     * static while `LoggerConfig` is per-instance: only the FIRST ContextEnricher ever
     * constructed had its config's `contextTypeRules` merged in at all (guarded by a
     * one-time static flag), and that merge then leaked into every OTHER instance
     * regardless of what its own config said. In a Symfony app with several Monolog
     * channels (each with its own LoggerConfig) the emitted field TYPE then depended on
     * load order.
     *
     * Two enrichers, two disjoint config rules (`widget`/`gadget`, both absent from the
     * built-in table): each must honour ONLY its own rule.
     */
    public function testConfigContextTypeRulesDoNotLeakBetweenInstances(): void
    {
        $configA = new LoggerConfig(contextTypeRules: ['word' => ['widget' => ContextEnricher::CONTEXT_TYPE_INT]]);
        $configB = new LoggerConfig(contextTypeRules: ['word' => ['gadget' => ContextEnricher::CONTEXT_TYPE_FLOAT]]);

        // Construction order matters for the bug this guards: A is built (and used) FIRST.
        $enricherA = $this->makeEnricher($configA);
        $enricherB = $this->makeEnricher($configB);

        $contextA = ['order_widget' => '5', 'order_gadget' => '5'];
        $enricherA->contextTypeCorrector($contextA);

        $contextB = ['order_widget' => '5', 'order_gadget' => '5'];
        $enricherB->contextTypeCorrector($contextB);

        // A honours its OWN rule (widget -> int) ...
        $this->assertSame(5, $contextA['order_widget']);
        // ... and does NOT gain B's rule: 'gadget' stays the string default for A.
        $this->assertSame('5', $contextA['order_gadget']);

        // B honours its OWN rule (gadget -> float) ...
        $this->assertSame(5.0, $contextB['order_gadget']);
        // ... and does NOT gain A's rule (this is the leak the bug caused): 'widget' stays
        // the string default for B, not the int A configured.
        $this->assertSame('5', $contextB['order_widget']);
    }

    public function testCorrectorIsIdempotent(): void
    {
        $enricher = $this->makeEnricher();
        $context = [
            'order_id' => '42',
            'retry_count' => '42',
            'is_bot' => 'F',
            'cart_money' => '1.5',
            'payload' => [1],
            'nothing' => null,
        ];
        $enricher->contextTypeCorrector($context);
        $once = $context;
        $enricher->contextTypeCorrector($context);

        $this->assertSame($once, $context);
    }
}

<?php declare(strict_types=1);

namespace Xakki\LogSymfonyTests\Unit;

use PHPUnit\Framework\TestCase;
use Xakki\LogSymfony\Redactor;

class RedactorTest extends TestCase
{
    private Redactor $redactor;

    protected function setUp(): void
    {
        parent::setUp();
        // The Laravel version cached needles in a static, config()-fed list; here a fresh
        // Redactor is just constructed per test with no extra needles (built-ins only).
        $this->redactor = new Redactor();
    }

    // --- Fix 1: quoted/JSON-ish key=value values (HIGH) ---

    public function testJsonDoubleQuotedValueIsRedacted(): void
    {
        // The quoted value (incl. its own quotes) is matched and replaced as a single unit,
        // so no dangling quote is left behind around the mask.
        $out = $this->redactor->redactValue('body: {"user": "alice", "password": "hunter2"}');

        $this->assertStringNotContainsString('hunter2', $out);
        $this->assertSame('body: {"user": "alice", "password": ***}', $out);
    }

    public function testPhpArrowSingleQuotedValueIsRedacted(): void
    {
        $out = $this->redactor->redactValue("'token' => 'abc'");

        $this->assertStringNotContainsString('abc', $out);
        $this->assertSame("'token' => ***", $out);
    }

    public function testUnquotedKeyEqualsQuotedValueIsRedacted(): void
    {
        $out = $this->redactor->redactValue('password="hunter2"');

        $this->assertStringNotContainsString('hunter2', $out);
        $this->assertSame('password=***', $out);
    }

    public function testPlainUnquotedAssignmentStillWorks(): void
    {
        // Pre-existing behaviour — must not regress.
        $out = $this->redactor->redactValue('secret=abc');

        $this->assertSame('secret=***', $out);
    }

    public function testColonSeparatedAssignmentStillWorks(): void
    {
        $out = $this->redactor->redactValue('token: abc123');

        $this->assertSame('token: ***', $out);
    }

    // --- Fix 2: URL-embedded credentials (MED) ---

    public function testPostgresUrlPasswordIsMaskedHostAndUserSurvive(): void
    {
        $out = $this->redactor->redactValue('postgres://user:S3cr3t@host/db');

        $this->assertStringNotContainsString('S3cr3t', $out);
        $this->assertSame('postgres://user:***@host/db', $out);
    }

    public function testHttpsUrlPasswordIsMasked(): void
    {
        $out = $this->redactor->redactValue('connect to https://user:pass@host/path failed');

        $this->assertStringNotContainsString('pass@', $out);
        $this->assertStringContainsString('https://user:***@host/path', $out);
    }

    // --- Pre-existing behaviours (no regression) ---

    public function testBuiltinNeedleIsRedacted(): void
    {
        $out = $this->redactor->redactValue('api_key=sk-live-123');

        $this->assertSame('api_key=***', $out);
    }

    public function testBearerTokenIsFullyMasked(): void
    {
        $out = $this->redactor->redactValue('Authorization: Bearer abcDEF123.token-value');

        $this->assertSame(Redactor::MASK, $out);
    }

    public function testJwtIsFullyMasked(): void
    {
        $jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U';
        $out = $this->redactor->redactValue($jwt);

        $this->assertSame(Redactor::MASK, $out);
    }

    public function testKeyMatchRedactsByField(): void
    {
        $this->assertTrue($this->redactor->shouldRedactKey('password'));
        $this->assertTrue($this->redactor->shouldRedactKey('User-Password'));
        $this->assertTrue($this->redactor->shouldRedactKey('api_key'));
    }

    public function testCardinalityAndAuthorAreNotOverRedacted(): void
    {
        // 'card'/'auth' are deliberately NOT needles — only 'card_number'/'authorization' are.
        $this->assertFalse($this->redactor->shouldRedactKey('cardinality'));
        $this->assertFalse($this->redactor->shouldRedactKey('author'));

        $out = $this->redactor->redactValue('cardinality=5, author=jdoe');
        $this->assertSame('cardinality=5, author=jdoe', $out);
    }

    // --- New: extra needles come in via the constructor (replaces config('logger.redact')) ---

    public function testExtraNeedlesFromConstructorAreRedacted(): void
    {
        $redactor = new Redactor(['x_internal_token']);

        $this->assertTrue($redactor->shouldRedactKey('x_internal_token'));
        $this->assertSame('x_internal_token=***', $redactor->redactValue('x_internal_token=abc'));
        // Built-ins are still active alongside the extra needle.
        $this->assertTrue($redactor->shouldRedactKey('password'));
    }
}

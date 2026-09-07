<?php declare(strict_types=1);

namespace Xakki\LogSymfonyTests\Unit;

use PHPUnit\Framework\TestCase;
use Xakki\LogSymfony\RequestId;

/**
 * FIX 4a regression tests. `tests/Unit/ContextEnricherTest.php::testInit()` only asserted
 * `request_id` is present — `RequestId::get()`'s body could be replaced with `return 'x';`
 * and the suite would stay green. These pin the actual VALUE-generation behaviour.
 *
 * Header PRECEDENCE is pinned by testInboundRequestIdWinsOverXRequestId(): `Request-Id`
 * beats `X-Request-Id`, mirroring log-laravel so both packages correlate the same request
 * to the same id. Owner decision 2026-09-06.
 */
class RequestIdTest extends TestCase
{
    private bool $hadXRequestId = false;
    private mixed $xRequestIdValue = null;
    private bool $hadRequestId = false;
    private mixed $requestIdValue = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hadXRequestId = array_key_exists('HTTP_X_REQUEST_ID', $_SERVER);
        $this->xRequestIdValue = $_SERVER['HTTP_X_REQUEST_ID'] ?? null;
        $this->hadRequestId = array_key_exists('HTTP_REQUEST_ID', $_SERVER);
        $this->requestIdValue = $_SERVER['HTTP_REQUEST_ID'] ?? null;

        unset($_SERVER['HTTP_X_REQUEST_ID'], $_SERVER['HTTP_REQUEST_ID']);
    }

    protected function tearDown(): void
    {
        if ($this->hadXRequestId) {
            $_SERVER['HTTP_X_REQUEST_ID'] = $this->xRequestIdValue;
        } else {
            unset($_SERVER['HTTP_X_REQUEST_ID']);
        }
        if ($this->hadRequestId) {
            $_SERVER['HTTP_REQUEST_ID'] = $this->requestIdValue;
        } else {
            unset($_SERVER['HTTP_REQUEST_ID']);
        }
        parent::tearDown();
    }

    public function testGeneratesAUuidV4ShapeWhenNoInboundHeaderIsPresent(): void
    {
        $id = (new RequestId())->get();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id,
        );
    }

    public function testGetIsStableAcrossRepeatedCalls(): void
    {
        $requestId = new RequestId();

        $first = $requestId->get();
        $second = $requestId->get();
        $third = $requestId->get();

        $this->assertSame($first, $second);
        $this->assertSame($first, $third);
    }

    public function testResetProducesADifferentId(): void
    {
        $requestId = new RequestId();

        $before = $requestId->get();
        $requestId->reset();
        $after = $requestId->get();

        $this->assertNotSame($before, $after);
    }

    public function testInboundXRequestIdHeaderIsHonoured(): void
    {
        $_SERVER['HTTP_X_REQUEST_ID'] = 'inbound-x-request-id-42';

        $id = (new RequestId())->get();

        $this->assertSame('inbound-x-request-id-42', $id);
    }

    public function testInboundRequestIdHeaderIsHonoured(): void
    {
        $_SERVER['HTTP_REQUEST_ID'] = 'inbound-request-id-42';

        $id = (new RequestId())->get();

        $this->assertSame('inbound-request-id-42', $id);
    }

    public function testInboundRequestIdWinsOverXRequestId(): void
    {
        $_SERVER['HTTP_REQUEST_ID'] = 'plain-request-id';
        $_SERVER['HTTP_X_REQUEST_ID'] = 'x-request-id';

        $id = (new RequestId())->get();

        $this->assertSame(
            'plain-request-id',
            $id,
            'Request-Id must win over X-Request-Id — same precedence as log-laravel.',
        );
    }

    public function testResetForgetsAnInboundHeaderToo(): void
    {
        $_SERVER['HTTP_X_REQUEST_ID'] = 'inbound-x-request-id-42';
        $requestId = new RequestId();
        $this->assertSame('inbound-x-request-id-42', $requestId->get());

        // The header is still present in $_SERVER after reset(), so the SAME inbound value
        // is resolved again — reset() only drops the cache, it doesn't touch $_SERVER.
        $requestId->reset();
        $this->assertSame('inbound-x-request-id-42', $requestId->get());
    }
}

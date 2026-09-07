<?php

declare(strict_types=1);

namespace Xakki\LogSymfony;

/**
 * Per-request/per-message correlation id (spec §4.4). Instance-scoped (unlike LaraLog's
 * `LogManager::getOrCreateRequestId()`, which stashed the value in `$_SERVER` + `putenv()`):
 * the host app builds one `RequestId` per unit of work and injects it wherever an id is
 * needed, and calls {@see self::reset()} between units of work (e.g. a future Symfony
 * Messenger subscriber resetting it between consumed messages).
 */
final class RequestId
{
    private ?string $id = null;

    /**
     * On first call: an inbound id from `Request-Id`, else `X-Request-Id`, else a
     * freshly generated UUIDv4. Cached for the lifetime of this instance (or until
     * {@see self::reset()}).
     */
    public function get(): string
    {
        if ($this->id === null) {
            $this->id = $this->resolveInbound() ?? self::generateUuidV4();
        }

        return $this->id;
    }

    /** Drop the cached id so the next {@see self::get()} resolves/generates a fresh one. */
    public function reset(): void
    {
        $this->id = null;
    }

    /**
     * Order mirrors LaraLog: `Request-Id` wins over `X-Request-Id` when both arrive with
     * different values, so the two packages correlate the same request identically.
     */
    private function resolveInbound(): ?string
    {
        foreach (['HTTP_REQUEST_ID', 'HTTP_X_REQUEST_ID'] as $key) {
            $value = $_SERVER[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private static function generateUuidV4(): string
    {
        $data = random_bytes(16);
        // Version 4 (random) + RFC 4122 variant bits.
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        $hex = bin2hex($data);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}

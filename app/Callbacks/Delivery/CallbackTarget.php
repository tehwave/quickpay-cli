<?php

namespace App\Callbacks\Delivery;

use InvalidArgumentException;
use ValueError;

/**
 * A callback destination that is safe to hand to the local HTTP forwarder.
 *
 * The command intentionally supports both loopback HTTP and public HTTPS
 * endpoints because local development is its primary use case. Validation is
 * nevertheless strict about URL structure so userinfo, fragments, and control
 * characters cannot make the displayed destination differ from the request.
 */
final readonly class CallbackTarget
{
    private function __construct(public string $url) {}

    public static function fromString(string $url): self
    {
        if ($url === '' || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            throw self::invalid();
        }

        try {
            $parts = parse_url($url);
        } catch (ValueError) {
            throw self::invalid();
        }

        if (! is_array($parts)
            || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || ! isset($parts['host'])
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw self::invalid();
        }

        return new self($url);
    }

    private static function invalid(): InvalidArgumentException
    {
        return new InvalidArgumentException('Callback destination must be a valid HTTP or HTTPS URL without userinfo or a fragment.');
    }
}

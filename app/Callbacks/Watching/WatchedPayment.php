<?php

namespace App\Callbacks\Watching;

use DateTimeImmutable;
use DateTimeZone;
use UnexpectedValueException;

/**
 * Reads the payment ID and operations a watch needs from a Quickpay payment,
 * rejecting malformed payments instead of guessing.
 */
final class WatchedPayment
{
    public static function id(mixed $payment): string
    {
        $id = is_array($payment) ? ($payment['id'] ?? null) : null;

        if ((! is_int($id) && ! is_string($id)) || (string) $id === '') {
            throw new UnexpectedValueException('Quickpay returned a payment without a valid payment ID.');
        }

        return (string) $id;
    }

    /**
     * Operations in creation order, ties broken by numeric operation ID.
     *
     * @return list<array{id: string, created_at: DateTimeImmutable}>
     */
    public static function operations(mixed $payment): array
    {
        $operations = is_array($payment) ? ($payment['operations'] ?? null) : null;

        if (! is_array($operations) || ! array_is_list($operations)) {
            throw new UnexpectedValueException('Quickpay returned a payment with malformed operations.');
        }

        $normalized = array_map(function (mixed $operation): array {
            $id = is_array($operation) ? ($operation['id'] ?? null) : null;

            if ((! is_int($id) && ! is_string($id)) || (string) $id === '') {
                throw new UnexpectedValueException('Quickpay returned an operation without a valid operation ID.');
            }

            return [
                'id' => (string) $id,
                'created_at' => self::operationTimestamp($operation['created_at'] ?? null),
            ];
        }, $operations);

        usort($normalized, fn (array $left, array $right): int => [
            (int) $left['created_at']->format('U'),
            (int) $left['created_at']->format('u'),
            str_pad($left['id'], 20, '0', STR_PAD_LEFT),
        ] <=> [
            (int) $right['created_at']->format('U'),
            (int) $right['created_at']->format('u'),
            str_pad($right['id'], 20, '0', STR_PAD_LEFT),
        ]);

        return $normalized;
    }

    private static function operationTimestamp(mixed $value): DateTimeImmutable
    {
        if (! is_string($value)
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/D', $value) !== 1) {
            throw new UnexpectedValueException('Quickpay returned an operation without a valid timestamp.');
        }

        $format = str_contains($value, '.') ? '!Y-m-d\TH:i:s.uP' : '!Y-m-d\TH:i:sP';
        $timestamp = DateTimeImmutable::createFromFormat($format, $value);
        $errors = DateTimeImmutable::getLastErrors();

        if ($timestamp === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new UnexpectedValueException('Quickpay returned an operation without a valid timestamp.');
        }

        return $timestamp->setTimezone(new DateTimeZone('UTC'));
    }
}

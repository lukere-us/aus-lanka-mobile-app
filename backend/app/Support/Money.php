<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Money convention (plan 2.1): DECIMAL(12,2) amount + ISO currency code, never floats.
 * Amounts are held as integer minor units (cents) inside the value object.
 */
final class Money
{
    private function __construct(public readonly int $minor, public readonly string $currency) {}

    public static function of(string|int $amount, ?string $currency = null): self
    {
        $amount = (string) $amount;
        if (! preg_match('/^-?\d+(\.\d{1,2})?$/', $amount)) {
            throw new InvalidArgumentException("Invalid money amount [$amount].");
        }
        [$whole, $fraction] = array_pad(explode('.', ltrim($amount, '-')), 2, '0');
        $minor = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');

        return new self(str_starts_with($amount, '-') ? -$minor : $minor, strtoupper($currency ?? config('marketplace.currency', 'LKR')));
    }

    public static function ofMinor(int $minor, ?string $currency = null): self
    {
        return new self($minor, strtoupper($currency ?? config('marketplace.currency', 'LKR')));
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor - $other->minor, $this->currency);
    }

    public function multiply(int $quantity): self
    {
        return new self($this->minor * $quantity, $this->currency);
    }

    /** Percentage in basis points (e.g. 750 = 7.5%), rounded half up to the nearest minor unit. */
    public function percentage(int $basisPoints): self
    {
        return new self(intdiv($this->minor * $basisPoints + ($this->minor >= 0 ? 5000 : -5000), 10000), $this->currency);
    }

    /** Decimal string for DECIMAL(12,2) columns and API payloads, e.g. "1500.00". */
    public function toDecimal(): string
    {
        $abs = abs($this->minor);

        return ($this->minor < 0 ? '-' : '').intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /** @return array{amount: string, currency: string} */
    public function toArray(): array
    {
        return ['amount' => $this->toDecimal(), 'currency' => $this->currency];
    }

    private function assertSameCurrency(self $other): void
    {
        if ($other->currency !== $this->currency) {
            throw new InvalidArgumentException("Currency mismatch: {$this->currency} vs {$other->currency}.");
        }
    }
}

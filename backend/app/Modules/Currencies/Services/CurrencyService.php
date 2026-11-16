<?php

namespace App\Modules\Currencies\Services;

use App\Http\Responses\ApiException;
use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Currencies\Models\Currency;
use App\Support\Money;

class CurrencyService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function baseCode(): string
    {
        return config('marketplace.currency');
    }

    /** An active currency customers may pay in, with a usable rate. */
    public function payable(string $code): Currency
    {
        $currency = Currency::where('code', strtoupper($code))->first();
        if (! $currency || ! $currency->is_active || (! $currency->is_base && ! $currency->rate_per_base)) {
            throw new ApiException(422, 'currency_not_supported', "Payments in {$code} are not available.");
        }

        return $currency;
    }

    /** Convert a base-currency amount into $currency at its current rate. */
    public function fromBase(Money $base, Currency $currency): Money
    {
        if ($base->currency !== $this->baseCode()) {
            throw new \InvalidArgumentException("Prices must be in {$this->baseCode()}, got {$base->currency}.");
        }

        return $currency->is_base ? $base : $base->convert((string) $currency->rate_per_base, $currency->code);
    }

    /** @param array{name?: string, symbol?: string, rate_per_base?: ?string, is_active?: bool} $data */
    public function upsert(string $code, array $data, User $admin): Currency
    {
        $code = strtoupper($code);
        $currency = Currency::firstOrNew(['code' => $code]);
        $before = $currency->exists ? $currency->only(['rate_per_base', 'is_active']) : null;

        $changesBaseRate = array_key_exists('rate_per_base', $data) && (float) $data['rate_per_base'] !== 1.0;
        if ($currency->is_base && ($changesBaseRate || ($data['is_active'] ?? true) === false)) {
            throw new ApiException(422, 'base_currency_locked', 'The base currency always has rate 1 and stays active.');
        }
        if (($data['is_active'] ?? $currency->is_active) && ! $currency->is_base && empty($data['rate_per_base'] ?? $currency->rate_per_base)) {
            throw new ApiException(422, 'rate_required', 'Set an exchange rate before activating this currency.');
        }

        $currency->fill($data);
        if (array_key_exists('rate_per_base', $data)) {
            $currency->rate_updated_at = now();
            $currency->rate_updated_by = $admin->id;
        }
        $currency->save();

        $this->audit->record($before ? 'currency.updated' : 'currency.created', $currency, [
            'before' => $before, 'after' => $currency->only(['rate_per_base', 'is_active']),
        ]);

        return $currency;
    }
}

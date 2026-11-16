<?php

namespace App\Modules\Settings\Services;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Business rules an admin can change without a deploy. Defaults are placeholders until the
 * product owner confirms them (decision log). Every change is audited.
 */
class PlatformSettings
{
    /** key => [default, validation rules, description] */
    public const DEFINITIONS = [
        'ticket_commission_bps' => [1000, ['integer', 'min:0', 'max:5000'], 'Platform commission on ticket sales, in basis points (1000 = 10%)'],
        'booking_fee_per_ticket' => ['0.00', ['decimal:0,2', 'min:0', 'max:100'], 'Booking fee per ticket charged to the customer (AUD)'],
        'booking_fee_bps' => [0, ['integer', 'min:0', 'max:2000'], 'Booking fee as a share of the ticket subtotal, in basis points'],
        'ticket_refund_cutoff_hours' => [48, ['integer', 'min:0', 'max:720'], 'Customers can request a ticket refund until this many hours before the event'],
        'rental_refund_window_hours' => [48, ['integer', 'min:0', 'max:720'], 'Unwatched rentals can be refunded within this many hours of purchase'],
        'payout_hold_days' => [2, ['integer', 'min:0', 'max:60'], 'Organizer earnings become payable this many days after the event starts'],
    ];

    private ?array $cache = null;

    public function __construct(private readonly AuditLogger $audit) {}

    public function get(string $key): mixed
    {
        if (! isset(self::DEFINITIONS[$key])) {
            throw new InvalidArgumentException("Unknown setting [$key].");
        }

        return $this->all()[$key];
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        if ($this->cache === null) {
            $stored = DB::table('platform_settings')->pluck('value', 'key')->map(fn ($v) => json_decode($v, true))->all();
            $this->cache = [];
            foreach (self::DEFINITIONS as $key => [$default]) {
                $this->cache[$key] = $stored[$key] ?? $default;
            }
        }

        return $this->cache;
    }

    /** @param array<string, mixed> $values already validated */
    public function update(array $values, User $admin): array
    {
        $before = $this->all();
        DB::transaction(function () use ($values, $admin) {
            foreach ($values as $key => $value) {
                $value = is_int(self::DEFINITIONS[$key][0]) ? (int) $value : number_format((float) $value, 2, '.', '');
                DB::table('platform_settings')->updateOrInsert(['key' => $key], [
                    'value' => json_encode($value), 'updated_by' => $admin->id, 'updated_at' => now(), 'created_at' => now(),
                ]);
            }
        });
        $this->cache = null;
        $after = $this->all();
        $this->audit->record('settings.updated', null, [
            'before' => array_intersect_key($before, $values), 'after' => array_intersect_key($after, $values),
        ]);

        return $after;
    }
}

<?php

namespace App\Modules\Orders\Http\Resources;

use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\Payment;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $money = fn (?string $amount) => Money::of($amount ?? '0', $this->currency)->toArray();
        $payment = $this->relationLoaded('latestPayment') ? $this->latestPayment : null;

        return [
            'reference' => $this->reference,
            'status' => $this->status->value,
            'subtotal' => $money($this->subtotal),
            'discount_total' => $money($this->discount_total),
            'fee_total' => $money($this->fee_total),
            'total' => $money($this->total),
            'exchange_rate' => $this->currency === $this->base_currency ? null : [
                'base_currency' => $this->base_currency, 'rate' => $this->exchange_rate,
                'base_total' => Money::of($this->base_total, $this->base_currency)->toArray(),
            ],
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'type' => $item->item_type,
                'name' => $item->name,
                'unit_price' => Money::of($item->unit_price, $item->currency)->toArray(),
                'quantity' => $item->quantity,
                'line_total' => Money::of($item->line_total, $item->currency)->toArray(),
            ])->values()),
            'payment' => $payment ? [
                'status' => $payment->status,
                'provider' => $payment->provider,
                'checkout_url' => $payment->status === Payment::INITIATED ? $payment->checkout_url : null,
            ] : null,
            'customer' => $this->when($request->is('api/v1/admin/*'), fn () => [
                'id' => $this->user_id,
                'email' => $this->user?->email,
            ]),
            'expires_at' => $this->expires_at?->toIso8601ZuluString(),
            'paid_at' => $this->paid_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}

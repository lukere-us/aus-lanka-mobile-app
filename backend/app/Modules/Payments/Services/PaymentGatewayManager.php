<?php

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Gateways\FakeGateway;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

class PaymentGatewayManager
{
    /** @var array<string, PaymentGateway> */
    private array $resolved = [];

    public function __construct(private readonly Container $container) {}

    public function driver(?string $name = null): PaymentGateway
    {
        $name ??= config('payments.default');

        return $this->resolved[$name] ??= $this->make($name);
    }

    public function exists(string $name): bool
    {
        return is_array(config("payments.gateways.{$name}"));
    }

    private function make(string $name): PaymentGateway
    {
        $config = config("payments.gateways.{$name}");
        if (! is_array($config)) {
            throw new InvalidArgumentException("Payment gateway [{$name}] is not configured.");
        }

        return match ($config['class']) {
            FakeGateway::class => new FakeGateway($this->container->make('cache')->store(), (string) $config['webhook_secret']),
            default => $this->container->make($config['class'], ['config' => $config]),
        };
    }
}

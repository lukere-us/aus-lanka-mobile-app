<?php

namespace App\Modules\Orders\Support;

use App\Modules\Orders\Contracts\PurchasableResolver;
use Illuminate\Contracts\Container\Container;

class PurchasableRegistry
{
    /** @var array<string, class-string<PurchasableResolver>|PurchasableResolver> */
    private array $resolvers = [];

    public function __construct(private readonly Container $container) {}

    /** @param class-string<PurchasableResolver>|PurchasableResolver $resolver */
    public function register(string $type, string|PurchasableResolver $resolver): void
    {
        $this->resolvers[$type] = $resolver;
    }

    public function has(string $type): bool
    {
        return isset($this->resolvers[$type]);
    }

    public function resolver(string $type): PurchasableResolver
    {
        $resolver = $this->resolvers[$type];

        return is_string($resolver) ? $this->resolvers[$type] = $this->container->make($resolver) : $resolver;
    }

    /** @return list<string> */
    public function types(): array
    {
        return array_keys($this->resolvers);
    }
}

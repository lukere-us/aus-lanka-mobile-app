<?php

namespace App\Modules\Rentals\Video;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

class VideoProviderManager
{
    public function __construct(private readonly Container $container) {}

    public function driver(?string $name = null): VideoProvider
    {
        $name ??= config('video.provider');
        $config = config("video.providers.{$name}");
        if (! is_array($config)) {
            throw new InvalidArgumentException("Video provider [{$name}] is not configured.");
        }

        return $this->container->make($config['class'], ['config' => $config]);
    }
}

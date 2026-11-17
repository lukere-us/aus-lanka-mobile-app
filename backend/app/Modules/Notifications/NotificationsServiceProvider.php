<?php

namespace App\Modules\Notifications;

use App\Modules\Notifications\Console\SendReminders;
use App\Modules\Notifications\Listeners\SendOrderReceipt;
use App\Modules\Notifications\Push\FcmPushSender;
use App\Modules\Notifications\Push\LogPushSender;
use App\Modules\Notifications\Push\PushSender;
use App\Modules\Orders\Events\OrderPaid;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class NotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PushSender::class, fn () => config('notifications.push_driver') === 'fcm'
            ? new FcmPushSender(config('notifications.fcm'))
            : new LogPushSender);
    }

    public function boot(): void
    {
        Event::listen(OrderPaid::class, SendOrderReceipt::class);

        if ($this->app->runningInConsole()) {
            $this->commands([SendReminders::class]);
        }
    }
}

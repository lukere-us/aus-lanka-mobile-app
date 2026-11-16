<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Catch lazy loading, silently discarded attributes and missing attributes during development.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Permissions are enforced server-side: Super Admin passes everything; everyone else needs the slug.
        Gate::before(function (User $user, string $ability) {
            if (! $user->isActive()) {
                return false;
            }
            if ($user->isSuperAdmin()) {
                return true;
            }

            return $user->hasPermission($ability) ? true : null;
        });

        Password::defaults(fn () => Password::min(10)->letters()->mixedCase()->numbers());

        ResetPassword::createUrlUsing(fn (User $user, string $token) => rtrim(config('marketplace.frontend_url'), '/')
            .'/reset-password?token='.$token.'&email='.urlencode($user->email));

        $this->configureRateLimits();
    }

    private function configureRateLimits(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

        // Payment provider callbacks: generous, but bounded per source IP.
        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(600)->by('wh:'.$request->ip()));

        // Gate scanners: a busy gate scans fast, but cap per device/user.
        RateLimiter::for('scanner', fn (Request $request) => Limit::perMinute(240)->by('scan:'.($request->user()?->getAuthIdentifier() ?: $request->ip())));

        // Login, registration and password reset: per account+IP, plus a ceiling per IP (B07).
        RateLimiter::for('auth', fn (Request $request) => [
            Limit::perMinute(5)->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(30)->by('ip:'.$request->ip()),
        ]);
    }
}

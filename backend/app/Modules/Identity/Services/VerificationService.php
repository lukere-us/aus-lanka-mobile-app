<?php

namespace App\Modules\Identity\Services;

use App\Http\Responses\ApiException;
use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\VerificationCode;
use App\Modules\Identity\Notifications\VerificationCodeNotification;
use App\Modules\Identity\Sms\SmsSender;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Email and phone verification by 6-digit code (C02). Codes expire after 10 minutes, allow 5 guesses,
 * can be resent after 60 seconds, and only a keyed hash is stored.
 */
class VerificationService
{
    public const TTL_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_SECONDS = 60;

    public function __construct(private readonly SmsSender $sms, private readonly AuditLogger $audit) {}

    /** @return string|null the code itself, returned only so local development can show it */
    public function send(User $user, string $channel): ?string
    {
        $destination = $channel === 'email' ? $user->email : $user->phone;
        if (! $destination) {
            throw new ApiException(422, 'no_phone', 'Add a mobile number to your profile first.');
        }
        if ($channel === 'email' ? $user->email_verified_at : $user->phone_verified_at) {
            throw new ApiException(409, 'already_verified', 'This is already verified.');
        }

        $recent = VerificationCode::where('user_id', $user->id)->where('channel', $channel)
            ->where('created_at', '>', now()->subSeconds(self::RESEND_SECONDS))->exists();
        if ($recent) {
            throw new ApiException(429, 'code_recently_sent', 'A code was just sent. Please wait a minute before asking again.');
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        DB::transaction(function () use ($user, $channel, $destination, $code) {
            VerificationCode::where('user_id', $user->id)->where('channel', $channel)->whereNull('consumed_at')
                ->update(['consumed_at' => now()]); // only the newest code works
            VerificationCode::create([
                'user_id' => $user->id, 'channel' => $channel, 'destination' => $destination,
                'code_hash' => self::hash($code), 'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            ]);
        });

        if ($channel === 'email') {
            Notification::send($user, new VerificationCodeNotification($code, self::TTL_MINUTES));
        } else {
            $this->sms->send($destination, "Your Aus Lanka verification code is {$code}. It expires in ".self::TTL_MINUTES.' minutes.');
        }

        return app()->environment('local') ? $code : null;
    }

    public function verify(User $user, string $channel, string $code): void
    {
        $verified = DB::transaction(function () use ($user, $channel, $code) {
            $record = VerificationCode::where('user_id', $user->id)->where('channel', $channel)
                ->whereNull('consumed_at')->latest('id')->lockForUpdate()->first();

            $destination = $channel === 'email' ? $user->email : $user->phone;
            if (! $record || $record->expires_at->isPast() || $record->destination !== $destination) {
                return 'expired';
            }
            if (! hash_equals($record->code_hash, self::hash($code))) {
                $record->attempts++;
                if ($record->attempts >= self::MAX_ATTEMPTS) {
                    $record->consumed_at = now();
                }
                $record->save();

                return 'wrong';
            }

            $record->update(['consumed_at' => now()]);
            $user->forceFill([$channel === 'email' ? 'email_verified_at' : 'phone_verified_at' => now()])->save();

            return 'ok';
        });

        // Throw only after the transaction, so a wrong guess is counted, not rolled back.
        if ($verified === 'expired') {
            throw new ApiException(422, 'code_expired', 'This code has expired or was replaced. Ask for a new one.');
        }
        if ($verified === 'wrong') {
            throw new ApiException(422, 'code_invalid', 'That code is not right. Please check and try again.');
        }
        $this->audit->record("user.{$channel}_verified", $user);
    }

    private static function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->char('preferred_currency', 3)->nullable()->after('status');
            $table->boolean('marketing_opt_in')->default(false)->after('preferred_currency');
            $table->json('notification_prefs')->nullable()->after('marketing_opt_in');
        });

        // One-time codes for email / phone verification. Only a keyed hash of the code is stored.
        Schema::create('verification_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 10);       // email | sms
            $table->string('destination', 255);  // the address/number the code was sent to
            $table->char('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'channel', 'created_at']);
        });

        // Push notification tokens (Firebase Cloud Messaging), one row per installed app.
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 512)->unique();
            $table->string('platform', 10); // ios | android | web
            $table->string('device_name', 100)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
        Schema::dropIfExists('verification_codes');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['preferred_currency', 'marketing_opt_in', 'notification_prefs']));
    }
};

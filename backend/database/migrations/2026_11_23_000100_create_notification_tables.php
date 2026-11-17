<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // In-app inbox (Laravel database notifications).
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::table('rentals', function (Blueprint $table) {
            $table->timestamp('expiry_reminded_at')->nullable()->after('expires_at');
        });

        // One "your event is tomorrow" reminder per person per event.
        Schema::create('event_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->timestamp('sent_at');
            $table->unique(['user_id', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_reminders');
        Schema::table('rentals', fn (Blueprint $t) => $t->dropColumn('expiry_reminded_at'));
        Schema::dropIfExists('notifications');
    }
};

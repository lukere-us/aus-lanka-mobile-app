<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_gates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60); // "Main gate", "VIP entrance"
            $table->timestamps();
            $table->unique(['event_id', 'name']);
        });

        // Which gate staff may scan at which event (optionally pinned to one gate).
        Schema::create('event_staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gate_id')->nullable()->constrained('event_gates')->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['event_id', 'user_id']);
        });

        // Phones/tablets registered for scanning; an organizer can revoke a lost device.
        Schema::create('scanner_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('registered_by')->constrained('users')->cascadeOnDelete();
            $table->string('device_uid', 100);
            $table->string('name', 100);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['organizer_id', 'device_uid']);
        });

        // Every scan attempt, admitted or not (G04). Never stores the QR value itself.
        Schema::create('ticket_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('gate_id')->nullable()->constrained('event_gates')->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('scanner_devices')->nullOnDelete();
            $table->string('result', 30); // admitted | already_used | wrong_event | not_valid | not_found | manual_admit | undo
            $table->string('note', 300)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['event_id', 'created_at']);
            $table->index(['event_id', 'result']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_scans');
        Schema::dropIfExists('scanner_devices');
        Schema::dropIfExists('event_staff');
        Schema::dropIfExists('event_gates');
    }
};

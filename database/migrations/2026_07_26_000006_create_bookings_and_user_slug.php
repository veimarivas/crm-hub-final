<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('booking_enabled')->default(false)->after('phone');
            $table->string('booking_slug', 60)->nullable()->unique()->after('booking_enabled');
            $table->unsignedInteger('booking_duration_min')->default(30)->after('booking_slug');
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('host_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('task_id')->nullable()->constrained()->nullOnDelete();
            $table->string('guest_name', 120);
            $table->string('guest_phone', 32);
            $table->string('guest_email', 150)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('scheduled_at');
            $table->unsignedInteger('duration_min')->default(30);
            $table->string('status', 20)->default('confirmed'); // confirmed|cancelled|completed
            $table->timestamps();

            $table->index(['host_user_id', 'scheduled_at']);
            $table->index(['account_id', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['booking_enabled', 'booking_slug', 'booking_duration_min']);
        });
    }
};

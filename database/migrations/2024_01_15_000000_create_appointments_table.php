<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('barber_name');
            $table->date('appointment_date');
            $table->time('appointment_time');
            $table->decimal('deposit_amount', 8, 2)->default(10.00);
            $table->string('payment_intent_id')->unique();
            $table->string('payment_status')->default('pending'); // pending, completed, failed, refunded
            $table->string('appointment_status')->default('scheduled'); // scheduled, completed, cancelled, no_show
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
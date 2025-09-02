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
        Schema::table('appointments', function (Blueprint $table) {
            $table->json('services')->nullable()->after('appointment_time');
            $table->decimal('total_amount', 8, 2)->default(0)->after('deposit_amount');
            $table->decimal('remaining_amount', 8, 2)->default(0)->after('total_amount');
            $table->boolean('full_payment_completed')->default(false)->after('payment_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn(['services', 'total_amount', 'remaining_amount', 'full_payment_completed']);
        });
    }
};
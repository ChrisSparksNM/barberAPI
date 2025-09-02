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
            $table->boolean('is_no_show')->default(false)->after('appointment_status');
            $table->decimal('no_show_charge_amount', 8, 2)->nullable()->after('is_no_show');
            $table->string('no_show_payment_intent_id')->nullable()->after('no_show_charge_amount');
            $table->timestamp('no_show_charged_at')->nullable()->after('no_show_payment_intent_id');
            $table->text('no_show_notes')->nullable()->after('no_show_charged_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn([
                'is_no_show', 
                'no_show_charge_amount', 
                'no_show_payment_intent_id', 
                'no_show_charged_at',
                'no_show_notes'
            ]);
        });
    }
};
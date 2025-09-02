<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\UserPaymentMethod;

class TestPaymentMethodsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get test customers
        $customer1 = User::where('email', 'customer@test.com')->first();
        $customer2 = User::where('email', 'customer2@test.com')->first();

        if ($customer1) {
            // Use Stripe's official test payment method for Visa
            UserPaymentMethod::create([
                'user_id' => $customer1->id,
                'stripe_payment_method_id' => 'pm_card_visa', // Stripe's test payment method
                'card_last_four' => '4242',
                'card_brand' => 'visa',
                'is_default' => true,
            ]);
        }

        if ($customer2) {
            // Use Stripe's official test payment method for Mastercard
            UserPaymentMethod::create([
                'user_id' => $customer2->id,
                'stripe_payment_method_id' => 'pm_card_mastercard', // Stripe's test payment method
                'card_last_four' => '5555',
                'card_brand' => 'mastercard',
                'is_default' => true,
            ]);
        }

        $this->command->info('Test payment methods created successfully!');
    }
}
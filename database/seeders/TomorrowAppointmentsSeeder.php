<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Appointment;
use App\Models\User;
use Carbon\Carbon;

class TomorrowAppointmentsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get test customers
        $customer1 = User::where('email', 'customer@test.com')->first();
        $customer2 = User::where('email', 'customer2@test.com')->first();

        if (!$customer1 || !$customer2) {
            $this->command->error('Test customers not found. Please run TestAppointmentsSeeder first.');
            return;
        }

        $tomorrow = Carbon::tomorrow();

        // Create appointments for tomorrow to test reminders
        $appointments = [
            [
                'user_id' => $customer1->id,
                'barber_name' => 'David',
                'appointment_date' => $tomorrow->format('Y-m-d'),
                'appointment_time' => '10:00',
                'services' => [
                    ['name' => 'Haircut', 'price' => 35.00],
                    ['name' => 'Beard Trim', 'price' => 15.00]
                ],
                'deposit_amount' => 10.00,
                'total_amount' => 50.00,
                'remaining_amount' => 40.00,
                'payment_intent_id' => 'pi_test_' . uniqid(),
                'payment_status' => 'deposit_paid',
                'appointment_status' => 'scheduled',
                'is_no_show' => false,
            ],
            [
                'user_id' => $customer2->id,
                'barber_name' => 'Jesse',
                'appointment_date' => $tomorrow->format('Y-m-d'),
                'appointment_time' => '14:30',
                'services' => [
                    ['name' => 'Haircut', 'price' => 40.00],
                    ['name' => 'Shampoo', 'price' => 10.00]
                ],
                'deposit_amount' => 10.00,
                'total_amount' => 50.00,
                'remaining_amount' => 40.00,
                'payment_intent_id' => 'pi_test_' . uniqid(),
                'payment_status' => 'deposit_paid',
                'appointment_status' => 'scheduled',
                'is_no_show' => false,
            ],
            [
                'user_id' => $customer1->id,
                'barber_name' => 'Marissa',
                'appointment_date' => $tomorrow->format('Y-m-d'),
                'appointment_time' => '16:00',
                'services' => [
                    ['name' => 'Haircut', 'price' => 45.00]
                ],
                'deposit_amount' => 10.00,
                'total_amount' => 45.00,
                'remaining_amount' => 35.00,
                'payment_intent_id' => 'pi_test_' . uniqid(),
                'payment_status' => 'deposit_paid',
                'appointment_status' => 'scheduled',
                'is_no_show' => false,
            ],
        ];

        foreach ($appointments as $appointmentData) {
            Appointment::create($appointmentData);
        }

        $this->command->info('Tomorrow appointments created successfully!');
        $this->command->info("Created " . count($appointments) . " appointments for " . $tomorrow->format('l, F j, Y'));
        $this->command->info('These appointments will be eligible for reminder SMS.');
    }
}
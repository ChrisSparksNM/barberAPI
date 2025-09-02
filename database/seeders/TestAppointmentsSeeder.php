<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Appointment;
use App\Models\User;
use Carbon\Carbon;

class TestAppointmentsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create a test customer if doesn't exist
        $customer = User::firstOrCreate(
            ['email' => 'customer@test.com'],
            [
                'name' => 'Test Customer',
                'email' => 'customer@test.com',
                'phone_number' => '+15551234567', // Test phone number
                'password' => bcrypt('password'),
                'role' => 'customer',
                'sms_notifications_enabled' => true,
            ]
        );

        $customer2 = User::firstOrCreate(
            ['email' => 'customer2@test.com'],
            [
                'name' => 'Jane Smith',
                'email' => 'customer2@test.com',
                'phone_number' => '+15559876543', // Test phone number
                'password' => bcrypt('password'),
                'role' => 'customer',
                'sms_notifications_enabled' => true,
            ]
        );

        // Create test appointments for today and upcoming days
        $appointments = [
            // Today's appointments (past time - eligible for no-show)
            [
                'user_id' => $customer->id,
                'barber_name' => 'David',
                'appointment_date' => now()->format('Y-m-d'),
                'appointment_time' => '09:00',
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
                'barber_name' => 'David',
                'appointment_date' => now()->format('Y-m-d'),
                'appointment_time' => '10:30',
                'services' => [
                    ['name' => 'Haircut', 'price' => 35.00]
                ],
                'deposit_amount' => 10.00,
                'total_amount' => 35.00,
                'remaining_amount' => 25.00,
                'payment_intent_id' => 'pi_test_' . uniqid(),
                'payment_status' => 'deposit_paid',
                'appointment_status' => 'scheduled',
                'is_no_show' => false,
            ],
            // Future appointment (not eligible for no-show yet)
            [
                'user_id' => $customer->id,
                'barber_name' => 'Jesse',
                'appointment_date' => now()->addDay()->format('Y-m-d'),
                'appointment_time' => '14:00',
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
            // Already completed appointment
            [
                'user_id' => $customer2->id,
                'barber_name' => 'Marissa',
                'appointment_date' => now()->subDay()->format('Y-m-d'),
                'appointment_time' => '11:00',
                'services' => [
                    ['name' => 'Haircut', 'price' => 45.00]
                ],
                'deposit_amount' => 10.00,
                'total_amount' => 45.00,
                'remaining_amount' => 35.00,
                'payment_intent_id' => 'pi_test_' . uniqid(),
                'payment_status' => 'completed',
                'appointment_status' => 'completed',
                'is_no_show' => false,
            ],
            // Existing no-show (not charged yet)
            [
                'user_id' => $customer->id,
                'barber_name' => 'David',
                'appointment_date' => now()->subDays(2)->format('Y-m-d'),
                'appointment_time' => '15:00',
                'services' => [
                    ['name' => 'Haircut', 'price' => 35.00]
                ],
                'deposit_amount' => 10.00,
                'total_amount' => 35.00,
                'remaining_amount' => 25.00,
                'payment_intent_id' => 'pi_test_' . uniqid(),
                'payment_status' => 'deposit_paid',
                'appointment_status' => 'no_show',
                'is_no_show' => true,
                'no_show_notes' => 'Customer did not show up',
            ],
        ];

        foreach ($appointments as $appointmentData) {
            Appointment::create($appointmentData);
        }

        $this->command->info('Test appointments created successfully!');
        $this->command->info('Created appointments for David, Jesse, and Marissa');
        $this->command->info('Some appointments are eligible for no-show marking');
    }
}
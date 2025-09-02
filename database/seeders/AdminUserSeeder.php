<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create admin user if it doesn't exist
        if (!User::where('email', 'admin@barbershop.com')->exists()) {
            User::create([
                'name' => 'Admin User',
                'email' => 'admin@barbershop.com',
                'phone_number' => '+1234567890',
                'password' => Hash::make('admin123'),
                'role' => 'admin',
                'email_verified_at' => now(),
                'is_active' => true,
            ]);

            $this->command->info('Admin user created: admin@barbershop.com / admin123');
        }

        // Create sample barbers
        $barbers = [
            [
                'name' => 'David Johnson',
                'email' => 'david@barbershop.com',
                'phone_number' => '+1234567891',
                'barber_name' => 'David',
                'bio' => 'Experienced barber specializing in classic cuts and modern styles.',
                'specialties' => ['Classic Cuts', 'Beard Trimming', 'Hot Towel Shaves'],
                'working_hours' => [
                    'monday' => ['start' => '09:00', 'end' => '17:00'],
                    'tuesday' => ['start' => '09:00', 'end' => '17:00'],
                    'wednesday' => ['start' => '09:00', 'end' => '17:00'],
                    'thursday' => ['start' => '09:00', 'end' => '17:00'],
                    'friday' => ['start' => '09:00', 'end' => '17:00'],
                    'saturday' => ['start' => '10:00', 'end' => '16:00'],
                    'sunday' => null
                ],
                'hourly_rate' => 75.00,
            ],
            [
                'name' => 'Jesse Martinez',
                'email' => 'jesse@barbershop.com',
                'phone_number' => '+1234567892',
                'barber_name' => 'Jesse',
                'bio' => 'Creative stylist with expertise in modern cuts and color.',
                'specialties' => ['Modern Cuts', 'Hair Coloring', 'Styling'],
                'working_hours' => [
                    'monday' => ['start' => '10:00', 'end' => '18:00'],
                    'tuesday' => ['start' => '10:00', 'end' => '18:00'],
                    'wednesday' => ['start' => '10:00', 'end' => '18:00'],
                    'thursday' => ['start' => '10:00', 'end' => '18:00'],
                    'friday' => ['start' => '10:00', 'end' => '18:00'],
                    'saturday' => ['start' => '09:00', 'end' => '17:00'],
                    'sunday' => null
                ],
                'hourly_rate' => 80.00,
            ],
            [
                'name' => 'Marissa Thompson',
                'email' => 'marissa@barbershop.com',
                'phone_number' => '+1234567893',
                'barber_name' => 'Marissa',
                'bio' => 'Professional stylist with 10+ years experience in all hair types.',
                'specialties' => ['Women\'s Cuts', 'Curly Hair', 'Treatments'],
                'working_hours' => [
                    'monday' => ['start' => '08:00', 'end' => '16:00'],
                    'tuesday' => ['start' => '08:00', 'end' => '16:00'],
                    'wednesday' => ['start' => '08:00', 'end' => '16:00'],
                    'thursday' => ['start' => '08:00', 'end' => '16:00'],
                    'friday' => ['start' => '08:00', 'end' => '16:00'],
                    'saturday' => ['start' => '09:00', 'end' => '15:00'],
                    'sunday' => null
                ],
                'hourly_rate' => 85.00,
            ],
        ];

        foreach ($barbers as $barberData) {
            if (!User::where('email', $barberData['email'])->exists()) {
                User::create([
                    'name' => $barberData['name'],
                    'email' => $barberData['email'],
                    'phone_number' => $barberData['phone_number'],
                    'password' => Hash::make('barber123'),
                    'role' => 'barber',
                    'barber_name' => $barberData['barber_name'],
                    'bio' => $barberData['bio'],
                    'specialties' => $barberData['specialties'],
                    'working_hours' => $barberData['working_hours'],
                    'hourly_rate' => $barberData['hourly_rate'],
                    'email_verified_at' => now(),
                    'is_active' => true,
                ]);

                $this->command->info("Barber created: {$barberData['email']} / barber123");
            }
        }

        // Create a sample customer
        if (!User::where('email', 'customer@example.com')->exists()) {
            User::create([
                'name' => 'John Customer',
                'email' => 'customer@example.com',
                'phone_number' => '+1234567894',
                'password' => Hash::make('customer123'),
                'role' => 'customer',
                'email_verified_at' => now(),
                'is_active' => true,
            ]);

            $this->command->info('Sample customer created: customer@example.com / customer123');
        }
    }
}
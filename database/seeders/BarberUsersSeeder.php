<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class BarberUsersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create barber users
        $barbers = [
            [
                'name' => 'David',
                'email' => 'david@taosempire.com',
                'password' => Hash::make('password123'),
                'role' => 'barber',
                'barber_name' => 'David',
            ],
            [
                'name' => 'Jesse',
                'email' => 'jesse@taosempire.com',
                'password' => Hash::make('password123'),
                'role' => 'barber',
                'barber_name' => 'Jesse',
            ],
            [
                'name' => 'Marissa',
                'email' => 'marissa@taosempire.com',
                'password' => Hash::make('password123'),
                'role' => 'barber',
                'barber_name' => 'Marissa',
            ],
        ];

        foreach ($barbers as $barber) {
            User::updateOrCreate(
                ['email' => $barber['email']],
                $barber
            );
        }

        // Create an admin user
        User::updateOrCreate(
            ['email' => 'admin@taosempire.com'],
            [
                'name' => 'Admin User',
                'email' => 'admin@taosempire.com',
                'password' => Hash::make('admin123'),
                'role' => 'admin',
                'barber_name' => null,
            ]
        );

        $this->command->info('Barber users created successfully!');
        $this->command->info('Login credentials:');
        $this->command->info('David: david@taosempire.com / password123');
        $this->command->info('Jesse: jesse@taosempire.com / password123');
        $this->command->info('Marissa: marissa@taosempire.com / password123');
        $this->command->info('Admin: admin@taosempire.com / admin123');
    }
}
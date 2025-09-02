<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone_number',
        'password',
        'role',
        'barber_name',
        'push_notification_token',
        'notifications_enabled',
        'sms_notifications_enabled',
        'is_active',
        'profile_image',
        'bio',
        'specialties',
        'working_hours',
        'hourly_rate',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'notifications_enabled' => 'boolean',
            'sms_notifications_enabled' => 'boolean',
            'specialties' => 'array',
            'working_hours' => 'array',
            'hourly_rate' => 'decimal:2',
        ];
    }

    /**
     * Get the user's payment methods.
     */
    public function paymentMethods()
    {
        return $this->hasMany(UserPaymentMethod::class);
    }

    /**
     * Get the user's default payment method.
     */
    public function defaultPaymentMethod()
    {
        return $this->hasOne(UserPaymentMethod::class)->where('is_default', true);
    }

    /**
     * Check if user is an admin
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Check if user is a barber
     */
    public function isBarber(): bool
    {
        return $this->role === 'barber';
    }

    /**
     * Check if user is staff (barber or admin)
     */
    public function isStaff(): bool
    {
        return $this->isBarber() || $this->isAdmin();
    }

    /**
     * Get appointments for this barber
     */
    public function barberAppointments()
    {
        return $this->hasMany(Appointment::class, 'barber_name', 'barber_name');
    }
}

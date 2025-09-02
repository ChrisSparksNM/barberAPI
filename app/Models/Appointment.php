<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appointment extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'barber_name',
        'appointment_date',
        'appointment_time',
        'services',
        'deposit_amount',
        'total_amount',
        'remaining_amount',
        'payment_intent_id',
        'remaining_balance_payment_intent_id',
        'payment_status',
        'full_payment_completed',
        'appointment_status',
        'is_no_show',
        'no_show_charge_amount',
        'no_show_payment_intent_id',
        'no_show_charged_at',
        'no_show_notes',
        'notes',
        'reminder_sent_at',
        'tip_amount',
        'tip_payment_intent_id',
        'tip_paid_at',
    ];

    protected $casts = [
        'appointment_date' => 'date',
        'services' => 'array',
        'deposit_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'remaining_amount' => 'decimal:2',
        'full_payment_completed' => 'boolean',
        'is_no_show' => 'boolean',
        'no_show_charge_amount' => 'decimal:2',
        'no_show_charged_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
        'tip_amount' => 'decimal:2',
        'tip_paid_at' => 'datetime',
    ];

    /**
     * Get the user that owns the appointment.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope for upcoming appointments
     */
    public function scopeUpcoming($query)
    {
        return $query->where('appointment_date', '>=', now()->toDateString())
                    ->where('appointment_status', '!=', 'cancelled');
    }

    /**
     * Scope for completed appointments
     */
    public function scopeCompleted($query)
    {
        return $query->where('appointment_status', 'completed');
    }

    /**
     * Check if appointment is in the past
     */
    public function isPast(): bool
    {
        $appointmentDateTime = $this->appointment_date->format('Y-m-d') . ' ' . $this->appointment_time . ':00';
        return now()->gt($appointmentDateTime);
    }

    /**
     * Check if appointment can be cancelled
     */
    public function canBeCancelled(): bool
    {
        // Can be cancelled if it's more than 24 hours away and status is scheduled
        if ($this->appointment_status !== 'scheduled') {
            return false;
        }
        
        $appointmentDateTime = $this->appointment_date->format('Y-m-d') . ' ' . $this->appointment_time . ':00';
        return now()->addDay()->lt($appointmentDateTime);
    }

    /**
     * Check if appointment is eligible for no-show charge
     */
    public function isEligibleForNoShowCharge(): bool
    {
        // Any scheduled appointment that hasn't been marked as no-show can be charged
        return $this->appointment_status === 'scheduled' && !$this->is_no_show;
    }

    /**
     * Check if appointment has remaining balance to charge
     */
    public function hasRemainingBalance(): bool
    {
        return $this->remaining_amount > 0;
    }

    /**
     * Get the remaining balance amount
     */
    public function getRemainingBalanceAmount(): float
    {
        return floatval($this->remaining_amount);
    }

    /**
     * Get the no-show charge amount (full service cost)
     */
    public function getNoShowChargeAmount(): float
    {
        return floatval($this->total_amount);
    }

    /**
     * Mark appointment as no-show
     */
    public function markAsNoShow(?string $notes = null): void
    {
        $this->update([
            'is_no_show' => true,
            'appointment_status' => 'no_show',
            'no_show_notes' => $notes,
        ]);
    }

    /**
     * Check if appointment can receive a tip
     */
    public function canReceiveTip(): bool
    {
        return $this->appointment_status === 'completed' && !$this->is_no_show;
    }

    /**
     * Get the total amount including tip
     */
    public function getTotalWithTip(): float
    {
        return floatval($this->total_amount) + floatval($this->tip_amount);
    }

    /**
     * Check if tip has been paid
     */
    public function hasTip(): bool
    {
        return $this->tip_amount > 0 && !empty($this->tip_paid_at);
    }
}
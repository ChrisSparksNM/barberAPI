<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPaymentMethod extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'stripe_payment_method_id',
        'card_brand',
        'card_last_four',
        'card_exp_month',
        'card_exp_year',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'card_exp_month' => 'integer',
        'card_exp_year' => 'integer',
    ];

    /**
     * Get the user that owns the payment method.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the formatted card display
     */
    public function getCardDisplayAttribute(): string
    {
        return ucfirst($this->card_brand) . ' •••• ' . $this->card_last_four;
    }

    /**
     * Set this payment method as default and unset others
     */
    public function setAsDefault(): void
    {
        // Unset all other default payment methods for this user
        self::where('user_id', $this->user_id)
            ->where('id', '!=', $this->id)
            ->update(['is_default' => false]);
        
        // Set this one as default
        $this->update(['is_default' => true]);
    }
}
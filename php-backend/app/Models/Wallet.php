<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Wallet extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'balance',
        'reserved',
        'currency',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'reserved' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function transactions()
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function getAvailableBalanceAttribute(): float
    {
        return (float) $this->balance - (float) $this->reserved;
    }

    public function reserve(float $amount): bool
    {
        if ($this->available_balance < $amount) {
            return false;
        }
        
        $this->increment('reserved', $amount);
        return true;
    }

    public function releaseReservation(float $amount): void
    {
        $this->decrement('reserved', min($amount, $this->reserved));
    }

    public function debit(float $amount, string $description, ?int $campaignId = null): WalletTransaction
    {
        $this->decrement('balance', $amount);
        
        return $this->transactions()->create([
            'amount' => -$amount,
            'type' => 'debit',
            'description' => $description,
            'reference' => $campaignId ? "CAMP-{$campaignId}" : null,
            'status' => 'completed',
        ]);
    }

    public function credit(float $amount, string $description, ?string $reference = null): WalletTransaction
    {
        $this->increment('balance', $amount);
        
        return $this->transactions()->create([
            'amount' => $amount,
            'type' => 'credit',
            'description' => $description,
            'reference' => $reference,
            'status' => 'completed',
        ]);
    }

    public function refund(float $amount, string $description): WalletTransaction
    {
        $this->increment('balance', $amount);
        
        return $this->transactions()->create([
            'amount' => $amount,
            'type' => 'refund',
            'description' => $description,
            'status' => 'completed',
        ]);
    }
}

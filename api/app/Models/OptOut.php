<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OptOut extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'recipient',
        'channel',
        'reason',
        'source',
        'campaign_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeSms($query)
    {
        return $query->whereIn('channel', ['sms', 'all']);
    }

    public function scopeEmail($query)
    {
        return $query->whereIn('channel', ['email', 'all']);
    }

    public function scopeForRecipient($query, string $recipient)
    {
        return $query->where('recipient', $recipient);
    }

    public static function isOptedOut(int $userId, string $recipient, string $channel = 'all'): bool
    {
        return static::where('user_id', $userId)
            ->where('recipient', $recipient)
            ->where(function ($q) use ($channel) {
                $q->where('channel', $channel)
                  ->orWhere('channel', 'all');
            })
            ->exists();
    }
}

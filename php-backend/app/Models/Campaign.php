<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Campaign extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'channel',
        'status',
        'message',
        'subject',
        'sender_id',
        'from_email',
        'from_name',
        'template_id',
        'scheduled_at',
        'sent_at',
        'completed_at',
        'total_recipients',
        'sent_count',
        'delivered_count',
        'failed_count',
        'estimated_cost',
        'actual_cost',
        'reserved_credits',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'sent_at' => 'datetime',
        'completed_at' => 'datetime',
        'estimated_cost' => 'decimal:2',
        'actual_cost' => 'decimal:2',
        'reserved_credits' => 'decimal:2',
    ];

    const STATUS_DRAFT = 'Draft';
    const STATUS_PENDING = 'Pending';
    const STATUS_QUEUED = 'Queued';
    const STATUS_SENDING = 'Sending';
    const STATUS_SENT = 'Sent';
    const STATUS_COMPLETED = 'Completed';
    const STATUS_FAILED = 'Failed';
    const STATUS_CANCELLED = 'Cancelled';

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    public function template()
    {
        return $this->belongsTo(Template::class);
    }

    public function scopeSms($query)
    {
        return $query->where('channel', 'sms');
    }

    public function scopeEmail($query)
    {
        return $query->where('channel', 'email');
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function isSms(): bool
    {
        return $this->channel === 'sms';
    }

    public function isEmail(): bool
    {
        return $this->channel === 'email';
    }

    public function isScheduled(): bool
    {
        return $this->scheduled_at !== null && $this->scheduled_at->isFuture();
    }

    public function canBeCancelled(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_PENDING, self::STATUS_QUEUED]);
    }

    public function updateStats(): void
    {
        $this->update([
            'sent_count' => $this->messages()->whereIn('status', ['Sent', 'Delivered', 'Failed'])->count(),
            'delivered_count' => $this->messages()->where('status', 'Delivered')->count(),
            'failed_count' => $this->messages()->where('status', 'Failed')->count(),
        ]);
    }

    public function getDeliveryRateAttribute(): float
    {
        if ($this->sent_count === 0) return 0;
        return round(($this->delivered_count / $this->sent_count) * 100, 2);
    }

    public function getProgressAttribute(): float
    {
        if ($this->total_recipients === 0) return 0;
        return round((($this->sent_count + $this->failed_count) / $this->total_recipients) * 100, 2);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'recipient',
        'content',
        'subject',
        'status',
        'external_id',
        'gateway_response',
        'cost',
        'parts',
        'sent_at',
        'delivered_at',
        'failed_at',
        'error_message',
    ];

    protected $casts = [
        'cost' => 'decimal:2',
        'gateway_response' => 'array',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    const STATUS_PENDING = 'Pending';
    const STATUS_QUEUED = 'Queued';
    const STATUS_SENT = 'Sent';
    const STATUS_AWAITING_DLR = 'Awaiting DLR';
    const STATUS_DELIVERED = 'Delivered';
    const STATUS_FAILED = 'Failed';
    const STATUS_OPTED_OUT = 'Opted-Out';
    const STATUS_REJECTED = 'Rejected';

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }

    public function dlrLogs()
    {
        return $this->hasMany(DlrLog::class);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeQueued($query)
    {
        return $query->where('status', self::STATUS_QUEUED);
    }

    public function scopeAwaitingDlr($query)
    {
        return $query->where('status', self::STATUS_AWAITING_DLR);
    }

    public function markSent(string $externalId, array $gatewayResponse = []): void
    {
        $this->update([
            'status' => self::STATUS_AWAITING_DLR,
            'external_id' => $externalId,
            'gateway_response' => $gatewayResponse,
            'sent_at' => now(),
        ]);
    }

    public function markDelivered(): void
    {
        $this->update([
            'status' => self::STATUS_DELIVERED,
            'delivered_at' => now(),
        ]);
    }

    public function markFailed(string $reason = null): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'failed_at' => now(),
            'error_message' => $reason,
        ]);
    }
}

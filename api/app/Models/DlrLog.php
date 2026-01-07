<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DlrLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'message_id',
        'external_id',
        'status',
        'raw_payload',
        'received_at',
    ];

    protected $casts = [
        'raw_payload' => 'array',
        'received_at' => 'datetime',
    ];

    public function message()
    {
        return $this->belongsTo(Message::class);
    }
}

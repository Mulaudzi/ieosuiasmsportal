<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'phone',
        'email',
        'first_name',
        'last_name',
        'company',
        'custom_fields',
        'opt_out',
        'source',
    ];

    protected $casts = [
        'custom_fields' => 'array',
        'opt_out' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function groups()
    {
        return $this->belongsToMany(ContactGroup::class, 'group_contacts', 'contact_id', 'group_id');
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeActive($query)
    {
        return $query->where('opt_out', false);
    }

    public function scopeWithPhone($query)
    {
        return $query->whereNotNull('phone')->where('phone', '!=', '');
    }

    public function scopeWithEmail($query)
    {
        return $query->whereNotNull('email')->where('email', '!=', '');
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}") ?: 'Unknown';
    }

    public function optOut(): void
    {
        $this->update(['opt_out' => true]);
    }

    public function optIn(): void
    {
        $this->update(['opt_out' => false]);
    }
}

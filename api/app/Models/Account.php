<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'company_name',
        'address',
        'city',
        'province',
        'postal_code',
        'country',
        'vat_number',
        'logo_url',
        'website',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

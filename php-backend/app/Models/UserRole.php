<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserRole extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'role',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function assignRole(int $userId, string $role): self
    {
        return static::firstOrCreate([
            'user_id' => $userId,
            'role' => $role,
        ]);
    }

    public static function removeRole(int $userId, string $role): bool
    {
        return static::where('user_id', $userId)
            ->where('role', $role)
            ->delete() > 0;
    }
}

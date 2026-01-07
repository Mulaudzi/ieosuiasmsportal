<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Template extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'channel',
        'content',
        'subject',
        'variables',
        'category',
        'is_default',
    ];

    protected $casts = [
        'variables' => 'array',
        'is_default' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function campaigns()
    {
        return $this->hasMany(Campaign::class);
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeSms($query)
    {
        return $query->where('channel', 'sms');
    }

    public function scopeEmail($query)
    {
        return $query->where('channel', 'email');
    }

    public function scopeDefaults($query)
    {
        return $query->where('is_default', true);
    }

    /**
     * Parse template content with variables
     */
    public function parse(array $data): string
    {
        $content = $this->content;
        
        foreach ($data as $key => $value) {
            $content = str_replace("{{$key}}", $value, $content);
            $content = str_replace("{{{$key}}}", $value, $content);
        }
        
        return $content;
    }

    /**
     * Extract variables from template content
     */
    public function extractVariables(): array
    {
        preg_match_all('/\{(\w+)\}/', $this->content, $matches);
        return array_unique($matches[1] ?? []);
    }
}

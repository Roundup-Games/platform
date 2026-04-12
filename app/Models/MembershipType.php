<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MembershipType extends Model
{
    use HasFactory;
    protected $fillable = [
        'name', 'description', 'price_cents', 'duration_months',
        'status', 'paddle_price_id', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'duration_months' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function formattedPrice(): string
    {
        return '$' . number_format($this->price_cents / 100, 2);
    }
}

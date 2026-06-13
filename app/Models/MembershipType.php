<?php

namespace App\Models;

use Database\Factories\MembershipTypeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property array{gm_plan?: bool}|null $metadata
 */
class MembershipType extends Model
{
    /** @use HasFactory<MembershipTypeFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'name', 'description', 'price_cents', 'duration_months',
        'status', 'type', 'paddle_price_id', 'metadata',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->id)) {
                $model->id = (string) Str::orderedUuid();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'duration_months' => 'integer',
            'metadata' => 'array',
        ];
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query)
    {
        return $query->where('status', 'active');
    }

    public function formattedPrice(): string
    {
        return '$'.number_format($this->price_cents / 100, 2);
    }
}

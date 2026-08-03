<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * An email address that must no longer receive mail.
 *
 * Created by the Resend webhook on hard bounce or spam complaint and consulted
 * by NotificationService at channel-resolution time to drop the mail channel for
 * the recipient. See the create_email_suppressions_table migration for the full
 * design.
 *
 * @property string $id
 * @property string $email
 * @property string $reason
 * @property string $source
 * @property string|null $trigger_message_id
 * @property Carbon $suppressed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class EmailSuppression extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'email',
        'reason',
        'source',
        'trigger_message_id',
        'suppressed_at',
    ];

    protected $casts = [
        'suppressed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->id)) {
                $model->id = (string) Str::orderedUuid();
            }
        });
    }

    /**
     * Whether the given address is currently suppressed.
     *
     * Case-insensitive (email stored lowercased). This is the single source of
     * truth consulted by NotificationService before adding MailChannel.
     */
    public static function isSuppressed(string $email): bool
    {
        return static::query()
            ->where('email', mb_strtolower(trim($email)))
            ->exists();
    }

    public function scopeForEmail(Builder $query, string $email): Builder
    {
        return $query->where('email', mb_strtolower(trim($email)));
    }
}

<?php

namespace App\Models;

use App\Casts\OAuthProviderCast;
use App\Enums\OAuthProvider;
use Database\Factories\LinkedAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property array{nickname: string|null, avatar: string|null, guilds?: list<array{id: string|null, name: string|null, icon: string|null}>}|null $provider_meta
 * @property OAuthProvider|null $provider
 * @property Carbon|null $token_expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class LinkedAccount extends Model
{
    /** @use HasFactory<LinkedAccountFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'user_id', 'provider', 'provider_user_id',
        'token', 'refresh_token', 'token_expires_at', 'provider_meta',
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
            'token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'provider_meta' => 'array',
            'provider' => OAuthProviderCast::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The Discord guilds recorded on this linked account's provider_meta.
     *
     * Populated by OAuthController when the Discord `guilds` OAuth scope
     * (M057/D119) is granted. Returns an empty list for non-Discord accounts
     * or when the best-effort fetch was omitted/failed — callers must treat
     * empty as "unknown", not "definitely none".
     *
     * @return list<array{id: string|null, name: string|null, icon: string|null}>
     */
    public function discordGuilds(): array
    {
        if ($this->provider !== OAuthProvider::Discord) {
            return [];
        }

        $guilds = $this->provider_meta['guilds'] ?? null;

        return is_array($guilds) ? $guilds : [];
    }

    /**
     * Snowflake IDs of the Discord guilds this linked user is a member of.
     *
     * Convenience projection of {@see discordGuilds()} for the discovery
     * service (T07), which intersects these against discord_guilds.guild_id.
     *
     * @return list<string>
     */
    public function discordGuildIds(): array
    {
        $ids = [];

        foreach ($this->discordGuilds() as $guild) {
            if (isset($guild['id'])) {
                $ids[] = (string) $guild['id'];
            }
        }

        return $ids;
    }
}

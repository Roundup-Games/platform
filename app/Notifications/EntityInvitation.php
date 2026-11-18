<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use App\Services\Discord\DiscordPalette;
use App\Services\Discord\DiscordWebhookPayload;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Log;

class EntityInvitation extends BaseNotification
{
    use RoutesGameOrCampaign;

    /**
     * @param  Game|Campaign  $entity  The game or campaign the user is invited to
     * @param  User  $inviter  The user who sent the invitation
     */
    public function __construct(
        public Game|Campaign $entity,
        public User $inviter,
    ) {}

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(User $notifiable): MailMessage
    {
        $locale = $this->resolveLocale($notifiable);
        $type = $this->getEntityType();
        $actionUrl = route("{$type}s.show", ['locale' => $locale, 'id' => $this->entity]);

        $mail = (new MailMessage)
            ->subject(__("notifications.subject_{$type}_invitation", [
                'inviter' => $this->inviter->name,
                $type => $this->entity->name,
            ], $locale))
            ->greeting($this->greetingLine($notifiable))
            ->line(__("notifications.body_{$type}_invitation", [
                'inviter' => $this->inviter->name,
                $type => $this->entity->name,
            ], $locale));

        // A game invitation answers "when? where?" in the body — the
        // recipient should not need the calendar attachment to know if
        // the date even works for them (campaigns have no single date).
        if ($this->entity instanceof Game && $this->entity->date_time !== null) {
            $mail->line('**'.__('common.field_date_time', [], $locale).':** '
                .format_date($this->entity->date_time, 'datetime', $notifiable->timezone ?? community_timezone()));

            $venue = $this->entity->linkedLocation?->name;

            if (($venue === null || $venue === '') && is_array($this->entity->location)) {
                $venue = $this->entity->location['details'] ?? null;
            }

            if (is_string($venue) && $venue !== '') {
                $mail->line('**'.__('common.field_location', [], $locale).':** '.$venue);
            }
        }

        $mail->action(__("notifications.action_view_{$type}", [], $locale), $this->actionUrlWithMarker($notifiable, $actionUrl))
            ->line($this->unsubscribeLine($notifiable, "{$type}_invitation"));

        // iTIP METHOD:REQUEST (D287): the calendar invitation rides on the
        // mail; campaigns resolve to no VEVENT and attach nothing.
        $this->attachIcsRequest($mail, $this->entity, $notifiable, $actionUrl);

        return $this->withPreheader($mail, "notifications.preheader_{$type}_invitation", [
            $type => $this->entity->name,
        ]);
    }

    /**
     * Get the array representation of the notification.
     *
     * Preserves backward-compatible keys: {entity_type}_id / {entity_type}_name
     * for existing database records and frontend code.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        $locale = $this->resolveLocale($notifiable);
        $type = $this->getEntityType();

        return [
            'type' => "{$type}_invitation",
            "{$type}_id" => $this->entity->id,
            "{$type}_name" => $this->entity->name,
            'inviter_id' => $this->inviter->id,
            'inviter_name' => $this->inviter->name,
            'action_url' => route("{$type}s.show", ['locale' => $locale, 'id' => $this->entity]),
        ];
    }

    /**
     * Get the actor for block-list checking by NotificationService.
     */
    public function getActor(): User
    {
        return $this->inviter;
    }

    /**
     * Get the push notification representation.
     */
    public function toPush(User $notifiable): PushPayload
    {
        $type = $this->getEntityType();

        return $this->pushPayload(
            __("notifications.category_{$type}_invitation"),
            __("notifications.push_body_{$type}_invitation", [
                'inviter' => $this->inviter->name,
                $type => $this->entity->name,
            ]),
            $this->actionUrlWithMarker($notifiable, route("{$type}s.show", ['locale' => $this->resolveLocale($notifiable), 'id' => $this->entity])),
            "{$type}-invitation-{$this->entity->id}",
        );
    }

    /**
     * Mirrors toPush() as a Discord embed (D130: Discord mirrors push),
     * enriched with the S04 invitation design language: the embed reads as
     * personally hosted (author block = the inviter, deep-linked to their
     * public profile) and carries the entity's cover image as the thumbnail
     * when one exists. The description voice is unchanged D130 push-mirroring.
     */
    public function toDiscord(User $notifiable): DiscordWebhookPayload
    {
        $type = $this->getEntityType();
        $locale = $this->resolveLocale($notifiable);

        return $this->discordEmbed(
            __("notifications.category_{$type}_invitation"),
            route("{$type}s.show", ['locale' => $locale, 'id' => $this->entity]),
            __("notifications.push_body_{$type}_invitation", [
                'inviter' => $this->inviter->name,
                $type => $this->entity->name,
            ]),
            thumbnailUrl: $this->coverThumbnailUrl(),
            author: $this->hostAuthor($locale),
        );
    }

    /**
     * Cover thumbnail URL for the invitation embed — the host-uploaded
     * cover (the 'cover' collection; Game and Campaign both compose
     * ResolvesCoverImage), served through the 'thumb' conversion (150x150)
     * sized for Discord thumbnails. Entities without a host cover omit the
     * thumbnail key entirely (graceful skip), and resolveCoverUrl()'s
     * on-disk guard means a stale media row degrades to the representative
     * cover rung instead of a broken URL.
     */
    private function coverThumbnailUrl(): ?string
    {
        if (! $this->entity->hasCover()) {
            return null;
        }

        try {
            $url = $this->entity->resolveCoverUrl('thumb');
        } catch (\Throwable $e) {
            Log::warning('discord_invitation.cover_thumbnail_failed', [
                'entity_type' => $this->entity::class,
                'entity_id' => $this->entity->getKey(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($url === null || $url === '') {
            return null;
        }

        return $this->absoluteUrl($url);
    }

    /**
     * Author block rendering the invitation as personally hosted: the
     * inviter's display name deep-linked to their public profile. Omitted
     * entirely when no display name resolves — author.name is the only
     * required Discord author field, and a null name would make Discord
     * reject the whole message. The URL is skipped for the rare slug-less
     * legacy account (the slug route key would render an empty segment).
     *
     * @return array{name: string, url?: string}|null
     */
    private function hostAuthor(string $locale): ?array
    {
        $name = $this->inviter->name ?? '';

        if ($name === '') {
            return null;
        }

        $author = ['name' => $name];

        if ($this->inviter->slug !== null && $this->inviter->slug !== '') {
            $author['url'] = route('profile.public', ['locale' => $locale, 'user' => $this->inviter]);
        }

        return $author;
    }

    /**
     * Force an absolute URL — Discord rejects relative paths in embed
     * thumbnail/author fields. Storage disk URLs already carry APP_URL
     * (config/filesystems.php), so this is the guard for a disk or asset()
     * configured with a relative URL prefix.
     */
    private function absoluteUrl(string $url): string
    {
        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        $appUrl = config('app.url');
        $base = is_string($appUrl) ? rtrim($appUrl, '/') : '';

        return $base.'/'.ltrim($url, '/');
    }

    /**
     * D280 semantic layer: action-needed amber — the recipient must respond to the invitation (accept/decline).
     */
    protected function semanticColor(): int
    {
        return DiscordPalette::ACTION_NEEDED;
    }
}

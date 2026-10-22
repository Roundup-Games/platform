<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Enums\BadgeSource;
use App\Models\User;
use App\Services\Badges\BadgeCopy;
use App\Services\Badges\BadgeRegistry;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The badge award moment (M069 spec §2.2): celebratory but restrained.
 * One notification class covers both moments — a fresh award and a tier
 * upgrade — sent by SendBadgeAwardedNotification under the BadgeAwarded
 * category, so per-category channel preferences apply (in-app + push by
 * default; mail is opt-in, the weekly digest covers the email surface).
 *
 * Constructed from the identifier-only domain event payload
 * (BadgeAwarded / BadgeUpgraded), never hydrated models, so it
 * serializes cleanly onto the queue. Copy is rendered at send time in
 * the recipient's locale: the badge display name resolves through the
 * code registry's name key (D186) and the tier label through the
 * badges lang file, each with a humanized-key fallback for anything the
 * registry/lang pair does not know.
 *
 * The :badge placeholder interpolates "Name (Tier)" for tiered badges
 * and the bare name for tierless ones. Action target is the recipient's
 * profile, where badge display lands.
 */
class BadgeAwardedNotification extends BaseNotification
{
    use HasUnsubscribeLink;

    public function __construct(
        public string $badgeKey,
        public ?string $tier,
        public BadgeSource $source,
        public bool $upgraded = false,
    ) {}

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__($this->subjectKey(), ['badge' => $this->displayBadge()]))
            ->greeting(__('common.field_hey_name', ['name' => $notifiable->name ?? $notifiable->email]))
            ->line(__($this->bodyKey(), ['badge' => $this->displayBadge()]))
            ->action(__('notifications.action_badge_awarded'), $this->actionUrl($notifiable))
            ->line($this->unsubscribeLine($notifiable, 'badge_awarded'));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return [
            'type' => $this->upgraded ? 'badge_upgraded' : 'badge_awarded',
            'badge_key' => $this->badgeKey,
            'badge_name' => $this->displayBadge(),
            'tier' => $this->tier,
            'source' => $this->source->value,
            'action_url' => $this->actionUrl($notifiable),
        ];
    }

    /**
     * Get the push notification representation.
     */
    public function toPush(User $notifiable): PushPayload
    {
        return new PushPayload(
            title: __('notifications.category_badge_awarded'),
            body: __($this->subjectKey(), ['badge' => $this->displayBadge()]),
            icon: '/icons/pwa-192x192.png',
            url: $this->actionUrl($notifiable),
            tag: "badge-{$this->badgeKey}",
        );
    }

    /**
     * Get the actor for block-list checking by NotificationService.
     * Returns null since this is a system notification, not triggered by a user.
     */
    public function getActor(): ?User
    {
        return null;
    }

    private function subjectKey(): string
    {
        return $this->upgraded
            ? 'notifications.subject_badge_upgraded'
            : 'notifications.subject_badge_awarded';
    }

    private function bodyKey(): string
    {
        return $this->upgraded
            ? 'notifications.body_badge_upgraded'
            : 'notifications.body_badge_awarded';
    }

    private function actionUrl(User $notifiable): string
    {
        $locale = $notifiable->preferred_language->value ?? app()->getLocale();

        return route('profile.show-authenticated', ['locale' => $locale, 'user' => $notifiable]);
    }

    /**
     * Display name — registry name key resolved in the recipient's locale,
     * with the tier label appended when the badge is tiered.
     */
    private function displayBadge(): string
    {
        $tierLabel = $this->tierLabel();

        return $tierLabel === null ? $this->badgeName() : $this->badgeName()." ({$tierLabel})";
    }

    private function badgeName(): string
    {
        return BadgeCopy::name(app(BadgeRegistry::class)->find($this->badgeKey)?->nameKey, $this->badgeKey);
    }

    private function tierLabel(): ?string
    {
        return BadgeCopy::tierLabel($this->tier);
    }
}

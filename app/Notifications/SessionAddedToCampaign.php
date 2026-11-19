<?php

namespace App\Notifications;

use App\Models\Campaign;
use App\Models\Game;
use App\Models\User;
use App\Services\Discord\DiscordWebhookPayload;
use Illuminate\Notifications\Messages\MailMessage;

class SessionAddedToCampaign extends BaseNotification
{
    /**
     * @param  Game  $session  The session (Game model) added to the campaign
     * @param  Campaign  $campaign  The parent campaign
     */
    public function __construct(
        public Game $session,
        public Campaign $campaign,
    ) {}

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(User $notifiable): MailMessage
    {
        $locale = $this->resolveLocale($notifiable);
        $actionUrl = route('games.show', ['locale' => $locale, 'id' => $this->session]);

        $mail = (new MailMessage)
            ->subject(__('notifications.subject_session_added_to_campaign', [
                'campaign' => $this->campaign->name,
            ], $locale))
            ->greeting($this->greetingLine($notifiable))
            ->line(__('notifications.body_session_added_to_campaign', [
                'campaign' => $this->campaign->name,
            ], $locale));

        // The date is the news — put it in the body in the recipient's
        // wall clock (R089), not behind the click the preheader teases.
        if ($this->session->date_time !== null) {
            $mail->line('**'.__('common.field_date_time', [], $locale).':** '
                .format_date($this->session->date_time, 'datetime', $notifiable->timezone ?? community_timezone()));
        }

        $mail->action(__('notifications.action_session_added_to_campaign', [], $locale), $this->actionUrlWithMarker($notifiable, $actionUrl))
            ->line($this->unsubscribeLine($notifiable, 'session_added_to_campaign'));

        // iTIP METHOD:REQUEST (D287): each campaign session addresses its
        // own VEVENT (campaign-{id}-session-{gameId}@roundup.games), so the
        // invitation lands on the session's calendar slot, not the
        // campaign's.
        $this->attachIcsRequest($mail, $this->session, $notifiable, $actionUrl);

        return $this->withPreheader($mail, 'notifications.preheader_session_added_to_campaign');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        $locale = $this->resolveLocale($notifiable);

        return [
            'type' => 'session_added_to_campaign',
            'session_id' => $this->session->id,
            'session_name' => $this->session->name,
            'campaign_id' => $this->campaign->id,
            'campaign_name' => $this->campaign->name,
            'action_url' => route('games.show', ['locale' => $locale, 'id' => $this->session]),
        ];
    }

    /**
     * Get the actor for block-list checking by NotificationService.
     * This is a system-generated notification — no specific actor.
     * Returns the campaign owner as the closest actor, or null.
     */
    public function getActor(): ?User
    {
        return $this->campaign->owner;
    }

    /**
     * Burst batch key (D296): the CAMPAIGN's burst, not the individual
     * session's — a host scheduling several sessions at once produces one
     * batch per campaign, which is the burst the members experience.
     */
    public function batchKey(User $notifiable): string
    {
        return 'session_added_to_campaign:'.$this->campaign->id;
    }

    /**
     * Per-event snapshot extras (D296/M082 review): the batch keys on the
     * campaign, so the buffered item must carry the SESSION's identity —
     * the batched mail renders session-named digest lines and re-attaches
     * the per-session iTIP REQUEST (D287) from it at flush time.
     *
     * @return array<string, string>
     */
    public function batchItemContext(User $notifiable): array
    {
        return [
            'session_id' => $this->session->id,
            'session_name' => $this->session->name,
        ];
    }

    /**
     * Discord opted out for the immediate single-event surface (D130: this
     * type has no push surface, so it has no Discord surface either). The
     * batched path is separate: a burst of two or more sessions flushes as
     * BatchedNotification, which carries its own push + Discord voice —
     * deliberately, per D286's per-channel batch rendering.
     */
    public function toDiscord(User $notifiable): ?DiscordWebhookPayload
    {
        return null;
    }
}

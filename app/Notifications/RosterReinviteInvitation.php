<?php

namespace App\Notifications;

use App\Dto\PushPayload;
use App\Models\Game;
use App\Models\User;
use App\Services\Discord\DiscordPalette;
use App\Services\Discord\DiscordWebhookPayload;
use App\Support\ChangedFieldLabels;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Roster re-invitation (D320, D321): the host cancelled a game and set up a
 * replacement linked to it, then chose "Invite the previous roster".
 *
 * The roster is never carried into the replacement — every former
 * participant opts in again. This notification is what makes that a real
 * choice rather than a formality: it names the cancelled game and states
 * WHAT CHANGED between it and the replacement, so the recipient can decide
 * in one glance whether this is still the game they said yes to.
 *
 * It travels the ordinary GameInvitation path (D320: no new category) —
 * the recipient's game_invitation preference row gates mail, push and
 * Discord, invitations never batch, and push keeps the deadline-critical
 * delivery profile.
 */
class RosterReinviteInvitation extends BaseNotification
{
    /**
     * @param  Game  $game  The replacement game (linked to its cancelled predecessor by recreates_game_id)
     * @param  User  $inviter  The host who cancelled the predecessor and set up the replacement
     * @param  string  $replacedGameName  Display name of the cancelled game
     * @param  list<string>  $changedFields  Machine COLUMN names of the Tier-1 fields that differ between the
     *                                       cancelled game and this one, computed by the caller. Labels are
     *                                       resolved per RECIPIENT locale at render time through
     *                                       ChangedFieldLabels — never pass display strings, and an unmapped
     *                                       column can never leak a schema name at a reader.
     */
    public function __construct(
        public Game $game,
        public User $inviter,
        public string $replacedGameName,
        public array $changedFields,
    ) {}

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(User $notifiable): MailMessage
    {
        $locale = $this->resolveLocale($notifiable);
        $actionUrl = route('games.show', ['locale' => $locale, 'id' => $this->game]);

        $replace = [
            'inviter' => $this->inviter->name,
            'game' => $this->game->name,
            'replaced' => $this->replacedGameName,
        ];

        $mail = (new MailMessage)
            ->subject(__('notifications.subject_roster_reinvite', [
                'game' => $this->game->name,
            ], $locale))
            ->greeting($this->greetingLine($notifiable))
            ->line(__($this->bodyKey(), $replace, $locale))
            ->action(__('notifications.action_roster_reinvite_view', [], $locale), $this->actionUrlWithMarker($notifiable, $actionUrl))
            ->line($this->unsubscribeLine($notifiable, 'game_invitation'));

        foreach ($this->changedFieldLines($locale) as $line) {
            $mail->line($line);
        }

        return $this->withPreheader($mail, $this->preheaderKey());
    }

    /**
     * Body copy key: the diff-stating body when fields differ, the
     * same-details body when they do not — the mail never claims changes
     * the caller did not find.
     */
    private function bodyKey(): string
    {
        return $this->changedFields === []
            ? 'notifications.body_roster_reinvite_unchanged'
            : 'notifications.body_roster_reinvite';
    }

    /**
     * Preheader key, following the same diff/no-diff split as the body.
     */
    private function preheaderKey(): string
    {
        return $this->changedFields === []
            ? 'notifications.preheader_roster_reinvite_unchanged'
            : 'notifications.preheader_roster_reinvite';
    }

    /**
     * The "What changed" heading followed by one label per changed field,
     * in the recipient's locale. Empty when nothing differs — a diff-free
     * re-invite carries no block at all rather than a heading over nothing.
     *
     * @return list<string>
     */
    private function changedFieldLines(string $locale): array
    {
        if ($this->changedFields === []) {
            return [];
        }

        $labels = ChangedFieldLabels::forLocale($this->changedFields, $locale);

        if ($labels === []) {
            return [];
        }

        $heading = __('notifications.content_roster_reinvite_changed_heading', [], $locale);

        return ['**'.$heading.'**', ...$labels];
    }

    /**
     * Get the array representation of the notification.
     *
     * changed_fields carries the machine column names (not labels): the stored
     * row stays locale-neutral, and a surface renders them through
     * ChangedFieldLabels in the reader's locale.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        $locale = $this->resolveLocale($notifiable);

        return [
            'type' => 'game_invitation',
            'entity_type' => 'game',
            'entity_id' => $this->game->id,
            'entity_name' => $this->game->name,
            'inviter_id' => $this->inviter->id,
            'inviter_name' => $this->inviter->name,
            'replaces_game_id' => $this->game->recreates_game_id,
            'replaces_game_name' => $this->replacedGameName,
            'changed_fields' => $this->changedFields,
            'action_url' => route('games.show', ['locale' => $locale, 'id' => $this->game]),
        ];
    }

    /**
     * Get the actor for block-list checking by NotificationService: the host,
     * so a recipient who blocked them never receives the re-invite.
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
        return $this->pushPayload(
            __('notifications.category_game_invitation'),
            __('notifications.subject_roster_reinvite', ['game' => $this->game->name]),
            $this->actionUrlWithMarker($notifiable, route('games.show', ['locale' => $this->resolveLocale($notifiable), 'id' => $this->game])),
            "game-roster-reinvite-{$this->game->id}",
        );
    }

    /**
     * Discord DM mirroring the mail copy: the replacement statement plus the
     * changed-field list, so the decision material is in the DM itself.
     */
    public function toDiscord(User $notifiable): DiscordWebhookPayload
    {
        $locale = $this->resolveLocale($notifiable);
        $description = __($this->bodyKey(), [
            'inviter' => $this->inviter->name,
            'game' => $this->game->name,
            'replaced' => $this->replacedGameName,
        ], $locale);

        $lines = $this->changedFieldLines($locale);

        if ($lines !== []) {
            $heading = array_shift($lines);
            $bullets = array_map(fn (string $label): string => '- '.$label, $lines);

            $description .= "\n\n".$heading."\n".implode("\n", $bullets);
        }

        return $this->discordEmbed(
            __('notifications.subject_roster_reinvite', ['game' => $this->game->name], $locale),
            route('games.show', ['locale' => $locale, 'id' => $this->game]),
            $description,
        );
    }

    /**
     * D280 semantic layer: action-needed amber — the recipient must respond to
     * the invitation (accept/decline), like EntityInvitation.
     */
    protected function semanticColor(): int
    {
        return DiscordPalette::ACTION_NEEDED;
    }
}

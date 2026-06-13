<?php

namespace App\Dto;

/**
 * Welcome data for the dashboard newcomer card.
 *
 * Replaces the ad-hoc array{first_name, city, preferred_systems, ...}
 * shape from DashboardNewcomerService::computeWelcomeData().
 */
class DashboardWelcomeData
{
    /**
     * @param  string  $firstName  Displayed first name
     * @param  string|null  $city  User's city, if set
     * @param  array<int, string>  $preferredSystems  Names of preferred game systems
     * @param  int  $matchingGamesCount  Count of games matching their preferences
     * @param  bool  $hasLocation  Whether the user has a location set
     * @param  string  $welcomeMessageKey  i18n key for the welcome message
     */
    public function __construct(
        public readonly string $firstName,
        public readonly ?string $city,
        public readonly array $preferredSystems,
        public readonly int $matchingGamesCount,
        public readonly bool $hasLocation,
        public readonly string $welcomeMessageKey,
    ) {}

    /**
     * @return array{first_name: string, city: string|null, preferred_systems: array<int, string>, matching_games_count: int, has_location: bool, welcome_message_key: string}
     */
    public function toArray(): array
    {
        return [
            'first_name' => $this->firstName,
            'city' => $this->city,
            'preferred_systems' => $this->preferredSystems,
            'matching_games_count' => $this->matchingGamesCount,
            'has_location' => $this->hasLocation,
            'welcome_message_key' => $this->welcomeMessageKey,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $systems = is_array($data['preferred_systems'] ?? null) ? $data['preferred_systems'] : [];

        return new self(
            firstName: is_string($data['first_name'] ?? null) ? $data['first_name'] : '',
            city: is_string($data['city'] ?? null) ? $data['city'] : null,
            preferredSystems: array_filter($systems, 'is_string'),
            matchingGamesCount: is_int($data['matching_games_count'] ?? null) ? $data['matching_games_count'] : 0,
            hasLocation: is_bool($data['has_location'] ?? null) ? $data['has_location'] : false,
            welcomeMessageKey: is_string($data['welcome_message_key'] ?? null) ? $data['welcome_message_key'] : 'dashboard.welcome.new',
        );
    }
}

<?php

namespace App\Console\Commands;

use App\Enums\ActivityType;
use App\Enums\AttendanceStatus;
use App\Enums\ExperienceLevel;
use App\Enums\GameStatus;
use App\Enums\GameType;
use App\Enums\GmProficiency;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Enums\RelationshipType;
use App\Enums\VibeFlag;
use App\Enums\Visibility;
use App\Models\Campaign;
use App\Models\Game;
use App\Models\GameSystem;
use App\Models\Location;
use App\Models\MembershipType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoSeedCommand extends Command
{
    protected $signature = 'demo:seed
        {--force : Skip environment check}
        {--users=10000 : Total users to create}
        {--gms=500 : Number of game organizers}
        {--subscribers=100 : GMs with paid membership}
        {--dry-run : Simulate without writing to the database}';

    protected $description = 'Seed a realistic 10k-user dataset with games, campaigns, reviews, and social graph.';

    public const MARKER = '[TEST]';

    private const PASSWORD = 'Demo1234!';

    // ── Runtime state ──────────────────────────────────────────────

    /** @var array<string, string[]> city => location IDs */
    private array $locationsByCity = [];

    /** @var string[] all user IDs */
    /** @var array<int, string> */
    private array $allUserIds = [];

    /** @var array{city: string, id: string}[] */
    private array $gmInfo = [];

    /** @var string[] player-only user IDs */
    private array $playerIds = [];

    /** @var array<int, array{id:string,name:string,min:int,max:int,dur:int}> weighted board game pool */
    private array $boardGamePool = [];

    /** @var array<int, array{id:string,name:string,min:int,max:int}> weighted TTRPG pool */
    private array $ttrpgPool = [];

    /** @var array<string, string> user ID => GM profile ID */
    private array $gmProfileIds = [];

    /** @var array<string, string[]> user ID => follower user IDs */
    /** @var array<string, array<int, string>> */
    private array $gmFollowers = [];

    /** @var list<array{game_id: string, owner_id: string, participant_ids: array<string>, language: string, is_session_zero: bool}> completed games eligible for reviews */
    private array $completedGames = [];

    /** @var list<array{game_id: string, owner_id: string, reported_id: string, attendance: string}> completed games needing attendance reports */
    private array $attendanceReportQueue = [];

    /** @var list<array{game_id: string, owner_id: string}> scheduled board game sessions needing short links */
    private array $scheduledBoardGames = [];

    /** @var list<array{game_id: string, owner_id: string}> campaign session games eligible for short links */
    private array $campaignSessionGames = [];

    /** @var list<array{game_id: string, owner_id: string, language: string, participant_ids: array<string>}> completed session zero game IDs for survey seeding */
    private array $completedSessionZeros = [];

    /** @var list<array{campaign_id: string, owner_id: string, participant_ids: array<string>, language: string}> campaigns eligible for reviews */
    private array $completedCampaignsForReview = [];

    /** @var array<string, string> user ID => assigned city */
    private array $userCityMap = [];

    /** @var array<string, int> table => row count for dry-run summary */
    private array $dryCounts = [];

    /** @var string[] resolved board game system IDs (flat, for user preferences) */
    private array $resolvedBoardIds = [];

    /** @var string[] resolved TTRPG system IDs (flat, for user preferences) */
    private array $resolvedTtrpgIds = [];

    /** @var array<int, array{game_id: string, game_system_id: string}> in-memory game_game_system pivot pairs harvested by dryInsertMany() (S06: games.game_system_id dropped) */
    private array $pendingGameSystemPivots = [];

    /** @var array<int, array{campaign_id: string, game_system_id: string}> in-memory campaign_game_system pivot pairs harvested by dryInsertMany() (S06: campaigns.game_system_id dropped) */
    private array $pendingCampaignSystemPivots = [];

    /** @var array<string, string> user ID => email (for invitee_email on friend_invite/email_invite) */
    private array $userEmailMap = [];

    /** @var array<string, string> user ID => display name (avoids per-GM User::find queries) */
    private array $userNameMap = [];

    /** @var array<string, Location|null> location ID => cached Location model */
    private array $locationCache = [];

    private int $totalUsers;

    private int $totalGms;

    private int $totalSubscribers;

    /** @var bool dry-run mode: simulate without DB writes */
    private bool $dryRun = false;

    // ── Name pools ─────────────────────────────────────────────────

    private const FIRST_FEMALE = [
        'Anna', 'Marie', 'Sophie', 'Emma', 'Hannah', 'Mia', 'Lena', 'Laura', 'Julia', 'Sarah',
        'Katharina', 'Lisa', 'Eva', 'Nina', 'Clara', 'Lea', 'Lina', 'Maja', 'Helena', 'Franziska',
        'Jennifer', 'Nicole', 'Sandra', 'Petra', 'Monika', 'Katrin', 'Ines', 'Tanja', 'Stefanie',
        'Andrea', 'Melanie', 'Bianca', 'Sylvia', 'Corinna', 'Diana', 'Elena', 'Isabella', 'Lara',
        'Nora', 'Charlotte', 'Emilia', 'Greta', 'Mathilda', 'Ida', 'Lotte', 'Pauline', 'Theresa',
        'Josefine', 'Luisa', 'Amelie', 'Pia', 'Finja', 'Zoe', 'Mila', 'Leona', 'Thea', 'Annika',
    ];

    private const FIRST_MALE = [
        'Alexander', 'Maximilian', 'Paul', 'Leon', 'Felix', 'Lukas', 'David', 'Jonas', 'Julian',
        'Marco', 'Stefan', 'Thomas', 'Christian', 'Daniel', 'Patrick', 'Martin', 'Michael',
        'Andreas', 'Markus', 'Peter', 'Frank', 'Robert', 'Sebastian', 'Florian', 'Tobias',
        'Benjamin', 'Jan', 'Philipp', 'Simon', 'Niklas', 'Moritz', 'Finn', 'Luis', 'Tim',
        'Liam', 'Noah', 'Anton', 'Friedrich', 'Karl', 'Otto', 'Richard', 'Emil', 'Oskar',
        'Bruno', 'Henrik', 'Johannes', 'Konstantin', 'Viktor', 'Gregor', 'Arthur', 'Levin',
    ];

    private const LAST_NAMES = [
        'Müller', 'Schmidt', 'Schneider', 'Fischer', 'Weber', 'Meyer', 'Wagner', 'Becker',
        'Schulz', 'Hoffmann', 'Koch', 'Richter', 'Wolf', 'Klein', 'Schröder', 'Neumann',
        'Schwarz', 'Braun', 'Zimmermann', 'Krüger', 'Huber', 'Maier', 'Lehmann', 'Hartmann',
        'Werner', 'Lange', 'Schäfer', 'Schulte', 'Böhm', 'Vogt', 'Keller', 'Graf', 'Baumann',
        'Schuster', 'Lang', 'Herrmann', 'Fuchs', 'Peters', 'Berger', 'Schubert', 'Roth',
        'Beck', 'Engel', 'Horn', 'Busch', 'Berg', 'Möller', 'Pohl', 'Kraft', 'Stein',
    ];

    /**
     * Board games to look up by slug from the database.
     * Weight (w) controls how often each game is picked relative to others.
     * Player counts and play times come from the DB record when available,
     * with the values below as fallbacks.
     */
    private const BOARD_GAME_SLUGS = [
        // weight 5 — casual staples everyone plays
        'catan' => ['min' => 3, 'max' => 4, 'dur' => 120, 'w' => 5],
        'carcassonne' => ['min' => 2, 'max' => 5, 'dur' => 45,  'w' => 5],
        'ticket-to-ride' => ['min' => 2, 'max' => 5, 'dur' => 60,  'w' => 5],
        'pandemic' => ['min' => 2, 'max' => 4, 'dur' => 45,  'w' => 5],
        'codenames' => ['min' => 2, 'max' => 8, 'dur' => 15,  'w' => 5],
        // weight 4 — popular modern
        'azul' => ['min' => 2, 'max' => 4, 'dur' => 45,  'w' => 4],
        '7-wonders' => ['min' => 2, 'max' => 7, 'dur' => 30,  'w' => 4],
        'dominion' => ['min' => 2, 'max' => 4, 'dur' => 30,  'w' => 4],
        'wingspan' => ['min' => 1, 'max' => 5, 'dur' => 70,  'w' => 4],
        'scythe' => ['min' => 1, 'max' => 5, 'dur' => 115, 'w' => 4],
        'king-of-tokyo' => ['min' => 2, 'max' => 6, 'dur' => 30,  'w' => 4],
        // weight 3 — hobby staples
        'everdell' => ['min' => 1, 'max' => 4, 'dur' => 80,  'w' => 3],
        'spirit-island' => ['min' => 1, 'max' => 4, 'dur' => 120, 'w' => 3],
        'agricola' => ['min' => 1, 'max' => 5, 'dur' => 150, 'w' => 3],
        'stone-age' => ['min' => 2, 'max' => 4, 'dur' => 90,  'w' => 3],
        'power-grid' => ['min' => 2, 'max' => 6, 'dur' => 120, 'w' => 3],
        'betrayal-at-house-on-the-hill' => ['min' => 3, 'max' => 6, 'dur' => 60, 'w' => 3],
        'dune-imperium' => ['min' => 1, 'max' => 4, 'dur' => 120, 'w' => 3],
        'nemesis' => ['min' => 1, 'max' => 5, 'dur' => 180, 'w' => 3],
        // weight 2 — heavy / niche
        'brass-birmingham' => ['min' => 2, 'max' => 4, 'dur' => 120, 'w' => 2],
        'gloomhaven' => ['min' => 1, 'max' => 4, 'dur' => 120, 'w' => 2],
        'the-castles-of-burgundy' => ['min' => 2, 'max' => 4, 'dur' => 90,  'w' => 2],
        'gloomhaven-jaws-of-the-lion' => ['min' => 1, 'max' => 4, 'dur' => 120, 'w' => 2],
        'marvel-champions-the-card-game' => ['min' => 1, 'max' => 4, 'dur' => 90,  'w' => 2],
        'terra-mystica' => ['min' => 2, 'max' => 5, 'dur' => 150, 'w' => 2],
        'crokinole' => ['min' => 2, 'max' => 4, 'dur' => 30,  'w' => 2],
        'caverna-the-cave-farmers' => ['min' => 1, 'max' => 7, 'dur' => 210, 'w' => 2],
        // extra slug-only entries
        'eldritch-horror' => ['min' => 1, 'max' => 4, 'dur' => 120, 'w' => 3],
        'small-world' => ['min' => 2, 'max' => 4, 'dur' => 90,  'w' => 3],
        'gaia-project' => ['min' => 1, 'max' => 4, 'dur' => 150, 'w' => 2],
    ];

    /**
     * TTRPGs to look up by slug from the database.
     * Weight (w) controls how often each system is picked.
     */
    private const TTRPG_SLUGS = [
        // weight 6 — the biggest
        'dungeons-and-dragons-5e' => ['min' => 3, 'max' => 7, 'w' => 6],
        // weight 5 — the big three minus D&D
        'pathfinder-2e' => ['min' => 3, 'max' => 6, 'w' => 5],
        'call-of-cthulhu' => ['min' => 3, 'max' => 6, 'w' => 5],
        'vampire-the-masquerade-5e' => ['min' => 3, 'max' => 5, 'w' => 5],
        // weight 4 — popular modern
        'blades-in-the-dark' => ['min' => 3, 'max' => 5, 'w' => 4],
        'shadowdark-rpg' => ['min' => 3, 'max' => 6, 'w' => 4],
        'daggerheart' => ['min' => 3, 'max' => 6, 'w' => 4],
        'savage-worlds' => ['min' => 3, 'max' => 5, 'w' => 4],
        // weight 3 — niche but active
        'delta-green' => ['min' => 3, 'max' => 5, 'w' => 3],
        'monster-of-the-week' => ['min' => 3, 'max' => 5, 'w' => 3],
        'fabula-ultima' => ['min' => 3, 'max' => 5, 'w' => 3],
        'cyberpunk-red' => ['min' => 3, 'max' => 5, 'w' => 3],
        // weight 2 — smaller communities
        'alien-the-roleplaying-game' => ['min' => 1, 'max' => 5, 'w' => 2],
        'warhammer-fantasy-roleplay' => ['min' => 3, 'max' => 5, 'w' => 2],
        'mork-borg' => ['min' => 3, 'max' => 6, 'w' => 2],
        'old-school-essentials' => ['min' => 3, 'max' => 6, 'w' => 2],
        'forbidden-lands' => ['min' => 2, 'max' => 6, 'w' => 2],
        'dragonbane' => ['min' => 1, 'max' => 6, 'w' => 2],
        'fate' => ['min' => 2, 'max' => 5, 'w' => 2],
        'mutant-year-zero' => ['min' => 2, 'max' => 6, 'w' => 2],
        'pirate-borg' => ['min' => 2, 'max' => 6, 'w' => 2],
        'tales-of-the-valiant' => ['min' => 3, 'max' => 5, 'w' => 2],
        // extra slug-only entries
        'starfinder' => ['min' => 3, 'max' => 6, 'w' => 3],
        'star-wars-5e' => ['min' => 3, 'max' => 6, 'w' => 2],
        'the-one-ring-2e' => ['min' => 3, 'max' => 5, 'w' => 2],
    ];

    /** @var array<string, string[]> Campaign names keyed by language */
    private const CAMPAIGN_NAMES = [
        'en' => [
            'Storm King\'s Thunder', 'Curse of Strahd', 'Lost Mine of Phandelver',
            'Waterdeep: Dragon Heist', 'Hoard of the Dragon Queen',
            'Shadows of Berlin', 'The Haunting of Hamburg', 'Munich by Night',
            'Operation: FALLGATE', 'The Darkening Tower', 'Age of Umbra',
            'Rebellion in the Ruins', 'Crimson Veil', 'The Last Protocol',
            'Sundered Realms', 'Ironwood Legacy', 'Echoes of the Void',
            'Neon Underground', 'Wild Frontier', 'The Burning Wheel',
        ],
        'de' => [
            'Sturmriesen Zorn', 'Strahds Fluch', 'Verlorene Mine von Phandelver',
            'Tiefwasser: Drachenraub', 'Der Hort der Drachenkönigin',
            'Schatten über Berlin', 'Das Spukhaus von Hamburg', 'München bei Nacht',
            'Operation: FALLGATE', 'Der verdunkelnde Turm', 'Zeitalter des Schattens',
            'Rebellion in den Ruinen', 'Purpurvorhang', 'Das letzte Protokoll',
            'Gespaltene Reiche', 'Eisenholz-Vermächtnis', 'Echos der Leere',
            'Neon-Untergrund', 'Wilde Grenze', 'Das brennende Rad',
        ],
    ];

    /** @var array<string, string[]> Recaps keyed by language */
    private const RECAPS = [
        'en' => [
            'Great session! Everyone was engaged and we finished the game. Looking forward to the next one.',
            'Fantastic evening — the highlight was the final round where everything came together.',
            'Close game, the winner was decided in the last turn. Well played by everyone.',
            'Wonderful group, very cooperative spirit. Beat the scenario on expert difficulty.',
            'Good session despite a slow start. Picked up after round two and everyone had a blast.',
            'New players picked up the rules quickly. Welcome to the group!',
            'Long but rewarding session. Thanks for staying focused everyone!',
            'Lighthearted fun evening with lots of laughs. Exactly what game nights should be.',
            'Tense final round — came down to one die roll. What a finish!',
            'Solid group, good sportsmanship. Already planning the rematch.',
        ],
        'de' => [
            'Tolle Runde! Alle waren voll dabei. Hat riesigen Spaß gemacht, freue mich auf das nächste Mal.',
            'Super Abend — das Highlight war die letzte Runde, da kam alles zusammen.',
            'Knappes Spiel, der Sieger wurde in der letzten Runde entschieden. Gut gespielt!',
            'Wunderbare Gruppe, sehr kooperativ. Das Szenario auf Experte geschafft!',
            'Guter Abend trotz holprigem Start. Nach Runde zwei lief es richtig gut.',
            'Neue Spieler haben die Regeln schnell kapiert. Willkommen in der Gruppe!',
            'Lange aber lohnende Session. Danke für die Konzentration!',
            'Lockerer Abend mit vielen Lachern. Genau so muss ein Spieleabend sein.',
            'Spannende letzte Runde — es kam auf einen Würfelwurf an. Wahnsinn!',
            'Solide Truppe, fair gespielt. Revanche ist schon geplant.',
        ],
    ];

    /** @var array<string, string[]> Review bodies keyed by language */
    private const REVIEW_BODIES = [
        'en' => [
            'Great GM, kept the game moving and made sure everyone was included.',
            'Really enjoyed the session. Good pacing and clear rule explanations.',
            'Excellent atmosphere and storytelling. Would definitely play again!',
            'Had a lot of fun. Patient with new players and very engaging.',
            'Well-prepared session with nice touches. Recommended!',
            'Good balance of roleplay and mechanics. Thoroughly enjoyed it.',
            'Creative encounters and great NPC voices. A memorable session.',
            'Welcoming and inclusive. Felt comfortable even as a first-time player.',
            'Solid session, good energy. A few pacing issues but overall great.',
            'Knows the rules inside out but keeps things moving. Great facilitator.',
        ],
        'de' => [
            'Super Spielleiter, hat das Tempo gut gehalten und alle eingebunden.',
            'Runde hat großen Spaß gemacht. Gutes Tempo und klare Regelerklärungen.',
            'Tolle Atmosphäre und Erzählung. Würde jederzeit wieder mitspielen!',
            'Hatte viel Spaß. Geduldig mit neuen Spielern und sehr engagiert.',
            'Gut vorbereitete Session mit schönen Details. Sehr empfehlenswert!',
            'Gute Balance zwischen Rollenspiel und Mechaniken. Hat mir sehr gefallen.',
            'Kreative Begegnungen und tolle NPC-Stimmen. Ein unvergesslicher Abend.',
            'Willkommen und inklusiv. Hab mich als Neuling wohlgefühlt.',
            'Solide Runde, gute Energie. Ein paar Tempoprobleme, aber insgesamt super.',
            'Kennt die Regeln in- und auswendig, aber treibt die Geschichte voran. Toll.',
        ],
    ];

    /** @var array<string, string[]> Session-zero review bodies (setup/expectations, not gameplay) */
    private const SESSION_ZERO_REVIEW_BODIES = [
        'en' => [
            'Great session zero. Everyone felt comfortable sharing boundaries.',
            'Well-organized character creation and expectations discussion.',
            'Clear safety tools explained. Good start to the campaign!',
            'Helpful introduction to the campaign theme and tone.',
            'Welcoming atmosphere for discussing character concepts and backstories.',
            'Thorough session zero — covered lines, veils, and expectations thoroughly.',
            'Good communication of house rules and campaign expectations.',
            'Smooth session zero. Got everyone on the same page quickly.',
        ],
        'de' => [
            'Tolle Session Zero. Alle konnten ihre Grenzen teilen.',
            'Gut organisierte Charaktererstellung und Erwartungsbesprechung.',
            'Sicherheitstools wurden klar erklärt. Guter Kampagnenstart!',
            'Hilfreiche Einführung in Kampagnenthema und Tonfall.',
            'Willkommene Atmosphäre für Charakterkonzepte und Hintergründe.',
            'Gründliche Session Zero — Lines, Veils und Erwartungen gut abgedeckt.',
            'Gute Kommunikation von Hausregeln und Kampagnen-Erwartungen.',
            'Runde Session Zero. Alle waren schnell auf demselben Stand.',
        ],
    ];

    /** @var array<string, string[]> Session-name suffixes keyed by language */
    private const SESSION_SUFFIXES = [
        'en' => ['Evening', 'Night', 'Session', 'Game Night', 'Meetup'],
        'de' => ['Abend', 'Runde', 'Session', 'Spieleabend', 'Treffen'],
    ];

    /** @var array<string, string[]> Board game session descriptions keyed by language */
    private const BOARD_DESCRIPTIONS = [
        'en' => [
            'Organized board game session. All skill levels welcome.',
            'Board game night featuring a great group. Come join us!',
            'Casual board game evening. Beginners welcome.',
            'Competitive board game session for experienced players.',
            'Friendly board game meetup. Good vibes guaranteed.',
        ],
        'de' => [
            'Organisierter Brettspielabend. Alle Erfahrungsstufen willkommen.',
            'Brettspielabend mit toller Runde. Komm dazu!',
            'Lockerer Brettspielabend. Auch für Anfänger geeignet.',
            'Kompetitives Brettspiel für erfahrene Spieler.',
            'Gemütliches Brettspiel-Treffen. Gute Stimmung garantiert.',
        ],
    ];

    /** @var array<string, string> Session zero descriptions keyed by language */
    private const SESSION_ZERO_DESC = [
        'en' => 'Session zero to discuss expectations, create characters, and set the tone. New players welcome.',
        'de' => 'Session Zero um Erwartungen zu besprechen, Charaktere zu erstellen und den Ton zu setzen. Auch für Neueinsteiger.',
    ];

    /** @var array<string, string[]> Campaign descriptions keyed by language */
    private const CAMPAIGN_DESCRIPTIONS = [
        'en' => [
            'Ongoing campaign with a dedicated group. Looking for committed players.',
            'Long-form campaign with rich story and character development.',
            'Weekly campaign session. Punctuality and engagement appreciated.',
            'Campaign running for several months. Consistent attendance expected.',
        ],
        'de' => [
            'Laufende Kampagne mit einer festen Gruppe. Suche engagierte Spieler.',
            'Langzeit-Kampagne mit reicher Geschichte und Charakterentwicklung.',
            'Wöchentliche Kampagnen-Session. Pünktlichkeit und Engagement erwünscht.',
            'Kampagne läuft seit mehreren Monaten. Regelmäßige Teilnahme erwartet.',
        ],
    ];

    /** Board-game-appropriate vibe flags (subset of VibeFlag enum values) */
    private const BOARD_VIBE_KEYS = [
        'competitive', 'cooperative', 'tactical', 'lighthearted', 'rules-heavy',
        'rules-light', 'new-player-friendly', 'drop-in-friendly', 'sandbox',
    ];

    /** TTRPG-appropriate vibe flags (subset of VibeFlag enum values) */
    private const TTRPG_VIBE_KEYS = [
        'story-rich', 'roleplay-heavy', 'atmospheric', 'serious', 'lighthearted',
        'horror', 'character-driven', 'exploration', 'tactical', 'combat-focused',
        'roleplay-light', 'new-player-friendly', 'cooperative', 'rule-of-cool',
        'dungeon-crawl', 'theater-of-the-mind', 'rules-as-written',
    ];

    private const GM_BIOS = [
        'Veteran TTRPG game master with 10+ years of running campaigns. Love narrative-driven games with deep character work.',
        'Board game enthusiast and organizer hosting weekly public game nights — all skill levels welcome.',
        'Specializing in horror and investigation games. If it\'s dark, I run it.',
        'Story-first GM who loves character drama and player agency.',
        'Tactical combat enthusiast running challenging encounters requiring teamwork.',
        'Sandbox GM — players drive the story, I build the world around their choices.',
        'New-player-focused GM. Love introducing people to the hobby.',
        'Long-term campaign GM with focus on immersive worldbuilding and continuity.',
        'Narrative TTRPG specialist. Investigation and mystery are my favorite genres.',
        'Game organizer running everything from light party games to heavy strategy.',
    ];

    // =========================================================================
    // MAIN
    // =========================================================================

    public function handle(): int
    {
        $this->totalUsers = (int) $this->option('users');
        $this->totalGms = (int) $this->option('gms');
        $this->totalSubscribers = (int) $this->option('subscribers');
        $this->dryRun = (bool) $this->option('dry-run');

        if (! $this->validate()) {
            return self::FAILURE;
        }

        if ($this->dryRun) {
            $this->warn('🔍 DRY RUN — no data will be written to the database.');
            $this->newLine();
        }

        // Suppress all mail — @example.org would bounce
        $this->suppressMail();

        $this->resolveGameSystems();
        $this->createLocations();
        $this->createUsers();
        $this->setupGMs();
        $this->buildSocialGraph();
        $this->seedUserPreferences();
        $this->seedGmSocialLinks();
        $this->createCompletedBoardGameSessions();
        $this->createScheduledBoardGameSessions();
        $this->createAttendanceReports();
        $this->createReviews();
        $this->createCampaigns();
        $this->createCampaignReviews();
        $this->createSessionDebriefings();
        $this->createSessionZeroSurveys();
        $this->createLinkedAccounts();
        $this->seedActivityLogs();
        $this->createShortLinks();
        $this->assignShortLinkAttribution();
        $this->seedNotifications();

        // populate the canonical game_game_system + campaign_game_system
        // pivots from the just-written anchor columns. Idempotent (ON CONFLICT
        // DO NOTHING) so it stays safe if re-run on a partially-seeded DB.
        $this->syncGameSystemPivots();

        if (! $this->dryRun) {
            $this->updateGmAggregates();
        }

        $this->printSummary();

        return self::SUCCESS;
    }

    // =========================================================================
    // SETUP
    // =========================================================================

    private function suppressMail(): void
    {
        // Force the array mailer so nothing is actually sent.
        // Also clear any resolved mailer instance so the config sticks.
        app()->forgetInstance('mail.manager');
        app()->forgetInstance('mailer');
        config(['mail.default' => 'array']);
    }

    private function validate(): bool
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->warn('Running in production. Use --force to proceed.');
            if (! $this->confirm('Continue?')) {
                return false;
            }
        }

        if (! $this->dryRun) {
            $existing = User::where('email', 'like', '%@example.org')
                ->where('bio', 'like', '%'.self::MARKER.'%')
                ->exists();

            if ($existing) {
                $this->error('Existing test data found. Run `php artisan demo:teardown` first.');

                return false;
            }
        }

        if (! $this->dryRun) {
            try {
                $hasGmPlan = MembershipType::whereJsonContains('metadata->gm_plan', true)->exists();
                if (! $hasGmPlan) {
                    $this->warn('No GM membership type found. Run MembershipTypeSeeder first.');
                }
            } catch (\Throwable $e) {
                $this->warn('Could not query membership_types: '.$e->getMessage());
            }
        }

        return true;
    }

    // =========================================================================
    // GAME SYSTEMS
    // =========================================================================

    private function resolveGameSystems(): void
    {
        // Resolve ALL game systems by slug — no hardcoded UUIDs.
        // DB player counts/play times are used when available, fallback to constant values.
        $boardSlugs = self::BOARD_GAME_SLUGS;
        $ttrpgSlugs = self::TTRPG_SLUGS;

        // Batch-query all slugs in one round-trip per type
        $boardRecords = GameSystem::where('type', 'boardgame')
            ->whereIn('slug', array_keys($boardSlugs))
            ->get()
            ->keyBy('slug');

        $ttrpgRecords = GameSystem::where('type', 'ttrpg')
            ->whereIn('slug', array_keys($ttrpgSlugs))
            ->get()
            ->keyBy('slug');

        $resolvedBoard = 0;
        $resolvedTtrpg = 0;
        $missingBoard = [];
        $missingTtrpg = [];

        // Build weighted board game pool from slug-resolved records
        $this->boardGamePool = [];
        foreach ($boardSlugs as $slug => $meta) {
            $gs = $boardRecords->get($slug);
            if (! $gs) {
                $missingBoard[] = $slug;

                continue;
            }

            $name = $gs->getTranslation('name', 'en') ?: $slug;
            $entry = [
                'id' => $gs->id,
                'name' => is_string($name) ? $name : $slug,
                'min' => $gs->min_players ?? $meta['min'],
                'max' => $gs->max_players ?? $meta['max'],
                'dur' => $gs->average_play_time ?? $meta['dur'],
                'w' => $meta['w'],
            ];

            for ($i = 0; $i < $entry['w']; $i++) {
                $this->boardGamePool[] = $entry;
            }
            $this->resolvedBoardIds[] = $gs->id;
            $resolvedBoard++;
        }

        // Build weighted TTRPG pool from slug-resolved records
        $this->ttrpgPool = [];
        foreach ($ttrpgSlugs as $slug => $meta) {
            $gs = $ttrpgRecords->get($slug);
            if (! $gs) {
                $missingTtrpg[] = $slug;

                continue;
            }

            $name = $gs->getTranslation('name', 'en') ?: $slug;
            $entry = [
                'id' => $gs->id,
                'name' => is_string($name) ? $name : $slug,
                'min' => $gs->min_players ?? $meta['min'],
                'max' => $gs->max_players ?? $meta['max'],
                'w' => $meta['w'],
            ];

            for ($i = 0; $i < $entry['w']; $i++) {
                $this->ttrpgPool[] = $entry;
            }
            $this->resolvedTtrpgIds[] = $gs->id;
            $resolvedTtrpg++;
        }

        $this->info('Game systems resolved: '
            .$resolvedBoard.' board, '
            .$resolvedTtrpg.' TTRPG (pools: '
            .count($this->boardGamePool).' / '.count($this->ttrpgPool).' weighted)');

        if (! empty($missingBoard)) {
            $this->warn('Board game slugs not found in DB (skipped): '.implode(', ', $missingBoard));
        }
        if (! empty($missingTtrpg)) {
            $this->warn('TTRPG slugs not found in DB (skipped): '.implode(', ', $missingTtrpg));
        }

        if (empty($this->boardGamePool) && empty($this->ttrpgPool)) {
            $this->error('No game systems resolved at all. Check game_systems table and slug values.');
        }
    }

    /**
     * Insert multiple rows (or track them in dry-run mode).
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function dryInsertMany(string $table, array $rows): void
    {
        // S06: the legacy games.game_system_id / campaigns.game_system_id anchor
        // columns were dropped. Raw batch rows still carry those keys, so
        // intercept games/campaigns here: harvest each row's system id into an
        // in-memory pivot accumulator (flushed by syncGameSystemPivots()) and
        // strip the dead key so the INSERT doesn't reference a column that no
        // longer exists.
        if ($table === 'games' || $table === 'campaigns') {
            $cleaned = [];
            foreach ($rows as $row) {
                $systemId = $row['game_system_id'] ?? null;
                $entityId = $row['id'] ?? null;
                if (is_string($systemId) && is_string($entityId)) {
                    if ($table === 'games') {
                        $this->pendingGameSystemPivots[] = [
                            'game_id' => $entityId,
                            'game_system_id' => $systemId,
                        ];
                    } else {
                        $this->pendingCampaignSystemPivots[] = [
                            'campaign_id' => $entityId,
                            'game_system_id' => $systemId,
                        ];
                    }
                }
                unset($row['game_system_id']);
                $cleaned[] = $row;
            }
            $rows = $cleaned;
        }

        if ($this->dryRun) {
            $this->dryCounts[$table] = ($this->dryCounts[$table] ?? 0) + count($rows);

            return;
        }
        DB::table($table)->insert($rows);
    }

    /**
     * Flush the in-memory game_game_system / campaign_game_system pivot pairs
     * harvested by dryInsertMany() while it stripped the dropped anchor column
     * from games/campaigns batch rows.
     *
     * Replaces the former read-back from games.game_system_id / game_systems /
     * campaigns.game_system_id (those columns were retired in S06/T06).
     * insertOrIgnore keeps this idempotent against the migration's own backfill
     * and any prior seed run. Skipped in dry-run (no rows were written).
     */
    private function syncGameSystemPivots(): void
    {
        if ($this->dryRun) {
            return;
        }

        foreach (array_chunk($this->pendingGameSystemPivots, 500) as $chunk) {
            DB::table('game_game_system')->insertOrIgnore($chunk);
        }

        foreach (array_chunk($this->pendingCampaignSystemPivots, 500) as $chunk) {
            DB::table('campaign_game_system')->insertOrIgnore($chunk);
        }
    }

    // =========================================================================
    // LOCATIONS
    /**
     * Generate a row ID for tables with bigint auto-increment PKs.
     * Queries the current max on first call per table, then increments.
     *
     * @var array<string, int>
     */
    private array $rowIdCounters = [];

    private function nextRowId(string $table = 'linked_accounts'): int
    {
        if (! isset($this->rowIdCounters[$table])) {
            $max = DB::table($table)->max('id') ?? 0;
            $this->rowIdCounters[$table] = is_numeric($max) ? (int) $max : 0;
        }

        return ++$this->rowIdCounters[$table];
    }

    // =========================================================================

    private function createLocations(): void
    {
        $this->newLine();
        $this->info('Creating locations...');
        $allVenues = $this->allVenueData();
        $total = array_sum(array_map('count', $allVenues));
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        foreach ($allVenues as $city => $venues) {
            $this->locationsByCity[$city] = [];
            foreach ($venues as $v) {
                if ($this->dryRun) {
                    $locId = (string) Str::orderedUuid();
                    $this->dryCounts['locations'] = ($this->dryCounts['locations'] ?? 0) + 1;
                } else {
                    $loc = Location::create([
                        'id' => $locId = (string) Str::orderedUuid(),
                        'name' => (is_string($v['name'] ?? null) ? $v['name'] : '').' '.self::MARKER,
                        'description' => (is_string($ds = $v['desc'] ?? '') ? $ds : '').' '.self::MARKER,
                        'address' => ($v['address'] ?? null),
                        'city' => $city,
                        'postal_code' => $v['plz'] ?? null,
                        'country' => 'DE',
                        'latitude' => $v['lat'],
                        'longitude' => $v['lng'],
                        'source' => 'demo-seed',
                    ]);
                    $locId = $loc->id;
                }
                $this->locationsByCity[$city][] = $locId;
                $bar->advance();
            }
        }

        $bar->finish();
        $this->newLine();
        $this->info("Created {$total} locations across ".count($this->locationsByCity).' cities.');
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function allVenueData(): array
    {
        return [
            'Berlin' => array_merge($this->berlinVenues(), $this->genericVenues('Berlin', 52.5200, 13.4050, 42, '10')),
            'Hamburg' => array_merge($this->hamburgVenues(), $this->genericVenues('Hamburg', 53.5511, 9.9937, 20, '20')),
            'München' => array_merge($this->munchenVenues(), $this->genericVenues('München', 48.1351, 11.5820, 20, '80')),
        ];
    }

    /**
     * @return array{lat: float, lng: float}
     */
    private function coord(float $baseLat, float $baseLng, float $dLat, float $dLng): array
    {
        return ['lat' => round($baseLat + $dLat, 4), 'lng' => round($baseLng + $dLng, 4)];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function berlinVenues(): array
    {
        $B = 52.5200;
        $L = 13.4050;

        return [
            array_merge(['name' => 'Spielwiese', 'address' => 'Bergmannstr. 24', 'plz' => '10961', 'desc' => 'Brettspielcafé Kreuzberg'], $this->coord($B, $L, -0.028, -0.016)),
            array_merge(['name' => 'Café Oblomow', 'address' => 'Knaackstr. 22', 'plz' => '10405', 'desc' => 'Spielecafé Prenzlauer Berg'], $this->coord($B, $L, 0.018, 0.018)),
            array_merge(['name' => 'Das Gift', 'address' => 'Herrfurthstr. 13', 'plz' => '12049', 'desc' => 'Bar & Spieleabend Neukölln'], $this->coord($B, $L, -0.040, 0.029)),
            array_merge(['name' => 'Next Level', 'address' => 'Oranienstr. 58', 'plz' => '10969', 'desc' => 'Gaming Bar Kreuzberg'], $this->coord($B, $L, -0.021, 0.019)),
            array_merge(['name' => 'Freiraum', 'address' => 'Boxhagener Str. 18', 'plz' => '10245', 'desc' => 'Community Space Friedrichshain'], $this->coord($B, $L, -0.005, 0.050)),
            array_merge(['name' => 'Zoulou Café', 'address' => 'Gipsstr. 5', 'plz' => '10119', 'desc' => 'Café & Spiele Mitte'], $this->coord($B, $L, 0.002, -0.003)),
            array_merge(['name' => 'B-Flat', 'address' => 'Rosenthaler Str. 13', 'plz' => '10119', 'desc' => 'Jazz Bar & Event Space'], $this->coord($B, $L, 0.005, -0.004)),
            array_merge(['name' => 'Tempelhofer Feld', 'address' => 'Columbia-Damm', 'plz' => '12101', 'desc' => 'Outdoor Gaming'], $this->coord($B, $L, -0.052, -0.018)),
            array_merge(['name' => 'Volkspark', 'address' => 'Am Friedrichshain', 'plz' => '10249', 'desc' => 'Outdoor Gaming'], $this->coord($B, $L, 0.008, 0.052)),
            array_merge(['name' => 'Spielkreis', 'address' => 'Kantstr. 28', 'plz' => '10623', 'desc' => 'Brettspielcafé Charlottenburg'], $this->coord($B, $L, -0.004, -0.100)),
            array_merge(['name' => 'Kneipe zum Würfel', 'address' => 'Sonnenallee 45', 'plz' => '12047', 'desc' => 'Kneipe Neukölln'], $this->coord($B, $L, -0.041, 0.030)),
            array_merge(['name' => 'Tabletop Treff', 'address' => 'Gerichtstr. 23', 'plz' => '13347', 'desc' => 'Spieleclub Wedding'], $this->coord($B, $L, 0.028, -0.037)),
            array_merge(['name' => 'Arminius Markthalle', 'address' => 'Arminiusstr. 2', 'plz' => '10559', 'desc' => 'Brettspielbar Moabit'], $this->coord($B, $L, 0.008, -0.062)),
            array_merge(['name' => 'Spieleabend Schöneberg', 'address' => 'Goltzstr. 34', 'plz' => '10781', 'desc' => 'Community Space'], $this->coord($B, $L, -0.024, -0.054)),
            array_merge(['name' => 'Wühlischspiele', 'address' => 'Wühlischstr. 12', 'plz' => '10245', 'desc' => 'Spiele Friedrichshain'], $this->coord($B, $L, -0.002, 0.048)),
            array_merge(['name' => 'Pankow Spielzimmer', 'address' => 'Breite Str. 40', 'plz' => '13187', 'desc' => 'Community Pankow'], $this->coord($B, $L, 0.060, 0.012)),
            array_merge(['name' => 'Treptower Spieletreff', 'address' => 'Köpenicker Str. 18', 'plz' => '12435', 'desc' => 'Spieletreff Treptow'], $this->coord($B, $L, -0.035, 0.062)),
            array_merge(['name' => 'Spandau Brettspielrunde', 'address' => 'Claire-Waldoff-Str. 5', 'plz' => '13589', 'desc' => 'Spiele Spandau'], $this->coord($B, $L, 0.015, -0.208)),
            array_merge(['name' => 'Steglitz Spielewelt', 'address' => 'Schloßstr. 12', 'plz' => '12163', 'desc' => 'Spiele Steglitz'], $this->coord($B, $L, -0.063, -0.079)),
            array_merge(['name' => 'Mitte Board Game Café', 'address' => 'Auguststr. 20', 'plz' => '10117', 'desc' => 'Board Game Café'], $this->coord($B, $L, 0.006, -0.010)),
            array_merge(['name' => 'Neukölln Game Night', 'address' => 'Hermannstr. 42', 'plz' => '12049', 'desc' => 'Game Night'], $this->coord($B, $L, -0.041, 0.024)),
            array_merge(['name' => 'Friedrichshain Tabletop Club', 'address' => 'Simon-Dach-Str. 10', 'plz' => '10245', 'desc' => 'Tabletop Club'], $this->coord($B, $L, -0.003, 0.048)),
            array_merge(['name' => 'Moabit Brettspiel Club', 'address' => 'Stromstr. 30', 'plz' => '10551', 'desc' => 'Brettspiel Club'], $this->coord($B, $L, 0.010, -0.055)),
            array_merge(['name' => 'Lichtenberg Boardgame Meet', 'address' => 'Frankfurter Allee 35', 'plz' => '10247', 'desc' => 'Board Game Meet'], $this->coord($B, $L, -0.002, 0.091)),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function hamburgVenues(): array
    {
        $B = 53.5511;
        $L = 9.9937;

        return [
            array_merge(['name' => 'Spielcafé St. Pauli', 'address' => 'Reeperbahn 20', 'plz' => '20359', 'desc' => 'Spielcafé'], $this->coord($B, $L, -0.002, -0.033)),
            array_merge(['name' => 'Brettspielbar Sternschanze', 'address' => 'Sternstr. 12', 'plz' => '20357', 'desc' => 'Brettspielbar'], $this->coord($B, $L, 0.009, -0.021)),
            array_merge(['name' => 'Tabletop Treff Eimsbüttel', 'address' => 'Osterstr. 30', 'plz' => '20259', 'desc' => 'Tabletop Treff'], $this->coord($B, $L, 0.016, -0.041)),
            array_merge(['name' => 'Spieleabend Winterhude', 'address' => 'Mühlenkamp 15', 'plz' => '22303', 'desc' => 'Spieleabend'], $this->coord($B, $L, 0.032, 0.022)),
            array_merge(['name' => 'Board Game Night Altona', 'address' => 'Ottenser Hauptstr. 10', 'plz' => '22769', 'desc' => 'Board Game Night'], $this->coord($B, $L, -0.003, -0.059)),
            array_merge(['name' => 'Hamburg Spieletreff', 'address' => 'Eppendorfer Baum 8', 'plz' => '20249', 'desc' => 'Spieletreff'], $this->coord($B, $L, 0.029, -0.013)),
            array_merge(['name' => 'Kneipe zum Doppelkopf', 'address' => 'Süderstr. 25', 'plz' => '20097', 'desc' => 'Kneipe'], $this->coord($B, $L, -0.015, 0.010)),
            array_merge(['name' => 'Wandsbek Spielewelt', 'address' => 'Wandsbeker Allee 18', 'plz' => '22041', 'desc' => 'Spielewelt'], $this->coord($B, $L, 0.038, 0.057)),
            array_merge(['name' => 'Harburg Brettspielabend', 'address' => 'Lüneburger Str. 5', 'plz' => '21073', 'desc' => 'Brettspielabend'], $this->coord($B, $L, -0.098, 0.032)),
            array_merge(['name' => 'Barmbek Game Table', 'address' => 'Fuhlsbüttler Str. 50', 'plz' => '22305', 'desc' => 'Game Table'], $this->coord($B, $L, 0.040, 0.022)),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function munchenVenues(): array
    {
        $B = 48.1351;
        $L = 11.5820;

        return [
            array_merge(['name' => 'Spielcafé Schwabing', 'address' => 'Leopoldstr. 30', 'plz' => '80802', 'desc' => 'Spielcafé'], $this->coord($B, $L, 0.026, 0.000)),
            array_merge(['name' => 'Glockenbach Brettspielbar', 'address' => 'Klenzestr. 12', 'plz' => '80469', 'desc' => 'Brettspielbar'], $this->coord($B, $L, -0.007, -0.013)),
            array_merge(['name' => 'Maxvorstadt Tabletop Club', 'address' => 'Amalienstr. 20', 'plz' => '80333', 'desc' => 'Tabletop Club'], $this->coord($B, $L, 0.016, -0.009)),
            array_merge(['name' => 'Haidhausen Spieletreff', 'address' => 'Weißenburger Str. 8', 'plz' => '81667', 'desc' => 'Spieletreff'], $this->coord($B, $L, -0.001, 0.017)),
            array_merge(['name' => 'Sendlinger Spieleabend', 'address' => 'Sendlinger Str. 30', 'plz' => '80331', 'desc' => 'Spieleabend'], $this->coord($B, $L, -0.016, -0.036)),
            array_merge(['name' => 'München Board Game Café', 'address' => 'Thalkirchner Str. 15', 'plz' => '80337', 'desc' => 'Board Game Café'], $this->coord($B, $L, -0.020, -0.023)),
            array_merge(['name' => 'Neuhausen Brettspielrunde', 'address' => 'Nymphenburger Str. 40', 'plz' => '80335', 'desc' => 'Brettspielrunde'], $this->coord($B, $L, 0.007, -0.034)),
            array_merge(['name' => 'Bogenhausen Game Night', 'address' => 'Ismaninger Str. 50', 'plz' => '81675', 'desc' => 'Game Night'], $this->coord($B, $L, 0.005, 0.026)),
            array_merge(['name' => 'Pasing Spieletreff', 'address' => 'Bodenseestr. 10', 'plz' => '81243', 'desc' => 'Spieletreff'], $this->coord($B, $L, 0.001, -0.139)),
            array_merge(['name' => 'Olympia Spielewelt', 'address' => 'Lerchenauer Str. 5', 'plz' => '80809', 'desc' => 'Spielewelt'], $this->coord($B, $L, 0.034, -0.028)),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function genericVenues(string $city, float $cLat, float $cLng, int $count, string $plzPrefix): array
    {
        $types = ['Spielcafé', 'Brettspielcafé', 'Spieleabend', 'Spieletreff', 'Brettspielbar', 'Tabletop Club', 'Game Night', 'Spielewelt'];
        $out = [];
        for ($i = 1; $i <= $count; $i++) {
            $out[] = [
                'name' => $types[array_rand($types)].' '.$city.' '.($i + 10),
                'plz' => $plzPrefix.str_pad((string) random_int(10, 99), 2, '0', STR_PAD_LEFT),
                'lat' => round($cLat + random_int(-50, 50) / 1000, 4),
                'lng' => round($cLng + random_int(-50, 50) / 1000, 4),
            ];
        }

        return $out;
    }

    // =========================================================================
    // USERS
    // =========================================================================

    private function createUsers(): void
    {
        $this->info("Creating {$this->totalUsers} users...");

        $cityCounts = [
            'Berlin' => (int) round($this->totalUsers * 0.60),
            'Hamburg' => (int) round($this->totalUsers * 0.20),
            'München' => $this->totalUsers - (int) round($this->totalUsers * 0.60) - (int) round($this->totalUsers * 0.20),
        ];

        $password = Hash::make(self::PASSWORD);
        $bar = $this->output->createProgressBar($this->totalUsers);
        $bar->setRedrawFrequency(200);
        $bar->start();

        $usedEmails = [];
        $batch = [];

        foreach ($cityCounts as $city => $count) {
            $locs = $this->locationsByCity[$city] ?? [];
            if (empty($locs)) {
                continue;
            }

            for ($i = 0; $i < $count; $i++) {
                // Pick gender then matching first name for realistic profiles
                $genderRoll = mt_rand() / mt_getrandmax();
                $gender = $genderRoll < 0.45 ? 'female' : ($genderRoll < 0.90 ? 'male' : 'other');
                $genderConsent = true;
                // Align pronouns with gender (with ~10% variation for realism)
                $pronounRoll = mt_rand() / mt_getrandmax();
                $userPronouns = $gender === 'female' ? ($pronounRoll < 0.90 ? 'she/her' : 'they/them')
                    : ($gender === 'male' ? ($pronounRoll < 0.90 ? 'he/him' : 'they/them')
                    : 'they/them');
                $firstNamePool = $gender === 'female' ? self::FIRST_FEMALE
                    : ($gender === 'male' ? self::FIRST_MALE : array_merge(self::FIRST_FEMALE, self::FIRST_MALE));
                $first = $firstNamePool[array_rand($firstNamePool)];
                $last = self::LAST_NAMES[array_rand(self::LAST_NAMES)];
                $suffix = random_int(1000, 9999);

                $email = Str::slug("{$first}.{$last}.{$suffix}").'@example.org';
                while (isset($usedEmails[$email])) {
                    $suffix = random_int(1000, 9999);
                    $email = Str::slug("{$first}.{$last}.{$suffix}").'@example.org';
                }
                $usedEmails[$email] = true;

                $batch[] = [
                    'id' => $uid = (string) Str::orderedUuid(),
                    'name' => "{$first} {$last}",
                    'email' => $email,
                    'password' => $password,
                    'slug' => Str::slug("{$first}-{$last}").'-'.Str::random(6),
                    'email_verified_at' => now(),
                    'bio' => 'Gaming enthusiast based in '.$city.'. '.self::MARKER,
                    'pronouns' => $userPronouns,
                    'location_id' => $locs[array_rand($locs)],
                    'preferred_language' => mt_rand(0, 1) ? 'de' : 'en',
                    'profile_complete' => true,
                    'profile_version' => 1,
                    'profile_updated_at' => now(),
                    'privacy_settings' => json_encode($this->randomPrivacySettings()),
                    'gender' => $gender,
                    'gender_consent' => $genderConsent,
                    'terms_accepted_at' => now(),
                    'privacy_policy_accepted_at' => now(),
                    'created_at' => now()->subDays(random_int(1, 180)),
                    'updated_at' => now(),
                ];
                $this->userCityMap[$uid] = $city;
                $this->userEmailMap[$uid] = $email;
                $this->userNameMap[$uid] = "{$first} {$last}";

                if (count($batch) >= 500) {
                    $this->dryInsertMany('users', $batch);
                    foreach ($batch as $row) {
                        $this->allUserIds[] = $row['id'];
                    }
                    $batch = [];
                    $bar->setProgress(count($this->allUserIds));
                }
            }
        }

        if (! empty($batch)) {
            $this->dryInsertMany('users', $batch);
            foreach ($batch as $row) {
                $this->allUserIds[] = $row['id'];
            }
        }

        $bar->finish();
        $this->newLine();
        $this->info('Created '.count($this->allUserIds).' users.');
    }

    // =========================================================================
    // GM SETUP
    // =========================================================================

    private function setupGMs(): void
    {
        $this->newLine();
        $this->info("Setting up {$this->totalGms} game organizers...");

        $shuffled = $this->allUserIds;
        shuffle($shuffled);
        $gmUserIds = array_slice($shuffled, 0, $this->totalGms);
        $this->playerIds = array_values(array_diff($this->allUserIds, $gmUserIds));

        // Spatie role assignment (batch)
        $role = DB::table('roles')->where('name', 'Game Master')->first();
        if ($role) {
            $rows = array_map(fn ($uid) => [
                'role_id' => $role->id,
                'model_type' => User::class,
                'model_id' => $uid,
            ], $gmUserIds);
            foreach (array_chunk($rows, 500) as $chunk) {
                $this->dryInsertMany('model_has_roles', $chunk);
            }
        }

        // Grant can_create_public_entries for all GMs
        $gmCount = count($gmUserIds);
        if ($this->dryRun) {
            $this->info("Would set can_create_public_entries=true on {$gmCount} GM users.");
        } else {
            User::whereIn('id', $gmUserIds)->update(['can_create_public_entries' => true]);
            $this->info("Set can_create_public_entries=true on {$gmCount} GM users.");
        }

        // GM profiles — batch insert for performance
        $proficiencies = GmProficiency::values();
        $bar = $this->output->createProgressBar(count($gmUserIds));
        $bar->setRedrawFrequency(200);
        $bar->start();

        $gmProfileBatch = [];
        foreach ($gmUserIds as $uid) {
            $city = $this->cityForUser($uid);
            $userName = $this->userNameMap[$uid] ?? 'gm';

            $profileId = (string) Str::orderedUuid();
            $specCount = random_int(1, 3);
            $specKeys = $this->randomKeys($proficiencies, min($specCount, count($proficiencies)));
            $specs = array_values(array_intersect_key(
                $proficiencies,
                array_flip($specKeys)
            ));

            $gmProfileBatch[] = [
                'id' => $profileId,
                'user_id' => $uid,
                'bio' => self::GM_BIOS[array_rand(self::GM_BIOS)].' '.self::MARKER,
                'specializations' => json_encode($specs),
                'slug' => Str::slug($userName).'-'.Str::random(6),
                'average_rating' => null,
                'review_count' => 0,
                'is_active' => mt_rand() / mt_getrandmax() < 0.90, // 90% active, 10% paused
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $this->gmProfileIds[$uid] = $profileId;
            $this->gmInfo[] = ['id' => $uid, 'city' => $city];
            $this->gmFollowers[$uid] = []; // initialize

            if (count($gmProfileBatch) >= 500) {
                $this->dryInsertMany('gm_profiles', $gmProfileBatch);
                $gmProfileBatch = [];
            }
            $bar->advance();
        }
        if (! empty($gmProfileBatch)) {
            $this->dryInsertMany('gm_profiles', $gmProfileBatch);
        }
        $bar->finish();
        $this->newLine();

        // GM subscriptions for early adopters
        $gmPlan = null;
        try {
            $gmPlan = MembershipType::whereJsonContains('metadata->gm_plan', true)->first();
        } catch (\Throwable $e) {
            // Table may not exist or metadata column not json — handled below
        }
        if ($gmPlan) {
            $subIds = $gmUserIds;
            shuffle($subIds);
            $subIds = array_slice($subIds, 0, $this->totalSubscribers);

            // Subscriptions must predate game/campaign creation.
            // Games start ~7-180 days ago, campaigns ~14-60 days ago.
            // Subscriptions start 60-150 days ago to always come first.
            $rows = array_map(fn ($uid) => [
                'id' => (string) Str::orderedUuid(),
                'user_id' => $uid,
                'membership_type_id' => $gmPlan->id,
                'status' => 'active',
                'starts_at' => now()->subDays(random_int(60, 150)),
                'ends_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ], $subIds);
            foreach (array_chunk($rows, 500) as $chunk) {
                $this->dryInsertMany('local_subscriptions', $chunk);
            }
            $this->info('Created '.count($subIds).' GM subscriptions.');
        } elseif ($this->dryRun) {
            // In dry-run, simulate subscription count even without the plan in DB
            $this->dryCounts['local_subscriptions'] = ($this->dryCounts['local_subscriptions'] ?? 0) + $this->totalSubscribers;
            $this->warn("No GM membership plan found. Simulating {$this->totalSubscribers} subscriptions for dry-run.");
        } else {
            $this->warn('No GM membership type found. Skipping subscriptions.');
        }

        $this->info('GMs: '.count($this->gmInfo).' set up with profiles.');
    }

    private function cityForUser(string $userId): string
    {
        // Use prebuilt map (works in both dry-run and real mode)
        if (isset($this->userCityMap[$userId])) {
            return $this->userCityMap[$userId];
        }

        // Fallback: query DB (real mode only, for any user not in map)
        if (! $this->dryRun) {
            $user = User::find($userId);
            if ($user?->location_id) {
                $loc = Location::find($user->location_id);
                if ($loc) {
                    $this->warn("cityForUser fallback hit for {$userId} — map may be incomplete.");

                    return $this->userCityMap[$userId] = $loc->city ?? 'Berlin';
                }
            }
        }

        return 'Berlin';
    }

    // =========================================================================
    // SOCIAL GRAPH
    // =========================================================================

    private function buildSocialGraph(): void
    {
        $this->newLine();
        $this->info('Building social graph...');

        // Group players by city
        $playersByCity = [];
        foreach ($this->playerIds as $pid) {
            $playersByCity[$this->cityForUser($pid)][] = $pid;
        }

        /** @var list<array<string, mixed>> $insertRows */
        $insertRows = [];  // Batch accumulator
        $total = 0;
        $flushEvery = 200; // Flush every N rows to keep memory bounded

        $flush = function () use (&$insertRows, &$total): void {
            if (empty($insertRows)) {
                return;
            }
            if ($this->dryRun) {
                $this->dryCounts['user_relationships'] = ($this->dryCounts['user_relationships'] ?? 0) + count($insertRows);
            } else {
                // Dedup at flush time by (user_id, related_user_id) to avoid unique violations
                $uniqueRows = [];
                foreach ($insertRows as $row) {
                    $uid = is_string($row['user_id'] ?? null) ? $row['user_id'] : '';
                    $rid = is_string($row['related_user_id'] ?? null) ? $row['related_user_id'] : '';
                    $key = $uid.':'.$rid;
                    $uniqueRows[$key] = $row;
                }
                // Chunk to stay under PostgreSQL's 65,535 parameter limit (6 params per row → max ~10,000 rows)
                foreach (array_chunk(array_values($uniqueRows), 5000) as $chunk) {
                    DB::table('user_relationships')->insertOrIgnore($chunk);
                }
            }
            $total += count($insertRows);
            $insertRows = [];
        };

        // --- City-level social clusters ---
        foreach ($playersByCity as $city => $cityPlayers) {
            $clusterSize = random_int(25, 70);
            $clusters = array_chunk($cityPlayers, $clusterSize);

            // Within-cluster mutual follows (30-50% density)
            foreach ($clusters as $cluster) {
                $density = random_int(30, 50) / 100;
                $n = count($cluster);
                for ($i = 0; $i < $n; $i++) {
                    for ($j = $i + 1; $j < $n; $j++) {
                        if (mt_rand() / mt_getrandmax() < $density) {
                            $this->addFollow($cluster[$i], $cluster[$j], $insertRows);
                            $this->addFollow($cluster[$j], $cluster[$i], $insertRows);
                        }
                    }
                }
                // Flush after each cluster to bound memory
                if (count($insertRows) >= $flushEvery) {
                    $flush();
                }
            }

            // Inter-cluster: each user follows ~0-5 random users from other clusters.
            // Pre-compute all city players once; pick and skip same-cluster hits.
            $allCityPlayers = $cityPlayers;

            foreach ($clusters as $cluster) {
                $clusterSet = array_flip($cluster); // O(1) membership test
                foreach ($cluster as $uid) {
                    $followCount = random_int(0, 5);
                    $attempts = 0;
                    for ($f = 0; $f < $followCount && $attempts < $followCount * 3; $attempts++) {
                        $target = $allCityPlayers[array_rand($allCityPlayers)];
                        if (isset($clusterSet[$target])) {
                            continue; // same cluster, try again
                        }
                        $this->addFollow($uid, $target, $insertRows);
                        $f++;
                    }
                }
            }
        }

        // --- GM followers: 30-200 players follow each GM in their city ---
        foreach ($this->gmInfo as $gm) {
            $cityPlayers = $playersByCity[$gm['city']] ?? [];
            $followerCount = min(random_int(30, 200), count($cityPlayers));
            $keys = $this->randomKeys($cityPlayers, $followerCount);
            foreach ($keys as $k) {
                $this->addFollow($cityPlayers[(int) $k], $gm['id'], $insertRows);
            }
            // Flush per GM to keep memory bounded
            if (count($insertRows) >= $flushEvery) {
                $flush();
            }
        }

        // --- Cross-city GM→GM follows (2%) ---
        // Pre-group by city to avoid O(n²) all-pairs iteration
        $gmByCity = [];
        foreach ($this->gmInfo as $gm) {
            $gmByCity[$gm['city']][] = $gm['id'];
        }
        $cities = array_keys($gmByCity);
        $cityCount = count($cities);
        for ($ci = 0; $ci < $cityCount; $ci++) {
            for ($cj = $ci + 1; $cj < $cityCount; $cj++) {
                $listA = $gmByCity[$cities[$ci]];
                $listB = $gmByCity[$cities[$cj]];
                foreach ($listA as $a) {
                    foreach ($listB as $b) {
                        if (mt_rand() / mt_getrandmax() < 0.02) {
                            $this->addFollow($a, $b, $insertRows);
                        }
                    }
                }
            }
        }

        $flush();
        $this->info("Created {$total} follow relationships.");
    }

    /**
     * Add a follow relationship. Batches into $insertRows.
     * Dedup is handled at flush time via insertOrIgnore.
     *
     * @param  array<int, array<string, mixed>>  $insertRows
     */
    private function addFollow(string $from, string $to, array &$insertRows): void
    {
        if ($from === $to) {
            return;
        }
        $insertRows[] = [
            'id' => (string) Str::orderedUuid(),
            'user_id' => $from,
            'related_user_id' => $to,
            'type' => RelationshipType::Follow->value,
            'created_at' => now()->subDays(random_int(1, 120)),
            'updated_at' => now(),
        ];

        // Build the gmFollowers index for later use
        if (isset($this->gmFollowers[$to])) {
            $this->gmFollowers[$to][] = $from;
        }
    }

    /**
     * Select participants respecting visibility rules:
     *  - Private: only followers of the GM, joined via invitation
     *  - Protected: mix of followers (via application) + random others
     *  - Public: anyone
     *
     * @return array<int, string>
     */
    private function selectParticipants(string $gmId, int $count, string $visibility): array
    {
        $followers = $this->gmFollowers[$gmId] ?? [];

        if ($visibility === Visibility::Private->value) {
            // Only followers, via friend_invite
            $take = min($count, count($followers));
            $keys = $this->randomKeys($followers, $take);

            return array_map(fn ($k) => $followers[$k], $keys);
        }

        if ($visibility === Visibility::Protected->value) {
            // 60% followers, rest from general pool
            $fromFollowers = (int) ceil($count * 0.6);
            $fTake = min($fromFollowers, count($followers));
            $fKeys = count($followers) > 0 ? $this->randomKeys($followers, $fTake) : [];
            $picked = array_map(fn ($k) => $followers[$k], $fKeys);
            $remaining = $count - count($picked);
            if ($remaining > 0) {
                $pool = array_values(array_diff($this->playerIds, $picked));
                $rKeys = $this->randomKeys($pool, min($remaining, count($pool)));
                $picked = array_merge($picked, array_map(fn ($k) => $pool[(int) $k], $rKeys));
            }

            return $picked;
        }

        // Public: random via randomKeys (O(k) instead of O(n) shuffle)
        $pool = $this->playerIds;
        $take = min($count, count($pool));
        $keys = $this->randomKeys($pool, $take);

        return array_map(fn ($k) => $pool[$k], $keys);
    }

    /**
     * Safe array_rand that always returns an array of keys, even when count is 1.
     * PHP's array_rand returns a single int when count=1, which breaks (array) casts.
     *
     * @param  array<mixed>  $arr
     * @return list<int|string>
     */
    private function randomKeys(array $arr, int $count): array
    {
        $count = min($count, count($arr));
        if ($count <= 0 || empty($arr)) {
            return [];
        }
        $keys = array_rand($arr, $count);

        return array_values(is_array($keys) ? $keys : [$keys]);
    }

    /**
     * Get invitee_email for a participant when the join source is invite-based.
     * Returns null for non-invite sources.
     */
    private function inviteeEmailForSource(string $userId, string $joinSource): ?string
    {
        return in_array($joinSource, ['friend_invite', 'email_invite'], true)
            ? ($this->userEmailMap[$userId] ?? null)
            : null;
    }

    private function joinSourceForVisibility(string $visibility, bool $allowShortLink = true): string
    {
        // CHECK constraints allow: friend_invite, share_link, application, email_invite, short_link
        // Mix join sources to exercise all discovery paths.
        if ($visibility === Visibility::Private->value) {
            $roll = mt_rand() / mt_getrandmax();
            if ($roll < 0.55) {
                return 'friend_invite';
            }
            if ($roll < 0.80) {
                return 'share_link';
            }

            return 'email_invite';
        }

        if ($visibility === Visibility::Protected->value) {
            $roll = mt_rand() / mt_getrandmax();
            if ($roll < 0.45) {
                return 'application';
            }
            if ($roll < 0.80) {
                return 'share_link';
            }

            return 'friend_invite';
        }

        // Public
        $roll = mt_rand() / mt_getrandmax();
        if ($roll < 0.55) {
            return 'application';
        }
        if ($roll < 0.85) {
            return 'share_link';
        }

        return $allowShortLink ? 'short_link' : 'share_link';
    }

    // =========================================================================
    // COMPLETED BOARD GAME SESSIONS
    // =========================================================================

    private function createCompletedBoardGameSessions(): void
    {
        $this->newLine();
        $this->info('Creating completed board game sessions...');
        if (empty($this->boardGamePool)) {
            $this->warn('No board game systems in pool. Skipping.');

            return;
        }

        $bar = $this->output->createProgressBar(count($this->gmInfo));
        $bar->setRedrawFrequency(50);
        $bar->start();

        /** @var list<array<string, mixed>> $gameBatch */
        $gameBatch = [];
        /** @var list<array<string, mixed>> $participantBatch */
        $participantBatch = [];
        /** @var list<array<string, mixed>> $applicationBatch */
        $applicationBatch = [];

        foreach ($this->gmInfo as $gm) {
            $sessionCount = random_int(3, 12);
            $city = $gm['city'];
            $cityLocs = $this->locationsByCity[$city] ?? [];

            for ($s = 0; $s < $sessionCount; $s++) {
                $game = $this->boardGamePool[array_rand($this->boardGamePool)];

                $maxPlayers = random_int($game['min'], $game['max']);
                $minPlayers = max($game['min'], $maxPlayers - 2);
                $isBench = mt_rand() / mt_getrandmax() < 0.20;
                $locationId = $cityLocs[array_rand($cityLocs)] ?? null;

                // Visibility: 25% public, 35% protected, 40% private
                $roll = mt_rand() / mt_getrandmax();
                $visibility = $roll < 0.25 ? Visibility::Public
                    : ($roll < 0.60 ? Visibility::Protected : Visibility::Private);

                // Experience level: 40% all, 25% beginner, 20% intermediate, 15% advanced
                $expRoll = mt_rand() / mt_getrandmax();
                $experienceLevel = $expRoll < 0.40 ? ExperienceLevel::All
                    : ($expRoll < 0.65 ? ExperienceLevel::Beginner
                    : ($expRoll < 0.85 ? ExperienceLevel::Intermediate : ExperienceLevel::Advanced));

                $daysAgo = random_int(7, 180);
                $dateTime = Carbon::now()->subDays($daysAgo)->setTime(random_int(14, 20), random_int(0, 3) * 15);

                $language = random_int(0, 2) > 0 ? 'de' : 'en'; // ~66% DE, ~33% EN
                $gameId = (string) Str::orderedUuid();
                $suffixes = self::SESSION_SUFFIXES[$language];
                $suffix = $suffixes[array_rand($suffixes)];
                $descs = self::BOARD_DESCRIPTIONS[$language];
                $recaps = self::RECAPS[$language];

                // ~5% of past sessions are cancelled (no recap, no attendance)
                $isCancelled = mt_rand() / mt_getrandmax() < 0.05;
                $gameStatus = $isCancelled
                    ? GameStatus::Canceled->value
                    : GameStatus::Completed->value;

                $gameBatch[] = [
                    'id' => $gameId,
                    'owner_id' => $gm['id'],
                    'campaign_id' => null,
                    'game_system_id' => $game['id'],
                    'name' => json_encode([$language => $game['name'].' '.$suffix.' '.self::MARKER]),
                    'description' => json_encode([$language => $descs[array_rand($descs)].' '.self::MARKER]),
                    'game_type' => GameType::BoardGame->value,
                    'date_time' => $dateTime,
                    'expected_duration' => round($game['dur'] / 60, 1), // minutes → hours
                    'location_id' => $locationId,
                    'location' => $this->buildLocationJson($locationId),
                    'status' => $gameStatus,
                    'visibility' => $visibility->value,
                    'experience_level' => $experienceLevel->value,
                    'min_players' => $minPlayers,
                    'max_players' => $maxPlayers,
                    'bench_mode' => $isBench,
                    'vibe_flags' => json_encode($this->randomVibes('board')),
                    'safety_rules' => json_encode([]),
                    'language' => $language,
                    'recap' => $isCancelled ? null : $recaps[array_rand($recaps)],
                    'created_at' => $dateTime->copy()->subDays(random_int(3, 14)),
                    'updated_at' => $dateTime,
                ];

                // Select participants using visibility-aware logic
                $playerCount = random_int(max(1, $minPlayers - 1), max(1, $maxPlayers - 1));
                $selected = $this->selectParticipants($gm['id'], $playerCount, $visibility->value);
                $joinSource = $this->joinSourceForVisibility($visibility->value);

                $participantIds = [$gm['id']];

                // GM as owner
                $participantBatch[] = [
                    'id' => (string) Str::orderedUuid(),
                    'game_id' => $gameId,
                    'user_id' => $gm['id'],
                    'role' => ParticipantRole::Owner->value,
                    'status' => ParticipantStatus::Approved->value,
                    'attendance_status' => $isCancelled ? null : AttendanceStatus::Attended->value,
                    'join_source' => 'application',
                    'invitee_email' => null,
                    'benched_at' => null,
                    'waitlisted_at' => null,
                    'created_at' => $dateTime->copy()->subDays(random_int(1, 7)),
                ];

                // Players — batch
                foreach ($selected as $pid) {
                    $attendance = $isCancelled ? null : (mt_rand() / mt_getrandmax() < 0.90
                        ? AttendanceStatus::Attended->value
                        : AttendanceStatus::NoShow->value);

                    $participantBatch[] = [
                        'id' => (string) Str::orderedUuid(),
                        'game_id' => $gameId,
                        'user_id' => $pid,
                        'role' => ParticipantRole::Player->value,
                        'status' => ParticipantStatus::Approved->value,
                        'attendance_status' => $attendance,
                        'join_source' => $joinSource,
                        'invitee_email' => $this->inviteeEmailForSource($pid, $joinSource),
                        'benched_at' => null,
                        'waitlisted_at' => null,
                        'created_at' => $dateTime->copy()->subDays(random_int(1, 7)),
                    ];
                    if (! $isCancelled && $attendance === AttendanceStatus::Attended->value) {
                        $participantIds[] = $pid;
                    }

                    $applicationBatch[] = [
                        'id' => (string) Str::orderedUuid(),
                        'game_id' => $gameId,
                        'user_id' => $pid,
                        'status' => ParticipantStatus::Approved->value,
                        'message' => null,
                        'created_at' => $dateTime->copy()->subDays(random_int(3, 14)),
                        'updated_at' => $dateTime->copy()->subDays(random_int(1, 7)),
                    ];
                }

                // Overflow
                $overflowCount = random_int(0, 4);
                $overflowPool = array_values(array_diff($this->playerIds, $selected, [$gm['id']]));
                $overflowKeys = $this->randomKeys($overflowPool, min($overflowCount, count($overflowPool)));
                $overflowPlayers = array_map(fn ($k) => $overflowPool[(int) $k], $overflowKeys);

                foreach ($overflowPlayers as $pid) {
                    $status = $isBench ? ParticipantStatus::Benched->value : ParticipantStatus::Waitlisted->value;

                    $participantBatch[] = [
                        'id' => (string) Str::orderedUuid(),
                        'game_id' => $gameId,
                        'user_id' => $pid,
                        'role' => ParticipantRole::Player->value,
                        'status' => $status,
                        'attendance_status' => null,
                        'join_source' => 'application',
                        'invitee_email' => null,
                        'benched_at' => $isBench ? $dateTime->copy()->subDays(random_int(0, 5)) : null,
                        'waitlisted_at' => $isBench ? null : $dateTime->copy()->subDays(random_int(0, 5)),
                        'created_at' => $dateTime->copy()->subDays(random_int(1, 7)),
                    ];

                    // Application matching the overflow participant's status
                    $applicationBatch[] = [
                        'id' => (string) Str::orderedUuid(),
                        'game_id' => $gameId,
                        'user_id' => $pid,
                        'status' => $isBench ? 'rejected' : 'pending',
                        'message' => null,
                        'created_at' => $dateTime->copy()->subDays(random_int(3, 14)),
                        'updated_at' => $dateTime->copy()->subDays(random_int(1, 7)),
                    ];
                }

                // Flush batches when large enough — games must flush before
                // participants AND applications to satisfy FK constraints
                $needFlush = count($participantBatch) >= 500
                    || count($applicationBatch) >= 500
                    || count($gameBatch) >= 500;
                if ($needFlush) {
                    $this->dryInsertMany('games', $gameBatch);
                    $gameBatch = [];
                    $this->dryInsertMany('game_participants', $participantBatch);
                    $participantBatch = [];
                    if (! empty($applicationBatch)) {
                        $this->dryInsertMany('game_applications', $applicationBatch);
                        $applicationBatch = [];
                    }
                }

                if (! $isCancelled) {
                    $this->completedGames[] = [
                        'game_id' => $gameId,
                        'owner_id' => $gm['id'],
                        'participant_ids' => $participantIds,
                        'language' => $language,
                        'is_session_zero' => false,
                    ];

                    // Queue attendance reports: GM reports attendance for each attended player
                    foreach ($participantIds as $pid) {
                        if ($pid === $gm['id']) {
                            continue;
                        }
                        $this->attendanceReportQueue[] = [
                            'game_id' => $gameId,
                            'owner_id' => $gm['id'],
                            'reported_id' => $pid,
                            'attendance' => AttendanceStatus::Attended->value,
                        ];
                    }
                }
            }

            $bar->advance();
        }

        // Flush remaining batches
        if (! empty($gameBatch)) {
            $this->dryInsertMany('games', $gameBatch);
        }
        if (! empty($participantBatch)) {
            $this->dryInsertMany('game_participants', $participantBatch);
        }
        if (! empty($applicationBatch)) {
            $this->dryInsertMany('game_applications', $applicationBatch);
        }

        $bar->finish();
        $this->newLine();
        $this->info('Created '.count($this->completedGames).' completed board game sessions.');
    }

    // =========================================================================
    // REVIEWS
    // =========================================================================

    private function createReviews(): void
    {
        $this->newLine();
        $this->info('Creating reviews...');
        $proficiencies = GmProficiency::values();
        $bar = $this->output->createProgressBar(count($this->completedGames));
        $bar->setRedrawFrequency(200);
        $bar->start();

        $total = 0;
        $batch = [];

        foreach ($this->completedGames as $game) {
            $gmProfileId = $this->gmProfileIds[$game['owner_id']] ?? null;
            if (! $gmProfileId) {
                $bar->advance();

                continue;
            }

            $participants = array_values(array_filter(
                $game['participant_ids'],
                fn ($pid) => $pid !== $game['owner_id']
            ));

            if (empty($participants)) {
                $bar->advance();

                continue;
            }

            $reviewRate = random_int(60, 80) / 100;
            $reviewerCount = max(1, (int) (count($participants) * $reviewRate));
            $reviewerKeys = $this->randomKeys($participants, min($reviewerCount, count($participants)));

            $isSessionZero = ! empty($game['is_session_zero']);
            $reviewBodies = $isSessionZero
                ? self::SESSION_ZERO_REVIEW_BODIES[$game['language']]
                : self::REVIEW_BODIES[$game['language']];

            foreach ($reviewerKeys as $k) {
                // Distribution: 27%→5★, 36%→4★, 20%→3★, 17%→2★ (range 2-5 per spec)
                $roll = mt_rand() / mt_getrandmax();
                $rating = $roll < 0.27 ? 5 : ($roll < 0.63 ? 4 : ($roll < 0.83 ? 3 : 2));

                $tagKeys = $this->randomKeys($proficiencies, min(random_int(1, 3), count($proficiencies)));
                $tags = array_values(array_intersect_key(
                    $proficiencies,
                    array_flip($tagKeys)
                ));

                $batch[] = [
                    'id' => (string) Str::orderedUuid(),
                    'reviewable_type' => Game::class,
                    'reviewable_id' => $game['game_id'],
                    'reviewer_id' => $participants[(int) $k],
                    'gm_profile_id' => $gmProfileId,
                    'rating' => $rating,
                    'body' => $reviewBodies[array_rand($reviewBodies)].' '.self::MARKER,
                    'proficiency_tags' => json_encode($tags),
                    'status' => 'published',
                    'reported_at' => null,
                    'reported_by' => null,
                    'reply' => null,
                    'replied_at' => null,
                    'created_at' => now()->subDays(random_int(1, 30)),
                    'updated_at' => now(),
                ];
                $total++;

                if (count($batch) >= 500) {
                    $this->dryInsertMany('reviews', $batch);
                    $batch = [];
                }
            }

            $bar->advance();
        }

        if (! empty($batch)) {
            $this->dryInsertMany('reviews', $batch);
        }

        $bar->finish();
        $this->newLine();
        $this->info("Created {$total} reviews.");
    }

    // =========================================================================
    // CAMPAIGN REVIEWS
    // =========================================================================

    private function createCampaignReviews(): void
    {
        if (empty($this->completedCampaignsForReview)) {
            return;
        }

        $this->newLine();
        $this->info('Creating campaign reviews...');
        $proficiencies = GmProficiency::values();

        $bar = $this->output->createProgressBar(count($this->completedCampaignsForReview));
        $bar->setRedrawFrequency(200);
        $bar->start();

        $total = 0;
        $batch = [];

        foreach ($this->completedCampaignsForReview as $campaign) {
            $gmProfileId = $this->gmProfileIds[$campaign['owner_id']] ?? null;
            if (! $gmProfileId) {
                $bar->advance();

                continue;
            }

            $participants = $campaign['participant_ids'];
            if (empty($participants)) {
                $bar->advance();

                continue;
            }

            // ~50-70% of eligible participants write campaign-level reviews
            $reviewRate = random_int(50, 70) / 100;
            $reviewerCount = max(1, (int) (count($participants) * $reviewRate));
            $reviewerKeys = $this->randomKeys($participants, min($reviewerCount, count($participants)));

            $language = $campaign['language']; // shape always has language
            $reviewBodies = $language === 'de'
                ? [
                    'Tolle Kampagne mit gutem Storyverlauf. '.self::MARKER,
                    'Sehr empfehlenswert! Wir hatten viel Spass. '.self::MARKER,
                    'Gute Balance zwischen Story und Kampf. '.self::MARKER,
                    'Spannende Kampagne mit interessanten Charakteren. '.self::MARKER,
                    'Kampagne war gut strukturiert und hat Spass gemacht. '.self::MARKER,
                    'Leider etwas zu lang, aber sonst gut. '.self::MARKER,
                    'Mittelmässig, hatte mir mehr erhofft. '.self::MARKER,
                    'Nicht meins, aber fair geleitet. '.self::MARKER,
                ]
                : [
                    'Great campaign with a well-structured story arc. '.self::MARKER,
                    'Highly recommended! We had a blast throughout. '.self::MARKER,
                    'Good balance between roleplay and combat encounters. '.self::MARKER,
                    'Engaging campaign with memorable NPCs and plot twists. '.self::MARKER,
                    'Campaign was well-paced and kept everyone engaged. '.self::MARKER,
                    'A bit slow in the middle, but strong finish. '.self::MARKER,
                    'Decent but didn\'t quite live up to the premise. '.self::MARKER,
                    'Not my style, but the GM was fair and prepared. '.self::MARKER,
                ];

            foreach ($reviewerKeys as $k) {
                // Distribution: 27%→5★, 36%→4★, 20%→3★, 17%→2★ (range 2-5 per spec)
                $roll = mt_rand() / mt_getrandmax();
                $rating = $roll < 0.27 ? 5 : ($roll < 0.63 ? 4 : ($roll < 0.83 ? 3 : 2));

                $tagKeys = $this->randomKeys($proficiencies, min(random_int(1, 3), count($proficiencies)));
                $tags = array_values(array_intersect_key(
                    $proficiencies,
                    array_flip($tagKeys)
                ));

                $batch[] = [
                    'id' => (string) Str::orderedUuid(),
                    'reviewable_type' => Campaign::class,
                    'reviewable_id' => $campaign['campaign_id'],
                    'reviewer_id' => $participants[$k],
                    'gm_profile_id' => $gmProfileId,
                    'rating' => $rating,
                    'body' => $reviewBodies[array_rand($reviewBodies)],
                    'proficiency_tags' => json_encode($tags),
                    'status' => 'published',
                    'reported_at' => null,
                    'reported_by' => null,
                    'reply' => null,
                    'replied_at' => null,
                    'created_at' => now()->subDays(random_int(1, 30)),
                    'updated_at' => now(),
                ];
                $total++;

                if (count($batch) >= 500) {
                    $this->dryInsertMany('reviews', $batch);
                    $batch = [];
                }
            }

            $bar->advance();
        }

        if (! empty($batch)) {
            $this->dryInsertMany('reviews', $batch);
        }

        $bar->finish();
        $this->newLine();
        $this->info("Created {$total} campaign reviews.");
    }

    // =========================================================================
    // CAMPAIGNS + SESSIONS
    // =========================================================================

    private function createCampaigns(): void
    {
        $this->newLine();
        $this->info('Creating campaigns...');
        if (empty($this->ttrpgPool)) {
            $this->warn('No TTRPG systems in pool. Skipping campaigns.');

            return;
        }

        // Pre-compute static arrays used in tight loops (avoids per-iteration collect() allocations)
        $recurrenceOptions = ['weekly', 'bi-weekly'];
        $experienceLevels = array_map(fn ($case) => $case->value, ExperienceLevel::cases());

        $bar = $this->output->createProgressBar(count($this->gmInfo));
        $bar->setRedrawFrequency(50);
        $bar->start();

        $totalCampaigns = 0;
        $totalSessions = 0;

        // Batch accumulators — flush every 500 rows for performance
        /** @var list<array<string, mixed>> $campaignBatch */
        $campaignBatch = [];
        /** @var list<array<string, mixed>> $campaignParticipantBatch */
        $campaignParticipantBatch = [];
        /** @var list<array<string, mixed>> $campaignApplicationBatch */
        $campaignApplicationBatch = [];
        /** @var list<array<string, mixed>> $gameBatch */
        $gameBatch = [];
        /** @var list<array<string, mixed>> $gameParticipantBatch */
        $gameParticipantBatch = [];
        /** @var list<array<string, mixed>> $gameApplicationBatch */
        $gameApplicationBatch = [];

        $flushAll = function () use (
            &$campaignBatch, &$campaignParticipantBatch, &$campaignApplicationBatch,
            &$gameBatch, &$gameParticipantBatch, &$gameApplicationBatch,
        ): void {
            // Flush any batch that has rows — called at flush points to prevent unbounded growth
            if (! empty($campaignBatch)) {
                $this->dryInsertMany('campaigns', $campaignBatch);
                $campaignBatch = [];
            }
            if (! empty($campaignParticipantBatch)) {
                $this->dryInsertMany('campaign_participants', $campaignParticipantBatch);
                $campaignParticipantBatch = [];
            }
            if (! empty($campaignApplicationBatch)) {
                $this->dryInsertMany('campaign_applications', $campaignApplicationBatch);
                $campaignApplicationBatch = [];
            }
            if (! empty($gameBatch)) {
                $this->dryInsertMany('games', $gameBatch);
                $gameBatch = [];
            }
            if (! empty($gameParticipantBatch)) {
                $this->dryInsertMany('game_participants', $gameParticipantBatch);
                $gameParticipantBatch = [];
            }
            if (! empty($gameApplicationBatch)) {
                $this->dryInsertMany('game_applications', $gameApplicationBatch);
                $gameApplicationBatch = [];
            }
        };

        foreach ($this->gmInfo as $gm) {
            $campaignCount = random_int(1, 4);
            $city = $gm['city'];
            $cityLocs = $this->locationsByCity[$city] ?? [];

            for ($c = 0; $c < $campaignCount; $c++) {
                $rpg = $this->ttrpgPool[array_rand($this->ttrpgPool)];

                $maxPlayers = random_int($rpg['min'], $rpg['max']);
                $minPlayers = max($rpg['min'], $maxPlayers - 2);
                $isBench = mt_rand() / mt_getrandmax() < 0.20;
                $locationId = $cityLocs[array_rand($cityLocs)] ?? null;

                // Visibility: 20% public, 40% protected, 40% private
                $roll = mt_rand() / mt_getrandmax();
                $visibility = $roll < 0.20 ? Visibility::Public
                    : ($roll < 0.60 ? Visibility::Protected : Visibility::Private);

                // ~25% of campaigns are completed, ~10% cancelled, rest active
                $statusRoll = mt_rand() / mt_getrandmax();
                $campaignStatus = $statusRoll < 0.65 ? 'active' : ($statusRoll < 0.75 ? 'cancelled' : 'completed');

                $campaignId = (string) Str::orderedUuid();
                $language = random_int(0, 2) > 0 ? 'de' : 'en'; // ~66% DE
                $campaignNames = self::CAMPAIGN_NAMES[$language];
                $campaignDescs = self::CAMPAIGN_DESCRIPTIONS[$language];
                $campaignName = $campaignNames[array_rand($campaignNames)];
                $campaignCreatedAt = now()->subDays(random_int(14, 60));
                $campaignExpLevel = $experienceLevels[array_rand($experienceLevels)];

                $campaignBatch[] = [
                    'id' => $campaignId,
                    'owner_id' => $gm['id'],
                    'game_system_id' => $rpg['id'],
                    'location_id' => $locationId,
                    'name' => json_encode([$language => $campaignName.' '.self::MARKER]),
                    'description' => json_encode([$language => $campaignDescs[array_rand($campaignDescs)].' '.self::MARKER]),
                    'visibility' => $visibility->value,
                    'min_players' => $minPlayers,
                    'max_players' => $maxPlayers,
                    'recurrence' => $recurrenceOptions[array_rand($recurrenceOptions)],
                    'time_of_day' => sprintf('%02d:%02d', random_int(14, 20), random_int(0, 3) * 15),
                    'session_duration' => random_int(3, 5),
                    'experience_level' => $campaignExpLevel,
                    'vibe_flags' => json_encode($this->randomVibes('ttrpg')),
                    'safety_rules' => json_encode(['tools' => ['session_zero', 'lines_and_veils', 'x-card']]),
                    'bench_mode' => $isBench,
                    'status' => $campaignStatus,
                    'language' => $language,
                    'created_at' => $campaignCreatedAt,
                    'updated_at' => now(),
                ];

                // Select campaign participants using same visibility-aware logic
                $playerCount = random_int(max(2, $minPlayers - 1), max(2, $maxPlayers - 1));
                $selected = $this->selectParticipants($gm['id'], $playerCount, $visibility->value);
                $joinSource = $this->joinSourceForVisibility($visibility->value, false);
                $campaignParticipants = array_merge([$gm['id']], $selected);

                // Owner participant
                $campaignParticipantBatch[] = [
                    'id' => (string) Str::orderedUuid(),
                    'campaign_id' => $campaignId,
                    'user_id' => $gm['id'],
                    'role' => ParticipantRole::Owner->value,
                    'status' => ParticipantStatus::Approved->value,
                    'join_source' => 'application',
                    'invitee_email' => null,
                    'benched_at' => null,
                    'waitlisted_at' => null,
                    'created_at' => $campaignCreatedAt,
                ];

                // Player participants
                foreach ($selected as $pid) {
                    $playerJoinedAt = $campaignCreatedAt->copy()->addDays(random_int(0, 5));

                    $campaignParticipantBatch[] = [
                        'id' => (string) Str::orderedUuid(),
                        'campaign_id' => $campaignId,
                        'user_id' => $pid,
                        'role' => ParticipantRole::Player->value,
                        'status' => ParticipantStatus::Approved->value,
                        'join_source' => $joinSource,
                        'invitee_email' => $this->inviteeEmailForSource($pid, $joinSource),
                        'benched_at' => null,
                        'waitlisted_at' => null,
                        'created_at' => $playerJoinedAt,
                    ];

                    // Campaign application — tracks the application lifecycle
                    $campaignApplicationBatch[] = [
                        'id' => (string) Str::orderedUuid(),
                        'campaign_id' => $campaignId,
                        'user_id' => $pid,
                        'status' => ParticipantStatus::Approved->value,
                        'message' => null,
                        'created_at' => $playerJoinedAt->copy()->subDays(random_int(1, 3)),
                        'updated_at' => $playerJoinedAt,
                    ];
                }

                // Overflow — use randomKeys instead of shuffle for consistency
                $overflowCount = random_int(0, 3);
                $overflowPool = array_values(array_diff($this->playerIds, $selected, [$gm['id']]));
                $overflowKeys = $this->randomKeys($overflowPool, min($overflowCount, count($overflowPool)));
                foreach (array_map(fn ($k) => $overflowPool[(int) $k], $overflowKeys) as $pid) {
                    $status = $isBench ? ParticipantStatus::Benched->value : ParticipantStatus::Waitlisted->value;

                    $overflowJoinedAt = $campaignCreatedAt->copy()->addDays(random_int(3, 10));

                    $campaignParticipantBatch[] = [
                        'id' => (string) Str::orderedUuid(),
                        'campaign_id' => $campaignId,
                        'user_id' => $pid,
                        'role' => ParticipantRole::Player->value,
                        'status' => $status,
                        'join_source' => 'application',
                        'invitee_email' => null,
                        'benched_at' => $isBench ? $overflowJoinedAt : null,
                        'waitlisted_at' => $isBench ? null : $overflowJoinedAt,
                        'created_at' => $overflowJoinedAt,
                    ];

                    // Application matching the overflow participant's status
                    $campaignApplicationBatch[] = [
                        'id' => (string) Str::orderedUuid(),
                        'campaign_id' => $campaignId,
                        'user_id' => $pid,
                        'status' => $isBench ? 'rejected' : 'pending',
                        'message' => null,
                        'created_at' => $overflowJoinedAt->copy()->subDays(random_int(1, 3)),
                        'updated_at' => $overflowJoinedAt,
                    ];
                }

                // --- Session Zero ---
                $interval = $isBench ? 14 : 7;
                $baseDate = now()->subDays(random_int(7, 21));
                $szId = (string) Str::orderedUuid();
                $szDate = $baseDate->copy()->addDays(random_int(0, 7));
                // Cancelled campaigns have cancelled session zeros; otherwise 75% completed
                // Both active and completed campaigns can have completed session zeros
                $szCompleted = $campaignStatus !== 'cancelled' && mt_rand() / mt_getrandmax() < 0.75;
                $szStatus = $campaignStatus === 'cancelled'
                    ? GameStatus::Canceled->value
                    : ($szCompleted ? GameStatus::Completed->value : GameStatus::Scheduled->value);

                $gameBatch[] = [
                    'id' => $szId,
                    'owner_id' => $gm['id'],
                    'campaign_id' => $campaignId,
                    'game_system_id' => $rpg['id'],
                    'name' => json_encode([$language => ($language === 'de' ? 'Session Zero: Charaktere & Weltenbau' : 'Session Zero: Character & World Building').' '.self::MARKER]),
                    'description' => json_encode([$language => self::SESSION_ZERO_DESC[$language].' '.self::MARKER]),
                    'game_type' => GameType::Ttrpg->value,
                    'date_time' => $szDate,
                    'expected_duration' => 2.5,
                    'location_id' => $locationId,
                    'location' => $this->buildLocationJson($locationId),
                    'status' => $szStatus,
                    'visibility' => $visibility->value,
                    'experience_level' => $campaignExpLevel,
                    'min_players' => $minPlayers,
                    'max_players' => $maxPlayers,
                    'bench_mode' => $isBench,
                    'vibe_flags' => json_encode($this->randomVibes('ttrpg')),
                    'safety_rules' => json_encode(['tools' => ['session_zero', 'lines_and_veils', 'x-card']]),
                    'language' => $language,
                    'recap' => $szCompleted ? self::RECAPS[$language][array_rand(self::RECAPS[$language])] : null,
                    'created_at' => $szDate->copy()->subDays(7),
                    'updated_at' => $szDate,
                ];

                $attendedPids = [];
                foreach ($campaignParticipants as $pid) {
                    // Attendance randomization: 90% attended, 10% no-show for completed sessions
                    $attendance = null;
                    if ($szCompleted && $pid !== $gm['id']) {
                        $attendance = mt_rand() / mt_getrandmax() < 0.90
                            ? AttendanceStatus::Attended->value
                            : AttendanceStatus::NoShow->value;
                    } elseif ($szCompleted) {
                        $attendance = AttendanceStatus::Attended->value; // GM always attended
                    }

                    // Track who actually attended for survey seeding
                    if ($szCompleted && ($attendance === AttendanceStatus::Attended->value || $pid === $gm['id'])) {
                        $attendedPids[] = $pid;
                    }

                    $gameParticipantBatch[] = [
                        'id' => (string) Str::orderedUuid(),
                        'game_id' => $szId,
                        'user_id' => $pid,
                        'role' => $pid === $gm['id'] ? ParticipantRole::Owner->value : ParticipantRole::Player->value,
                        'status' => ParticipantStatus::Approved->value,
                        'attendance_status' => $attendance,
                        'join_source' => $joinSource,
                        'invitee_email' => $pid !== $gm['id'] ? $this->inviteeEmailForSource($pid, $joinSource) : null,
                        'benched_at' => null,
                        'waitlisted_at' => null,
                        'created_at' => $szDate->copy()->subDays(random_int(1, 7)),
                    ];

                    // Game application for each campaign session participant
                    // Game application for each campaign session participant (skip GM — owners don't apply)
                    if ($pid !== $gm['id']) {
                        $gameApplicationBatch[] = [
                            'id' => (string) Str::orderedUuid(),
                            'game_id' => $szId,
                            'user_id' => $pid,
                            'status' => ParticipantStatus::Approved->value,
                            'message' => null,
                            'created_at' => $szDate->copy()->subDays(random_int(3, 14)),
                            'updated_at' => $szDate->copy()->subDays(random_int(1, 7)),
                        ];
                    }
                }
                $totalSessions++;

                // Track non-cancelled public/protected session zeros for short links
                if ($szStatus !== GameStatus::Canceled->value && $visibility !== Visibility::Private) {
                    $this->campaignSessionGames[] = ['game_id' => $szId, 'owner_id' => $gm['id']];
                }

                // Track completed session zeros for survey seeding and review generation
                if ($szCompleted) {
                    // Exclude GM from survey participants — surveys are for players
                    $surveyParticipantIds = array_values(array_filter($attendedPids, fn ($pid) => $pid !== $gm['id']));
                    $szEntry = [
                        'game_id' => $szId,
                        'owner_id' => $gm['id'],
                        'language' => $language,
                        'participant_ids' => $surveyParticipantIds,
                    ];
                    $this->completedSessionZeros[] = $szEntry;
                    // Also feed into completedGames so createReviews() generates TTRPG reviews
                    // (createReviews already filters out owner from reviewers)
                    $szEntry['is_session_zero'] = true;
                    $this->completedGames[] = $szEntry;

                    // Queue attendance reports for session zero participants
                    foreach ($surveyParticipantIds as $pid) {
                        $this->attendanceReportQueue[] = [
                            'game_id' => $szId,
                            'owner_id' => $gm['id'],
                            'reported_id' => $pid,
                            'attendance' => AttendanceStatus::Attended->value,
                        ];
                    }

                    // Track campaign for campaign-level reviews only when campaign is completed
                    // Active campaigns haven't had enough play to warrant a campaign-level review
                    if ($campaignStatus === 'completed') {
                        $this->completedCampaignsForReview[] = [
                            'campaign_id' => $campaignId,
                            'owner_id' => $gm['id'],
                            'participant_ids' => array_values(array_filter(
                                $campaignParticipants,
                                fn ($pid) => $pid !== $gm['id']
                            )),
                            'language' => $language,
                        ];
                    }
                }

                // --- Completed past sessions (active & completed campaigns) ---
                // These represent sessions that already happened between session zero and now,
                // with full attendance tracking, recaps, and post-completion data.
                $pastSessionCount = $campaignStatus === 'cancelled' ? 0
                    : random_int(1, 3);
                for ($p = 1; $p <= $pastSessionCount; $p++) {
                    $maxDaysAgo = max(3, $szDate->diffInDays(now()) - 1);
                    $pastDaysAgo = random_int(3, (int) $maxDaysAgo);
                    $pastDate = now()->subDays($pastDaysAgo)->setTime(random_int(14, 20), random_int(0, 3) * 15);
                    $pId = (string) Str::orderedUuid();
                    $sessionNum = $p + 1; // session zero was #1

                    $sessionLabel = $language === 'de' ? "Sitzung {$sessionNum}" : "Session {$sessionNum}";
                    $sessionDesc = $language === 'de'
                        ? "Abgeschlossene Kampagnensitzung {$sessionNum}. ".self::MARKER
                        : "Completed campaign session {$sessionNum}. ".self::MARKER;

                    // ~3% of past sessions were cancelled
                    $isPastCancelled = mt_rand() / mt_getrandmax() < 0.03;
                    $pStatus = $isPastCancelled
                        ? GameStatus::Canceled->value
                        : GameStatus::Completed->value;

                    $gameBatch[] = [
                        'id' => $pId,
                        'owner_id' => $gm['id'],
                        'campaign_id' => $campaignId,
                        'game_system_id' => $rpg['id'],
                        'name' => json_encode([$language => $sessionLabel.' '.self::MARKER]),
                        'description' => json_encode([$language => $sessionDesc]),
                        'game_type' => GameType::Ttrpg->value,
                        'date_time' => $pastDate,
                        'expected_duration' => random_int(3, 5),
                        'location_id' => $locationId,
                        'location' => $this->buildLocationJson($locationId),
                        'status' => $pStatus,
                        'visibility' => $visibility->value,
                        'experience_level' => $campaignExpLevel,
                        'min_players' => $minPlayers,
                        'max_players' => $maxPlayers,
                        'bench_mode' => $isBench,
                        'vibe_flags' => json_encode($this->randomVibes('ttrpg')),
                        'safety_rules' => json_encode(['tools' => ['lines_and_veils', 'x-card']]),
                        'language' => $language,
                        'recap' => $isPastCancelled ? null : self::RECAPS[$language][array_rand(self::RECAPS[$language])],
                        'created_at' => $pastDate->copy()->subDays(random_int(3, 7)),
                        'updated_at' => $pastDate,
                    ];

                    $pastAttendedPids = [];
                    foreach ($campaignParticipants as $pid) {
                        $attendance = null;
                        if (! $isPastCancelled && $pid === $gm['id']) {
                            $attendance = AttendanceStatus::Attended->value;
                        } elseif (! $isPastCancelled) {
                            $attendance = mt_rand() / mt_getrandmax() < 0.90
                                ? AttendanceStatus::Attended->value
                                : AttendanceStatus::NoShow->value;
                        }

                        if (! $isPastCancelled && $attendance === AttendanceStatus::Attended->value) {
                            $pastAttendedPids[] = $pid;
                        }

                        $sessionJoinSource = $joinSource;
                        if ($pid !== $gm['id'] && mt_rand() / mt_getrandmax() < 0.20) {
                            $sessionJoinSource = $this->joinSourceForVisibility($visibility->value, false);
                        }

                        $gameParticipantBatch[] = [
                            'id' => (string) Str::orderedUuid(),
                            'game_id' => $pId,
                            'user_id' => $pid,
                            'role' => $pid === $gm['id'] ? ParticipantRole::Owner->value : ParticipantRole::Player->value,
                            'status' => ParticipantStatus::Approved->value,
                            'attendance_status' => $attendance,
                            'join_source' => $sessionJoinSource,
                            'invitee_email' => $pid !== $gm['id'] ? $this->inviteeEmailForSource($pid, $sessionJoinSource) : null,
                            'benched_at' => null,
                            'waitlisted_at' => null,
                            'created_at' => $pastDate->copy()->subDays(random_int(1, 5)),
                        ];

                        if ($pid !== $gm['id']) {
                            $gameApplicationBatch[] = [
                                'id' => (string) Str::orderedUuid(),
                                'game_id' => $pId,
                                'user_id' => $pid,
                                'status' => ParticipantStatus::Approved->value,
                                'message' => null,
                                'created_at' => $pastDate->copy()->subDays(random_int(3, 10)),
                                'updated_at' => $pastDate->copy()->subDays(random_int(1, 5)),
                            ];
                        }
                    }
                    $totalSessions++;

                    if (! $isPastCancelled) {
                        // Feed completed past sessions into review generation
                        $this->completedGames[] = [
                            'game_id' => $pId,
                            'owner_id' => $gm['id'],
                            'participant_ids' => $pastAttendedPids,
                            'language' => $language,
                            'is_session_zero' => false,
                        ];

                        // Queue attendance reports for attended players
                        foreach ($pastAttendedPids as $pid) {
                            if ($pid === $gm['id']) {
                                continue;
                            }
                            $this->attendanceReportQueue[] = [
                                'game_id' => $pId,
                                'owner_id' => $gm['id'],
                                'reported_id' => $pid,
                                'attendance' => AttendanceStatus::Attended->value,
                            ];
                        }
                        // Also generate NoShow reports for no-show players
                        $noShowPids = array_values(array_filter(
                            $campaignParticipants,
                            fn ($pid) => $pid !== $gm['id'] && ! in_array($pid, $pastAttendedPids)
                        ));
                        foreach ($noShowPids as $pid) {
                            $this->attendanceReportQueue[] = [
                                'game_id' => $pId,
                                'owner_id' => $gm['id'],
                                'reported_id' => $pid,
                                'attendance' => AttendanceStatus::NoShow->value,
                            ];
                        }
                    }

                    // Track non-cancelled public/protected past sessions for short links
                    if ($pStatus !== GameStatus::Canceled->value && $visibility !== Visibility::Private) {
                        $this->campaignSessionGames[] = ['game_id' => $pId, 'owner_id' => $gm['id']];
                    }
                }

                // --- Future sessions (3-5, only for active campaigns) ---
                $futureCount = $campaignStatus === 'active' ? random_int(3, 5) : random_int(0, 1);
                for ($f = 1; $f <= $futureCount; $f++) {
                    $futureDate = now()->addDays($f * $interval)->setTime(random_int(14, 20), random_int(0, 3) * 15);
                    $sId = (string) Str::orderedUuid();

                    $sessionLabel = $language === 'de' ? "Sitzung {$f}" : "Session {$f}";
                    $sessionDesc = $language === 'de'
                        ? "Kampagnensitzung {$f}. ".self::MARKER
                        : "Campaign session {$f}. ".self::MARKER;

                    // Cancelled campaigns have all future sessions cancelled too
                    if ($campaignStatus === 'cancelled') {
                        $fStatus = GameStatus::Canceled->value;
                    } else {
                        // ~3% of future sessions are cancelled
                        $fStatus = mt_rand() / mt_getrandmax() < 0.03
                            ? GameStatus::Canceled->value
                            : GameStatus::Scheduled->value;
                    }

                    $gameBatch[] = [
                        'id' => $sId,
                        'owner_id' => $gm['id'],
                        'campaign_id' => $campaignId,
                        'game_system_id' => $rpg['id'],
                        'name' => json_encode([$language => $sessionLabel.' '.self::MARKER]),
                        'description' => json_encode([$language => $sessionDesc]),
                        'game_type' => GameType::Ttrpg->value,
                        'date_time' => $futureDate,
                        'expected_duration' => random_int(3, 5),
                        'location_id' => $locationId,
                        'location' => $this->buildLocationJson($locationId),
                        'status' => $fStatus,
                        'visibility' => $visibility->value,
                        'experience_level' => $campaignExpLevel,
                        'min_players' => $minPlayers,
                        'max_players' => $maxPlayers,
                        'bench_mode' => $isBench,
                        'vibe_flags' => json_encode($this->randomVibes('ttrpg')),
                        'safety_rules' => json_encode(['tools' => ['lines_and_veils', 'x-card']]),
                        'language' => $language,
                        'recap' => null,
                        'created_at' => $campaignCreatedAt->copy()->addDays(random_int(5, 12)),
                        'updated_at' => now(),
                    ];

                    foreach ($campaignParticipants as $pid) {
                        // Diversify join sources: ~80% use campaign's join source, ~20% get a random valid one
                        $sessionJoinSource = $joinSource;
                        if ($pid !== $gm['id'] && mt_rand() / mt_getrandmax() < 0.20) {
                            $sessionJoinSource = $this->joinSourceForVisibility($visibility->value, false);
                        }

                        $gameParticipantBatch[] = [
                            'id' => (string) Str::orderedUuid(),
                            'game_id' => $sId,
                            'user_id' => $pid,
                            'role' => $pid === $gm['id'] ? ParticipantRole::Owner->value : ParticipantRole::Player->value,
                            'status' => ParticipantStatus::Approved->value,
                            'attendance_status' => null,
                            'join_source' => $sessionJoinSource,
                            'invitee_email' => $pid !== $gm['id'] ? $this->inviteeEmailForSource($pid, $sessionJoinSource) : null,
                            'benched_at' => null,
                            'waitlisted_at' => null,
                            'created_at' => $campaignCreatedAt->copy()->addDays(random_int(5, 12)),
                        ];

                        // Game application for each campaign session participant (skip GM — owners don't apply)
                        if ($pid !== $gm['id']) {
                            $gameApplicationBatch[] = [
                                'id' => (string) Str::orderedUuid(),
                                'game_id' => $sId,
                                'user_id' => $pid,
                                'status' => ParticipantStatus::Approved->value,
                                'message' => null,
                                'created_at' => $campaignCreatedAt->copy()->addDays(random_int(3, 10)),
                                'updated_at' => $campaignCreatedAt->copy()->addDays(random_int(5, 12)),
                            ];
                        }
                    }

                    // Add 1-2 pending applications from non-members for active campaigns
                    if ($campaignStatus === 'active' && $fStatus !== GameStatus::Canceled->value) {
                        $nonMembers = array_values(array_diff($this->playerIds, $campaignParticipants));
                        $pendingCount = random_int(0, 2);
                        $pendingKeys = $this->randomKeys($nonMembers, min($pendingCount, count($nonMembers)));
                        foreach ($pendingKeys as $k) {
                            $pStatus = mt_rand() / mt_getrandmax() < 0.30 ? 'rejected' : 'pending';
                            $msgPool = [
                                'en' => ['Would love to join this campaign!', 'Experienced player, interested!', null, null],
                                'de' => ['Würde gerne der Kampagne beitreten!', 'Erfahrener Spieler, Interesse!', null, null],
                            ];
                            $msgs = $msgPool[$language];
                            $gameApplicationBatch[] = [
                                'id' => (string) Str::orderedUuid(),
                                'game_id' => $sId,
                                'user_id' => $nonMembers[(int) $k],
                                'status' => $pStatus,
                                'message' => $msgs[array_rand($msgs)],
                                'created_at' => now()->subDays(random_int(1, 3)),
                                'updated_at' => $pStatus === 'rejected' ? now()->subDays(random_int(0, 2)) : null,
                            ];
                        }
                    }
                    $totalSessions++;

                    // Track non-cancelled public/protected future sessions for short links
                    if ($fStatus !== GameStatus::Canceled->value && $visibility !== Visibility::Private) {
                        $this->campaignSessionGames[] = ['game_id' => $sId, 'owner_id' => $gm['id']];
                    }

                    // Flush large batches — games before participants for FK
                    $needFlush = count($gameParticipantBatch) >= 500
                        || count($gameApplicationBatch) >= 500
                        || count($gameBatch) >= 500;
                    if ($needFlush) {
                        $this->dryInsertMany('games', $gameBatch);
                        $gameBatch = [];
                        $this->dryInsertMany('game_participants', $gameParticipantBatch);
                        $gameParticipantBatch = [];
                        if (! empty($gameApplicationBatch)) {
                            $this->dryInsertMany('game_applications', $gameApplicationBatch);
                            $gameApplicationBatch = [];
                        }
                    }
                }

                $totalCampaigns++;
                $flushAll();
            }

            $bar->advance();
        }

        // Flush remaining batches
        $flushAll();

        $bar->finish();
        $this->newLine();
        $this->info("Created {$totalCampaigns} campaigns with {$totalSessions} sessions.");
    }

    // =========================================================================
    // SCHEDULED BOARD GAME SESSIONS
    // =========================================================================

    private function createScheduledBoardGameSessions(): void
    {
        $this->newLine();
        $this->info('Creating scheduled (future) board game sessions...');
        if (empty($this->boardGamePool)) {
            $this->warn('No board game systems in pool. Skipping.');

            return;
        }

        $bar = $this->output->createProgressBar(count($this->gmInfo));
        $bar->setRedrawFrequency(50);
        $bar->start();

        $totalScheduled = 0;
        /** @var list<array<string, mixed>> $gameBatch */
        $gameBatch = [];
        /** @var list<array<string, mixed>> $participantBatch */
        $participantBatch = [];
        /** @var list<array<string, mixed>> $applicationBatch */
        $applicationBatch = [];

        foreach ($this->gmInfo as $gm) {
            $futureCount = random_int(1, 3);
            $city = $gm['city'];
            $cityLocs = $this->locationsByCity[$city] ?? [];

            for ($f = 0; $f < $futureCount; $f++) {
                $game = $this->boardGamePool[array_rand($this->boardGamePool)];

                $maxPlayers = random_int($game['min'], $game['max']);
                $minPlayers = max($game['min'], $maxPlayers - 2);
                $isBench = mt_rand() / mt_getrandmax() < 0.20;
                $locationId = $cityLocs[array_rand($cityLocs)] ?? null;

                // Visibility: same distribution as completed sessions
                $roll = mt_rand() / mt_getrandmax();
                $visibility = $roll < 0.25 ? Visibility::Public
                    : ($roll < 0.60 ? Visibility::Protected : Visibility::Private);

                $daysAhead = random_int(1, 30);
                $dateTime = Carbon::now()->addDays($daysAhead)->setTime(random_int(14, 20), random_int(0, 3) * 15);

                $language = random_int(0, 2) > 0 ? 'de' : 'en';
                $gameId = (string) Str::orderedUuid();
                $suffixes = self::SESSION_SUFFIXES[$language];
                $suffix = $suffixes[array_rand($suffixes)];
                $descs = self::BOARD_DESCRIPTIONS[$language];

                // ~5% of scheduled sessions are cancelled
                $status = mt_rand() / mt_getrandmax() < 0.05
                    ? GameStatus::Canceled->value
                    : GameStatus::Scheduled->value;

                $gameBatch[] = [
                    'id' => $gameId,
                    'owner_id' => $gm['id'],
                    'campaign_id' => null,
                    'game_system_id' => $game['id'],
                    'name' => json_encode([$language => $game['name'].' '.$suffix.' '.self::MARKER]),
                    'description' => json_encode([$language => $descs[array_rand($descs)].' '.self::MARKER]),
                    'game_type' => GameType::BoardGame->value,
                    'date_time' => $dateTime,
                    'expected_duration' => round($game['dur'] / 60, 1),
                    'location_id' => $locationId,
                    'location' => $this->buildLocationJson($locationId),
                    'status' => $status,
                    'visibility' => $visibility->value,
                    'experience_level' => ExperienceLevel::All->value,
                    'min_players' => $minPlayers,
                    'max_players' => $maxPlayers,
                    'bench_mode' => $isBench,
                    'vibe_flags' => json_encode($this->randomVibes('board')),
                    'safety_rules' => json_encode([]),
                    'language' => $language,
                    'recap' => null,
                    'created_at' => now()->subDays(random_int(1, 7)),
                    'updated_at' => now(),
                ];

                // Select participants — fewer for scheduled sessions (people sign up over time)
                $playerCount = random_int(max(1, $minPlayers - 2), max(1, $maxPlayers - 1));
                $selected = $this->selectParticipants($gm['id'], $playerCount, $visibility->value);
                $joinSource = $this->joinSourceForVisibility($visibility->value);

                // GM as owner
                $participantBatch[] = [
                    'id' => (string) Str::orderedUuid(),
                    'game_id' => $gameId,
                    'user_id' => $gm['id'],
                    'role' => ParticipantRole::Owner->value,
                    'status' => ParticipantStatus::Approved->value,
                    'attendance_status' => null,
                    'join_source' => 'application',
                    'invitee_email' => null,
                    'benched_at' => null,
                    'waitlisted_at' => null,
                    'created_at' => now()->subDays(random_int(1, 5)),
                ];

                // No application for GM — owners create the game, they don't apply to join

                foreach ($selected as $pid) {
                    $participantBatch[] = [
                        'id' => (string) Str::orderedUuid(),
                        'game_id' => $gameId,
                        'user_id' => $pid,
                        'role' => ParticipantRole::Player->value,
                        'status' => ParticipantStatus::Approved->value,
                        'attendance_status' => null,
                        'join_source' => $joinSource,
                        'invitee_email' => $this->inviteeEmailForSource($pid, $joinSource),
                        'benched_at' => null,
                        'waitlisted_at' => null,
                        'created_at' => now()->subDays(random_int(1, 5)),
                    ];

                    $applicationBatch[] = [
                        'id' => (string) Str::orderedUuid(),
                        'game_id' => $gameId,
                        'user_id' => $pid,
                        'status' => ParticipantStatus::Approved->value,
                        'message' => null,
                        'created_at' => now()->subDays(random_int(3, 10)),
                        'updated_at' => now()->subDays(random_int(1, 5)),
                    ];
                }

                // Add 1-3 pending applications (users waiting to be approved/rejected)
                $pendingCount = random_int(1, 3);
                $pendingPool = array_values(array_diff($this->playerIds, $selected, [$gm['id']]));
                $pendingKeys = $this->randomKeys($pendingPool, min($pendingCount, count($pendingPool)));
                $pendingMessages = [
                    'en' => ['Looking forward to joining!', 'First time player, excited!', 'Sounds like fun, count me in!', null, null],
                    'de' => ['Freue mich darauf!', 'Erstes Mal, bin gespannt!', 'Klingt toll, bin dabei!', null, null],
                ];
                foreach ($pendingKeys as $k) {
                    $pStatus = mt_rand() / mt_getrandmax() < 0.20 ? 'rejected' : 'pending';
                    $msgPool = $pendingMessages[$language];
                    $applicationBatch[] = [
                        'id' => (string) Str::orderedUuid(),
                        'game_id' => $gameId,
                        'user_id' => $pendingPool[(int) $k],
                        'status' => $pStatus,
                        'message' => $msgPool[array_rand($msgPool)],
                        'created_at' => now()->subDays(random_int(1, 5)),
                        'updated_at' => $pStatus === 'rejected' ? now()->subDays(random_int(0, 3)) : null,
                    ];
                }

                // Flush all batches — games before participants/applications for FK
                $needFlush = count($participantBatch) >= 500
                    || count($applicationBatch) >= 500
                    || count($gameBatch) >= 500;
                if ($needFlush) {
                    $this->dryInsertMany('games', $gameBatch);
                    $gameBatch = [];
                    $this->dryInsertMany('game_participants', $participantBatch);
                    $participantBatch = [];
                    if (! empty($applicationBatch)) {
                        $this->dryInsertMany('game_applications', $applicationBatch);
                        $applicationBatch = [];
                    }
                }

                $totalScheduled++;

                // Track for short link creation (non-canceled public/protected only)
                if ($status !== GameStatus::Canceled->value && $visibility !== Visibility::Private) {
                    $this->scheduledBoardGames[] = ['game_id' => $gameId, 'owner_id' => $gm['id']];
                }
            }

            $bar->advance();
        }

        if (! empty($gameBatch)) {
            $this->dryInsertMany('games', $gameBatch);
        }
        if (! empty($participantBatch)) {
            $this->dryInsertMany('game_participants', $participantBatch);
        }
        if (! empty($applicationBatch)) {
            $this->dryInsertMany('game_applications', $applicationBatch);
        }

        $bar->finish();
        $this->newLine();
        $this->info("Created {$totalScheduled} scheduled board game sessions.");
    }

    // =========================================================================
    // USER PREFERENCES
    // =========================================================================

    private function seedUserPreferences(): void
    {
        $this->newLine();
        $this->info('Seeding user preferences...');

        $allSystemIds = array_merge($this->resolvedBoardIds, $this->resolvedTtrpgIds);
        if (empty($allSystemIds)) {
            $this->warn('No game systems resolved. Skipping preferences.');

            return;
        }

        $validVibes = VibeFlag::values();
        $batchSystem = [];
        $batchVibe = [];
        $totalSystem = 0;
        $totalVibe = 0;

        // Seed for all users in batches of 500
        $chunks = array_chunk($this->allUserIds, 500);
        $bar = $this->output->createProgressBar(count($chunks));
        $bar->start();

        foreach ($chunks as $chunk) {
            $seenPairs = []; // dedup per chunk

            foreach ($chunk as $uid) {
                // 2-5 game system favorites, 0-1 avoids
                $favCount = random_int(2, 5);
                $favKeys = $this->randomKeys($allSystemIds, min($favCount, count($allSystemIds)));
                foreach ($favKeys as $k) {
                    $sid = $allSystemIds[$k];
                    $pairKey = $uid.':sys:'.$sid;
                    if (isset($seenPairs[$pairKey])) {
                        continue;
                    }
                    $seenPairs[$pairKey] = true;
                    $batchSystem[] = [
                        'user_id' => $uid,
                        'game_system_id' => $sid,
                        'preference_type' => 'favorite',
                    ];
                }

                // Maybe 1 avoid
                if (mt_rand() / mt_getrandmax() < 0.15) {
                    $avoidKey = array_rand($allSystemIds);
                    $sid = $allSystemIds[$avoidKey];
                    $pairKey = $uid.':sys:'.$sid;
                    if (! isset($seenPairs[$pairKey])) {
                        $seenPairs[$pairKey] = true;
                        $batchSystem[] = [
                            'user_id' => $uid,
                            'game_system_id' => $sid,
                            'preference_type' => 'avoid',
                        ];
                    }
                }

                // 3-6 vibe favorites, 0-2 avoids
                $vibeFavCount = random_int(3, 6);
                $vibeKeys = $this->randomKeys($validVibes, min($vibeFavCount, count($validVibes)));
                foreach ($vibeKeys as $k) {
                    $v = $validVibes[$k];
                    $pairKey = $uid.':vibe:'.$v;
                    if (isset($seenPairs[$pairKey])) {
                        continue;
                    }
                    $seenPairs[$pairKey] = true;
                    $batchVibe[] = [
                        'user_id' => $uid,
                        'vibe_preference_value' => $v,
                        'preference_type' => 'favorite',
                    ];
                }

                if (mt_rand() / mt_getrandmax() < 0.20) {
                    $avoidCount = random_int(1, 2);
                    $avoidKeys = $this->randomKeys($validVibes, min($avoidCount, count($validVibes)));
                    foreach ($avoidKeys as $k) {
                        $v = $validVibes[$k];
                        $pairKey = $uid.':vibe:'.$v;
                        if (! isset($seenPairs[$pairKey])) {
                            $seenPairs[$pairKey] = true;
                            $batchVibe[] = [
                                'user_id' => $uid,
                                'vibe_preference_value' => $v,
                                'preference_type' => 'avoid',
                            ];
                        }
                    }
                }
            }

            if (! empty($batchSystem)) {
                $this->dryInsertMany('user_game_system_preferences', $batchSystem);
                $totalSystem += count($batchSystem);
                $batchSystem = [];
            }
            if (! empty($batchVibe)) {
                $this->dryInsertMany('user_vibe_preferences', $batchVibe);
                $totalVibe += count($batchVibe);
                $batchVibe = [];
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Seeded {$totalSystem} game system preferences and {$totalVibe} vibe preferences.");
    }

    // =========================================================================
    // GM SOCIAL LINKS
    // =========================================================================

    private function seedGmSocialLinks(): void
    {
        $this->newLine();
        $this->info('Seeding GM social links...');

        // Platforms from config — only use platforms that exist and don't require instance
        $platforms = config('platforms', []);
        $availablePlatforms = array_filter(is_array($platforms) ? $platforms : [], fn ($p) => is_array($p) && empty($p['instance_required']));
        $platformKeys = array_keys($availablePlatforms);

        if (empty($platformKeys)) {
            $this->warn('No platforms configured. Skipping GM social links.');

            return;
        }

        $batch = [];
        $bar = $this->output->createProgressBar(count($this->gmInfo));
        $bar->start();

        foreach ($this->gmInfo as $gm) {
            $linkCount = random_int(1, min(3, count($platformKeys)));
            $keys = $this->randomKeys($platformKeys, $linkCount);
            $seenPlatforms = []; // Dedup per GM to avoid unique constraint violation

            foreach ($keys as $k) {
                $platform = $platformKeys[(int) $k];
                if (isset($seenPlatforms[$platform])) {
                    continue;
                }
                $seenPlatforms[$platform] = true;
                $handleLen = random_int(4, 20);
                $handle = strtolower(Str::random($handleLen));

                // Generate URL from config template
                $urlTemplate = is_string($availablePlatforms[$platform]['url_template'] ?? null) ? $availablePlatforms[$platform]['url_template'] : null;
                $url = $urlTemplate ? str_replace('{handle}', $handle, $urlTemplate) : null;

                $batch[] = [
                    'id' => $this->nextRowId('gm_social_links'),
                    'user_id' => $gm['id'],
                    'platform' => $platform,
                    'handle' => $handle,
                    'instance' => null,
                    'url' => $url,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if (count($batch) >= 500) {
                $this->dryInsertMany('gm_social_links', $batch);
                $batch = [];
            }

            $bar->advance();
        }

        if (! empty($batch)) {
            $this->dryInsertMany('gm_social_links', $batch);
        }

        $bar->finish();
        $this->newLine();
        $this->info('Seeded GM social links.');
    }

    // =========================================================================
    // SESSION ZERO SURVEYS
    // =========================================================================

    private function createSessionZeroSurveys(): void
    {
        if (empty($this->completedSessionZeros)) {
            return;
        }

        $this->newLine();
        $this->info('Creating session zero surveys...');
        /** @var list<array<string, mixed>> $surveyBatch */
        $surveyBatch = [];
        $confirmationBatch = [];

        $bar = $this->output->createProgressBar(count($this->completedSessionZeros));
        $bar->start();

        foreach ($this->completedSessionZeros as $sz) {
            $gmProfileId = $this->gmProfileIds[$sz['owner_id']] ?? null;
            if (! $gmProfileId) {
                $bar->advance();

                continue;
            }

            $language = $sz['language'];
            $title = $language === 'de'
                ? 'Session Zero: Erwartungen & Sicherheit '.self::MARKER
                : 'Session Zero: Expectations & Safety '.self::MARKER;

            $surveyId = (string) Str::orderedUuid();
            $participantIds = $sz['participant_ids'];

            // 60-80% of participants confirm the session zero
            $confirmRate = random_int(60, 80) / 100;
            $confirmCount = max(1, (int) ceil(count($participantIds) * $confirmRate));
            $confirmKeys = $this->randomKeys($participantIds, min($confirmCount, count($participantIds)));

            $surveyBatch[] = [
                'id' => $surveyId,
                'gm_profile_id' => $gmProfileId,
                'game_id' => $sz['game_id'],
                'title' => $title,
                'content' => json_encode(['tools_discussed' => ['session_zero', 'lines_and_veils', 'x-card']]),
                'uuid' => (string) Str::uuid(),
                'status' => 'active',
                'confirmation_count' => count($confirmKeys),
                'created_at' => now()->subDays(random_int(3, 14)),
                'updated_at' => now(),
            ];

            foreach ($confirmKeys as $k) {
                $confirmationBatch[] = [
                    'id' => (string) Str::orderedUuid(),
                    'session_zero_survey_id' => $surveyId,
                    'confirmed_at' => now()->subDays(random_int(1, 10)),
                    'user_id' => $participantIds[(string) $k],
                ];
            }

            if (count($confirmationBatch) >= 500 || count($surveyBatch) >= 500) {
                $this->dryInsertMany('session_zero_surveys', $surveyBatch);
                $surveyBatch = [];
                if ($confirmationBatch !== []) {
                    $this->dryInsertMany('session_zero_confirmations', $confirmationBatch);
                    $confirmationBatch = [];
                }
            }

            $bar->advance();
        }

        if (! empty($surveyBatch)) {
            $this->dryInsertMany('session_zero_surveys', $surveyBatch);
        }
        if (! empty($confirmationBatch)) {
            $this->dryInsertMany('session_zero_confirmations', $confirmationBatch);
        }

        $bar->finish();
        $this->newLine();
        $this->info('Created session zero surveys with confirmations.');
    }

    // =========================================================================
    // SHORT LINKS
    // =========================================================================

    private function createShortLinks(): void
    {
        $this->newLine();
        $this->info('Creating short links for games...');

        $batch = [];
        $total = 0;

        // Create short links for a subset of public and protected games
        // Completed games + scheduled board game sessions + campaign sessions
        $gameIds = [];
        foreach ($this->completedGames as $g) {
            $gameIds[] = ['id' => $g['game_id'], 'owner_id' => $g['owner_id']];
        }
        // Include scheduled board game sessions
        foreach ($this->scheduledBoardGames as $g) {
            $gameIds[] = ['id' => $g['game_id'], 'owner_id' => $g['owner_id']];
        }
        // Include public/protected campaign session games
        foreach ($this->campaignSessionGames as $g) {
            $gameIds[] = ['id' => $g['game_id'], 'owner_id' => $g['owner_id']];
        }
        // Pick ~30% of eligible games to have short links
        shuffle($gameIds);
        $selected = array_slice($gameIds, 0, max(1, (int) (count($gameIds) * 0.30)));

        $usedCodes = [];
        foreach ($selected as $game) {
            // Generate a unique short code (6-8 alphanumeric chars)
            do {
                $code = strtoupper(Str::random(random_int(6, 8)));
            } while (isset($usedCodes[$code]));
            $usedCodes[$code] = true;

            $batch[] = [
                'id' => $this->nextRowId('short_links'),
                'code' => $code,
                'url' => '/games/'.$game['id'],
                'linkable_type' => Game::class,
                'linkable_id' => $game['id'],
                'user_id' => $game['owner_id'],
                'label' => 'Demo share link '.self::MARKER,
                'purpose' => 'share',
                'expires_at' => null,
                'max_hits' => null,
                'hit_count' => random_int(0, 25),
                'last_hit_at' => mt_rand() / mt_getrandmax() < 0.5 ? now()->subDays(random_int(1, 30)) : null,
                'deleted_at' => null,
                'created_at' => now()->subDays(random_int(1, 30)),
                'updated_at' => now(),
            ];

            if (count($batch) >= 500) {
                $this->dryInsertMany('short_links', $batch);
                $total += count($batch);
                $batch = [];
            }
        }

        if (! empty($batch)) {
            $this->dryInsertMany('short_links', $batch);
            $total += count($batch);
        }

        $this->info("Created {$total} short links for games.");
    }

    // =========================================================================
    // SHORT LINK ATTRIBUTION
    // =========================================================================

    /**
     * Link participants with join_source='short_link' to their game's short link.
     * Short links are created after participants, so we do a post-hoc UPDATE.
     */
    private function assignShortLinkAttribution(): void
    {
        if ($this->dryRun || empty($this->allUserIds)) {
            return;
        }

        $this->newLine();
        $this->info('Attributing short link joins...');

        // Process in chunks to avoid oversized IN clauses
        $totalGameUpdated = 0;
        $totalCampaignUpdated = 0;

        // Collect all demo game and campaign IDs for short link matching
        $demoGameIds = DB::table('games')->whereIn('owner_id', $this->allUserIds)->pluck('id');
        $demoCampaignIds = DB::table('campaigns')->whereIn('owner_id', $this->allUserIds)->pluck('id');

        // Game participants
        foreach ($demoGameIds->chunk(500) as $chunk) {
            $updated = DB::update(
                "UPDATE game_participants gp
                    SET short_link_id = sl.id
                    FROM short_links sl
                    WHERE gp.join_source = 'short_link'
                      AND gp.short_link_id IS NULL
                      AND sl.linkable_type = ?
                      AND sl.linkable_id = gp.game_id
                      AND gp.game_id IN (".implode(',', array_fill(0, count($chunk), '?')).')',
                array_merge([Game::class], $chunk->toArray())
            );
            $totalGameUpdated += $updated;
        }

        // Campaign participants
        foreach ($demoCampaignIds->chunk(500) as $chunk) {
            $updated = DB::update(
                "UPDATE campaign_participants cp
                    SET short_link_id = sl.id
                    FROM short_links sl
                    WHERE cp.join_source = 'short_link'
                      AND cp.short_link_id IS NULL
                      AND sl.linkable_type = ?
                      AND sl.linkable_id = cp.campaign_id
                      AND cp.campaign_id IN (".implode(',', array_fill(0, count($chunk), '?')).')',
                array_merge([Campaign::class], $chunk->toArray())
            );
            $totalCampaignUpdated += $updated;
        }

        if ($totalGameUpdated > 0 || $totalCampaignUpdated > 0) {
            $this->info("Attributed {$totalGameUpdated} game + {$totalCampaignUpdated} campaign short link joins.");
        }
    }

    // =========================================================================
    // GM AGGREGATES
    // =========================================================================

    private function updateGmAggregates(): void
    {
        $this->info('Updating GM profile aggregates...');
        $profileIds = array_values($this->gmProfileIds);

        if (empty($profileIds)) {
            return;
        }

        // Single aggregate query to get stats for all GM profiles at once
        $stats = DB::table('reviews')
            ->selectRaw('gm_profile_id, COUNT(*) as cnt, ROUND(AVG(rating), 2) as avg')
            ->where('status', 'published')
            ->whereIn('gm_profile_id', $profileIds)
            ->groupBy('gm_profile_id')
            ->get()
            ->keyBy('gm_profile_id');

        // Batched updates using a VALUES CTE joined against gm_profiles.
        // This avoids N individual queries while respecting NOT NULL constraints.
        foreach (array_chunk($profileIds, 100) as $chunk) {

            // Build values list for the CTE with explicit type casts
            $rows = [];
            $bindings = [];
            foreach ($chunk as $profileId) {
                $s = $stats->get($profileId);
                $avgRating = $s ? $s->avg : null;
                $cnt = $s ? (int) $s->cnt : 0;
                $rows[] = '(?::uuid, ?::numeric, ?::integer)';
                $bindings[] = $profileId;
                $bindings[] = $avgRating;
                $bindings[] = $cnt;
            }
            $valuesSql = implode(', ', $rows);

            DB::update(
                "UPDATE gm_profiles p SET
                    average_rating = v.avg_rating,
                    review_count = v.cnt,
                    updated_at = NOW()
                FROM (VALUES {$valuesSql}) AS v(id, avg_rating, cnt)
                WHERE p.id = v.id",
                $bindings
            );
        }

        $this->info('GM aggregates updated ('.count($profileIds).' profiles).');
    }

    // =========================================================================
    // NOTIFICATIONS
    // =========================================================================

    private function seedNotifications(): void
    {
        $this->newLine();
        $this->info('Seeding notifications...');

        $batch = [];
        $total = 0;

        // Notification types and their sample data
        $types = [
            'App\\Notifications\\GameInvitation' => ['game_name', 'inviter_name'],
            'App\\Notifications\\ApplicationApproved' => ['game_name'],
            'App\\Notifications\\SessionReminder' => ['game_name', 'hours_until'],
            'App\\Notifications\\NewReview' => ['rating'],
            'App\\Notifications\\CampaignInvite' => ['campaign_name'],
        ];

        // Pick ~15% of all users to have 1-4 notifications each
        $notifiedCount = max(1, (int) (count($this->allUserIds) * 0.15));
        $shuffled = $this->allUserIds;
        shuffle($shuffled);
        $notifiedUsers = array_slice($shuffled, 0, $notifiedCount);

        foreach ($notifiedUsers as $uid) {
            $count = random_int(1, 4);
            for ($n = 0; $n < $count; $n++) {
                $type = array_rand($types);
                $data = [];
                foreach ($types[$type] as $field) {
                    $data[$field] = $field === 'rating' ? random_int(2, 5)
                        : ($field === 'hours_until' ? random_int(1, 24)
                        : 'Demo '.$field.' '.self::MARKER);
                }

                $batch[] = [
                    'id' => (string) Str::uuid(),
                    'type' => $type,
                    'notifiable_type' => User::class,
                    'notifiable_id' => $uid,
                    'data' => json_encode($data),
                    'read_at' => mt_rand() / mt_getrandmax() < 0.60 ? now()->subDays(random_int(0, 7)) : null,
                    'created_at' => now()->subDays(random_int(0, 14)),
                    'updated_at' => now(),
                ];

                if (count($batch) >= 500) {
                    $this->dryInsertMany('notifications', $batch);
                    $total += count($batch);
                    $batch = [];
                }
            }
        }

        if (! empty($batch)) {
            $this->dryInsertMany('notifications', $batch);
            $total += count($batch);
        }

        $this->info("Seeded {$total} notifications for {$notifiedCount} users.");
    }

    // =========================================================================
    // ATTENDANCE REPORTS
    // =========================================================================

    private function createAttendanceReports(): void
    {
        if (empty($this->attendanceReportQueue)) {
            return;
        }

        $this->newLine();
        $this->info('Creating attendance reports...');
        $batch = [];
        $total = 0;

        $bar = $this->output->createProgressBar(count($this->attendanceReportQueue));
        $bar->setRedrawFrequency(200);
        $bar->start();

        foreach ($this->attendanceReportQueue as $entry) {
            $attendanceStatus = $entry['attendance'];
            $weight = $attendanceStatus === AttendanceStatus::Attended->value ? 1.0 : -0.5;

            $batch[] = [
                'id' => (string) Str::orderedUuid(),
                'game_id' => $entry['game_id'],
                'reporter_id' => $entry['owner_id'],
                'reported_id' => $entry['reported_id'],
                'status' => $attendanceStatus,
                'weight_applied' => $weight,
                'is_corroborated' => $attendanceStatus === AttendanceStatus::Attended->value
                    ? mt_rand() / mt_getrandmax() < 0.30
                    : false,
                'quarantined' => false,
                'created_at' => now()->subDays(random_int(1, 30)),
                'updated_at' => now(),
            ];
            $total++;

            if (count($batch) >= 500) {
                $this->dryInsertMany('attendance_reports', $batch);
                $batch = [];
            }
            $bar->advance();
        }

        if (! empty($batch)) {
            $this->dryInsertMany('attendance_reports', $batch);
        }

        $bar->finish();
        $this->newLine();
        $this->info("Created {$total} attendance reports.");
    }

    // =========================================================================
    // SESSION DEBRIEFINGS
    // =========================================================================

    private function createSessionDebriefings(): void
    {
        if (empty($this->completedGames)) {
            return;
        }

        $this->newLine();
        $this->info('Creating session debriefings...');
        $batch = [];
        $total = 0;
        $toolTypes = ['debriefing', 'stars-and-wishes'];

        $bar = $this->output->createProgressBar(count($this->completedGames));
        $bar->setRedrawFrequency(200);
        $bar->start();

        foreach ($this->completedGames as $game) {
            // ~30% of completed sessions get debriefings from 1-3 participants
            if (mt_rand() / mt_getrandmax() > 0.30) {
                $bar->advance();

                continue;
            }

            $participants = array_values(array_filter(
                $game['participant_ids'],
                fn ($pid) => $pid !== $game['owner_id']
            ));

            if (empty($participants)) {
                $bar->advance();

                continue;
            }

            $debriefCount = random_int(1, min(3, count($participants)));
            $debriefKeys = $this->randomKeys($participants, $debriefCount);

            foreach ($debriefKeys as $k) {
                $toolType = $toolTypes[array_rand($toolTypes)];

                $language = $game['language'];
                $responses = $toolType === 'debriefing'
                    ? [
                        'what_worked' => $language === 'de'
                            ? 'Gute Dynamik in der Gruppe.'
                            : 'Good group dynamics this session.',
                        'what_improved' => $language === 'de'
                            ? 'Tempo könnte etwas schneller sein.'
                            : 'Pacing could be a bit tighter.',
                    ]
                    : [
                        'stars' => $language === 'de'
                            ? 'Weltbau war faszinierend.'
                            : 'Worldbuilding was captivating.',
                        'wishes' => $language === 'de'
                            ? 'Mehr Exploration nächste Session.'
                            : 'More exploration next session.',
                    ];

                $batch[] = [
                    'id' => (string) Str::orderedUuid(),
                    'game_id' => $game['game_id'],
                    'user_id' => $participants[(int) $k],
                    'tool_type' => $toolType,
                    'responses' => json_encode($responses),
                    'submitted_at' => now()->subDays(random_int(1, 14)),
                    'created_at' => now()->subDays(random_int(3, 21)),
                    'updated_at' => now(),
                ];
                $total++;

                if (count($batch) >= 500) {
                    $this->dryInsertMany('session_debriefings', $batch);
                    $batch = [];
                }
            }

            $bar->advance();
        }

        if (! empty($batch)) {
            $this->dryInsertMany('session_debriefings', $batch);
        }

        $bar->finish();
        $this->newLine();
        $this->info("Created {$total} session debriefings.");
    }

    // =========================================================================
    // ACTIVITY LOGS
    // =========================================================================

    private function seedActivityLogs(): void
    {
        $this->newLine();
        $this->info('Seeding activity logs...');

        $batch = [];
        $total = 0;

        // Activity events to generate, each with a builder function
        $eventBuilders = [
            // game_created: GM creates a game
            fn (string $uid) => [
                'user_id' => $uid,
                'subject_type' => Game::class,
                'subject_id' => null,
                'event_type' => ActivityType::GameCreated->value,
                'properties' => json_encode(['game_name' => 'Demo game '.self::MARKER]),
                'created_at' => now()->subDays(random_int(3, 60)),
            ],
            // player_joined: player joins a game
            fn (string $uid) => [
                'user_id' => $uid,
                'subject_type' => Game::class,
                'subject_id' => null,
                'event_type' => ActivityType::PlayerJoined->value,
                'properties' => json_encode(['game_name' => 'Demo game '.self::MARKER]),
                'created_at' => now()->subDays(random_int(1, 30)),
            ],
            // follow_received: user gained a follower
            fn (string $uid) => [
                'user_id' => $uid,
                'subject_type' => User::class,
                'subject_id' => null,
                'event_type' => ActivityType::FollowReceived->value,
                'properties' => json_encode(['follower_name' => 'Demo user '.self::MARKER]),
                'created_at' => now()->subDays(random_int(1, 45)),
            ],
            // review_received: GM received a review
            fn (string $uid) => [
                'user_id' => $uid,
                'subject_type' => Game::class,
                'subject_id' => null,
                'event_type' => ActivityType::ReviewReceived->value,
                'properties' => json_encode(['rating' => random_int(2, 5)]),
                'created_at' => now()->subDays(random_int(1, 30)),
            ],
            // game_completed: GM completed a game
            fn (string $uid) => [
                'user_id' => $uid,
                'subject_type' => Game::class,
                'subject_id' => null,
                'event_type' => ActivityType::GameCompleted->value,
                'properties' => json_encode(['game_name' => 'Demo game '.self::MARKER]),
                'created_at' => now()->subDays(random_int(1, 30)),
            ],
            // campaign_created: GM started a campaign
            fn (string $uid) => [
                'user_id' => $uid,
                'subject_type' => Campaign::class,
                'subject_id' => null,
                'event_type' => ActivityType::CampaignCreated->value,
                'properties' => json_encode(['campaign_name' => 'Demo campaign '.self::MARKER]),
                'created_at' => now()->subDays(random_int(7, 60)),
            ],
            // session_scheduled: upcoming session reminder
            fn (string $uid) => [
                'user_id' => $uid,
                'subject_type' => Game::class,
                'subject_id' => null,
                'event_type' => ActivityType::SessionScheduled->value,
                'properties' => json_encode(['game_name' => 'Demo session '.self::MARKER]),
                'created_at' => now()->subDays(random_int(0, 14)),
            ],
        ];

        // ~40% of users get 2-8 activity log entries
        $activeCount = max(1, (int) (count($this->allUserIds) * 0.40));
        $shuffled = $this->allUserIds;
        shuffle($shuffled);
        $activeUsers = array_slice($shuffled, 0, $activeCount);

        $bar = $this->output->createProgressBar(count($activeUsers));
        $bar->setRedrawFrequency(200);
        $bar->start();

        foreach ($activeUsers as $uid) {
            $count = random_int(2, 8);
            for ($i = 0; $i < $count; $i++) {
                $builder = $eventBuilders[array_rand($eventBuilders)];
                $row = $builder($uid);
                $row['id'] = (string) Str::orderedUuid();
                $batch[] = $row;
                $total++;

                if (count($batch) >= 500) {
                    $this->dryInsertMany('activity_logs', $batch);
                    $batch = [];
                }
            }
            $bar->advance();
        }

        if (! empty($batch)) {
            $this->dryInsertMany('activity_logs', $batch);
        }

        $bar->finish();
        $this->newLine();
        $this->info("Seeded {$total} activity log entries for {$activeCount} users.");
    }

    // =========================================================================
    // LINKED ACCOUNTS
    // =========================================================================

    private function createLinkedAccounts(): void
    {
        $this->newLine();
        $this->info('Creating linked accounts...');

        // ~30% of GMs get a BGG linked account
        $gmCount = max(1, (int) (count($this->gmInfo) * 0.30));
        $shuffled = $this->gmInfo;
        shuffle($shuffled);
        $linkedGms = array_slice($shuffled, 0, $gmCount);

        $batch = [];
        foreach ($linkedGms as $gm) {
            $bggUsername = strtolower(Str::random(random_int(5, 15)));

            $batch[] = [
                'id' => (string) Str::orderedUuid(),
                'user_id' => $gm['id'],
                'provider' => 'bgg',
                'provider_user_id' => $bggUsername,
                'token' => null,
                'refresh_token' => null,
                'token_expires_at' => null,
                'provider_meta' => json_encode(['username' => $bggUsername]),
                'created_at' => now()->subDays(random_int(14, 90)),
                'updated_at' => now(),
            ];

            if (count($batch) >= 500) {
                $this->dryInsertMany('linked_accounts', $batch);
                $batch = [];
            }
        }

        // ~5% of all users get a Google linked account
        $googleCount = max(1, (int) (count($this->allUserIds) * 0.05));
        $shuffledUsers = $this->allUserIds;
        shuffle($shuffledUsers);
        $googleUsers = array_slice($shuffledUsers, 0, $googleCount);

        foreach ($googleUsers as $uid) {
            $batch[] = [
                'id' => (string) Str::orderedUuid(),
                'user_id' => $uid,
                'provider' => 'google',
                'provider_user_id' => (string) random_int(100000000000, 999999999999),
                'token' => null,
                'refresh_token' => null,
                'token_expires_at' => null,
                'provider_meta' => null,
                'created_at' => now()->subDays(random_int(7, 60)),
                'updated_at' => now(),
            ];

            if (count($batch) >= 500) {
                $this->dryInsertMany('linked_accounts', $batch);
                $batch = [];
            }
        }

        if (! empty($batch)) {
            $this->dryInsertMany('linked_accounts', $batch);
        }

        $this->info("Created linked accounts: {$gmCount} BGG + {$googleCount} Google.");
    }

    // =========================================================================
    // SUMMARY
    // =========================================================================

    private function printSummary(): void
    {
        $this->newLine();
        $header = $this->dryRun ? 'DRY RUN — SIMULATED SEED SUMMARY' : 'DEMO DATA SEED COMPLETE';
        $this->info('╔══════════════════════════════════════════════════╗');
        $this->info('║'.str_pad($header, 48, ' ', STR_PAD_BOTH).'║');
        $this->info('╚══════════════════════════════════════════════════╝');
        $this->newLine();

        $marker = self::MARKER;
        $totalLocations = array_sum(array_map('count', $this->locationsByCity));

        if ($this->dryRun) {
            // In dry-run, use tracked counts instead of querying the DB
            $rows = [];
            foreach ($this->dryCounts as $table => $count) {
                $rows[] = [$table, number_format($count)];
            }
            $rows[] = ['', ''];
            $rows[] = ['Total users (simulated)', number_format(count($this->allUserIds))];
            $rows[] = ['GMs (simulated)', number_format(count($this->gmInfo))];
            $rows[] = ['GM subscribers (simulated)', number_format(min($this->totalSubscribers, count($this->gmInfo)))];
            $rows[] = ['Locations (simulated)', number_format($totalLocations)];
            $rows[] = ['Completed board games (simulated)', number_format(count($this->completedGames))];
            $rows[] = ['Completed session zeros (for surveys)', number_format(count($this->completedSessionZeros))];

            $this->table(['Table / Metric', 'Rows'], $rows);
            $this->newLine();
            $this->warn('⚠ No data was written to the database.');
            $this->info('Run without --dry-run to apply for real.');
        } else {
            // Use in-memory counts where possible; fall back to owner_id queries
            // (name is jsonb so LIKE requires a text cast and won't use indexes)
            $gCount = Game::whereIn('owner_id', $this->allUserIds)->count();
            $cCount = DB::table('campaigns')->whereIn('owner_id', $this->allUserIds)->count();
            $rCount = DB::table('reviews')
                ->where('reviewable_type', Game::class)
                ->whereIn('reviewable_id', fn ($q) => $q->select('id')->from('games')->whereIn('owner_id', $this->allUserIds))
                ->count();
            $fCount = DB::table('user_relationships')
                ->whereIn('user_id', $this->allUserIds)
                ->count();
            $pCount = DB::table('game_participants')
                ->whereIn('game_id', fn ($q) => $q->select('id')->from('games')->whereIn('owner_id', $this->allUserIds))
                ->count();

            $this->table(['Metric', 'Count'], [
                ['Total users', number_format(count($this->allUserIds))],
                ['Game organizers (GMs)', number_format(count($this->gmInfo))],
                ['GM subscribers', number_format(min($this->totalSubscribers, count($this->gmInfo)))],
                ['Locations (venues)', number_format($totalLocations)],
                ['Game sessions (all)', number_format($gCount)],
                ['  Completed board games', number_format(count($this->completedGames))],
                ['  Completed session zeros', number_format(count($this->completedSessionZeros))],
                ['Campaigns (active)', number_format($cCount)],
                ['Reviews (published)', number_format($rCount)],
                ['Follow relationships', number_format($fCount)],
                ['Game participants', number_format($pCount)],
            ]);

            $this->newLine();
            $this->info('Login: any user with password "'.self::PASSWORD.'"');
            $this->info('Email pattern: firstname.lastname.NNNN@example.org');
            $this->info('Teardown: php artisan demo:teardown');
        }
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Build the JSON value for the NOT NULL `games.location` column.
     * Looks up the location record to get address and coordinates.
     * Falls back to a minimal structure if location not found.
     */
    private function buildLocationJson(?string $locationId): string
    {
        if ($locationId === null) {
            return json_encode(['type' => 'online', 'details' => '']) ?: '{}';
        }

        // In dry-run mode, we don't have the actual location in DB — use a stub
        if ($this->dryRun) {
            return json_encode(['type' => 'physical', 'location_id' => $locationId]) ?: '{}';
        }

        if (! isset($this->locationCache[$locationId])) {
            $this->locationCache[$locationId] = Location::find($locationId);
        }
        $loc = $this->locationCache[$locationId];

        if ($loc) {
            return json_encode([
                'type' => 'physical',
                'address' => trim(($loc->address ?? '').', '.($loc->postal_code ?? '').' '.($loc->city ?? '')),
                'lat' => (float) $loc->latitude,
                'lng' => (float) $loc->longitude,
            ]) ?: '{}';
        }

        return json_encode(['type' => 'physical', 'location_id' => $locationId]) ?: '{}';
    }

    /**
     * @return array<int, string>
     */
    private function randomVibes(string $type): array
    {
        $keys = $type === 'board' ? self::BOARD_VIBE_KEYS : self::TTRPG_VIBE_KEYS;
        $validValues = VibeFlag::values();
        // Filter to only values that actually exist in the enum
        $pool = array_values(array_intersect($keys, $validValues));
        if (count($pool) < 2) {
            return [];
        }
        $count = random_int(2, min(4, count($pool)));
        $picked = array_map(fn ($k) => $pool[(int) $k], $this->randomKeys($pool, $count));

        return $picked;
    }

    /**
     * Generate varied privacy settings per user.
     * Distributions: bio=70% everyone/30% friends, location=60% everyone/30% friends/10% nobody, etc.
     *
     * @return array<string, mixed>
     */
    private function randomPrivacySettings(): array
    {
        $pick = function (array $weights): string {
            $roll = mt_rand() / mt_getrandmax();
            $cumulative = 0.0;
            foreach ($weights as $value => $threshold) {
                $cumulative += $threshold;
                if ($roll < $cumulative) {
                    return $value;
                }
            }

            return (string) array_key_last($weights);
        };

        return [
            'location' => $pick(['everyone' => 0.60, 'friends' => 0.30, 'nobody' => 0.10]),
            'game_systems' => $pick(['everyone' => 0.40, 'friends' => 0.50, 'nobody' => 0.10]),
            'vibes' => $pick(['everyone' => 0.35, 'friends' => 0.55, 'nobody' => 0.10]),
            'campaigns' => $pick(['everyone' => 0.30, 'friends' => 0.60, 'nobody' => 0.10]),
            'teams' => $pick(['everyone' => 0.25, 'friends' => 0.65, 'nobody' => 0.10]),
            'gm_profile' => $pick(['everyone' => 0.50, 'friends' => 0.40, 'nobody' => 0.10]),
            'bio' => $pick(['everyone' => 0.70, 'friends' => 0.25, 'nobody' => 0.05]),
        ];
    }
}

---
paths:
  - 'app/**'
---

# App

## Participant row writes are single-sourced in sanctioned modules
game_participants/campaign_participants creation and status writes are permitted only in: ParticipantLifecycle, WaitlistService, Roster, OwnerParticipantService, CapacityService, ParticipantService (host invites), OverflowRouter, HandlesWaitlist, HasParticipantDefaults. Never inline a participant create/status write in a component, job, or other service — route through one of these (INV-15, enforced by scripts/check_platform_invariants.sh). Adding a new writer means deliberately extending the allowlist in that script in the same change.

## Outbound HTTP lives in client classes
Every external API call goes through a dedicated client class under app/Services/<Provider>/ or app/SEO/ (BggClient, SpClient, GscClient, PsiClient, DiscordWebhookClient, GeocodingService are the reference shapes): config-driven base URL/timeout/auth, one class per provider, tests fake responses via Http::fake. Agents adding a new integration copy an existing client's shape instead of inventing one. Exceptions (OAuthController, SyncCloudflareCacheRules) are listed in the arch-test allowlist in tests/Arch/ArchitectureTest.php — new outbound calls must go through a client class, never join the allowlist silently.

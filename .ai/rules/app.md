---
paths:
  - 'app/**'
---

# App

## Participant row writes are single-sourced in sanctioned modules
game_participants/campaign_participants creation and status writes are permitted only in: ParticipantLifecycle, WaitlistService, Roster, OwnerParticipantService, CapacityService, ParticipantService (host invites), OverflowRouter, HandlesWaitlist, HasParticipantDefaults. Never inline a participant create/status write in a component, job, or other service — route through one of these (INV-15, enforced by scripts/check_platform_invariants.sh). Adding a new writer means deliberately extending the allowlist in that script in the same change.

---
paths:
  - 'app/Services/**'
---

# Services

## Lock the Game/Campaign row before participant rows
When a flow needs both the entity (Game/Campaign) row and participant rows, lockForUpdate() the entity row FIRST, then participant rows — the canonical order everywhere (WaitlistService::promoteNext, ParticipantLifecycle::acceptInvitation, ProcessDiscordLeave). depart()'s cascade re-locks the entity then the first waitlisted row, so participant-first ordering deadlocks (AB-BA) against concurrent promotions; PG kills the victim and the whole leave rolls back. See HandlesWaitlist::leaveWaitlist and removeFromBench for the pattern. Do NOT defer the cascade with DB::afterCommit — its callbacks never fire inside the test suite's DatabaseTransactions wrapper (root-commit only), silently breaking cascade tests.

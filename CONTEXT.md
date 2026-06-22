# Roundup Games

The platform for organizing tabletop gaming sessions — board games, RPGs, and card games. Users create and join game sessions, campaigns, and events; hosts manage participants through a waitlist/bench system with reliability scoring.

## Language

### Participants & Sessions

**Game**:
A single scheduled game session with one owner (host) and approved players. Has a status lifecycle (scheduled → completed/canceled) and a capacity.
_Avoid_: match, table, session (reserved for campaign sessions)

**Campaign**:
A group of related game sessions under one organizer. Has its own participant roster and may be reviewed as a whole.
_Avoid_: series, league

**Participant**:
A user's membership in a Game or Campaign, with a role (owner/player) and a status (approved/waitlisted/benched/pending). The `Participant` contract (`app/Contracts/Participant.php`) unifies GameParticipant and CampaignParticipant behind one seam.
_Avoid_: member, attendee, roster entry

**Session** (campaign):
A single Game belonging to a Campaign. Distinct from a standalone Game.
_Avoid_: episode, chapter

### Dashboard

**Dashboard**:
The authenticated user's home page. Assembles personalized sections based on the viewer's mode and location.
_Avoid_: home, feed (feed is one section, not the whole page)

**Dashboard mode**:
Whether the viewer is a `newcomer` (zero attended games AND account under 30 days old) or `established`. Determines which sections render.
_Avoid_: view, layout, tab

**Dashboard section**:
A named unit of dashboard content with its own cache key, TTL, and invalidation rule. Current sections: `week`, `feed`, `opportunities`, `contributions`, `recaps`, `action_center`, `newcomer_welcome`, `progress_tracker`, `nearby_people`, `newcomer_matches`, `host_again`, `milestone_cards`.
_Avoid_: widget, card, panel, block

**Warm** (verb):
To synchronously compute a Dashboard section's value and store it in cache, outside the request cycle. Triggered by a cache miss (background job) or proactively.
_Avoid_: precompute, refresh (refresh implies re-read; warm implies compute-and-store)

**Dashboard cache**:
The module that owns cache keys, TTLs, and invalidation rules for Dashboard sections. Computers live on sibling services; the cache module invokes them via a `remember` primitive.
_Avoid_: the dashboard service (ambiguous — there are several), cache layer

## Flagged ambiguities

- **"Pending" participant status** is overloaded: it means both *pending invite response* (role=Invited) and *pending waitlist-promotion confirmation* (role=Player + `confirmation_expires_at` set). Disambiguate by `role`, not by status alone. (Relevant to participant-lifecycle work.)

## Relationships

- A **Game** or **Campaign** has many **Participants**; each Participant has one role and one status.
- A **Dashboard** renders a subset of **Dashboard sections** chosen by the viewer's **Dashboard mode**.
- Each **Dashboard section** is computed by a sibling service and cached by the **Dashboard cache**; **warming** and invalidation are keyed by section name.

## Example dialogue

> **Dev:** "The newcomer dashboard is empty — is the section warming?"
> **Expert:** "Check **Dashboard mode** first. If they're established, `newcomer_welcome` won't render regardless of warm state. Then check whether `newcomer_matches` invalidated — it's geohash-tracked, so the keys live in a tracking set, not a single key."
>
> **Dev:** "And when a game completes?"
> **Expert:** "The observer calls `invalidateForGameEvent`, which fans out to `week`, `opportunities`, `host_again`, and `milestone_cards` for every affected **Participant**. `contributions` and `feed` invalidate through the attendance path instead."

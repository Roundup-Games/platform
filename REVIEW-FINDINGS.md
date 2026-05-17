# CodeRabbit Review Findings for M044 PR

## Major (19)

### 1. MigrateShareTokensToShortLinks.php:59 — Pass explicit code
Pass `'code' => $game->share_token` (and `$campaign->share_token`) to createLink() to preserve legacy tokens.

### 2. PruneExpiredShortLinks.php:24 — Validate --days and --grace bounds
Add guard: `if ($days < 1 || $grace < 0)` → error + FAILURE.

### 3. ShortLinkController.php:69 — Miss counter TTL race condition
Replace Cache::increment + conditional Cache::put with RateLimiter::hit($missKey, static::MISS_TTL).

### 4. ShortLinkController.php:105 — Hit-cap enforcement on stale cached data
Use DB-authoritative check instead of cached hasHitCap().

### 5. ShortLinkController.php:113 — Wrap analytics dispatch in try/catch
Wrap RecordShortLinkHit::dispatch() in try/catch so redirect never fails.

### 6. ProcessShareIntent.php:629 — Guard null after lockForUpdate()->find()
Add null check for $lockedGame and $lockedCampaign (4 locations: lines ~199, ~327, ~622, ~745).

### 7. CampaignDetail.php:371 — Revalidate short link before join
Re-resolve short link at join time, not just from mount() cached id.

### 8. GameDetail.php:419 — Revalidate short link before join
Same as #7 for GameDetail.

### 9. GmWorkspace.php:176 — Aggregate referrers by host, not full URL
Use PHP collection aggregation (pluck → map to host → countBy) instead of SQL groupBy('referer').

### 10. GmSocialLink.php:20 — Keep url derived-only
Remove 'url' from $fillable, add 'instance'. Regenerate on platform/handle/instance change.

### 11. GmSocialLinkService.php:156 — Enforce missing required instance
When instance_required && empty instance → validation error, don't skip silently.

### 12. ShortLinkService.php:33 — Avoid logging full codes
Mask codes in all log entries (3 locations): use code_prefix or code_hash.

### 13. ShortLinkService.php:63 — Add insert-time retry for code collisions
Wrap ShortLink::create in retry loop catching QueryException 23000.

### 14. ShortLinkService.php:166 — Revoke should invalidate cache
Add Cache::forget for code-based and id-based keys in revokeLink().

### 15. Migration add_short_link_to_join_source_check:34 — Rollback remapping
In down(), UPDATE join_source='short_link' → 'share_link' before re-adding constraint.

### 16. GmSocialLinkTest.php:117 — Don't assert malformed Mastodon URL
Missing instance should return null, not 'https:///@charlie'.

### 17. GmWorkspaceAnalyticsTest.php:167 — Validate domain-level aggregation
Test should reflect true domain aggregation after fix #9.

### 18. ShortLinkModelTest.php:58 — Failure-safe Str override reset
Wrap in try/finally to always reset Str::createRandomStringsUsing.

### 19. GmSocialLinkServiceTest.php:48 — Don't lock in malformed Mastodon URL
Expect null for missing instance instead of 'https:///@testuser'.

## Minor (7)

### M1. Team.php:117-126 — Use getMorphClass() for morphMap compatibility
Change linkable_type filter from static::class to $this->getMorphClass().

### M2. lang/de/profile.php:210-219 — Add missing German content_find_me_on key

### M3. _share-links-manager.blade.php:73 — Use @js() for confirm message
Replace raw __('gws.link_confirm_revoke') with @js(__('gws.link_confirm_revoke')).

### M4. short-link-display.blade.php:56-61 — Use @js() for Alpine bindings
Wrap all Blade interpolations in Alpine/JS context with @js().

### M5. GmSocialLinkDisplayTest.php:108-112 — Assert strpos presence before ordering
Add expect($twitterPos)->not->toBeFalse() before comparing positions.

### M6. ShortLinkServiceTest.php:303-316 — Test actual custom max_links_per_entity
Set custom limit on user factory instead of re-testing default.

### M7. gm-workspace.blade.php:359-365 — Move ShortLink query to Livewire class
Don't run Eloquent queries in Blade templates.

## Nitpick (11)

### N1. MigrateShareTokensToShortLinks.php:38-39 — Use chunked iteration
Replace ->get() with ->lazyById(200) for both game and campaign queries.

### N2. UserResource.php:152-157 — Use integer() + nullable() for max_links_per_entity
Replace ->numeric() with ->integer()->nullable().

### N3. CampaignParticipant.php:65-71 — Guard against lazy-loading in source_label accessor
Check relationLoaded('shortLink') before accessing.

### N4. GameParticipant.php:75-82 — Same as N3 for GameParticipant.

### N5. Migration game_participants short_link_id — Add explicit index
Chain ->index() on short_link_id column.

### N6. Migration campaign_participants short_link_id — Add explicit index
Same as N5.

### N7. _participant-list.blade.php:23-48 — Extract join-source badge to shared partial
Create reusable Blade component for badge rendering.

### N8. gm-workspace.blade.php:359-365 — Move query to component (same as M7)

### N9. ShortLinkRedirectTest.php:20-26 — Targeted cache cleanup
Replace Cache::flush() with Cache::forget for specific keys.

### N10. ShortLinkCleanupTest.php:249-269 — Un-skip log assertion
Fake the daily log channel to make assertion deterministic.

### N11. ShortLinkServiceTest.php:41-74 — Failure-safe Str override (same pattern as #18)
Wrap both Str override tests in try/finally.

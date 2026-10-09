---
paths:
  - 'app/Livewire/**'
---

# Livewire

## Participant joins route through ShareLinkJoinService; authorization through policies
The lock-guarded share-link join pipeline (lockForUpdate + short-link revalidation + capacity re-check + overflow routing + approved_at stamping) exists exactly once: App\Services\ShareLinkJoinService::join(). Detail components only do pre-guards (canJoinViaShareLink, signup cutoff, rate limit) and post-join flash/refresh. Inline DB::transaction join/capacity mutations in components are a violation (INV-6). Share-link owner actions authorize via the manageShareToken/leave/clone policy abilities — no hand-rolled owner_id comparisons.

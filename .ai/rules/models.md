---
paths:
  - 'app/Models/**'
---

# Models

## Query-shape rules are single-sourced — never re-implement inline
scopeVisibleTo (connection-aware visibility) lives ONLY in App\Models\Concerns\VisibleToScope; geohash tile bbox ONLY in Geohash::applyBounds (ProximityQuery's radius bbox is the only exception); slug transliteration ONLY in SlugService; share-token hash_equals ONLY in HasShareToken. Compose the scopes/traits — never re-implement the clause. Enforced by composer invariants (scripts/check_platform_invariants.sh, INV-1..5) and CI.

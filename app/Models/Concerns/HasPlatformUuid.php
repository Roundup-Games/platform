<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * Platform-standard UUIDv7 primary keys (D170).
 *
 * Wraps the framework HasUuids trait — which generates RFC 9562 UUIDv7 via
 * Str::uuid7() — so the platform's id policy has exactly one named home.
 * Models must NOT hand-roll `creating` hooks that assign ids.
 *
 * Policy:
 *
 *  - UUIDv7 for identity: time-ordered ids keep B-tree insert locality on
 *    Postgres and give stable keyset-pagination cursors. Creation time is
 *    NOT a secret (v7 encodes it); ids must never be treated as capability
 *    tokens — shareable URLs go through ShortLink codes instead.
 *  - UUIDv4 for secrecy: opaque tokens (survey share uuids, share tokens)
 *    stay on Str::uuid() where unpredictability is the requirement. This
 *    trait is only for primary keys.
 *  - Existing rows keep their v4/comb ids — UUIDs of every version are
 *    valid values for a uuid column; there is no data migration for ids.
 *
 * Storage contract (same decision): every id and morph id column the
 * platform owns is a native Postgres uuid column — never varchar. Exempt
 * by documented decision: short_links + short_link_hits (bigint counter
 * tables; 'code' is the public key) and vendor-package schemas.
 *
 * Pinned by scripts/check_platform_invariants.sh (INV-10).
 */
trait HasPlatformUuid
{
    use HasUuids;
}

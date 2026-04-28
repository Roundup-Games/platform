# VAPID Key Rotation

## Overview

VAPID (Voluntary Application Server Identification) keys authenticate your server with browser push services. When you rotate keys, all existing push subscriptions become invalid because they are bound to the key pair used during subscription.

## When to Rotate

- Key compromise suspected
- Regulatory compliance requirements
- As part of regular security hygiene (annually)

## Rotation Procedure

### 1. Generate New Keys

```bash
php artisan pwa:generate-vapid-keys
```

This outputs new `VAPID_PUBLIC_KEY` and `VAPID_PRIVATE_KEY` values.

### 2. Update Environment Variables

Update your `.env` (or deployment environment) with the new keys:

```
VAPID_PUBLIC_KEY="<new-public-key>"
VAPID_PRIVATE_KEY="<new-private-key>"
```

### 3. Deploy

Deploy the updated environment variables. The application handles the rest:

- `AppServiceProvider` reads the new keys on the next request
- Existing subscriptions will fail delivery with 410/404 responses
- `PushChannel` auto-deletes expired subscriptions on failed delivery
- `pwa:prune-stale-subscriptions` cleans up any remaining stale entries (runs weekly)

### 4. Users Re-subscribe Automatically

When users next visit the app:

1. `push.js` detects the new VAPID public key via `/api/push/vapid-public-key`
2. The existing subscription is invalid (different `applicationServerKey`)
3. The browser automatically creates a new subscription with the new key
4. `push.js` syncs the new subscription via `POST /api/push/subscribe`

No user action required — the transition is seamless.

### 5. Monitor

Check structured logs for:

- `push.subscription_expired` — old subscriptions being cleaned up
- `push.send_failed` — transient failures during rotation
- `pwa.subscriptions.pruned` — weekly pruning count

## Timeline

| Time | Event |
|------|-------|
| T+0 | Deploy new keys |
| T+0 to T+24h | Old subscriptions expire during normal push delivery |
| T+7 days | Weekly prune removes any stragglers |
| T+14 days | All users have re-subscribed on next visit |

## Rollback

If you need to rollback, restore the old keys. Users who re-subscribed with the new keys will go through the same expiration + re-subscribe flow.

## Precautions

- **Never delete VAPID keys without replacement.** Without keys, no push notifications can be sent.
- **Keep keys in environment variables, never in version control.**
- **Test in staging first.** Generate separate keys for staging and production.

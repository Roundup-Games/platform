#!/usr/bin/env bash
# Patch escalated-laravel to work with the platform's UUID user primary keys.
# Re-applies after every composer install/update via composer.json post-autoload-dump.
#
# Background: the platform converts escalated's user-ref and morph columns to
# PostgreSQL `uuid` (see 2026_05_14_233000_convert_escalated_user_refs_to_uuid.php)
# because the host User model uses UUID keys. escalated's own ticket/reply models
# still carry integer auto-increment PKs.
#
# What used to be patched (now obsolete upstream — escalated-laravel >= v1.4.0):
#   - int type hints on scopes/services/events receiving user/agent IDs.
#     Upstream now ships `int|string` everywhere (Ticket::assign, scopeAssignedTo,
#     SavedView::scopeForUser, TicketAssigned::$agentId, etc.), so the type-hint
#     stripping is no longer needed and was removed.
#
# What is still patched here:
#   - Attachment eager-loads. The morph column `attachments.attachable_id` was
#     converted to UUID, but escalated Ticket/Reply models have integer PKs, so
#     any `->with('attachments')` produces `WHERE attachable_id IN (1)` which
#     PostgreSQL rejects (operator does not exist: uuid = bigint). Removing the
#     eager-load is the minimal fix; attachments still lazy-load per-instance
#     when needed and upload/storage is unaffected.

set -e

VENDOR_DIR="vendor/escalated-dev/escalated-laravel/src"

if [ ! -d "$VENDOR_DIR" ]; then
    echo "  > Escalated package not found — skipping UUID patch."
    exit 0
fi

echo "  > Patching escalated-laravel for UUID attachment compatibility..."

# =====================================================================
# Remove ALL attachment eager loads (UUID morph column vs int model PK)
# =====================================================================
# Covers every controller that eager-loads ticket/reply attachments: the five
# original TicketControllers plus the two Mobile* controllers added in
# escalated-laravel v1.4.0 (latent 500 if escalated.api.enabled is ever true).

CONTROLLERS=(
    "$VENDOR_DIR/Http/Controllers/Customer/TicketController.php"
    "$VENDOR_DIR/Http/Controllers/Guest/TicketController.php"
    "$VENDOR_DIR/Http/Controllers/Admin/TicketController.php"
    "$VENDOR_DIR/Http/Controllers/Agent/TicketController.php"
    "$VENDOR_DIR/Http/Controllers/Api/TicketController.php"
    "$VENDOR_DIR/Http/Controllers/Api/MobileTicketController.php"
    "$VENDOR_DIR/Http/Controllers/Api/MobileGuestTicketController.php"
)

# Remove 'attachments' from every eager-load context. Three forms appear across
# the controllers:
#   1. Leading element in a single-line array:  'attachments', 'tags', 'department'
#   2. Reply eager-load:                         with('author', 'attachments')
#   3. Trailing element or standalone line:
#        $ticket->load(['department', 'tags', 'assignee', 'attachments'])
#        multi-line arrays where 'attachments', sits on its own line
# The standalone-line deletion and trailing-element strip also cover the
# Mobile* controllers added in v1.4.0. Validation rules ('attachments' => ...)
# and upload calls ($request->file('attachments', ...)) use ' =>' / 'file(' and
# are never matched by these patterns.
for f in "${CONTROLLERS[@]}"; do
    [ -f "$f" ] || continue
    # 1. Leading element in known multi-relation arrays.
    sed -i.bak "s/'attachments', 'tags', 'department'/'tags', 'department'/g" "$f"
    sed -i.bak "s/'attachments', 'department'/'department'/g" "$f"
    # 2. Reply-level eager-load.
    sed -i.bak "s/with('author', 'attachments')/with('author')/g" "$f"
    # 3a. Trailing 'attachments' before a closing bracket.
    sed -i.bak "s/, 'attachments'\]/\]/g" "$f"
    # 3b. Standalone 'attachments', line (multi-line load arrays).
    sed -i.bak -e "/^[[:space:]]*'attachments',[[:space:]]*$/d" "$f"
done

# Also strip reply-level attachment eager-loads from SideConversation models
# if they ship them (guarded — not all releases do).
for SC in \
    "$VENDOR_DIR/Models/SideConversation.php" \
    "$VENDOR_DIR/Models/SideConversationReply.php"; do
    if [ -f "$SC" ]; then
        sed -i.bak "s/with('author', 'attachments')/with('author')/g" "$SC"
    fi
done

# Clean up backups
find "$VENDOR_DIR" -name "*.bak" -delete

echo "  > Escalated UUID attachment patch applied."

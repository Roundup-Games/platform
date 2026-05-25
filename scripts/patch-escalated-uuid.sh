#!/usr/bin/env bash
# Patch escalated-laravel to work with UUID user PKs.
# Re-applies after every composer install/update.
#
# Three categories of fixes:
#   1. Remove `int` type hints from methods receiving user/agent IDs
#   2. Remove reply->attachments eager load (UUID FK vs int PK mismatch)
#   3. (Future: proper fix via upstream PR)
#
# This should be replaced by a proper upstream fix in escalated-laravel.

set -e

VENDOR_DIR="vendor/escalated-dev/escalated-laravel/src"

if [ ! -d "$VENDOR_DIR" ]; then
    echo "  > Escalated package not found — skipping UUID patch."
    exit 0
fi

echo "  > Patching escalated-laravel for UUID compatibility..."

# =====================================================================
# 1. Remove int type hints from scope/instance/service methods
# =====================================================================

# Models: scope methods
sed -i.bak -E 's/(public function scope(ForAgent|AssignedTo|ForUser)\(\$query, )int (\$[a-zA-Z]+)/\1\3/g' \
    "$VENDOR_DIR/Models/Macro.php" \
    "$VENDOR_DIR/Models/CannedResponse.php" \
    "$VENDOR_DIR/Models/ChatSession.php" \
    "$VENDOR_DIR/Models/Ticket.php" \
    "$VENDOR_DIR/Models/SavedView.php"

# Models: instance methods — Ticket
sed -i.bak -E 's/(public function (isFollowedBy|follow|unfollow)\()int (\$[a-zA-Z]+)/\1\3/g' \
    "$VENDOR_DIR/Models/Ticket.php"
sed -i.bak -E 's/public function assign\(Model\|int/public function assign(Model|string|int/' \
    "$VENDOR_DIR/Models/Ticket.php"

# Models: instance methods — Contact, AgentProfile
sed -i.bak -E 's/(public function (linkToUser|promoteToUser)\()int (\$[a-zA-Z]+)/\1\3/g' \
    "$VENDOR_DIR/Models/Contact.php"
sed -i.bak -E 's/(public static function forUser\()int (\$[a-zA-Z]+)/\1\2/g' \
    "$VENDOR_DIR/Models/AgentProfile.php"

# Contracts & Drivers: assignTicket
sed -i.bak -E 's/(public function assignTicket\([^,]+, )int (\$[a-zA-Z]+)/\1\2/g' \
    "$VENDOR_DIR/Contracts/TicketDriver.php" \
    "$VENDOR_DIR/Drivers/LocalDriver.php" \
    "$VENDOR_DIR/Drivers/CloudDriver.php" \
    "$VENDOR_DIR/Drivers/SyncedDriver.php"

# Events: TicketAssigned constructor
sed -i.bak -E 's/(public )int (\$agentId)/\1\2/g' \
    "$VENDOR_DIR/Events/TicketAssigned.php"

# Services: AssignmentService
sed -i.bak -E 's/(public function (assign|reassign)\([^,]+, )int (\$[a-zA-Z]+)/\1\3/g' \
    "$VENDOR_DIR/Services/AssignmentService.php"
sed -i.bak -E 's/(public function getAgentWorkload\()int (\$[a-zA-Z]+)/\1\2/g' \
    "$VENDOR_DIR/Services/AssignmentService.php"

# Services: CapacityService
sed -i.bak -E 's/(public function (canAcceptTicket|incrementLoad|decrementLoad)\()int (\$[a-zA-Z]+)/\1\3/g' \
    "$VENDOR_DIR/Services/CapacityService.php"

# Services: ChatRoutingService, ChatAvailabilityService
sed -i.bak -E 's/(public function getAgentChatCount\()int (\$[a-zA-Z]+)/\1\2/g' \
    "$VENDOR_DIR/Services/ChatRoutingService.php" \
    "$VENDOR_DIR/Services/ChatAvailabilityService.php"

# Services: ChatSessionService
sed -i.bak -E 's/(public function assignAgent\([^,]+, )int (\$[a-zA-Z]+)/\1\2/g' \
    "$VENDOR_DIR/Services/ChatSessionService.php"
sed -i.bak -E 's/\?int \$(userId)/?string \$\1/g' \
    "$VENDOR_DIR/Services/ChatSessionService.php"

# Services: ReportingService
sed -i.bak -E 's/(public function (getAgentMetrics|agentResponseTimePercentiles)\()int (\$[a-zA-Z]+)/\1\3/g' \
    "$VENDOR_DIR/Services/ReportingService.php"

# Middleware: CheckPermission
sed -i.bak -E 's/(protected function userHasPermission\()int (\$[a-zA-Z]+)/\1\2/g' \
    "$VENDOR_DIR/Http/Middleware/CheckPermission.php"

# =====================================================================
# 2. Remove reply->attachments eager load (UUID FK vs int PK mismatch)
# =====================================================================
# The convert_escalated_user_refs_to_uuid migration changed attachable_id to
# UUID. Reply has integer auto-increment PKs. Eager loading reply attachments
# generates WHERE attachable_id IN (1) which fails against UUID type.
# Remove 'attachments' from the reply eager-load in Customer TicketController.
# Ticket-level attachments still load fine.

CUSTOMER_TC="$VENDOR_DIR/Http/Controllers/Customer/TicketController.php"
if [ -f "$CUSTOMER_TC" ]; then
    # Replace "with('author', 'attachments')" with "with('author')"
    sed -i.bak "s/with('author', 'attachments')/with('author')/" "$CUSTOMER_TC"
    echo "  > Removed reply attachments eager load from Customer TicketController"
fi

# Same fix for Admin, Agent, Guest, and API ticket controllers
for TC in \
    "$VENDOR_DIR/Http/Controllers/Admin/TicketController.php" \
    "$VENDOR_DIR/Http/Controllers/Agent/TicketController.php" \
    "$VENDOR_DIR/Http/Controllers/Api/TicketController.php" \
    "$VENDOR_DIR/Http/Controllers/Guest/TicketController.php"; do
    if [ -f "$TC" ]; then
        sed -i.bak "s/with('author', 'attachments')/with('author')/" "$TC"
    fi
done

# Also patch any SideConversationReply with same pattern
for SC in \
    "$VENDOR_DIR/Models/SideConversation.php" \
    "$VENDOR_DIR/Models/SideConversationReply.php"; do
    if [ -f "$SC" ]; then
        sed -i.bak "s/with('author', 'attachments')/with('author')/" "$SC"
    fi
done

# Clean up backups
find "$VENDOR_DIR" -name "*.bak" -delete

echo "  > Escalated UUID patch applied."

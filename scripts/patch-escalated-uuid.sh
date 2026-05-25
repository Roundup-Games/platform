#!/usr/bin/env bash
# Patch escalated-laravel to accept string (UUID) agent/user IDs instead of int.
# Re-applies after every composer install/update.
#
# The package assumes int PKs everywhere. Our User model uses UUID strings.
# This script removes int type hints from all methods that receive user/agent IDs.
#
# This should be replaced by a proper upstream fix in escalated-laravel.

set -e

VENDOR_DIR="vendor/escalated-dev/escalated-laravel/src"

if [ ! -d "$VENDOR_DIR" ]; then
    echo "  > Escalated package not found — skipping UUID patch."
    exit 0
fi

echo "  > Patching escalated-laravel for UUID agent IDs..."

# --- Models: scope methods ---
# Macro, CannedResponse, ChatSession: scopeForAgent
# Ticket: scopeAssignedTo
# SavedView: scopeForUser
sed -i.bak -E 's/(public function scope(ForAgent|AssignedTo|ForUser)\(\$query, )int (\$[a-zA-Z]+)/\1\3/g' \
    "$VENDOR_DIR/Models/Macro.php" \
    "$VENDOR_DIR/Models/CannedResponse.php" \
    "$VENDOR_DIR/Models/ChatSession.php" \
    "$VENDOR_DIR/Models/Ticket.php" \
    "$VENDOR_DIR/Models/SavedView.php"

# --- Models: instance methods ---
# Ticket: isFollowedBy, follow, unfollow, assign (union type)
# Contact: linkToUser, promoteToUser
# AgentProfile: forUser
sed -i.bak -E 's/(public function (isFollowedBy|follow|unfollow)\()int (\$[a-zA-Z]+)/\1\3/g' \
    "$VENDOR_DIR/Models/Ticket.php"

sed -i.bak -E 's/public function assign\(Model\|int/public function assign(Model|string|int/' \
    "$VENDOR_DIR/Models/Ticket.php"

sed -i.bak -E 's/(public function (linkToUser|promoteToUser)\()int (\$[a-zA-Z]+)/\1\3/g' \
    "$VENDOR_DIR/Models/Contact.php"

sed -i.bak -E 's/(public static function forUser\()int (\$[a-zA-Z]+)/\1\2/g' \
    "$VENDOR_DIR/Models/AgentProfile.php"

# --- Contracts & Drivers ---
# TicketDriver interface: assignTicket
# LocalDriver, CloudDriver, SyncedDriver: assignTicket
sed -i.bak -E 's/(public function assignTicket\([^,]+, )int (\$[a-zA-Z]+)/\1\2/g' \
    "$VENDOR_DIR/Contracts/TicketDriver.php" \
    "$VENDOR_DIR/Drivers/LocalDriver.php" \
    "$VENDOR_DIR/Drivers/CloudDriver.php" \
    "$VENDOR_DIR/Drivers/SyncedDriver.php"

# --- Events ---
# TicketAssigned: constructor $agentId
sed -i.bak -E 's/(public )int (\$agentId)/\1\2/g' \
    "$VENDOR_DIR/Events/TicketAssigned.php"

# --- Services ---
# AssignmentService: assign, reassign, getAgentWorkload
sed -i.bak -E 's/(public function (assign|reassign)\([^,]+, )int (\$[a-zA-Z]+)/\1\3/g' \
    "$VENDOR_DIR/Services/AssignmentService.php"

sed -i.bak -E 's/(public function getAgentWorkload\()int (\$[a-zA-Z]+)/\1\2/g' \
    "$VENDOR_DIR/Services/AssignmentService.php"

# CapacityService: canAcceptTicket, incrementLoad, decrementLoad
sed -i.bak -E 's/(public function (canAcceptTicket|incrementLoad|decrementLoad)\()int (\$[a-zA-Z]+)/\1\3/g' \
    "$VENDOR_DIR/Services/CapacityService.php"

# ChatRoutingService: getAgentChatCount
sed -i.bak -E 's/(public function getAgentChatCount\()int (\$[a-zA-Z]+)/\1\2/g' \
    "$VENDOR_DIR/Services/ChatRoutingService.php"

# ChatAvailabilityService: getAgentChatCount
sed -i.bak -E 's/(public function getAgentChatCount\()int (\$[a-zA-Z]+)/\1\2/g' \
    "$VENDOR_DIR/Services/ChatAvailabilityService.php"

# ChatSessionService: assignAgent, sendMessage
sed -i.bak -E 's/(public function assignAgent\([^,]+, )int (\$[a-zA-Z]+)/\1\2/g' \
    "$VENDOR_DIR/Services/ChatSessionService.php"

sed -i.bak -E 's/(\?int \$userId)/?\ $userId/g' \
    "$VENDOR_DIR/Services/ChatSessionService.php"

# ReportingService: getAgentMetrics, agentResponseTimePercentiles
sed -i.bak -E 's/(public function (getAgentMetrics|agentResponseTimePercentiles)\()int (\$[a-zA-Z]+)/\1\3/g' \
    "$VENDOR_DIR/Services/ReportingService.php"

# --- Middleware ---
# CheckPermission: userHasPermission
sed -i.bak -E 's/(protected function userHasPermission\()int (\$[a-zA-Z]+)/\1\2/g' \
    "$VENDOR_DIR/Http/Middleware/CheckPermission.php"

# Clean up backups
find "$VENDOR_DIR" -name "*.bak" -delete

echo "  > Escalated UUID patch applied."

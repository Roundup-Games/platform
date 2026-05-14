<?php

namespace Database\Seeders;

use Escalated\Laravel\Models\CustomField;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\EscalationRule;
use Escalated\Laravel\Models\SlaPolicy;
use Escalated\Laravel\Models\Tag;
use Illuminate\Database\Seeder;

class EscalatedSetupSeeder extends Seeder
{
    /**
     * Seed Escalated helpdesk configuration data: departments, tags, and SLA policies.
     */
    public function run(): void
    {
        $this->seedDepartments();
        $this->seedTags();
        $this->seedSlaPolicies();
        $this->seedCustomFields();
        $this->seedEscalationRules();
    }

    protected function seedDepartments(): void
    {
        $departments = [
            [
                'name' => 'Contact',
                'description' => 'General inquiries and questions',
                'is_active' => true,
            ],
            [
                'name' => 'Game Systems',
                'description' => 'System requests, BGG-related issues',
                'is_active' => true,
            ],
            [
                'name' => 'Safety',
                'description' => 'Review reports, content moderation, user reports',
                'is_active' => true,
            ],
            [
                'name' => 'Events',
                'description' => 'Attendance disputes, event issues',
                'is_active' => true,
            ],
            [
                'name' => 'Billing',
                'description' => 'Payment issues, subscription questions',
                'is_active' => true,
            ],
            [
                'name' => 'Account Support',
                'description' => 'Account recovery, access issues, name changes',
                'is_active' => true,
            ],
        ];

        foreach ($departments as $dept) {
            Department::firstOrCreate(
                ['name' => $dept['name']],
                $dept
            );
        }
    }

    protected function seedTags(): void
    {
        $tags = [
            ['name' => 'bug', 'color' => '#EF4444'],
            ['name' => 'feature-request', 'color' => '#3B82F6'],
            ['name' => 'question', 'color' => '#6B7280'],
            ['name' => 'urgent', 'color' => '#F97316'],
            ['name' => 'inappropriate-content', 'color' => '#DC2626'],
            ['name' => 'harassment', 'color' => '#B91C1C'],
            ['name' => 'spam', 'color' => '#D97706'],
            ['name' => 'misleading', 'color' => '#EA580C'],
            ['name' => 'dispute', 'color' => '#7C3AED'],
            ['name' => 'bgg-sync', 'color' => '#059669'],
            ['name' => 'review-report', 'color' => '#E11D48'],
            ['name' => 'user-report', 'color' => '#BE185D'],
            ['name' => 'game-report', 'color' => '#DB2777'],
            ['name' => 'campaign-report', 'color' => '#EC4899'],
        ];

        foreach ($tags as $tag) {
            Tag::firstOrCreate(
                ['name' => $tag['name']],
                $tag
            );
        }
    }

    protected function seedSlaPolicies(): void
    {
        // SLA policies use JSON arrays keyed by TicketPriority value
        // Each policy defines first_response_hours and resolution_hours per priority level
        $policies = [
            [
                'name' => 'Safety SLA',
                'description' => 'Fast response for safety-related issues (reports, moderation, harassment)',
                'first_response_hours' => [
                    'low' => 8,
                    'medium' => 4,
                    'high' => 2,
                    'urgent' => 1,
                    'critical' => 0.5,
                ],
                'resolution_hours' => [
                    'low' => 48,
                    'medium' => 24,
                    'high' => 12,
                    'urgent' => 8,
                    'critical' => 4,
                ],
                'business_hours_only' => false,
                'is_default' => false,
                'is_active' => true,
            ],
            [
                'name' => 'Billing SLA',
                'description' => 'Response targets for payment issues and subscription questions',
                'first_response_hours' => [
                    'low' => 48,
                    'medium' => 24,
                    'high' => 12,
                    'urgent' => 4,
                    'critical' => 2,
                ],
                'resolution_hours' => [
                    'low' => 120,
                    'medium' => 72,
                    'high' => 48,
                    'urgent' => 24,
                    'critical' => 12,
                ],
                'business_hours_only' => false,
                'is_default' => false,
                'is_active' => true,
            ],
            [
                'name' => 'Contact SLA',
                'description' => 'General inquiries and questions',
                'first_response_hours' => [
                    'low' => 72,
                    'medium' => 48,
                    'high' => 24,
                    'urgent' => 12,
                    'critical' => 4,
                ],
                'resolution_hours' => [
                    'low' => 168,
                    'medium' => 120,
                    'high' => 72,
                    'urgent' => 48,
                    'critical' => 24,
                ],
                'business_hours_only' => false,
                'is_default' => true,
                'is_active' => true,
            ],
            [
                'name' => 'Game Systems SLA',
                'description' => 'System requests and BGG-related issues',
                'first_response_hours' => [
                    'low' => 96,
                    'medium' => 72,
                    'high' => 48,
                    'urgent' => 24,
                    'critical' => 8,
                ],
                'resolution_hours' => [
                    'low' => 240,
                    'medium' => 168,
                    'high' => 120,
                    'urgent' => 72,
                    'critical' => 48,
                ],
                'business_hours_only' => false,
                'is_default' => false,
                'is_active' => true,
            ],
            [
                'name' => 'Events SLA',
                'description' => 'Attendance disputes and event issues',
                'first_response_hours' => [
                    'low' => 48,
                    'medium' => 24,
                    'high' => 12,
                    'urgent' => 4,
                    'critical' => 2,
                ],
                'resolution_hours' => [
                    'low' => 120,
                    'medium' => 72,
                    'high' => 48,
                    'urgent' => 24,
                    'critical' => 12,
                ],
                'business_hours_only' => false,
                'is_default' => false,
                'is_active' => true,
            ],
            [
                'name' => 'Account Support SLA',
                'description' => 'Account recovery, access issues, name changes',
                'first_response_hours' => [
                    'low' => 48,
                    'medium' => 24,
                    'high' => 12,
                    'urgent' => 4,
                    'critical' => 2,
                ],
                'resolution_hours' => [
                    'low' => 96,
                    'medium' => 48,
                    'high' => 24,
                    'urgent' => 12,
                    'critical' => 8,
                ],
                'business_hours_only' => false,
                'is_default' => false,
                'is_active' => true,
            ],
        ];

        foreach ($policies as $policy) {
            SlaPolicy::firstOrCreate(
                ['name' => $policy['name']],
                $policy
            );
        }
    }

    protected function seedCustomFields(): void
    {
        $fields = [
            [
                'name' => 'BGG URL',
                'slug' => 'bgg_url',
                'type' => 'text',
                'context' => 'ticket',
                'description' => 'BoardGameGeek URL for the requested game system',
                'required' => false,
                'position' => 10,
                'active' => true,
            ],
            [
                'name' => 'Publisher',
                'slug' => 'publisher',
                'type' => 'text',
                'context' => 'ticket',
                'description' => 'Publisher of the requested game system',
                'required' => false,
                'position' => 20,
                'active' => true,
            ],
            [
                'name' => 'Designer',
                'slug' => 'designer',
                'type' => 'text',
                'context' => 'ticket',
                'description' => 'Designer of the requested game system',
                'required' => false,
                'position' => 30,
                'active' => true,
            ],
            [
                'name' => 'Game System Type',
                'slug' => 'game_system_type',
                'type' => 'select',
                'context' => 'ticket',
                'description' => 'Type of game system being requested',
                'options' => [
                    ['label' => 'Board Game', 'value' => 'boardgame'],
                    ['label' => 'TTRPG', 'value' => 'ttrpg'],
                    ['label' => 'Other', 'value' => 'other'],
                ],
                'required' => true,
                'position' => 40,
                'active' => true,
            ],
            [
                'name' => 'Game System ID',
                'slug' => 'game_system_id',
                'type' => 'text',
                'context' => 'ticket',
                'description' => 'UUID of the created or matched GameSystem (set on approval or duplicate)',
                'required' => false,
                'position' => 50,
                'active' => true,
            ],
        ];

        foreach ($fields as $field) {
            CustomField::firstOrCreate(
                ['slug' => $field['slug']],
                $field
            );
        }
    }

    protected function seedEscalationRules(): void
    {
        $safety = Department::where('name', 'Safety')->first();

        $rules = [
            [
                'name' => 'Safety Ticket Auto-Escalation',
                'description' => 'Auto-escalate Safety department tickets unresolved after 4 hours to urgent priority',
                'trigger_type' => 'time_based',
                'category' => 'Safety',
                'conditions' => array_filter([
                    ['field' => 'department_id', 'value' => $safety?->id],
                    ['field' => 'age_hours', 'value' => 4],
                ]),
                'actions' => [
                    ['type' => 'escalate'],
                    ['type' => 'change_priority', 'value' => 'urgent'],
                ],
                'is_active' => true,
                'order' => 10,
            ],
        ];

        foreach ($rules as $rule) {
            // Only seed if Safety department exists (conditions require department_id)
            if ($safety) {
                EscalationRule::firstOrCreate(
                    ['name' => $rule['name']],
                    $rule
                );
            }
        }
    }
}

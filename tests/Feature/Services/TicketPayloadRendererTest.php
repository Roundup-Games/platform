<?php

use App\Models\User;
use App\Services\TicketPayloadRenderer;
use Database\Seeders\EscalatedSetupSeeder;
use Escalated\Laravel\Enums\TicketChannel;
use Escalated\Laravel\Enums\TicketPriority;
use Escalated\Laravel\Models\Department;
use Escalated\Laravel\Models\Ticket;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class TicketPayloadRendererTest extends TestCase
{
    use DatabaseTransactions;

    private TicketPayloadRenderer $renderer;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(EscalatedSetupSeeder::class);
        $this->renderer = app(TicketPayloadRenderer::class);
        $this->user = User::factory()->create(['name' => 'Test User']);
    }

    // ── Builders: schema shape ─────────────────────────────

    public function test_content_report_payload_has_schema_and_flat_keys(): void
    {
        $payload = TicketPayloadRenderer::contentReportPayload(
            $this->user,
            'game',
            'game-uuid',
            'Test Game',
            'spam',
        );

        $this->assertEquals('content_report/v1', $payload['schema']);
        $this->assertEquals('report', $payload['action']);
        $actor = $payload['actor'];
        $this->assertIsArray($actor);
        $this->assertEquals($this->user->id, $actor['id']);
        $entities = $payload['entities'];
        $this->assertIsArray($entities);
        $this->assertCount(1, $entities);
        // Flat keys for backward compat
        $this->assertEquals('game', $payload['entity_type']);
        $this->assertEquals('game-uuid', $payload['entity_id']);
        $this->assertEquals('spam', $payload['report_reason']);
        $this->assertEquals($this->user->id, $payload['reporter_id']);
    }

    public function test_review_report_payload_has_schema_and_flat_keys(): void
    {
        $payload = TicketPayloadRenderer::reviewReportPayload(
            $this->user,
            'review-uuid',
            'author-uuid',
            'Author Name',
            'harassment',
        );

        $this->assertEquals('review_report/v1', $payload['schema']);
        $this->assertEquals('review-uuid', $payload['review_id']);
        $this->assertEquals('author-uuid', $payload['review_author_id']);
        $this->assertEquals('harassment', $payload['report_reason']);
        $this->assertNotNull($payload['reported_user']);
    }

    public function test_billing_support_payload_includes_subscription_context(): void
    {
        $payload = TicketPayloadRenderer::billingSupportPayload(
            $this->user,
            'billing_issue',
            null,
            ['has_subscription' => true, 'subscription_status' => 'active', 'paddle_subscription_id' => 'sub_123'],
        );

        $this->assertEquals('billing_support/v1', $payload['schema']);
        $this->assertTrue($payload['has_subscription']);
        $this->assertEquals('active', $payload['subscription_status']);
        $this->assertEquals('sub_123', $payload['paddle_subscription_id']);
    }

    public function test_data_export_payload_has_correct_schema(): void
    {
        $payload = TicketPayloadRenderer::dataExportPayload($this->user);

        $this->assertEquals('data_export/v1', $payload['schema']);
        $this->assertEquals('export', $payload['action']);
        $this->assertEquals('data_request', $payload['reason']);
    }

    public function test_game_system_request_payload_has_flat_keys(): void
    {
        $payload = TicketPayloadRenderer::gameSystemRequestPayload(
            $this->user,
            'Wingspan',
            'https://boardgamegeek.com/boardgame/266192/wingspan',
            'Stonemaier Games',
            'Elizabeth Hargrave',
            'boardgame',
            'Please add.',
        );

        $this->assertEquals('game_system_request/v1', $payload['schema']);
        $this->assertEquals('Wingspan', $payload['game_system_name']);
        $this->assertEquals('boardgame', $payload['game_system_type']);
        $this->assertTrue($payload['game_system_request']);
    }

    // ── Renderer: HTML output ──────────────────────────────

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function createTicket(string $type, array $metadata, string $subject = 'Test'): Ticket
    {
        $department = Department::firstOrFail();

        return Ticket::create([
            'subject' => $subject,
            'description' => 'Test description',
            'department_id' => $department->id,
            'requester_type' => User::class,
            'requester_id' => $this->user->id,
            'ticket_type' => $type,
            'metadata' => $metadata,
            'status' => 'open',
            'priority' => TicketPriority::Medium->value,
            'channel' => TicketChannel::Web->value,
        ]);
    }

    public function test_render_returns_null_for_empty_metadata(): void
    {
        $ticket = $this->createTicket('content_report', []);

        $this->assertNull($this->renderer->renderStructured($ticket));
    }

    public function test_render_content_report_outputs_reason_and_entity(): void
    {
        $ticket = $this->createTicket('content_report', TicketPayloadRenderer::contentReportPayload(
            $this->user, 'game', 'game-uuid', 'Test Game', 'spam', 'Some details',
        ));

        $html = $this->renderer->renderStructured($ticket);

        $this->assertNotNull($html);
        $this->assertStringContainsString('Test Game', $html);
        $this->assertStringContainsString('Some details', $html);
    }

    public function test_render_game_system_request_shows_name_and_type(): void
    {
        $ticket = $this->createTicket('game_system_request', TicketPayloadRenderer::gameSystemRequestPayload(
            $this->user, 'Wingspan', null, 'Stonemaier', 'Hargrave', 'boardgame', 'Notes here',
        ));

        $html = $this->renderer->renderStructured($ticket);

        $this->assertNotNull($html);
        $this->assertStringContainsString('Wingspan', $html);
        $this->assertStringContainsString('Board Game', $html);
    }

    public function test_render_game_system_request_links_safe_bgg_url(): void
    {
        $ticket = $this->createTicket('game_system_request', TicketPayloadRenderer::gameSystemRequestPayload(
            $this->user, 'Wingspan', 'https://boardgamegeek.com/boardgame/296912/wingspan', null, null, 'boardgame', null,
        ));

        $html = $this->renderer->renderStructured($ticket);

        $this->assertNotNull($html);
        $this->assertStringContainsString('href="https://boardgamegeek.com/boardgame/296912/wingspan"', $html);
    }

    public function test_render_game_system_request_never_links_unsafe_bgg_url_scheme(): void
    {
        // Laravel's `url` rule (filter_var) accepts the javascript:// scheme, so it can
        // reach the metadata. It must never be emitted as a clickable href — that would
        // be stored XSS executing in the admin session when clicked.
        $ticket = $this->createTicket('game_system_request', TicketPayloadRenderer::gameSystemRequestPayload(
            $this->user, 'Evil', 'javascript://x/%0aalert(document.cookie)', null, null, 'boardgame', null,
        ));

        $html = $this->renderer->renderStructured($ticket);

        $this->assertNotNull($html);
        $this->assertStringNotContainsString('href="javascript:', $html);
        $this->assertStringNotContainsString("href='javascript:", $html);
        // The value is still shown (auto-escaped as text), not executed.
        $this->assertStringContainsString('javascript://x/', $html);
    }

    public function test_render_billing_support_shows_subscription_context(): void
    {
        $ticket = $this->createTicket('billing_support', TicketPayloadRenderer::billingSupportPayload(
            $this->user, 'billing_issue', 'Details here',
            ['has_subscription' => true, 'subscription_status' => 'active', 'paddle_subscription_id' => 'sub_123'],
        ));

        $html = $this->renderer->renderStructured($ticket);

        $this->assertNotNull($html);
        $this->assertStringContainsString('active', $html);
        $this->assertStringContainsString('sub_123', $html);
    }

    public function test_render_escapes_user_supplied_values(): void
    {
        $ticket = $this->createTicket('content_report', TicketPayloadRenderer::contentReportPayload(
            $this->user, 'game', 'game-uuid', '<script>alert(1)</script>', 'spam', '<img src=x onerror=alert(1)>',
        ));

        $html = $this->renderer->renderStructured($ticket);

        $this->assertNotNull($html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_render_normalizes_legacy_flat_key_metadata(): void
    {
        // Legacy ticket with flat keys but no schema key
        $ticket = $this->createTicket('content_report', [
            'entity_type' => 'game',
            'entity_id' => 'legacy-uuid',
            'entity_name' => 'Legacy Game',
            'report_reason' => 'spam',
            'reporter_id' => $this->user->id,
            'description' => 'Legacy description',
        ]);

        $html = $this->renderer->renderStructured($ticket);

        $this->assertNotNull($html);
        $this->assertStringContainsString('Legacy Game', $html);
    }

    public function test_render_review_report_shows_review_author(): void
    {
        $author = User::factory()->create(['name' => 'Jane Author']);
        $ticket = $this->createTicket('review_report', TicketPayloadRenderer::reviewReportPayload(
            $this->user, 'review-uuid', (string) $author->id, 'Jane Author', 'harassment',
        ));

        $html = $this->renderer->renderStructured($ticket);

        $this->assertNotNull($html);
        $this->assertStringContainsString('Jane Author', $html);
        // Must NOT be rendered as a content_report (which has entities but no reported_user)
        $this->assertStringContainsString(__('Review author'), $html);
    }

    public function test_render_context_localizes_booleans_and_labels(): void
    {
        $ticket = $this->createTicket('content_report', array_merge(
            TicketPayloadRenderer::contentReportPayload(
                $this->user, 'game', 'game-uuid', 'Test Game', 'spam',
            ),
            ['context' => [
                'entity_owner' => 'Owner Name',
                'has_subscription' => true,
            ]],
        ));

        $html = $this->renderer->renderStructured($ticket);

        $this->assertNotNull($html);
        // Label uses original casing: 'Entity owner' (lowercase o), not 'Entity Owner'
        $this->assertStringContainsString(__('Entity owner'), $html);
        // Boolean is localized, not hardcoded 'Yes'
        $this->assertStringContainsString(__('Yes'), $html);
    }

    public function test_render_context_json_encodes_array_values(): void
    {
        $ticket = $this->createTicket('content_report', array_merge(
            TicketPayloadRenderer::contentReportPayload(
                $this->user, 'game', 'game-uuid', 'Test Game', 'spam',
            ),
            ['context' => ['nested_data' => ['a' => 1, 'b' => 2]]],
        ));

        $html = $this->renderer->renderStructured($ticket);

        $this->assertNotNull($html);
        // Array values are JSON-encoded then HTML-escaped (quotes become &quot;)
        $this->assertStringContainsString('{&quot;a&quot;:1,&quot;b&quot;:2}', $html);
        $this->assertStringNotContainsString('>Array<', $html);
    }
}

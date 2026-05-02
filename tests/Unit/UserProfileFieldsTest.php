<?php

namespace Tests\Unit;

use App\Enums\ContentLanguage;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Tests\Traits\SetsUpLocale;

class UserProfileFieldsTest extends TestCase
{
    use DatabaseTransactions;
    use SetsUpLocale;

    // ── preferred_language ────────────────────────────

    public function test_preferred_language_defaults_to_null(): void
    {
        $user = User::factory()->create();

        $this->assertNull($user->preferred_language);
    }

    public function test_preferred_language_casts_to_content_language_enum(): void
    {
        $user = User::factory()->create([
            'preferred_language' => 'en',
        ]);

        $this->assertInstanceOf(ContentLanguage::class, $user->preferred_language);
        $this->assertEquals(ContentLanguage::En, $user->preferred_language);
    }

    public function test_preferred_language_stores_german(): void
    {
        $user = User::factory()->create([
            'preferred_language' => 'de',
        ]);

        $this->assertEquals(ContentLanguage::De, $user->preferred_language);
    }

    public function test_preferred_language_can_be_set_after_creation(): void
    {
        $user = User::factory()->create();
        $user->preferred_language = ContentLanguage::De;
        $user->save();

        $user->refresh();
        $this->assertEquals(ContentLanguage::De, $user->preferred_language);
    }

    public function test_preferred_language_can_be_set_to_null(): void
    {
        $user = User::factory()->create(['preferred_language' => 'en']);

        $user->preferred_language = null;
        $user->save();

        $user->refresh();
        $this->assertNull($user->preferred_language);
    }

    // ── location ──────────────────────────────────────

    public function test_location_defaults_to_null(): void
    {
        $user = User::factory()->create();

        $this->assertNull($user->location);
    }

    public function test_location_casts_to_array(): void
    {
        $locationData = [
            'address' => '123 Main St, Berlin, Germany',
            'lat' => 52.5200,
            'lng' => 13.4050,
            'placeId' => 'ChIJAVkDPzdOqEcRcDteW0YgIQQ',
        ];

        $user = User::factory()->create([
            'location' => $locationData,
        ]);

        $this->assertIsArray($user->location);
        $this->assertEquals('123 Main St, Berlin, Germany', $user->location['address']);
        $this->assertEquals(52.5200, $user->location['lat']);
        $this->assertEquals(13.4050, $user->location['lng']);
        $this->assertEquals('ChIJAVkDPzdOqEcRcDteW0YgIQQ', $user->location['placeId']);
    }

    public function test_location_can_store_partial_data(): void
    {
        $locationData = [
            'address' => 'Munich, Germany',
        ];

        $user = User::factory()->create([
            'location' => $locationData,
        ]);

        $this->assertIsArray($user->location);
        $this->assertEquals('Munich, Germany', $user->location['address']);
        $this->assertArrayNotHasKey('lat', $user->location);
    }

    public function test_location_can_be_set_after_creation(): void
    {
        $user = User::factory()->create();

        $user->location = [
            'address' => 'Hamburg, Germany',
            'lat' => 53.5511,
            'lng' => 9.9937,
            'placeId' => 'ChIJuRMYfoNhsUcRoK6DTG0U7VM',
        ];
        $user->save();

        $user->refresh();
        $this->assertIsArray($user->location);
        $this->assertEquals('Hamburg, Germany', $user->location['address']);
    }

    public function test_location_can_be_set_to_null(): void
    {
        $user = User::factory()->create([
            'location' => ['address' => 'Berlin'],
        ]);

        $user->location = null;
        $user->save();

        $user->refresh();
        $this->assertNull($user->location);
    }

    // ── Both fields together ──────────────────────────

    public function test_user_can_have_both_language_and_location(): void
    {
        $user = User::factory()->create([
            'preferred_language' => 'de',
            'location' => [
                'address' => 'Frankfurt, Germany',
                'lat' => 50.1109,
                'lng' => 8.6821,
                'placeId' => 'ChIJyS1T42NLqEcRUQ6uoC3Mddo',
            ],
        ]);

        $this->assertEquals(ContentLanguage::De, $user->preferred_language);
        $this->assertIsArray($user->location);
        $this->assertEquals('Frankfurt, Germany', $user->location['address']);
    }

    public function test_user_in_fillable_includes_new_fields(): void
    {
        $user = User::factory()->create();

        // Verify fillable includes the new fields
        $this->assertContains('preferred_language', $user->getFillable());
        $this->assertContains('location', $user->getFillable());
    }
}

<?php

namespace Tests\Feature;

use App\Enums\RelationshipType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserRelationshipsMigrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function table_exists_after_migration(): void
    {
        $this->assertTrue(
            DB::getSchemaBuilder()->hasTable('user_relationships'),
            'user_relationships table should exist after migration.'
        );
    }

    #[Test]
    public function table_has_expected_columns(): void
    {
        $columns = DB::getSchemaBuilder()->getColumnListing('user_relationships');

        $this->assertContains('id', $columns);
        $this->assertContains('user_id', $columns);
        $this->assertContains('related_user_id', $columns);
        $this->assertContains('type', $columns);
        $this->assertContains('created_at', $columns);
        $this->assertContains('updated_at', $columns);
    }

    #[Test]
    public function unique_constraint_prevents_duplicate_relationships(): void
    {
        $user = User::factory()->create();
        $related = User::factory()->create();

        DB::table('user_relationships')->insert([
            'user_id' => $user->id,
            'related_user_id' => $related->id,
            'type' => 'follow',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('user_relationships')->insert([
            'user_id' => $user->id,
            'related_user_id' => $related->id,
            'type' => 'follow',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function same_users_can_have_different_relationship_types(): void
    {
        $user = User::factory()->create();
        $related = User::factory()->create();

        // Should not throw — follow and block are different types
        DB::table('user_relationships')->insert([
            'user_id' => $user->id,
            'related_user_id' => $related->id,
            'type' => 'follow',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('user_relationships')->insert([
            'user_id' => $user->id,
            'related_user_id' => $related->id,
            'type' => 'block',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertEquals(2, DB::table('user_relationships')->count());
    }

    #[Test]
    public function cascade_delete_removes_relationships_on_user_deletion(): void
    {
        $user = User::factory()->create();
        $related = User::factory()->create();

        DB::table('user_relationships')->insert([
            'user_id' => $user->id,
            'related_user_id' => $related->id,
            'type' => 'follow',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('user_relationships')->insert([
            'user_id' => $related->id,
            'related_user_id' => $user->id,
            'type' => 'block',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user->delete();

        // Both rows involving the deleted user (as user_id or related_user_id) should be gone
        $this->assertEquals(0, DB::table('user_relationships')->count());
    }

    #[Test]
    public function relationship_type_enum_has_expected_cases(): void
    {
        $this->assertEquals('follow', RelationshipType::Follow->value);
        $this->assertEquals('block', RelationshipType::Block->value);
        $this->assertCount(2, RelationshipType::cases());
    }

    #[Test]
    public function relationship_type_values_method_returns_all_values(): void
    {
        $values = RelationshipType::values();

        $this->assertContains('follow', $values);
        $this->assertContains('block', $values);
        $this->assertCount(2, $values);
    }
}

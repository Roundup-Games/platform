<?php

namespace Tests\Unit\Enums;

use App\Enums\ParticipantRole;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ParticipantRoleTest extends TestCase
{
    public function test_enum_cases_have_correct_values(): void
    {
        $this->assertSame('owner', ParticipantRole::Owner->value);
        $this->assertSame('player', ParticipantRole::Player->value);
        $this->assertSame('invited', ParticipantRole::Invited->value);
        $this->assertSame('applicant', ParticipantRole::Applicant->value);
    }

    public function test_values_returns_all_case_values(): void
    {
        $values = ParticipantRole::values();

        $this->assertSame(['owner', 'player', 'invited', 'applicant'], $values);
    }

    public function test_labels(): void
    {
        $this->assertSame('Owner', ParticipantRole::Owner->label());
        $this->assertSame('Player', ParticipantRole::Player->label());
        $this->assertSame('Invited', ParticipantRole::Invited->label());
        $this->assertSame('Applicant', ParticipantRole::Applicant->label());
    }

    public function test_is_owner(): void
    {
        $this->assertTrue(ParticipantRole::Owner->isOwner());
        $this->assertFalse(ParticipantRole::Player->isOwner());
        $this->assertFalse(ParticipantRole::Invited->isOwner());
        $this->assertFalse(ParticipantRole::Applicant->isOwner());
    }

    public function test_is_player(): void
    {
        $this->assertTrue(ParticipantRole::Player->isPlayer());
        $this->assertFalse(ParticipantRole::Owner->isPlayer());
        $this->assertFalse(ParticipantRole::Invited->isPlayer());
        $this->assertFalse(ParticipantRole::Applicant->isPlayer());
    }
}

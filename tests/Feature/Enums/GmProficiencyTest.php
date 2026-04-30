<?php

use App\Enums\GmProficiency;

describe('GmProficiency Enum', function () {
    it('returns correct values for all cases', function () {
        $expected = [
            'creativity', 'inclusive', 'knows-the-rules', 'rule-of-cool',
            'sets-the-mood', 'storytelling', 'teacher', 'visual-aid',
            'voices', 'world-builder',
        ];
        expect(GmProficiency::values())->toBe($expected);
    });

    it('label() returns a translatable string for each case', function () {
        foreach (GmProficiency::cases() as $case) {
            $label = $case->label();
            expect($label)->toBeString();
            expect($label)->not->toBeEmpty("{$case->value} label should not be empty");
            expect($label)->not->toBe($case->value, "{$case->value} label should be a translation, not the raw value");
        }
    });

    it('label() returns the expected translation keys', function () {
        expect(GmProficiency::Creativity->label())->toBe(__('profile.gm_proficiency_creativity'));
        expect(GmProficiency::Inclusive->label())->toBe(__('profile.gm_proficiency_inclusive'));
        expect(GmProficiency::KnowsTheRules->label())->toBe(__('profile.gm_proficiency_knows_the_rules'));
        expect(GmProficiency::RuleOfCool->label())->toBe(__('profile.gm_proficiency_rule_of_cool'));
        expect(GmProficiency::SetsTheMood->label())->toBe(__('profile.gm_proficiency_sets_the_mood'));
        expect(GmProficiency::Storytelling->label())->toBe(__('profile.gm_proficiency_storytelling'));
        expect(GmProficiency::Teacher->label())->toBe(__('profile.gm_proficiency_teacher'));
        expect(GmProficiency::VisualAid->label())->toBe(__('profile.gm_proficiency_visual_aid'));
        expect(GmProficiency::Voices->label())->toBe(__('profile.gm_proficiency_voices'));
        expect(GmProficiency::WorldBuilder->label())->toBe(__('profile.gm_proficiency_world_builder'));
    });

    it('description() returns non-empty string for each case', function () {
        foreach (GmProficiency::cases() as $case) {
            $desc = $case->description();
            expect($desc)->toBeString();
            expect($desc)->not->toBeEmpty("{$case->value} description should not be empty");
        }
    });

    it('description() returns the expected translation keys', function () {
        expect(GmProficiency::Creativity->description())->toBe(__('profile.gm_proficiency_creativity_desc'));
        expect(GmProficiency::Inclusive->description())->toBe(__('profile.gm_proficiency_inclusive_desc'));
        expect(GmProficiency::KnowsTheRules->description())->toBe(__('profile.gm_proficiency_knows_the_rules_desc'));
        expect(GmProficiency::RuleOfCool->description())->toBe(__('profile.gm_proficiency_rule_of_cool_desc'));
        expect(GmProficiency::SetsTheMood->description())->toBe(__('profile.gm_proficiency_sets_the_mood_desc'));
        expect(GmProficiency::Storytelling->description())->toBe(__('profile.gm_proficiency_storytelling_desc'));
        expect(GmProficiency::Teacher->description())->toBe(__('profile.gm_proficiency_teacher_desc'));
        expect(GmProficiency::VisualAid->description())->toBe(__('profile.gm_proficiency_visual_aid_desc'));
        expect(GmProficiency::Voices->description())->toBe(__('profile.gm_proficiency_voices_desc'));
        expect(GmProficiency::WorldBuilder->description())->toBe(__('profile.gm_proficiency_world_builder_desc'));
    });

    it('descriptions are longer than labels', function () {
        foreach (GmProficiency::cases() as $case) {
            expect(strlen($case->description()))->toBeGreaterThan(
                strlen($case->label()),
                "{$case->value} description should be longer than label"
            );
        }
    });

    it('all values are kebab-case', function () {
        foreach (GmProficiency::values() as $value) {
            expect($value)->toMatch('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', "{$value} should be kebab-case");
        }
    });

    it('no duplicate labels across cases', function () {
        $labels = array_map(fn ($case) => $case->label(), GmProficiency::cases());
        expect($labels)->toHaveCount(count(array_unique($labels)));
    });
});

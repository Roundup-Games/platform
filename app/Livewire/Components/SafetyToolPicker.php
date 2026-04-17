<?php

namespace App\Livewire\Components;

use App\Enums\SafetyTool;
use App\Enums\SafetyToolCategory;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Reusable safety tool picker with category-grouped checkboxes.
 *
 * Renders 9 safety tools grouped by category (Before / During / After the Game),
 * each as a checkbox with label, short description, and an expandable info panel
 * for the full description. Lines & Veils shows a conditional textarea when selected.
 * A custom note textarea is always visible.
 *
 * Modes:
 *   'selection'  — For game/campaign creation. Tools stored as selected array.
 *   'display'    — Read-only display of selected tools (no interaction).
 *
 * Usage in parent Blade:
 *   <livewire:components.safety-tool-picker
 *       :selected="$existingTools"
 *       :linesAndVeilsText="$existingLinesAndVeils"
 *       :customNote="$existingNote"
 *       mode="selection"
 *   />
 *
 * Dispatches:
 *   safety-tools-changed — { safetyRules: { tools: string[], lines_and_veils_text: string, custom_note: string } }
 */
class SafetyToolPicker extends Component
{
    /** @var string[] Selected safety tool values */
    public array $selected = [];

    /** @var string Text content for Lines & Veils */
    public string $linesAndVeilsText = '';

    /** @var string Custom safety note */
    public string $customNote = '';

    public string $mode = 'selection';

    public function mount(
        array $selected = [],
        string $linesAndVeilsText = '',
        string $customNote = '',
        string $mode = 'selection',
    ): void {
        $this->selected = $selected;
        $this->linesAndVeilsText = $linesAndVeilsText;
        $this->customNote = $customNote;
        $this->mode = $mode;
    }

    /**
     * Toggle a safety tool on/off in the selected array.
     * When deselecting Lines & Veils, clear the associated text.
     */
    public function toggleTool(string $tool): void
    {
        $safetyTool = SafetyTool::tryFrom($tool);
        if (! $safetyTool) {
            return;
        }

        if (in_array($tool, $this->selected)) {
            $this->selected = array_values(array_filter($this->selected, fn ($t) => $t !== $tool));

            // Clear Lines & Veils text when deselecting
            if ($safetyTool === SafetyTool::LinesAndVeils) {
                $this->linesAndVeilsText = '';
            }
        } else {
            $this->selected[] = $tool;
        }

        $this->dispatch('safety-tools-changed', safetyRules: $this->getSafetyRules());
    }

    /**
     * Update the Lines & Veils text and dispatch change event.
     */
    public function updatedLinesAndVeilsText(): void
    {
        $this->dispatch('safety-tools-changed', safetyRules: $this->getSafetyRules());
    }

    /**
     * Update the custom note and dispatch change event.
     */
    public function updatedCustomNote(): void
    {
        $this->dispatch('safety-tools-changed', safetyRules: $this->getSafetyRules());
    }

    /**
     * Get the structured safety rules payload for parent forms.
     *
     * @return array{tools: string[], lines_and_veils_text: string, custom_note: string}
     */
    #[Computed]
    public function getSafetyRules(): array
    {
        return [
            'tools' => $this->selected,
            'lines_and_veils_text' => $this->linesAndVeilsText,
            'custom_note' => $this->customNote,
        ];
    }

    /**
     * Get tools grouped by category for template rendering.
     *
     * @return array<int, array{category: SafetyToolCategory, tools: array<int, array{value: string, label: string, shortDescription: string, fullDescription: string, supportsText: bool, textPlaceholder: string, isSelected: bool}>}>
     */
    #[Computed]
    public function getGroupedTools(): array
    {
        $result = [];

        foreach (SafetyToolCategory::cases() as $category) {
            $tools = [];

            foreach (SafetyTool::cases() as $tool) {
                if ($tool->category() === $category) {
                    $tools[] = [
                        'value' => $tool->value,
                        'label' => $tool->label(),
                        'shortDescription' => $tool->shortDescription(),
                        'fullDescription' => $tool->fullDescription(),
                        'supportsText' => $tool->supportsText(),
                        'textPlaceholder' => $tool->textPlaceholder(),
                        'isSelected' => in_array($tool->value, $this->selected),
                    ];
                }
            }

            $result[] = [
                'category' => $category,
                'tools' => $tools,
            ];
        }

        return $result;
    }

    public function render()
    {
        return view('livewire.components.safety-tool-picker');
    }
}

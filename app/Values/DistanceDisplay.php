<?php

namespace App\Values;

/**
 * Immutable display value for a location distance, produced exclusively by
 * LocationDisclosureService.
 *
 * Three mutually-exclusive modes:
 *
 *  - MODE_HIDDEN:  no distance should be rendered (blocked viewer, null location).
 *  - MODE_PRECISE: an exact km figure is safe to show (verified commercial venue).
 *  - MODE_GRID:    a D060 grid-snapped bucket (private/unverified locations),
 *                  optionally flagged as "In your area" when the viewer shares a
 *                  geohash tile with the location or sits within 5km.
 *
 * The grid-snap math mirrors the reference implementation at
 * resources/views/livewire/people/people-page.blade.php:258
 * (max(5, round($distanceKm / 5) * 5)).
 *
 * This object carries no behaviour beyond presentation — it is the narrow
 * surface views render from, so the trilateration defence is auditable here.
 */
final class DistanceDisplay
{
    public const MODE_HIDDEN = 'hidden';

    public const MODE_PRECISE = 'precise';

    public const MODE_GRID = 'grid';

    public readonly string $mode;

    public readonly ?float $preciseKm;

    public readonly ?int $bucketKm;

    public readonly bool $inArea;

    private function __construct(
        string $mode,
        ?float $preciseKm = null,
        ?int $bucketKm = null,
        bool $inArea = false,
    ) {
        $this->mode = $mode;
        $this->preciseKm = $preciseKm;
        $this->bucketKm = $bucketKm;
        $this->inArea = $inArea;
    }

    /**
     * Precise distance (verified commercial venue only).
     */
    public static function precise(float $km): self
    {
        return new self(self::MODE_PRECISE, preciseKm: max(0.0, $km));
    }

    /**
     * Grid-snapped distance bucket.
     *
     * @param  int  $bucketKm  Snapped value, always a multiple of 5 with a 5km floor.
     * @param  bool  $inArea  True when the viewer shares a tile or is within 5km.
     */
    public static function gridSnapped(int $bucketKm, bool $inArea): self
    {
        return new self(self::MODE_GRID, bucketKm: $bucketKm, inArea: $inArea);
    }

    /**
     * No distance should be rendered.
     */
    public static function hidden(): self
    {
        return new self(self::MODE_HIDDEN);
    }

    public function isHidden(): bool
    {
        return $this->mode === self::MODE_HIDDEN;
    }

    public function isPrecise(): bool
    {
        return $this->mode === self::MODE_PRECISE;
    }

    public function isGridSnapped(): bool
    {
        return $this->mode === self::MODE_GRID;
    }

    /**
     * Human-readable, locale-aware display string.
     *
     * Empty string when hidden so views can render unconditionally.
     */
    public function display(): string
    {
        return match ($this->mode) {
            self::MODE_HIDDEN => '',
            self::MODE_GRID => $this->inArea
                ? __('people.nearby_in_your_area')
                : __('people.nearby_distance_label', ['distance' => (string) $this->bucketKm]),
            self::MODE_PRECISE => __('people.nearby_precise_distance', [
                'distance' => is_float($this->preciseKm) ? number_format($this->preciseKm, 1) : '',
            ]),
            default => '',
        };
    }
}

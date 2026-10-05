<?php

namespace App\Enums;

enum PredictionClass: string
{
    case Normal = 'NORMAL';

    case Pneumonia = 'PNEUMONIA';

    /**
     * Every supported label, in canonical display order.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $case): string => $case->value,
            self::cases(),
        );
    }

    /**
     * Resolve an incoming label leniently, since the inference service is an
     * external boundary that may pad or change case.
     */
    public static function tryFromLabel(string $label): ?self
    {
        return self::tryFrom(mb_strtoupper(trim($label)));
    }

    /**
     * Semantic colour role for this outcome, used by the result view.
     *
     * Kept next to the label so the interface never guesses a tone from the
     * name itself.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Normal => 'positive',
            self::Pneumonia => 'critical',
        };
    }
}

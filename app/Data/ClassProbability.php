<?php

namespace App\Data;

use App\Enums\PredictionClass;

/**
 * Probability that the model assigned to a single class.
 */
final readonly class ClassProbability
{
    public function __construct(
        public PredictionClass $class,
        public float $probability,
    ) {}

    /**
     * Value in the 0-100 range, rounded for display only.
     */
    public function percentage(): float
    {
        return round($this->probability * 100, 1);
    }

    public function label(): string
    {
        return $this->class->value;
    }

    /**
     * @return array{class: string, probability: float, percentage: float}
     */
    public function toArray(): array
    {
        return [
            'class' => $this->label(),
            'probability' => $this->probability,
            'percentage' => $this->percentage(),
        ];
    }
}

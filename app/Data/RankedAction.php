<?php

namespace App\Data;

use App\Exceptions\InvalidInferenceResponseException;

/**
 * One action in the RL agent's ranking, with its current Q-value.
 */
final readonly class RankedAction
{
    public function __construct(
        public string $id,
        public string $label,
        public float $qValue,
    ) {}

    /**
     * @throws InvalidInferenceResponseException
     */
    public static function fromArray(mixed $raw): self
    {
        if (! is_array($raw)) {
            throw InvalidInferenceResponseException::malformed('a ranked action is not an object');
        }

        $id = $raw['id'] ?? null;
        $label = $raw['label'] ?? null;
        $qValue = $raw['q_value'] ?? null;

        if (! is_string($id) || trim($id) === '') {
            throw InvalidInferenceResponseException::malformed('a ranked action has no id');
        }

        if (! is_string($label) || trim($label) === '') {
            throw InvalidInferenceResponseException::malformed('a ranked action has no label');
        }

        if (! is_numeric($qValue) || ! is_finite((float) $qValue)) {
            throw InvalidInferenceResponseException::malformed('a ranked action has an invalid q_value');
        }

        return new self($id, $label, (float) $qValue);
    }

    /**
     * @return array{id: string, label: string, q_value: float}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'q_value' => $this->qValue,
        ];
    }
}

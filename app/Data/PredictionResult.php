<?php

namespace App\Data;

use App\Enums\PredictionClass;
use App\Exceptions\InvalidInferenceResponseException;

/**
 * Validated prediction produced by the inference service.
 *
 * The shape is accepted only after every invariant holds, so consumers can
 * trust that the configured classes are all present and form a distribution.
 */
final readonly class PredictionResult
{
    /**
     * @param  list<ClassProbability>  $probabilities
     */
    public function __construct(
        public PredictionClass $predictedClass,
        public array $probabilities,
        public string $modelName,
    ) {}

    /**
     * Build a result from a raw inference payload, rejecting anything that
     * does not describe a usable two class distribution.
     *
     * Configuration is supplied by the caller so this stays free of framework
     * concerns and can be exercised as a plain unit.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws InvalidInferenceResponseException
     */
    public static function fromArray(array $payload, string $fallbackModelName, float $tolerance = 0.01): self
    {
        $prediction = $payload['prediction'] ?? null;

        if (! is_array($prediction)) {
            throw InvalidInferenceResponseException::malformed('missing "prediction" object');
        }

        $class = $prediction['class'] ?? null;

        if (! is_string($class) || ($predictedClass = PredictionClass::tryFromLabel($class)) === null) {
            throw InvalidInferenceResponseException::malformed('unsupported "prediction.class" value');
        }

        $probabilities = self::normalizeProbabilities($prediction['probabilities'] ?? null, $tolerance);

        $modelName = $payload['model']['name'] ?? null;

        if (! is_string($modelName) || $modelName === '') {
            $modelName = $fallbackModelName;
        }

        return new self($predictedClass, $probabilities, $modelName);
    }

    /**
     * Probability assigned to a given class.
     */
    public function probabilityFor(PredictionClass $class): float
    {
        foreach ($this->probabilities as $probability) {
            if ($probability->class === $class) {
                return $probability->probability;
            }
        }

        return 0.0;
    }

    /**
     * Highest probability in the distribution, which is the model's own
     * statement of how strong the classification was.
     */
    public function confidence(): float
    {
        $highest = 0.0;

        foreach ($this->probabilities as $probability) {
            $highest = max($highest, $probability->probability);
        }

        return round($highest, 4);
    }

    /**
     * Ordered for rendering, so the bars always appear in the same sequence.
     *
     * @return list<ClassProbability>
     */
    public function ordered(): array
    {
        $byClass = [];

        foreach ($this->probabilities as $probability) {
            $byClass[$probability->class->value] = $probability;
        }

        $ordered = [];

        foreach (PredictionClass::values() as $label) {
            if (isset($byClass[$label])) {
                $ordered[] = $byClass[$label];
            }
        }

        return $ordered;
    }

    /**
     * @return array{
     *     class: string,
     *     confidence: float,
     *     model: string,
     *     probabilities: list<array{class: string, probability: float, percentage: float}>
     * }
     */
    public function toArray(): array
    {
        return [
            'class' => $this->predictedClass->value,
            'confidence' => $this->confidence(),
            'model' => $this->modelName,
            'probabilities' => array_map(
                static fn (ClassProbability $probability): array => $probability->toArray(),
                $this->ordered(),
            ),
        ];
    }

    /**
     * @return list<ClassProbability>
     *
     * @throws InvalidInferenceResponseException
     */
    private static function normalizeProbabilities(mixed $raw, float $tolerance): array
    {
        if (! is_array($raw) || $raw === []) {
            throw InvalidInferenceResponseException::malformed('missing "prediction.probabilities"');
        }

        $expected = PredictionClass::values();

        if (array_diff($expected, array_keys($raw)) !== [] || array_diff(array_keys($raw), $expected) !== []) {
            throw InvalidInferenceResponseException::malformed('probabilities do not match the configured classes');
        }

        $sum = 0.0;

        $probabilities = [];

        foreach ($expected as $label) {
            $value = $raw[$label];

            if (! is_numeric($value)) {
                throw InvalidInferenceResponseException::malformed("probability for {$label} is not numeric");
            }

            $value = (float) $value;

            if ($value < 0.0 || $value > 1.0) {
                throw InvalidInferenceResponseException::malformed("probability for {$label} is outside [0, 1]");
            }

            $sum += $value;

            $probabilities[] = new ClassProbability(PredictionClass::from($label), round($value, 4));
        }

        if (abs($sum - 1.0) > $tolerance) {
            throw InvalidInferenceResponseException::malformed('probabilities do not sum to 1');
        }

        return $probabilities;
    }
}

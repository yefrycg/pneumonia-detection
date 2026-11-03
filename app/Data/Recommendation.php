<?php

namespace App\Data;

use App\Exceptions\InvalidInferenceResponseException;
use Illuminate\Support\Str;

/**
 * Validated recommendation produced by the LLM + RL pipeline.
 *
 * The text is user-facing prose, never markup, and the RL values are only used
 * for the collapsible "how the agent learned" panel. Keeping the schema check
 * here means the controller and the Blade view can trust every field.
 */
final readonly class Recommendation
{
    /**
     * @param  list<RankedAction>  $rankedActions
     */
    public function __construct(
        public string $id,
        public string $text,
        public string $source,
        public string $state,
        public string $actionId,
        public string $actionLabel,
        public float $qValue,
        public bool $isExploration,
        public int $feedbackCount,
        public array $rankedActions,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws InvalidInferenceResponseException
     */
    public static function fromArray(array $payload, int $minChars = 200): self
    {
        $id = $payload['recommendation_id'] ?? null;

        if (! is_string($id) || ! Str::isUuid($id)) {
            throw InvalidInferenceResponseException::malformed('missing or invalid "recommendation_id"');
        }

        $text = $payload['text'] ?? null;

        if (! is_string($text) || mb_strlen(trim($text)) < $minChars) {
            throw InvalidInferenceResponseException::malformed('recommendation text is empty or too short');
        }

        $source = $payload['source'] ?? null;

        if (! in_array($source, ['llm', 'fallback'], true)) {
            throw InvalidInferenceResponseException::malformed('unsupported recommendation "source"');
        }

        $state = $payload['state'] ?? null;

        if (! is_string($state) || trim($state) === '') {
            throw InvalidInferenceResponseException::malformed('missing recommendation "state"');
        }

        $action = $payload['action'] ?? null;

        if (! is_array($action)) {
            throw InvalidInferenceResponseException::malformed('missing recommendation "action"');
        }

        $actionId = $action['id'] ?? null;
        $actionLabel = $action['label'] ?? null;

        if (! is_string($actionId) || trim($actionId) === '') {
            throw InvalidInferenceResponseException::malformed('recommendation "action.id" is invalid');
        }

        if (! is_string($actionLabel) || trim($actionLabel) === '') {
            throw InvalidInferenceResponseException::malformed('recommendation "action.label" is invalid');
        }

        $rl = $payload['rl'] ?? null;

        if (! is_array($rl)) {
            throw InvalidInferenceResponseException::malformed('missing recommendation "rl" block');
        }

        $qValue = $rl['q_value'] ?? null;

        if (! is_numeric($qValue) || ! is_finite((float) $qValue)) {
            throw InvalidInferenceResponseException::malformed('recommendation "rl.q_value" is invalid');
        }

        $isExploration = $rl['is_exploration'] ?? null;

        if (! is_bool($isExploration)) {
            throw InvalidInferenceResponseException::malformed('recommendation "rl.is_exploration" is invalid');
        }

        $feedbackCount = $rl['feedback_count'] ?? null;

        if (! is_int($feedbackCount) || $feedbackCount < 0) {
            throw InvalidInferenceResponseException::malformed('recommendation "rl.feedback_count" is invalid');
        }

        $ranked = $rl['ranked_actions'] ?? null;

        if (! is_array($ranked) || $ranked === []) {
            throw InvalidInferenceResponseException::malformed('recommendation "rl.ranked_actions" is missing');
        }

        return new self(
            id: $id,
            text: trim($text),
            source: (string) $source,
            state: $state,
            actionId: $actionId,
            actionLabel: $actionLabel,
            qValue: (float) $qValue,
            isExploration: $isExploration,
            feedbackCount: $feedbackCount,
            rankedActions: array_values(array_map(
                static fn (mixed $entry): RankedAction => RankedAction::fromArray($entry),
                $ranked,
            )),
        );
    }

    public function isFallback(): bool
    {
        return $this->source === 'fallback';
    }

    /**
     * @return array{
     *     id: string,
     *     text: string,
     *     source: string,
     *     state: string,
     *     action: array{id: string, label: string},
     *     rl: array{
     *         q_value: float,
     *         is_exploration: bool,
     *         feedback_count: int,
     *         ranked_actions: list<array{id: string, label: string, q_value: float}>
     *     }
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'text' => $this->text,
            'source' => $this->source,
            'state' => $this->state,
            'action' => [
                'id' => $this->actionId,
                'label' => $this->actionLabel,
            ],
            'rl' => [
                'q_value' => $this->qValue,
                'is_exploration' => $this->isExploration,
                'feedback_count' => $this->feedbackCount,
                'ranked_actions' => array_map(
                    static fn (RankedAction $action): array => $action->toArray(),
                    $this->rankedActions,
                ),
            ],
        ];
    }
}

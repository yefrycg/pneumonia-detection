@php
    $endpoint = route('analyzer.recommend');
    $feedbackEndpoint = route('analyzer.feedback');
@endphp

<script>
    window.analyzerLocales = window.analyzerLocales || {};
    window.analyzerLocales.numberFormatter = new Intl.NumberFormat('{{ app()->getLocale() }}', {
        minimumFractionDigits: 3,
        maximumFractionDigits: 3,
    });
</script>

<section
    {{ $attributes->merge(['class' => 'flex flex-col gap-5 rounded-2xl border border-border bg-surface-raised p-5 shadow-panel sm:p-6']) }}
    data-recommendation
    data-recommend-endpoint="{{ $endpoint }}"
    data-feedback-endpoint="{{ $feedbackEndpoint }}"
    data-locale="{{ app()->getLocale() }}"
    data-source-llm="{{ __('analyzer.recommendation.source.llm') }}"
    data-source-fallback="{{ __('analyzer.recommendation.source.fallback') }}"
    data-message-loading="{{ __('analyzer.recommendation.loading') }}"
    data-message-error="{{ __('analyzer.recommendation.error') }}"
    data-message-feedback-error="{{ __('analyzer.recommendation.feedback.error') }}"
    data-message-feedback-thanks="{{ __('analyzer.recommendation.feedback.thanks') }}"
    data-message-feedback-already="{{ __('analyzer.recommendation.feedback.already') }}"
    data-mode-exploration="{{ __('analyzer.recommendation.agent.exploration') }}"
    data-mode-exploitation="{{ __('analyzer.recommendation.agent.exploitation') }}"
    data-agent-unavailable="{{ __('analyzer.recommendation.agent.unavailable') }}"
    hidden
>
    <div class="flex flex-col gap-2 border-b border-border pb-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-base font-semibold tracking-tight">{{ __('analyzer.recommendation.heading') }}</h2>

            <span
                data-source-badge
                data-source="llm"
                hidden
                class="rounded-full border border-border-strong bg-surface-sunken px-2.5 py-1 text-xs font-medium text-ink-muted data-[source=llm]:border-accent/30 data-[source=llm]:bg-accent/10 data-[source=llm]:text-accent data-[source=fallback]:border-warning-ink/25 data-[source=fallback]:bg-warning-soft/60 data-[source=fallback]:text-warning-ink"
            ></span>
        </div>

        <p class="text-sm text-ink-muted">{{ __('analyzer.recommendation.subtitle') }}</p>
    </div>

    <div data-recommendation-loading role="status" aria-live="polite" aria-busy="true" class="flex items-center gap-3 rounded-xl border border-border bg-surface-sunken p-4">
        <span class="size-4 shrink-0 animate-spin rounded-full border-2 border-accent border-t-transparent" aria-hidden="true"></span>
        <p class="text-sm text-ink-muted">{{ __('analyzer.recommendation.loading') }}</p>
    </div>

    <div data-recommendation-error hidden role="alert" class="flex gap-3 rounded-xl border border-critical/30 bg-critical-soft p-4">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="mt-0.5 size-5 shrink-0 text-critical" aria-hidden="true">
            <circle cx="12" cy="12" r="9" />
            <path d="M12 7.5V13m0 3.5h.01" stroke-linecap="round" />
        </svg>
        <p data-recommendation-error-message class="text-sm font-medium text-critical"></p>
    </div>

    <div data-recommendation-content hidden class="flex flex-col gap-5">
        <p data-recommendation-text class="text-base leading-relaxed text-ink text-pretty"></p>

        <div data-feedback-block class="flex flex-col gap-3 border-t border-border pt-5">
            <p class="text-sm font-medium text-ink">{{ __('analyzer.recommendation.feedback.prompt') }}</p>

            <div class="flex flex-wrap items-center gap-2" role="group" aria-label="{{ __('analyzer.recommendation.feedback.prompt') }}">
                <button
                    type="button"
                    data-feedback-up
                    class="inline-flex items-center gap-2 rounded-lg border border-border bg-surface px-4 py-2.5 text-sm font-medium text-ink-muted transition-colors hover:border-positive/40 hover:bg-positive-soft hover:text-positive disabled:cursor-not-allowed disabled:opacity-50 focus:outline-2 focus:outline-offset-2 focus:outline-positive"
                    aria-pressed="false"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="size-4" aria-hidden="true">
                        <path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <span>{{ __('analyzer.recommendation.feedback.useful') }}</span>
                </button>

                <button
                    type="button"
                    data-feedback-down
                    class="inline-flex items-center gap-2 rounded-lg border border-border bg-surface px-4 py-2.5 text-sm font-medium text-ink-muted transition-colors hover:border-critical/40 hover:bg-critical-soft hover:text-critical disabled:cursor-not-allowed disabled:opacity-50 focus:outline-2 focus:outline-offset-2 focus:outline-critical"
                    aria-pressed="false"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="size-4" aria-hidden="true">
                        <path d="M10 15v4a3 3 0 0 0 3 3l4-9V2H5.72a2 2 0 0 0-2 1.7l-1.38 9a2 2 0 0 0 2 2.3zm7-22h3a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2h-3" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <span>{{ __('analyzer.recommendation.feedback.not_useful') }}</span>
                </button>
            </div>

            <p data-feedback-status role="status" aria-live="polite" class="text-sm text-ink-muted"></p>
        </div>

        <details data-rl-details class="group overflow-hidden rounded-xl border border-border bg-surface-sunken">
            <summary class="flex cursor-pointer items-start justify-between gap-4 p-4 transition-colors hover:bg-surface-sunken/60 focus:outline-2 focus:outline-offset-2 focus:outline-accent">
                <span class="flex flex-col gap-1">
                    <span class="text-xs font-medium tracking-wide text-ink-subtle uppercase">
                        {{ __('analyzer.recommendation.agent.heading') }}
                    </span>
                    <span class="text-sm font-medium text-ink">
                        {{ __('analyzer.recommendation.agent.summary') }}
                    </span>
                </span>

                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="mt-0.5 size-4 shrink-0 text-ink-subtle transition-transform duration-200 group-open:rotate-180" aria-hidden="true">
                    <path d="M6 9l6 6 6-6" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </summary>

            <div class="flex flex-col gap-4 border-t border-border p-4">
                <p data-rl-summary class="text-sm leading-relaxed text-ink-muted"></p>

                <div class="flex flex-col gap-1.5 rounded-xl border border-border bg-surface p-4">
                    <p class="text-xs font-medium tracking-wide text-ink-subtle uppercase">
                        {{ __('analyzer.recommendation.agent.chosen') }}
                    </p>
                    <p data-rl-action class="text-base font-medium text-ink">—</p>
                    <p data-rl-hint class="text-sm leading-relaxed text-ink-muted"></p>
                </div>

                <div class="flex flex-col gap-2 border-t border-border pt-4">
                    <p class="text-xs font-medium tracking-wide text-ink-subtle uppercase">
                        {{ __('analyzer.recommendation.agent.ranking') }}
                    </p>
                    <p class="text-xs leading-relaxed text-ink-subtle">
                        {{ __('analyzer.recommendation.agent.ranking_hint') }}
                    </p>
                    <ol data-rl-ranking class="mt-1 flex flex-col gap-2 text-sm text-ink-muted"></ol>
                </div>
            </div>
        </details>
    </div>
</section>
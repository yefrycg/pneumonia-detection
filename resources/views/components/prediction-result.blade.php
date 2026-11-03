@props(['classes' => ['NORMAL', 'PNEUMONIA']])

<script>
    window.analyzerLocales = window.analyzerLocales || {};
    window.analyzerLocales.percentFormatter = new Intl.NumberFormat('{{ app()->getLocale() }}', {
        style: 'percent',
        minimumFractionDigits: 1,
        maximumFractionDigits: 1,
    });
</script>

<section
    data-result
    hidden
    class="flex flex-col gap-6 rounded-2xl border border-border bg-surface-raised p-5 shadow-panel sm:p-6"
>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-base font-semibold tracking-tight">{{ __('analyzer.result.heading') }}</h2>
    </div>

    <div
        data-verdict
        data-tone="neutral"
        data-context-normal="{{ __('analyzer.result.context_normal') }}"
        data-context-pneumonia="{{ __('analyzer.result.context_pneumonia') }}"
        class="flex flex-col gap-4 rounded-xl border border-border bg-surface-sunken p-4"
    >
        <div class="flex items-center gap-3">
            <span class="text-xs font-medium tracking-wide text-ink-subtle uppercase">
                {{ __('analyzer.result.predicted_class') }}
            </span>
            <p
                data-class-label
                class="text-2xl font-semibold tracking-tight text-ink data-[tone=positive]:text-positive data-[tone=critical]:text-critical"
            >
                &mdash;
            </p>
        </div>

        <p
            data-context
            class="text-sm leading-relaxed text-ink-muted"
            aria-live="polite"
        ></p>

        <div class="flex flex-col gap-2">
            <div class="flex items-center justify-between gap-4">
                <div class="flex flex-col">
                    <span class="text-xs font-medium tracking-wide text-ink-subtle uppercase">
                        {{ __('analyzer.result.confidence') }}
                    </span>
                    <span class="text-3xl font-semibold tracking-tight tabular-nums text-ink">
                        <span data-confidence>—</span>
                    </span>
                </div>

                <div class="flex flex-col items-end">
                    <span class="text-xs font-medium tracking-wide text-ink-subtle uppercase">
                        {{ __('analyzer.result.model_name') }}
                    </span>
                    <span data-model class="text-sm font-medium text-ink-muted">—</span>
                </div>
            </div>

            <div
                data-confidence-meter
                role="progressbar"
                aria-valuemin="0"
                aria-valuemax="100"
                aria-valuenow="0"
                aria-label="{{ __('analyzer.result.confidence') }}"
                class="h-3 overflow-hidden rounded-full bg-surface-sunken"
            >
                <div
                    data-confidence-fill
                    style="width: 0%"
                    class="h-full rounded-full bg-ink-subtle transition-[width] duration-500 ease-out data-[tone=positive]:bg-positive data-[tone=critical]:bg-critical"
                ></div>
            </div>
        </div>
    </div>

    <details
        data-probabilities-details
        class="group"
    >
        <summary class="flex items-center justify-between gap-3 cursor-pointer rounded-lg border border-border bg-surface-sunken px-4 py-3 transition-colors hover:bg-surface-sunken/60 focus:outline-2 focus:outline-offset-2 focus:outline-accent">
            <span class="text-xs font-medium tracking-wide text-ink-subtle uppercase">
                {{ __('analyzer.result.probabilities') }}
            </span>
            <span
                data-probabilities-toggle
                class="flex items-center gap-1.5 text-sm font-medium text-ink-muted transition-transform duration-200 group-open:rotate-180"
            >
                {{ __('analyzer.result.probabilities_collapsed') }}
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="size-4" aria-hidden="true">
                    <path d="M6 9l6 6 6-6" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </span>
        </summary>

        <div
            data-probabilities-content
            class="overflow-hidden transition-[height,opacity] duration-300 ease-out"
        >
            <div class="flex flex-col gap-4 pt-4 border-t border-border">
                @foreach ($classes as $class)
                    <div data-probability="{{ $class }}" class="flex flex-col gap-1.5">
                        <div class="flex items-baseline justify-between gap-3 text-sm">
                            <span class="font-medium text-ink">{{ $class }}</span>
                            <span data-percentage class="font-medium tabular-nums text-ink-muted">&mdash;</span>
                        </div>

                        <div
                            data-bar
                            role="progressbar"
                            aria-valuemin="0"
                            aria-valuemax="100"
                            aria-valuenow="0"
                            aria-label="{{ __('analyzer.progress.label', ['class' => $class, 'percentage' => 0]) }}"
                            class="h-2 overflow-hidden rounded-full bg-surface-sunken"
                        >
                            <div
                                data-fill
                                style="width: 0%"
                                class="h-full rounded-full bg-ink-subtle transition-[width] duration-500 ease-out data-[tone=positive]:bg-positive data-[tone=critical]:bg-critical"
                            ></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </details>

    <div class="flex flex-wrap items-center gap-3 border-t border-border pt-5">
        <button
            type="button"
            data-analyze-again
            class="inline-flex items-center gap-2 rounded-lg border border-border bg-surface px-3 py-2 text-sm font-medium text-ink-muted transition-colors hover:bg-surface-sunken hover:text-ink"
        >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="size-4" aria-hidden="true">
                <path d="M4 7h11m0 0-3-3m3 3-3 3M20 17H9m0 0 3 3m-3-3 3-3" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            {{ __('analyzer.result.again') }}
        </button>

        <p class="text-xs text-ink-subtle">{{ __('analyzer.disclaimer.short') }}</p>
    </div>
</section>
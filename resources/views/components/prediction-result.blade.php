@props(['classes' => ['NORMAL', 'PNEUMONIA']])

<section
    data-result
    hidden
    class="flex flex-col gap-6 rounded-2xl border border-border bg-surface-raised p-5 shadow-panel sm:p-6"
>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-base font-semibold tracking-tight">{{ __('analyzer.result.heading') }}</h2>
        <span class="rounded-full border border-border-strong bg-surface-sunken px-2.5 py-1 text-xs font-medium text-ink-muted">
            {{ __('analyzer.model.experimental_badge') }}
        </span>
    </div>

    <div data-verdict data-tone="neutral" class="flex flex-col gap-1 rounded-xl border border-border bg-surface-sunken p-4">
        <p class="text-xs font-medium tracking-wide text-ink-subtle uppercase">
            {{ __('analyzer.result.predicted_class') }}
        </p>
        <p
            data-class-label
            class="text-2xl font-semibold tracking-tight text-ink data-[tone=positive]:text-positive data-[tone=critical]:text-critical"
        >
            &mdash;
        </p>
        <p
            data-statement
            data-template="{{ __('analyzer.result.statement', ['class' => '%CLASS%']) }}"
            class="text-sm text-ink-muted"
        ></p>
    </div>

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="flex flex-col">
            <span class="text-xs font-medium tracking-wide text-ink-subtle uppercase">
                {{ __('analyzer.result.confidence') }}
            </span>
            <span class="text-3xl font-semibold tracking-tight tabular-nums text-ink">
                <span data-confidence>—</span>
            </span>
        </div>

        <div class="flex flex-col">
            <span class="text-xs font-medium tracking-wide text-ink-subtle uppercase">
                {{ __('analyzer.result.model_name') }}
            </span>
            <span data-model class="text-sm font-medium text-ink-muted">—</span>
        </div>
    </div>

    <div class="flex flex-col gap-4">
        <p class="text-xs font-medium tracking-wide text-ink-subtle uppercase">
            {{ __('analyzer.result.probabilities') }}
        </p>

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
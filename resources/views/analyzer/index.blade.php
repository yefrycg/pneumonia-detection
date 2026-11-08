@php
    use App\Enums\PredictionClass;

    $analysisEndpoint = route('analyzer.analyze');
    $maxUploadKb = (int) config('inference.upload.max_kb');
    $acceptedExtensions = config('inference.upload.allowed_extensions');
    $acceptedExtensions = collect($acceptedExtensions)
        ->unique()
        ->values()
        ->toArray();
    $maxSizeLabel = $maxUploadKb >= 1024
        ? number_format($maxUploadKb / 1024, $maxUploadKb % 1024 === 0 ? 0 : 1).' MB'
        : $maxUploadKb.' KB';
    $tones = collect(PredictionClass::cases())
        ->mapWithKeys(fn (PredictionClass $class): array => [$class->value => $class->tone()])
        ->all();
@endphp

@extends('layouts.app')

@section('content')
    <div class="flex flex-col gap-8">
        <section class="flex flex-col gap-4">
            <div class="flex flex-wrap items-center gap-2.5">
                <h1 class="text-3xl font-semibold tracking-tight text-balance sm:text-4xl">
                    {{ __('analyzer.title') }}
                </h1>
                <span class="rounded-full border border-border-strong bg-surface-sunken px-2.5 py-1 text-xs font-medium text-ink-muted">
                    {{ __('analyzer.model.experimental_badge') }}
                </span>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <span class="rounded-full border border-border-strong bg-surface-sunken px-2.5 py-1 text-xs font-medium text-ink-muted">
                    {{ __('analyzer.model.cnn_badge', ['name' => config('inference.model.name')]) }}
                </span>
                <span class="rounded-full border border-accent/30 bg-accent/10 px-2.5 py-1 text-xs font-medium text-accent">
                    {{ __('analyzer.model.llm_badge', ['version' => 'Qwen3-0.6B']) }}
                </span>
            </div>

            <p class="max-w-4xl text-lg text-ink-muted text-pretty">
                {{ __('analyzer.tagline') }}
            </p>
        </section>

        <x-disclaimer />

        <div
            data-analyzer
            data-endpoint="{{ $analysisEndpoint }}"
            data-max-bytes="{{ $maxUploadKb * 1024 }}"
            data-max-size-label="{{ $maxSizeLabel }}"
            data-formats-label="{{ strtoupper(implode(', ', $acceptedExtensions)) }}"
            data-accepted-extensions="{{ implode(',', $acceptedExtensions) }}"
            data-error-generic="{{ __('errors.inference.UNEXPECTED') }}"
            data-message-wrong-type="{{ __('analyzer.client_errors.wrong_type', ['formats' => '%FORMATS%']) }}"
            data-message-too-large="{{ __('analyzer.client_errors.too_large', ['size' => '%SIZE%']) }}"
            data-message-unreadable="{{ __('analyzer.client_errors.unreadable') }}"
            data-message-missing-file="{{ __('analyzer.client_errors.missing_file') }}"
            data-message-in-process="{{ __('analyzer.preview.in_process') }}"
            data-message-completed="{{ __('analyzer.preview.completed', ['class' => '%CLASS%']) }}"
            data-label-analyze="{{ __('analyzer.analyze') }}"
            data-label-analyzing="{{ __('analyzer.analyzing') }}"
            data-label-unknown-dimensions="{{ __('analyzer.preview.unknown_dimensions') }}"
            data-tones="{{ json_encode($tones) }}"
            class="flex flex-col gap-6"
        >
            <div class="grid gap-6 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,0.8fr)] lg:items-start">
                <form
                    data-form
                    action="{{ $analysisEndpoint }}"
                    method="post"
                    enctype="multipart/form-data"
                    novalidate
                    class="flex flex-col gap-6 rounded-2xl border border-border bg-surface-raised p-5 shadow-panel sm:p-6"
                >
                    @csrf

                    <div
                        data-alert
                        hidden
                        role="alert"
                        class="flex gap-3 rounded-xl border border-critical/30 bg-critical-soft p-4"
                    >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="mt-0.5 size-5 shrink-0 text-critical" aria-hidden="true">
                            <circle cx="12" cy="12" r="9" />
                            <path d="M12 7.5V13m0 3.5h.01" stroke-linecap="round" />
                        </svg>
                        <p data-alert-message class="text-sm font-medium text-critical"></p>
                    </div>

                    <x-upload-dropzone :max-size-kb="$maxUploadKb" :accepted-extensions="$acceptedExtensions" />

                    <button
                        type="submit"
                        data-submit
                        disabled
                        class="inline-flex w-full items-center justify-center gap-2.5 rounded-lg bg-accent px-5 py-3 text-sm font-semibold text-accent-contrast transition-[background-color,opacity] hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto sm:self-start"
                    >
                        <span data-spinner hidden class="size-4 animate-spin rounded-full border-2 border-current border-t-transparent" aria-hidden="true"></span>
                        <span data-submit-label>{{ __('analyzer.analyze') }}</span>
                    </button>
                </form>

                <div class="flex flex-col gap-6">
                    <div
                        data-result-placeholder
                        class="flex min-h-full flex-col gap-5 rounded-2xl border border-dashed border-border-strong bg-surface-raised/60 p-5 sm:p-6"
                    >
                        <div class="flex flex-col gap-1.5">
                            <h2 class="text-base font-semibold tracking-tight">{{ __('analyzer.result.heading') }}</h2>
                            <p class="text-sm text-ink-muted">{{ __('analyzer.result.subtitle') }}</p>
                        </div>

                        <div class="flex flex-1 flex-col items-center justify-center gap-3 rounded-xl bg-surface-sunken px-6 py-10 text-center">
                            <span class="grid size-11 place-items-center rounded-full bg-accent/10 text-accent" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="size-5">
                                    <path d="M4 6h16M4 12h10M4 18h7" stroke-linecap="round" />
                                    <path d="m16.5 15.5 2 2 3.5-4" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </span>
                            <p class="max-w-xs text-sm font-medium text-ink">{{ __('analyzer.result.placeholder') }}</p>
                            <p class="max-w-sm text-xs leading-relaxed text-ink-subtle">{{ __('analyzer.result.placeholder_hint') }}</p>
                        </div>
                    </div>

                    <x-prediction-result />
                </div>
            </div>

            <x-recommendation class="w-full" />

            <p class="sr-only" role="status" aria-live="polite" data-status>{{ __('analyzer.accessibility.status_region') }}</p>
        </div>
    </div>
@endsection
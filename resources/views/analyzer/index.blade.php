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

            <p class="max-w-2xl text-lg text-ink-muted text-pretty">
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
            class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start"
        >
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

            <div class="flex flex-col gap-4">
                <x-prediction-result :classes="config('inference.classes')" />
            </div>

            <p class="sr-only" role="status" aria-live="polite" data-status>{{ __('analyzer.accessibility.status_region') }}</p>
        </div>
    </div>
@endsection
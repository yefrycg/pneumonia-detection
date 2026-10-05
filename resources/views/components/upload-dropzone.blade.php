@props([
    'name' => 'image',
    'maxSizeKb' => 10240,
    'formats' => 'JPG, PNG',
    'acceptedExtensions' => ['jpg', 'jpeg', 'png'],
])

@php
    $inputId = 'analyzer-image';
    $acceptedExtensions = collect($acceptedExtensions)
        ->map(fn (string $extension) => mb_strtolower($extension))
        ->unique()
        ->values()
        ->toArray();
    $accept = collect($acceptedExtensions)->map(fn (string $extension): string => ".{$extension}")->implode(',');
    $maxSizeLabel = $maxSizeKb >= 1024
        ? number_format($maxSizeKb / 1024, $maxSizeKb % 1024 === 0 ? 0 : 1).' MB'
        : $maxSizeKb.' KB';
@endphp

<div data-uploader class="flex flex-col gap-4">
    <input
        id="{{ $inputId }}"
        type="file"
        name="{{ $name }}"
        accept="{{ $accept }}"
        class="peer sr-only"
        data-file-input
        aria-describedby="{{ $inputId }}-hint"
    >

    <label
        for="{{ $inputId }}"
        data-dropzone
        data-dragging="false"
        class="flex cursor-pointer flex-col items-center gap-3 rounded-2xl border-2 border-dashed border-border-strong bg-surface-sunken px-6 py-12 text-center transition-colors hover:border-accent/60 hover:bg-surface-sunken/60 peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-accent data-[dragging=true]:border-accent data-[dragging=true]:bg-accent/5"
    >
        <span class="grid size-12 place-items-center rounded-full bg-accent/10 text-accent" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="size-6">
                <path d="M12 16V4m0 0L8 8m4-4 4 4M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </span>

        <span class="flex flex-col gap-1">
            <span class="text-base font-medium text-ink">{{ __('analyzer.upload.dropzone') }}</span>
            <span class="text-sm text-ink-subtle">{{ __('analyzer.upload.or') }}</span>
        </span>

        <span class="rounded-lg bg-accent px-4 py-2 text-sm font-medium text-accent-contrast">
            {{ __('analyzer.upload.choose') }}
        </span>
    </label>

        <div data-preview class="hidden flex-col gap-4">
        <div class="flex flex-col gap-4 rounded-2xl border border-border bg-surface-raised p-4 shadow-panel sm:flex-row sm:items-start">
            <img
                data-preview-image
                src=""
                alt="{{ __('analyzer.upload.preview_alt') }}"
                class="aspect-square w-full shrink-0 rounded-xl bg-surface-sunken object-contain sm:size-40"
            >

            <dl class="grid flex-1 grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
                <dt class="text-ink-subtle">{{ __('analyzer.upload.filename') }}</dt>
                <dd data-preview-name class="truncate font-medium text-ink" title=""></dd>

                <dt class="text-ink-subtle">{{ __('analyzer.upload.filesize') }}</dt>
                <dd data-preview-size class="font-medium text-ink"></dd>

                <dt class="text-ink-subtle">{{ __('analyzer.upload.dimensions') }}</dt>
                <dd data-preview-dimensions class="font-medium text-ink"></dd>
            </dl>
        </div>

        <label
            for="{{ $inputId }}"
            class="inline-flex w-fit cursor-pointer items-center gap-2 rounded-lg border border-border bg-surface px-3 py-1.5 text-sm font-medium text-ink-muted transition-colors hover:bg-surface-sunken hover:text-ink"
        >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" class="size-4" aria-hidden="true">
                <path d="M4 7h11m0 0-3-3m3 3-3 3M20 17H9m0 0 3 3m-3-3 3-3" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            {{ __('analyzer.upload.replace') }}
        </label>
    </div>

    <p id="{{ $inputId }}-hint" class="flex flex-col gap-1 text-xs text-ink-subtle">
        <span>{{ __('analyzer.upload.instructions', ['formats' => $formats]) }}</span>
        <span>{{ __('analyzer.upload.max_size', ['size' => $maxSizeLabel]) }}</span>
        <span>{{ __('analyzer.upload.hint') }}</span>
    </p>

    <p data-upload-error class="hidden text-sm font-medium text-critical" role="alert" hidden></p>
</div>
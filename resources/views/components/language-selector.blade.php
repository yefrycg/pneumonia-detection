@props(['current' => 'en'])

@php
    $locales = collect(config('app.supported_locales', ['en', 'es']))
        ->map(fn (string $locale): array => [
            'code' => $locale,
            'label' => __('analyzer.language.'.$locale),
        ])
        ->values();
@endphp

<div class="flex items-center gap-0.5 rounded-lg border border-border bg-surface p-0.5" role="group" aria-label="{{ __('analyzer.language.toggle') }}">
    @foreach ($locales as $locale)
        <a
            href="{{ route('locale.set', $locale['code']) }}"
            class="rounded-md px-2 py-1 text-xs font-medium transition-colors {{ $locale['code'] === $current ? 'bg-accent text-accent-contrast' : 'text-ink-muted hover:bg-surface-sunken hover:text-ink' }}"
            hreflang="{{ $locale['code'] }}"
            lang="{{ $locale['code'] }}"
            @if ($locale['code'] === $current) aria-current="true" @endif
        >
            <span class="sr-only">{{ $locale['label'] }}</span>
            <span aria-hidden="true">{{ strtoupper($locale['code']) }}</span>
        </a>
    @endforeach
</div>
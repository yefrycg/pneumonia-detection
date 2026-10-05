<button
    type="button"
    data-theme-toggle
    data-label-dark="{{ __('analyzer.theme.dark') }}"
    data-label-light="{{ __('analyzer.theme.light') }}"
    class="grid size-8 place-items-center rounded-lg border border-border bg-surface text-ink-muted transition-colors hover:bg-surface-sunken hover:text-ink"
    title="{{ __('analyzer.theme.toggle') }}"
>
    <span class="sr-only" data-theme-label>{{ __('analyzer.theme.toggle') }}</span>

    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="size-4 dark:hidden" aria-hidden="true">
        <circle cx="12" cy="12" r="4" />
        <path d="M12 2v2m0 16v2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M2 12h2m16 0h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" stroke-linecap="round" />
    </svg>

    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="hidden size-4 dark:block" aria-hidden="true">
        <path d="M20 14.5A8.5 8.5 0 0 1 9.5 4a8.5 8.5 0 1 0 10.5 10.5Z" stroke-linejoin="round" />
    </svg>
</button>
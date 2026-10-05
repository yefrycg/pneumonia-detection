<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex">
    <title>{{ $title ?? __('analyzer.title') }}</title>
    <meta name="description" content="{{ __('analyzer.description') }}">

    <script>
        (function () {
            try {
                var stored = window.localStorage.getItem('theme');
                var dark = stored
                    ? stored === 'dark'
                    : window.matchMedia('(prefers-color-scheme: dark)').matches;

                document.documentElement.classList.toggle('dark', dark);
                document.documentElement.dataset.theme = dark ? 'dark' : 'light';
            } catch (error) {
                document.documentElement.dataset.theme = 'light';
            }
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-dvh bg-surface font-sans text-ink antialiased">
    <a
        href="#main"
        class="sr-only focus:not-sr-only focus:absolute focus:top-3 focus:left-3 focus:z-50 focus:rounded-lg focus:bg-accent focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-accent-contrast"
    >
        {{ __('analyzer.accessibility.skip_to_content') }}
    </a>

    <header class="border-b border-border bg-surface-raised">
        <div class="mx-auto flex max-w-5xl items-center justify-between gap-4 px-4 py-3 sm:px-6">
            <div class="flex min-w-0 items-center gap-2.5">
                <span
                    class="grid size-8 shrink-0 place-items-center rounded-lg bg-accent/10 text-accent"
                    aria-hidden="true"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" class="size-5">
                        <path d="M12 3v18M7 8.5h10M6 12h12M7.5 15.5h9" stroke-linecap="round" />
                    </svg>
                </span>
                <span class="truncate text-sm font-semibold tracking-tight">{{ __('analyzer.title') }}</span>
            </div>

            <div class="flex items-center gap-1.5">
                <x-theme-toggle />
                <x-language-selector :current="app()->getLocale()" />
            </div>
        </div>
    </header>

    <main id="main" class="mx-auto w-full max-w-5xl px-4 py-8 sm:px-6 sm:py-12">
        @yield('content')
    </main>

    <footer class="border-t border-border">
        <div class="mx-auto max-w-5xl px-4 py-6 text-center sm:px-6">
            <p class="text-xs text-ink-subtle">{{ __('analyzer.footer.disclaimer') }}</p>
        </div>
    </footer>
</body>
</html>

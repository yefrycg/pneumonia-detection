import { initAnalyzer } from './analyzer';
import { initThemeToggle } from './theme';

function boot() {
    initThemeToggle();

    const root = document.querySelector('[data-analyzer]');

    if (root !== null) {
        initAnalyzer(root);
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}
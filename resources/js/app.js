import { initAnalyzer } from './analyzer';
import { initThemeToggle } from './theme';
import { initLightbox } from './lightbox';

function boot() {
    initThemeToggle();
    initLightbox();

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
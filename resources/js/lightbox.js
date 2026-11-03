/**
 * Lightbox module for accessible image zoom.
 * Traps focus, handles keyboard navigation, and respects reduced motion.
 */

const STORAGE_KEY = 'theme';

export function initLightbox() {
    const lightbox = document.getElementById('lightbox');
    if (!lightbox) return;

    const lightboxImage = lightbox.querySelector('[data-lightbox-image]');
    const closeButton = lightbox.querySelector('[data-lightbox-close]');
    const prevButton = lightbox.querySelector('[data-lightbox-prev]');
    const nextButton = lightbox.querySelector('[data-lightbox-next]');

    let lastFocusedElement = null;
    let currentSrc = null;

    const focusableElements = 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])';

    function getFocusableElements() {
        return Array.from(lightbox.querySelectorAll(focusableElements));
    }

    function trapFocus(event) {
        if (event.key !== 'Tab') return;

        const focusable = getFocusableElements();
        if (focusable.length === 0) return;

        const first = focusable[0];
        const last = focusable[focusable.length - 1];

        if (event.shiftKey) {
            if (document.activeElement === first) {
                event.preventDefault();
                last.focus();
            }
        } else {
            if (document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        }
    }

    function handleKeydown(event) {
        if (event.key === 'Escape') {
            close();
        } else if (event.key === 'ArrowLeft') {
            // Could implement multi-image navigation here
        } else if (event.key === 'ArrowRight') {
            // Could implement multi-image navigation here
        } else if (event.key === 'Tab') {
            trapFocus(event);
        }
    }

    function open(src, alt) {
        if (!src) return;

        lastFocusedElement = document.activeElement;
        currentSrc = src;

        lightboxImage.src = src;
        lightboxImage.alt = alt ?? '';
        lightbox.hidden = false;
        lightbox.classList.remove('hidden');
        lightbox.classList.add('flex');

        // Prevent body scroll
        document.body.style.overflow = 'hidden';

        // Focus management
        setTimeout(() => {
            closeButton.focus();
        }, 0);

        document.addEventListener('keydown', handleKeydown);
    }

    function close() {
        lightbox.hidden = true;
        lightbox.classList.add('hidden');
        lightbox.classList.remove('flex');
        lightboxImage.src = '';
        lightboxImage.alt = '';

        document.body.style.overflow = '';

        document.removeEventListener('keydown', handleKeydown);

        // Restore focus
        if (lastFocusedElement) {
            lastFocusedElement.focus();
            lastFocusedElement = null;
        }
    }

    // Event listeners
    closeButton?.addEventListener('click', close);
    prevButton?.addEventListener('click', () => { /* future multi-image */ });
    nextButton?.addEventListener('click', () => { /* future multi-image */ });

    // Close on backdrop click
    lightbox.addEventListener('click', (event) => {
        if (event.target === lightbox) {
            close();
        }
    });

    // Listen for open events from uploader
    document.addEventListener('lightbox:open', (event) => {
        const { src, alt } = event.detail ?? {};
        if (src) {
            open(src, alt);
        }
    });

    // Expose API
    window.lightbox = { open, close };
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initLightbox);
} else {
    initLightbox();
}
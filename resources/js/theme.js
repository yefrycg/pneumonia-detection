/**
 * Toggles between the light and dark themes.
 *
 * The initial theme is applied by an inline script in the document head so the
 * page never paints the wrong theme; this module only keeps the toggle button
 * and the stored preference in sync afterwards.
 */

const STORAGE_KEY = 'theme';

function readStoredTheme() {
    try {
        return window.localStorage.getItem(STORAGE_KEY);
    } catch {
        return null;
    }
}

function storeTheme(theme) {
    try {
        window.localStorage.setItem(STORAGE_KEY, theme);
    } catch {
        // Private browsing modes can refuse writes; the theme still applies for
        // this page view, it simply will not be remembered.
    }
}

function currentTheme() {
    return document.documentElement.classList.contains('dark') ? 'dark' : 'light';
}

export function initThemeToggle(root = document) {
    const toggle = root.querySelector('[data-theme-toggle]');

    if (toggle === null) {
        return;
    }

    const label = toggle.querySelector('[data-theme-label]');

    const syncLabel = () => {
        if (label === null) {
            return;
        }

        // Announce the theme the button switches to, not the active one.
        const next = currentTheme() === 'dark' ? toggle.dataset.labelLight : toggle.dataset.labelDark;

        label.textContent = next ?? '';
        toggle.setAttribute('title', next ?? '');
    };

    syncLabel();

    toggle.addEventListener('click', () => {
        const next = currentTheme() === 'dark' ? 'light' : 'dark';

        document.documentElement.classList.toggle('dark', next === 'dark');
        document.documentElement.dataset.theme = next;

        storeTheme(next);
        syncLabel();
    });
}

export { STORAGE_KEY, readStoredTheme };
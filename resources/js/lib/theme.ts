export type Theme = 'light' | 'dark' | 'system';

const STORAGE_KEY = 'synapse-theme';
const QUERY = '(prefers-color-scheme: dark)';

export function getStoredTheme(): Theme {
    const stored = localStorage.getItem(STORAGE_KEY);

    return stored === 'light' || stored === 'dark' || stored === 'system' ? stored : 'system';
}

export function resolveIsDark(theme: Theme): boolean {
    return theme === 'dark' || (theme === 'system' && window.matchMedia(QUERY).matches);
}

export function applyTheme(theme: Theme): void {
    document.documentElement.classList.toggle('dark', resolveIsDark(theme));
}

export function setTheme(theme: Theme): void {
    localStorage.setItem(STORAGE_KEY, theme);
    applyTheme(theme);
}

/** Apply the stored theme on boot and keep "system" in sync with the OS. */
export function initTheme(): void {
    applyTheme(getStoredTheme());

    window.matchMedia(QUERY).addEventListener('change', () => {
        if (getStoredTheme() === 'system') {
            applyTheme('system');
        }
    });
}

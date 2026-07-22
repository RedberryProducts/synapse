export type Theme = 'light' | 'dark' | 'system';

const STORAGE_KEY = 'synapse-theme';

export function getStoredTheme(): Theme {
    return (localStorage.getItem(STORAGE_KEY) as Theme | null) ?? 'system';
}

function systemPrefersDark(): boolean {
    return window.matchMedia('(prefers-color-scheme: dark)').matches;
}

export function applyTheme(theme: Theme): void {
    const dark = theme === 'dark' || (theme === 'system' && systemPrefersDark());
    document.documentElement.classList.toggle('dark', dark);
}

export function setTheme(theme: Theme): void {
    localStorage.setItem(STORAGE_KEY, theme);
    applyTheme(theme);
}

/** Apply the stored theme on boot and keep "system" in sync with the OS. */
export function initTheme(): void {
    applyTheme(getStoredTheme());

    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
        if (getStoredTheme() === 'system') {
            applyTheme('system');
        }
    });
}

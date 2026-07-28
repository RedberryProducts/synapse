import { useSearchParams } from 'react-router-dom';
import type { InfoTab } from '@/composed/InfoPanel';

const tabs: InfoTab[] = ['config', 'prompt', 'tools'];

interface PanelState {
    open: boolean;
    tab: InfoTab;
    openPanel: () => void;
    closePanel: () => void;
    setTab: (tab: InfoTab) => void;
}

/**
 * Info panel state lives in the query string (`?info=config`) so it survives a
 * reload and can be shared. `?info=1` opens the panel on its default tab.
 */
export function usePanelState(): PanelState {
    const [params, setParams] = useSearchParams();
    const value = params.get('info');

    const open = value !== null;
    const tab = tabs.includes(value as InfoTab) ? (value as InfoTab) : 'config';

    const update = (next: InfoTab | null) => {
        setParams(
            (current) => {
                const updated = new URLSearchParams(current);

                if (next === null) {
                    updated.delete('info');
                } else {
                    updated.set('info', next);
                }

                return updated;
            },
            { replace: true },
        );
    };

    return {
        open,
        tab,
        openPanel: () => update('config'),
        closePanel: () => update(null),
        setTab: (next: InfoTab) => update(next),
    };
}

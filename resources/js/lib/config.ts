export interface SynapseConfig {
    path: string;
    version: string;
    /**
     * Whether this deployment can push a response as it is produced. False under
     * runtimes that assemble the whole response first — the playground says so
     * rather than letting a blank thread read as a hang.
     */
    streaming: boolean;
}

declare global {
    interface Window {
        Synapse: SynapseConfig;
    }
}

export const config: SynapseConfig = window.Synapse ?? {
    path: 'synapse',
    version: 'dev',
    streaming: true,
};

/** The base path the dashboard is served from, e.g. "/synapse". */
export const basePath = '/' + config.path.replace(/^\/+|\/+$/g, '');

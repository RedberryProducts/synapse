export interface SynapseConfig {
    path: string;
    version: string;
}

declare global {
    interface Window {
        Synapse: SynapseConfig;
    }
}

export const config: SynapseConfig = window.Synapse ?? { path: 'synapse', version: 'dev' };

/** The base path the dashboard is served from, e.g. "/synapse". */
export const basePath = '/' + config.path.replace(/^\/+|\/+$/g, '');

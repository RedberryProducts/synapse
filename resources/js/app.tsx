import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import './styles/app.css';
import { initTheme } from '@/lib/theme';
import { AppRouter } from '@/router';

initTheme();

const container = document.getElementById('synapse');

if (container) {
    createRoot(container).render(
        <StrictMode>
            <AppRouter />
        </StrictMode>,
    );
}

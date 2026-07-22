import { createBrowserRouter, RouterProvider } from 'react-router-dom';
import { basePath } from '@/lib/config';
import { AppShell } from '@/components/AppShell';
import Discovery from '@/pages/Discovery';
import Playground from '@/pages/Playground';
import History from '@/pages/History';

const router = createBrowserRouter(
    [
        {
            path: '/',
            element: <AppShell />,
            children: [
                { index: true, element: <Discovery /> },
                { path: 'playground/:agent?', element: <Playground /> },
                { path: 'history', element: <History /> },
            ],
        },
    ],
    { basename: basePath },
);

export function AppRouter() {
    return <RouterProvider router={router} />;
}

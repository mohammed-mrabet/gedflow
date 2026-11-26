// Polices hébergées avec l'application (aucun appel à un service externe).
import '@fontsource/ibm-plex-sans/400.css';
import '@fontsource/ibm-plex-sans/500.css';
import '@fontsource/ibm-plex-sans/600.css';
import '@fontsource/ibm-plex-mono/400.css';
import '../css/app.css';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import type { ComponentType } from 'react';

createInertiaApp({
    title: (title) => (title ? `${title} · GedFlow` : 'GedFlow'),
    // Une page = un fichier JavaScript chargé à la demande (découpage du code par page).
    resolve: (name) => {
        const pages = import.meta.glob<{ default: ComponentType }>('./Pages/**/*.tsx');
        const page = pages[`./Pages/${name}.tsx`];
        if (!page) throw new Error(`Page introuvable : ${name}`);
        return page();
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: { color: '#b7791f' },
});

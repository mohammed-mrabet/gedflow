// Dates affichées à l'heure du Maroc ; elles sont stockées en UTC côté serveur.
const zone = 'Africa/Casablanca';

export const dateTime = (iso: string | null | undefined) =>
    iso ? new Intl.DateTimeFormat('fr-FR', { dateStyle: 'short', timeStyle: 'short', timeZone: zone }).format(new Date(iso)) : '—';

export const date = (iso: string | null | undefined) =>
    iso ? new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeZone: zone }).format(new Date(iso)) : '—';

export const relative = (iso: string) => {
    const minutes = Math.round((new Date(iso).getTime() - Date.now()) / 60000);
    const rtf = new Intl.RelativeTimeFormat('fr', { numeric: 'auto' });
    if (Math.abs(minutes) < 60) return rtf.format(minutes, 'minute');
    const hours = Math.round(minutes / 60);
    if (Math.abs(hours) < 48) return rtf.format(hours, 'hour');
    return rtf.format(Math.round(hours / 24), 'day');
};

export const fileSize = (bytes: number) =>
    bytes < 1024 ? `${bytes} o` : bytes < 1048576 ? `${(bytes / 1024).toFixed(0)} Ko` : `${(bytes / 1048576).toFixed(1).replace('.', ',')} Mo`;

export const cx = (...classes: (string | false | null | undefined)[]) => classes.filter(Boolean).join(' ');

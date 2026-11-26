import { router, useForm } from '@inertiajs/react';
import { Copy, ExternalLink } from 'lucide-react';
import { useState } from 'react';
import Layout from '@/Components/Layout';
import type { Option } from '@/types';
import { date, dateTime } from '@/lib/format';

interface Token { id: number; name: string; abilities: string[]; lastUsedAt: string | null; expiresAt: string | null; createdAt: string }

export default function ApiTokens({ tokens, abilities, newToken }: { tokens: Token[]; abilities: Option[]; newToken: string | null }) {
    const form = useForm<{ name: string; abilities: string[] }>({ name: '', abilities: ['documents:read'] });
    const [copied, setCopied] = useState(false);
    const toggle = (v: string) => form.setData('abilities', form.data.abilities.includes(v) ? form.data.abilities.filter((a) => a !== v) : [...form.data.abilities, v]);

    return (
        <Layout title="Accès API" actions={<a className="btn" href="/api/docs" target="_blank" rel="noopener"><ExternalLink className="size-4" /> Documentation de l'API</a>}>
            {newToken && (
                <div className="mb-6 rounded-xl border border-emerald-300 bg-emerald-50 p-5">
                    <p className="text-sm font-semibold text-emerald-900">Jeton créé. Copiez-le maintenant : il ne sera plus jamais affiché.</p>
                    <div className="mt-3 flex gap-2">
                        <code className="mono flex-1 overflow-x-auto rounded-lg bg-white px-3 py-2 ring-1 ring-emerald-200">{newToken}</code>
                        <button className="btn" onClick={() => { navigator.clipboard?.writeText(newToken); setCopied(true); }}><Copy className="size-4" /> {copied ? 'Copié' : 'Copier'}</button>
                    </div>
                    <p className="mt-3 text-xs text-emerald-900">Exemple : <code className="mono">curl -H "Authorization: Bearer {newToken.slice(0, 6)}…" {window.location.origin}/api/v1/documents?q=facture</code></p>
                </div>
            )}

            <div className="grid gap-6 lg:grid-cols-[1fr_1.4fr]">
                <form className="card card-pad space-y-4" onSubmit={(e) => { e.preventDefault(); form.post('/profil/api', { preserveScroll: true, onSuccess: () => form.reset('name') }); }}>
                    <h2 className="font-semibold">Nouveau jeton</h2>
                    <label className="field">Nom de l'application<input className="input" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="ex. Connecteur ERP" /></label>
                    {form.errors.name && <p className="error">{form.errors.name}</p>}
                    <fieldset className="space-y-2">
                        <legend className="mb-1 text-sm font-medium text-ink-700">Capacités</legend>
                        {abilities.map((a) => (
                            <label key={a.value} className="flex items-start gap-2 text-sm">
                                <input type="checkbox" className="mt-1" checked={form.data.abilities.includes(a.value)} onChange={() => toggle(a.value)} />
                                <span><code className="mono">{a.value}</code><span className="block text-xs text-ink-500">{a.label}</span></span>
                            </label>
                        ))}
                    </fieldset>
                    {(form.errors as Record<string, string>).abilities && <p className="error">{(form.errors as Record<string, string>).abilities}</p>}
                    <p className="text-xs text-ink-500">Validité : 90 jours. Les droits du jeton ne dépassent jamais les vôtres.</p>
                    <button className="btn btn-primary" disabled={form.processing}>Créer le jeton</button>
                </form>

                <section className="card">
                    <h2 className="border-b border-ink-100 px-5 py-3.5 font-semibold">Mes jetons</h2>
                    {tokens.length === 0 ? <p className="px-5 py-10 text-center text-sm text-ink-500">Aucun jeton.</p> : (
                        <ul className="divide-y divide-ink-100">
                            {tokens.map((t) => (
                                <li key={t.id} className="flex items-center gap-4 px-5 py-3 text-sm">
                                    <div className="min-w-0 flex-1">
                                        <p className="font-medium">{t.name}</p>
                                        <p className="text-xs text-ink-500">{t.abilities.join(', ')}</p>
                                        <p className="text-xs text-ink-400">Créé le {date(t.createdAt)} · expire le {date(t.expiresAt)} · dernière utilisation : {t.lastUsedAt ? dateTime(t.lastUsedAt) : 'jamais'}</p>
                                    </div>
                                    <button className="btn btn-sm" onClick={() => router.delete(`/profil/api/${t.id}`, { preserveScroll: true })}>Révoquer</button>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </Layout>
    );
}

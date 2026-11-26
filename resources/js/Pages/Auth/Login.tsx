import { Head, useForm } from '@inertiajs/react';
import { FileText } from 'lucide-react';

export default function Login({ demoAccounts }: { demoAccounts: { email: string; label: string }[] }) {
    const form = useForm({ email: '', password: '', remember: false });

    return (
        <div className="grid min-h-screen lg:grid-cols-2">
            <Head title="Connexion" />
            <section className="hidden flex-col justify-between bg-ink-900 p-12 text-ink-200 lg:flex">
                <div className="flex items-center gap-3 text-white">
                    <span className="grid size-10 place-items-center rounded-lg bg-white/10"><FileText className="size-5 text-amber-300" /></span>
                    <strong className="text-xl font-semibold">GedFlow</strong>
                </div>
                <div className="max-w-md">
                    <h2 className="text-3xl leading-tight font-semibold text-white">Vos documents, leurs versions et leurs circuits de validation, au même endroit.</h2>
                    <ul className="mt-6 space-y-2 text-sm text-ink-400">
                        <li>• Dépôt, versions et empreinte SHA-256 de chaque fichier</li>
                        <li>• Circuits de validation par type de document, avec délais et relances</li>
                        <li>• Recherche dans le contenu des PDF et documents Word</li>
                        <li>• Droits d'accès par service et journal d'audit complet</li>
                    </ul>
                </div>
                <p className="text-xs text-ink-500">Projet de démonstration · données fictives</p>
            </section>

            <section className="flex items-center justify-center bg-paper p-6">
                <div className="w-full max-w-sm">
                    <h1 className="text-2xl font-semibold text-ink-900">Connexion</h1>
                    <p className="mt-1 text-sm text-ink-500">Accédez à l'espace documentaire de votre entreprise.</p>
                    <form className="mt-6 space-y-4" onSubmit={(e) => { e.preventDefault(); form.post('/login', { onFinish: () => form.reset('password') }); }}>
                        <label className="field">Adresse e-mail
                            <input className="input" type="email" autoComplete="username" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} autoFocus />
                            {form.errors.email && <span className="error">{form.errors.email}</span>}
                        </label>
                        <label className="field">Mot de passe
                            <input className="input" type="password" autoComplete="current-password" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} />
                        </label>
                        <label className="flex items-center gap-2 text-sm text-ink-700">
                            <input type="checkbox" checked={form.data.remember} onChange={(e) => form.setData('remember', e.target.checked)} /> Rester connecté
                        </label>
                        <button className="btn btn-primary w-full" disabled={form.processing}>Se connecter</button>
                    </form>

                    {demoAccounts.length > 0 && (
                        <div className="mt-8 rounded-xl border border-ink-100 bg-white p-4">
                            <p className="text-sm font-semibold text-ink-800">Comptes de démonstration <span className="font-normal text-ink-500">· mot de passe <code className="mono">password</code></span></p>
                            <ul className="mt-3 space-y-1.5">
                                {demoAccounts.map((a) => (
                                    <li key={a.email}>
                                        <button type="button" className="w-full rounded-lg px-2 py-1.5 text-left text-sm hover:bg-ink-50" onClick={() => form.setData({ ...form.data, email: a.email, password: 'password' })}>
                                            <span className="mono block text-ink-800">{a.email}</span>
                                            <span className="text-xs text-ink-500">{a.label}</span>
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </div>
            </section>
        </div>
    );
}

import { Head, useForm } from '@inertiajs/react';
import { BadgeCheck, FileSearch, ShieldAlert, ShieldCheck } from 'lucide-react';
import { dateTime } from '@/lib/format';

interface Certificate {
    reference: string;
    title: string | null;
    type: string;
    department: string;
    status: string;
    approvedAt: string;
    version: number;
    sha256: string;
    visas: { step: string; department: string; by: string | null; onBehalfOf: string | null; at: string }[];
}

/** Page publique ouverte par le QR code d'un certificat (aucune connexion nécessaire). */
export default function Verify({ certificate, result }: { certificate: Certificate | null; result: { match: boolean; sha256: string; name: string } | null }) {
    const form = useForm<{ file: File | null }>({ file: null });

    return (
        <div className="min-h-screen bg-paper px-4 py-10">
            <Head title="Vérification d'un document" />
            <div className="mx-auto max-w-2xl">
                <p className="mb-6 text-center text-sm font-semibold tracking-wide text-ink-500 uppercase">GedFlow · vérification d'authenticité</p>

                {!certificate ? (
                    <div className="card card-pad text-center">
                        <ShieldAlert className="mx-auto size-10 text-red-600" />
                        <h1 className="mt-3 text-xl font-semibold">Certificat introuvable</h1>
                        <p className="mt-1 text-sm text-ink-500">Ce lien ne correspond à aucun document approuvé. Le document a peut-être été falsifié ou le lien est incomplet.</p>
                    </div>
                ) : (
                    <>
                        <div className="card card-pad">
                            <div className="flex items-start gap-4">
                                <BadgeCheck className="size-10 shrink-0 text-emerald-600" />
                                <div>
                                    <h1 className="text-xl font-semibold">Document authentique et approuvé</h1>
                                    <p className="text-sm text-ink-500">{certificate.title ?? 'Titre confidentiel'} · <span className="mono">{certificate.reference}</span></p>
                                </div>
                            </div>
                            <dl className="mt-5 grid grid-cols-[150px_1fr] gap-y-2 text-sm">
                                <dt className="text-ink-500">Type</dt><dd>{certificate.type}</dd>
                                <dt className="text-ink-500">Service émetteur</dt><dd>{certificate.department}</dd>
                                <dt className="text-ink-500">Statut</dt><dd>{certificate.status}</dd>
                                <dt className="text-ink-500">Approuvé le</dt><dd>{dateTime(certificate.approvedAt)}</dd>
                                <dt className="text-ink-500">Version approuvée</dt><dd>v{certificate.version}</dd>
                                <dt className="text-ink-500">Empreinte SHA-256</dt><dd className="mono break-all text-xs">{certificate.sha256}</dd>
                            </dl>
                            <h2 className="mt-6 text-sm font-semibold">Visas</h2>
                            <ol className="mt-2 space-y-2">
                                {certificate.visas.map((v, i) => (
                                    <li key={i} className="flex gap-3 rounded-lg bg-ink-50 px-3 py-2 text-sm">
                                        <span className="grid size-6 shrink-0 place-items-center rounded-full bg-emerald-600 text-xs font-bold text-white">{i + 1}</span>
                                        <span><strong>{v.step}</strong> ({v.department}) — {v.by}{v.onBehalfOf && ` pour le compte de ${v.onBehalfOf}`}, {dateTime(v.at)}</span>
                                    </li>
                                ))}
                            </ol>
                        </div>

                        <form className="card card-pad mt-6" onSubmit={(e) => { e.preventDefault(); form.post(window.location.pathname, { forceFormData: true, preserveScroll: true }); }}>
                            <h2 className="flex items-center gap-2 font-semibold"><FileSearch className="size-5 text-amber-brand" /> Vérifier un fichier reçu</h2>
                            <p className="mt-1 text-sm text-ink-500">Son empreinte est calculée et comparée à celle du document approuvé. Le fichier n'est pas conservé.</p>
                            <div className="mt-3 flex flex-wrap items-center gap-3">
                                <input type="file" className="text-sm" onChange={(e) => form.setData('file', e.target.files?.[0] ?? null)} />
                                <button className="btn btn-primary" disabled={!form.data.file || form.processing}>Vérifier</button>
                            </div>
                            {form.errors.file && <p className="error mt-2">{form.errors.file}</p>}
                            {result && (
                                <div className={`mt-4 flex gap-3 rounded-lg px-4 py-3 text-sm ${result.match ? 'bg-emerald-50 text-emerald-900' : 'bg-red-50 text-red-900'}`}>
                                    {result.match ? <ShieldCheck className="size-5 shrink-0" /> : <ShieldAlert className="size-5 shrink-0" />}
                                    <div>
                                        <strong>{result.match ? 'Fichier identique au document approuvé.' : 'Ce fichier ne correspond pas au document approuvé.'}</strong>
                                        <p className="mono mt-1 text-xs break-all opacity-80">{result.name} · {result.sha256}</p>
                                    </div>
                                </div>
                            )}
                        </form>
                    </>
                )}
            </div>
        </div>
    );
}

import { router, useForm } from '@inertiajs/react';
import { Archive, Award, CheckCircle2, Download, FileUp, Pencil, Send, ShieldCheck, Undo2, XCircle } from 'lucide-react';
import { useState } from 'react';
import Layout from '@/Components/Layout';
import ActivityList from '@/Components/ActivityList';
import { ConfidentialityBadge, StatusBadge, Tags, TaskBadge } from '@/Components/Badges';
import type { Activity, DocumentRow, Option, Task, Version } from '@/types';
import { cx, date, dateTime, fileSize, relative } from '@/lib/format';

interface Doc extends DocumentRow {
    description: string | null;
    reviewRound: number;
    submittedAt: string | null;
    approvedAt: string | null;
    archivedAt: string | null;
    retentionUntil: string | null;
    retentionYears: number;
    createdAt: string;
    currentVersion: Version | null;
    versions: Version[];
    tasks: Task[];
    plannedSteps: { position: number; name: string; department: string; dueHours: number }[];
    sharedWith: { id: number; name: string; department: string | null }[];
    hasCertificate: boolean;
    verificationUrl: string | null;
}

interface Props {
    document: Doc;
    pendingTaskId: number | null;
    can: { update: boolean; submit: boolean; withdraw: boolean; share: boolean; archive: boolean; decide: boolean };
    activity: Activity[];
    confidentialityOptions: Option[];
    shareCandidates: { id: number; name: string; department: string | null }[];
}

export default function Show({ document: doc, pendingTaskId, can, activity, confidentialityOptions, shareCandidates }: Props) {
    const [panel, setPanel] = useState<'version' | 'edit' | null>(null);
    const fileUrl = (v: Version, inline = false) => `/documents/${doc.id}/versions/${v.id}/fichier${inline ? '?inline=1' : ''}`;
    const post = (url: string) => router.post(url, {}, { preserveScroll: true });

    return (
        <Layout
            title={doc.title}
            actions={
                <>
                    {can.submit && <button className="btn btn-primary" onClick={() => post(`/documents/${doc.id}/soumettre`)}><Send className="size-4" /> {doc.reviewRound > 0 ? 'Soumettre de nouveau' : 'Soumettre à validation'}</button>}
                    {can.update && <button className="btn" onClick={() => setPanel(panel === 'version' ? null : 'version')}><FileUp className="size-4" /> Nouvelle version</button>}
                    {can.update && <button className="btn" onClick={() => setPanel(panel === 'edit' ? null : 'edit')}><Pencil className="size-4" /> Modifier</button>}
                    {can.withdraw && <button className="btn" onClick={() => post(`/documents/${doc.id}/retirer`)}><Undo2 className="size-4" /> Retirer du circuit</button>}
                    {doc.hasCertificate && <a className="btn" href={`/documents/${doc.id}/certificat`} target="_blank" rel="noopener"><Award className="size-4" /> Certificat de validation</a>}
                    {can.archive && <button className="btn" onClick={() => post(`/documents/${doc.id}/archiver`)}><Archive className="size-4" /> Archiver</button>}
                </>
            }
        >
            <div className="-mt-3 mb-6 flex flex-wrap items-center gap-3 text-sm text-ink-500">
                <span className="mono rounded bg-ink-900 px-2 py-0.5 text-white">{doc.reference}</span>
                <StatusBadge status={doc.status} label={doc.statusLabel} />
                <ConfidentialityBadge value={doc.confidentiality} label={doc.confidentialityLabel} />
                <span>{doc.type} · {doc.department} · déposé par {doc.owner}</span>
                <Tags tags={doc.tags} />
            </div>

            {doc.status === 'rejected' && <RejectionNotice tasks={doc.tasks} round={doc.reviewRound} currentVersion={doc.currentVersion} />}
            {panel === 'version' && <NewVersionForm docId={doc.id} onDone={() => setPanel(null)} />}
            {panel === 'edit' && <EditForm doc={doc} options={confidentialityOptions} onDone={() => setPanel(null)} />}
            {can.decide && pendingTaskId && <DecisionPanel taskId={pendingTaskId} task={doc.tasks.find((t) => t.id === pendingTaskId)!} />}

            <div className="grid gap-6 xl:grid-cols-[1.5fr_1fr]">
                <div className="space-y-6">
                    <section className="card overflow-hidden">
                        <div className="flex items-center justify-between border-b border-ink-100 px-5 py-3">
                            <h2 className="font-semibold">Aperçu · version {doc.currentVersion?.number}</h2>
                            {doc.currentVersion && <a className="btn btn-sm" href={fileUrl(doc.currentVersion)}><Download className="size-3.5" /> Télécharger</a>}
                        </div>
                        {doc.currentVersion?.previewable ? (
                            doc.currentVersion.mimeType === 'application/pdf'
                                ? <iframe title="Aperçu du document" src={`${fileUrl(doc.currentVersion, true)}#navpanes=0&view=FitH`} className="h-[560px] w-full bg-ink-100" />
                                : <img alt={doc.title} src={fileUrl(doc.currentVersion, true)} className="max-h-[560px] w-full bg-ink-50 object-contain" />
                        ) : (
                            <p className="px-5 py-14 text-center text-sm text-ink-500">Aperçu indisponible pour ce format : téléchargez le fichier pour l'ouvrir.</p>
                        )}
                    </section>

                    <section className="card">
                        <h2 className="border-b border-ink-100 px-5 py-3 font-semibold">Versions</h2>
                        <table className="w-full">
                            <thead className="bg-ink-50/60"><tr><th className="th">Version</th><th className="th">Fichier</th><th className="th">Empreinte SHA-256</th><th className="th" /></tr></thead>
                            <tbody className="divide-y divide-ink-100">
                                {doc.versions.map((v) => (
                                    <tr key={v.id}>
                                        <td className="td whitespace-nowrap"><strong>v{v.number}</strong>{v.id === doc.currentVersion?.id && <span className="ml-2 text-xs text-emerald-700">actuelle</span>}</td>
                                        <td className="td">
                                            <p className="font-medium">{v.name}</p>
                                            <p className="text-xs text-ink-500">{fileSize(v.size)} · {v.uploadedBy} · {dateTime(v.createdAt)} · {v.textIndexed ? 'texte indexé' : 'non indexé'}</p>
                                            {v.comment && <p className="mt-1 text-xs text-ink-600 italic">« {v.comment} »</p>}
                                        </td>
                                        <td className="td"><span className="mono text-ink-500" title={v.sha256}>{v.sha256.slice(0, 16)}…</span></td>
                                        <td className="td whitespace-nowrap text-right">
                                            <button className="btn btn-sm btn-ghost" title="Vérifier l'intégrité" onClick={() => post(`/documents/${doc.id}/versions/${v.id}/verification`)}><ShieldCheck className="size-4" /></button>
                                            <a className="btn btn-sm btn-ghost" href={fileUrl(v)} title="Télécharger"><Download className="size-4" /></a>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </section>
                </div>

                <div className="space-y-6">
                    <WorkflowCard doc={doc} />

                    <section className="card card-pad">
                        <h2 className="mb-3 font-semibold">Informations</h2>
                        <dl className="grid grid-cols-[140px_1fr] gap-x-3 gap-y-2 text-sm">
                            <dt className="text-ink-500">Créé le</dt><dd>{dateTime(doc.createdAt)}</dd>
                            <dt className="text-ink-500">Soumis le</dt><dd>{dateTime(doc.submittedAt)}</dd>
                            <dt className="text-ink-500">Approuvé le</dt><dd>{dateTime(doc.approvedAt)}</dd>
                            {doc.archivedAt && <><dt className="text-ink-500">Archivé le</dt><dd>{dateTime(doc.archivedAt)}</dd></>}
                            <dt className="text-ink-500">Conservation</dt>
                            <dd>{doc.retentionUntil ? <>jusqu'au {date(doc.retentionUntil)} {doc.retentionExpired && <span className="font-medium text-red-700">(échue)</span>}</> : `${doc.retentionYears} ans après approbation`}</dd>
                        </dl>
                        {doc.verificationUrl && (
                            <p className="mt-4 border-t border-ink-100 pt-3 text-xs text-ink-500">
                                Page publique de vérification (QR code du certificat) :{' '}
                                <a className="text-amber-brand-dark hover:underline" href={doc.verificationUrl} target="_blank" rel="noopener">ouvrir</a>
                            </p>
                        )}
                        {doc.description && <p className="mt-4 border-t border-ink-100 pt-3 text-sm text-ink-700">{doc.description}</p>}
                    </section>

                    {(doc.confidentiality === 'restricted' || doc.sharedWith.length > 0) && (
                        <SharePanel docId={doc.id} shared={doc.sharedWith} candidates={shareCandidates} canShare={can.share} />
                    )}

                    <section className="card">
                        <h2 className="border-b border-ink-100 px-5 py-3 font-semibold">Journal du document</h2>
                        <ActivityList items={activity} />
                    </section>
                </div>
            </div>
        </Layout>
    );
}

function WorkflowCard({ doc }: { doc: Doc }) {
    const current = doc.tasks.filter((t) => t.round === doc.reviewRound);
    const previous = doc.tasks.filter((t) => t.round < doc.reviewRound);
    const steps = doc.plannedSteps.map((s) => ({ ...s, task: current.find((t) => t.position === s.position) }));

    return (
        <section className="card card-pad">
            <h2 className="font-semibold">Circuit de validation {doc.reviewRound > 0 && <span className="text-sm font-normal text-ink-500">· tour {doc.reviewRound}</span>}</h2>
            {doc.reviewRound === 0 && <p className="mt-1 text-sm text-ink-500">Pas encore soumis. Étapes prévues :</p>}
            <ol className="mt-4 space-y-0">
                {steps.map((s, i) => {
                    const t = s.task;
                    const done = t?.status === 'approved';
                    const bad = t?.status === 'rejected';
                    return (
                        <li key={s.position} className="relative flex gap-3 pb-5">
                            {i < steps.length - 1 && <span className="absolute top-7 left-3.5 h-full w-px bg-ink-200" />}
                            <span className={cx('z-10 grid size-7 shrink-0 place-items-center rounded-full text-xs font-bold',
                                done ? 'bg-emerald-600 text-white' : bad ? 'bg-red-600 text-white' : t?.status === 'pending' ? 'bg-amber-brand text-white ring-4 ring-amber-brand/20' : 'bg-ink-100 text-ink-500')}>
                                {done ? <CheckCircle2 className="size-4" /> : bad ? <XCircle className="size-4" /> : s.position}
                            </span>
                            <div className="min-w-0 flex-1 text-sm">
                                <div className="flex flex-wrap items-center gap-2">
                                    <strong>{s.name}</strong>
                                    {t && <TaskBadge status={t.status} label={t.statusLabel} overdue={t.overdue} />}
                                    {t?.escalated && t.status === 'pending' && <span className="rounded-full bg-red-50 px-2 py-0.5 text-[11px] font-semibold text-red-700 ring-1 ring-red-200">Escaladé</span>}
                                </div>
                                <p className="text-xs text-ink-500">{s.department} · délai {s.dueHours} h</p>
                                {t?.status === 'pending' && <p className="text-xs text-ink-600">Échéance {dateTime(t.dueAt)} ({relative(t.dueAt)})</p>}
                                {t?.decidedBy && <p className="text-xs text-ink-600">{t.decidedBy}{t.onBehalfOf && <span className="text-violet-700"> pour le compte de {t.onBehalfOf}</span>}, {dateTime(t.decidedAt)}</p>}
                                {t?.comment && <p className="mt-1 rounded bg-ink-50 px-2 py-1 text-xs text-ink-700">« {t.comment} »</p>}
                            </div>
                        </li>
                    );
                })}
            </ol>
            {previous.length > 0 && (
                <details className="mt-1 border-t border-ink-100 pt-3 text-sm">
                    <summary className="cursor-pointer text-ink-600">Tours précédents ({previous.length} étape(s))</summary>
                    <ul className="mt-2 space-y-1.5">
                        {previous.map((t) => (
                            <li key={t.id} className="text-xs text-ink-600">
                                Tour {t.round} · v{t.version} · {t.stepName} : <strong>{t.statusLabel}</strong>{t.decidedBy && ` par ${t.decidedBy}`}{t.comment && ` — « ${t.comment} »`}
                            </li>
                        ))}
                    </ul>
                </details>
            )}
        </section>
    );
}

function RejectionNotice({ tasks, round, currentVersion }: { tasks: Task[]; round: number; currentVersion: Version | null }) {
    const rejected = [...tasks].reverse().find((t) => t.status === 'rejected');
    if (!rejected) return null;
    const corrected = currentVersion && rejected.version !== null && currentVersion.number > rejected.version;
    return (
        <div className="mb-6 rounded-xl border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-900">
            <strong>Rejeté au tour {round}</strong> à l'étape « {rejected.stepName} » par {rejected.decidedBy} : « {rejected.comment} »
            <p className="mt-1 text-red-800">{corrected ? `La version ${currentVersion!.number} a été déposée : vous pouvez soumettre de nouveau.` : 'Déposez une version corrigée puis soumettez de nouveau le document.'}</p>
        </div>
    );
}

function DecisionPanel({ taskId, task }: { taskId: number; task: Task }) {
    const form = useForm({ decision: '', comment: '' });
    const decide = (decision: 'approve' | 'reject') => { form.transform((d) => ({ ...d, decision })); form.post(`/validations/${taskId}/decision`, { preserveScroll: true }); };
    return (
        <section className="mb-6 rounded-xl border-2 border-amber-brand/40 bg-amber-50/50 p-5">
            <h2 className="font-semibold">Votre visa est attendu : {task.stepName}</h2>
            <p className="text-sm text-ink-600">Version {task.version} · échéance {dateTime(task.dueAt)} {task.overdue && <strong className="text-red-700">(en retard)</strong>}</p>
            <textarea className="input mt-3 min-h-20" placeholder="Commentaire (obligatoire en cas de rejet)" value={form.data.comment} onChange={(e) => form.setData('comment', e.target.value)} />
            {(form.errors.comment || (form.errors as Record<string, string>).task) && <p className="error mt-1">{form.errors.comment ?? (form.errors as Record<string, string>).task}</p>}
            <div className="mt-3 flex gap-2">
                <button className="btn btn-primary" disabled={form.processing} onClick={() => decide('approve')}><CheckCircle2 className="size-4" /> Viser</button>
                <button className="btn btn-danger" disabled={form.processing} onClick={() => decide('reject')}><XCircle className="size-4" /> Rejeter</button>
            </div>
        </section>
    );
}

function NewVersionForm({ docId, onDone }: { docId: number; onDone: () => void }) {
    const form = useForm<{ file: File | null; comment: string }>({ file: null, comment: '' });
    return (
        <form className="card card-pad mb-6 flex flex-wrap items-end gap-3" onSubmit={(e) => { e.preventDefault(); form.post(`/documents/${docId}/versions`, { forceFormData: true, preserveScroll: true, onSuccess: onDone }); }}>
            <label className="field">Fichier
                <input type="file" className="text-sm" accept=".pdf,.docx,.xlsx,.txt,.png,.jpg,.jpeg" onChange={(e) => form.setData('file', e.target.files?.[0] ?? null)} />
                {form.errors.file && <span className="error">{form.errors.file}</span>}
            </label>
            <label className="field min-w-64 flex-1">Ce qui a changé
                <input className="input" value={form.data.comment} onChange={(e) => form.setData('comment', e.target.value)} placeholder="ex. Montant corrigé selon la remarque de la Finance" />
            </label>
            <button className="btn btn-primary" disabled={form.processing || !form.data.file}>Déposer la version</button>
        </form>
    );
}

function EditForm({ doc, options, onDone }: { doc: Doc; options: Option[]; onDone: () => void }) {
    const form = useForm({ title: doc.title, description: doc.description ?? '', confidentiality: doc.confidentiality, tags: doc.tags.join(', ') });
    return (
        <form className="card card-pad mb-6 grid gap-3 md:grid-cols-2" onSubmit={(e) => {
            e.preventDefault();
            { form.transform((d) => ({ ...d, tags: d.tags.split(',').map((t) => t.trim()).filter(Boolean) })); form.put(`/documents/${doc.id}`, { preserveScroll: true, onSuccess: onDone }); };
        }}>
            <label className="field">Titre<input className="input" value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} />{form.errors.title && <span className="error">{form.errors.title}</span>}</label>
            <label className="field">Accès
                <select className="input" value={form.data.confidentiality} onChange={(e) => form.setData('confidentiality', e.target.value)}>
                    {options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                </select>
            </label>
            <label className="field">Mots-clés (séparés par des virgules)<input className="input" value={form.data.tags} onChange={(e) => form.setData('tags', e.target.value)} /></label>
            <label className="field">Description<input className="input" value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} /></label>
            <div><button className="btn btn-primary" disabled={form.processing}>Enregistrer</button></div>
        </form>
    );
}

function SharePanel({ docId, shared, candidates, canShare }: { docId: number; shared: Doc['sharedWith']; candidates: Props['shareCandidates']; canShare: boolean }) {
    const [userId, setUserId] = useState('');
    return (
        <section className="card card-pad">
            <h2 className="font-semibold">Accès autorisés</h2>
            <p className="text-xs text-ink-500">En plus de l'auteur et des valideurs du circuit.</p>
            <ul className="mt-3 space-y-1.5 text-sm">
                {shared.length === 0 && <li className="text-ink-500">Personne d'autre.</li>}
                {shared.map((u) => (
                    <li key={u.id} className="flex items-center justify-between">
                        <span>{u.name} <span className="text-xs text-ink-500">{u.department}</span></span>
                        {canShare && <button className="text-xs text-red-700 hover:underline" onClick={() => router.delete(`/documents/${docId}/partages/${u.id}`, { preserveScroll: true })}>Retirer</button>}
                    </li>
                ))}
            </ul>
            {canShare && (
                <form className="mt-3 flex gap-2" onSubmit={(e) => { e.preventDefault(); router.post(`/documents/${docId}/partages`, { user_id: userId }, { preserveScroll: true, onSuccess: () => setUserId('') }); }}>
                    <select className="input" value={userId} onChange={(e) => setUserId(e.target.value)}>
                        <option value="">Ajouter une personne…</option>
                        {candidates.map((u) => <option key={u.id} value={u.id}>{u.name}{u.department ? ` (${u.department})` : ''}</option>)}
                    </select>
                    <button className="btn" disabled={!userId}>Autoriser</button>
                </form>
            )}
        </section>
    );
}


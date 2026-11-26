export type Role = 'admin' | 'validator' | 'contributor' | 'reader';
export type DocumentStatus = 'draft' | 'in_review' | 'approved' | 'rejected' | 'archived';
export type TaskStatus = 'pending' | 'approved' | 'rejected' | 'cancelled';
export type Option = { value: string; label: string };
export type IdName = { id: number; name: string };

export interface SharedProps {
    auth: { user: { id: number; name: string; email: string; role: Role; roleLabel: string; department: string | null; jobTitle: string | null } } | null;
    counters: { tasks: number; notifications: number } | null;
    nav: { delegations: boolean; statistics: boolean; activeDelegation: string | null } | null;
    notifications: { id: string; message: string; kind: string | null; documentId: number | null; read: boolean; createdAt: string }[];
    flash: { success: string | null; error: string | null };
    errors: Record<string, string>;
    [key: string]: unknown;
}

export interface DocumentRow {
    id: number;
    reference: string;
    title: string;
    type: string;
    department: string;
    owner: string;
    status: DocumentStatus;
    statusLabel: string;
    confidentiality: string;
    confidentialityLabel: string;
    tags: string[];
    version: number | null;
    updatedAt: string;
    retentionExpired: boolean;
    excerpt?: string | null;
}

export interface Version {
    id: number;
    number: number;
    name: string;
    mimeType: string;
    size: number;
    sha256: string;
    comment: string | null;
    uploadedBy: string | null;
    createdAt: string;
    previewable: boolean;
    textIndexed: boolean;
}

export interface Task {
    id: number;
    round: number;
    position: number;
    stepName: string;
    department: string;
    status: TaskStatus;
    statusLabel: string;
    dueAt: string;
    overdue: boolean;
    decidedBy: string | null;
    onBehalfOf: string | null;
    escalated: boolean;
    delegatedFrom?: string | null;
    decidedAt: string | null;
    comment: string | null;
    version: number | null;
    document?: { id: number; reference: string; title: string; type?: string; owner?: string };
}

export interface Activity {
    id: number;
    action: string;
    label: string;
    user: string | null;
    document: { id: number; reference: string } | null;
    details: Record<string, unknown> | null;
    ip: string | null;
    createdAt: string;
}

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
}

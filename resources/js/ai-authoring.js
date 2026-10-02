/*
 * AI proposal review section of the course template Activity page.
 *
 * Follows the protocol of docs/platform/LF-AI-Authoring-Review-UI-Design.md
 * (§4): content is rendered only from a successful detail read; a denial, a
 * lost session or an unavailable service removes it; a response that arrives
 * after the user moved on is dropped; nothing is written to any browser storage
 * and no content goes into a URL or the console; every piece of text is set with
 * textContent, never as HTML.
 */

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

const STATUSES = ['pending_review', 'accepted', 'rejected', 'stale', 'deletion_pending', 'deleted'];
const KINDS = ['summary', 'concept', 'learning_objective', 'competency', 'node_mapping'];
const REVIEW_ACTIONS = [
    'edit', 'accept', 'reject', 'approve_node', 'confirm_target', 'reconfirm_target', 'reject_target',
    'rebase_target', 'inherit_draft', 'rebase_selection', 'reconfirm_context', 'cancel_application',
    'apply_intent', 'retry_application',
];
const APPLICATION_OPERATIONS = ['create_node', 'apply_intent'];
const APPLICATION_STATUSES = ['awaiting_publication', 'ready_to_apply', 'applied', 'failed', 'cancelled'];
const MAPPING_ROLES = ['teaches', 'practices', 'assesses'];
const SOURCE_USAGES = ['document', 'audio', 'video'];
const SOURCE_CONTENTS = ['extracted_text', 'region', 'table', 'formula', 'transcript', 'caption_asset', 'video_frame_text'];
const SOURCE_LOCATORS = ['page', 'timespan', 'sheet', 'region'];
// Colour is never the only signal: the status is always written out as well.
const BADGES = {
    pending_review: 'badge-info', accepted: 'badge-success', rejected: 'badge-danger',
    stale: 'badge-secondary', deletion_pending: 'badge-secondary', deleted: 'badge-secondary',
};
const NODE_TYPES = ['objective', 'concept', 'competency'];
const BASIS_KINDS = ['competency', 'node_mapping'];
const BULK_LIMIT = 100;
// Gate codes by what the person can do about them. Only what is listed is named;
// anything else falls back to a neutral message and never shows the code.
const GATE_MESSAGES = {
    AI_APPROVAL_REQUIRED: 'gate_not_ready',
    AI_QUOTA_EXCEEDED: 'gate_quota',
    AI_SAFETY_BLOCKED: 'gate_safety',
    AI_PROVIDER_CALL_FAILED: 'gate_incomplete',
    AI_QUOTA_COMMIT_FAILED: 'gate_incomplete',
    AI_QUOTA_RESERVATION_EXCEEDED: 'gate_incomplete',
    AI_ADAPTER_MISMATCH: 'gate_incomplete',
    AI_RUN_ALREADY_EXECUTED: 'gate_incomplete',
    AI_RUN_TRANSITION_CONFLICT: 'gate_incomplete',
    AI_RUN_PROVENANCE_CONFLICT: 'gate_incomplete',
    proposal_generation_output_unavailable: 'gate_incomplete',
    proposal_generation_cancelled_before_execution: 'gate_incomplete',
};
// What an HTTP status means whether or not the body could be read: a login page,
// a framework error page or broken JSON must still be told apart by status.
const STATUS_KINDS = {
    401: 'session', 419: 'session', 403: 'forbidden', 404: 'not_found', 409: 'conflict', 422: 'invalid',
    429: 'rate', 503: 'unavailable',
};
const HASH_PATTERN = /^[0-9a-f]{64}$/;
// The steps after acceptance: what each asks, where it goes, what it reports when done.
// `view` names what was shown (its hash is what is sent, `field`); `ask` is the base of the question's texts.
const TARGET_STEPS = {
    confirm: { path: 'target-confirmations', done: 'target_confirmed', hash: true, view: 'targetView', field: 'expected_target_hash', ask: 'confirm_target' },
    reject: { path: 'target-rejections', done: 'target_rejected', hash: true, view: 'targetView', field: 'expected_target_hash', ask: 'reject_target', danger: true },
    apply: { path: 'intent-applications', done: 'intent_applied', hash: false, ask: 'apply_target' },
    context: { path: 'context-confirmations', done: 'context_confirmed', hash: true, view: 'contextView', field: 'expected_course_context_hash', ask: 'confirm_context' },
};
// Why a receipt is cancelled: a fixed list of valid codes, the first being the default.
const RECEIPT_REASONS = ['human_cancel', 'wrong_target', 'course_changed', 'other'];
const RECEIPT_STEPS = {
    retry: { path: 'retry', done: 'receipt_retried', withReason: false },
    cancel: { path: 'cancel', done: 'receipt_cancelled', withReason: true },
};
// A successor can hold at most this many sources (contract: selection_limit), read a page at a time.
const SUCCESSOR_SOURCE_LIMIT = 200;
const SUCCESSOR_PAGE_SIZE = 100;
const SUCCESSOR_REASONS = ['source_revision_changed'];
const INHERIT_REASONS = ['node_retired', 'definition_inactive'];
// Why a Mapping is dropped in a rebase: a fixed list of valid codes.
const REBASE_REASONS = ['no_equivalent_node', 'no_longer_needed', 'other'];
const REBASE_ORIGINS = ['manual', 'ai_proposal'];
const CODE_PATTERN = /^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/;
const PAGE_SIZE = 25;
const PAYLOAD_SCHEMA_VERSION = 1;

/** A v4 UUID. randomUUID needs a secure context; getRandomValues does not. */
function newRequestId() {
    if (typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

/** Builds an element. `text` is always assigned as text, never parsed as HTML. */
function el(tag, { className, text, attrs } = {}, children = []) {
    const node = document.createElement(tag);
    if (className) {
        node.className = className;
    }
    if (text !== undefined && text !== null) {
        node.textContent = String(text);
    }
    for (const [name, value] of Object.entries(attrs ?? {})) {
        node.setAttribute(name, String(value));
    }
    for (const child of children) {
        // An absent child (null, undefined, false) is not text to show.
        if (child !== null && child !== undefined && child !== false) {
            node.append(child);
        }
    }

    return node;
}

/** A value from a fixed table, only for a key the table itself has (never an inherited one). */
function own(table, key) {
    return typeof key === 'string' && Object.hasOwn(table, key) ? table[key] : undefined;
}

function readJson(node, attribute) {
    try {
        const parsed = JSON.parse(node.getAttribute(attribute) ?? '');

        return parsed !== null && typeof parsed === 'object' ? parsed : null;
    } catch {
        return null;
    }
}

export class AiAuthoring {
    constructor(root, config, messages) {
        this.root = root;
        this.urls = config.urls;
        this.framework = config.framework && config.framework.selected === true
            ? { id: config.framework.framework_id, versionId: config.framework.framework_version_id } : null;
        this.csrfToken = typeof config.csrfToken === 'string' ? config.csrfToken : '';
        // The Template's mapping list, for an admin only; only an ordinary web address is followed.
        this.mappingsUrl = typeof config.mappingsUrl === 'string' && /^(https?:\/\/|\/(?![\/\\]))/i.test(config.mappingsUrl) ? config.mappingsUrl : null;
        // The draft versions of the Template's Framework in which an admin may have a new Node created:
        // only whole, positive ids with a name are taken.
        this.draftVersions = AiAuthoring.versionList(config.draftVersions);
        // The published versions a draft can be copied from (admin only).
        this.publishedVersions = AiAuthoring.versionList(config.publishedVersions);
        this.statusRegion = root.querySelector('[data-ai-authoring-status]');
        this.host = root.querySelector('[data-ai-authoring-list]');
        this.messages = messages;

        this.filters = { status: '', kind: '' };
        this.items = [];
        this.nextCursor = null;
        this.listLoaded = false;
        this.listBusy = false;
        // A response is applied only if its number is still the latest: a later
        // filter change or reload makes older responses stale.
        this.listSeq = 0;
        this.detailSeq = 0;
        this.openUuid = null;
        this.trigger = null;
        // The authorized detail on screen. It exists only while its content is shown
        // and is dropped with it; edits and decisions are built from it.
        this.detailData = null;
        // Commands whose outcome is not known, one per kind of command. Retrying must
        // send the same request id with the same body, so the server recognises it
        // as the same command.
        this.pendings = new Map();
        this.busy = false;
        this.createBusy = false;
        this.bulkBusy = false;
        // What this visit has actually opened: the versions a reviewer saw, never the
        // content. Only these proposals can join a bulk decision (design §4.5).
        this.reviewed = new Map();
        this.selected = new Set();
        this.bulkNote = '';
        this.notice = null;
        // Counts every loss of authority. A flow that started before one must not
        // show what it fetched afterwards.
        this.epoch = 0;
        this.revoked = false;
        this.generationId = null;
        // The edit form on screen and the elements that belong to the open proposal.
        // A command whose outcome is unknown locks them until it is resolved.
        this.editor = null;
        this.locked = false;
        this.commandArea = null;
        this.actionsHost = null;
        this.noteField = null;
        this.actionButtons = [];
        this.pageHidden = false;
        // A decision note that outlives the reread of its own proposal (see runCommand).
        this.carriedNote = null;
        // The target of the open accepted mapping as it was shown, with the version it was shown
        // against: what a confirmation is made from. It goes with the detail.
        this.targetView = null;
        this.targetHost = null;
        // The same for the changed Course context: what it is now, with the version it was shown against.
        this.contextView = null;
        this.contextHost = null;
        // The inherited draft of a stale proposal as shown, with every current source it would be tied to.
        this.successorView = null;
        this.successorHost = null;
        // The reason chosen for cancelling each receipt (by receipt), the block that holds them, and a
        // choice to put back over the reread that follows a refused cancel.
        this.receiptReasons = new Map();
        this.receiptHost = null;
        this.pendingReason = null;
        // The draft chosen for a new Node (the select that holds it), and a choice to put back over the
        // reread that follows a refused approval.
        this.draftChoice = null;
        this.pendingDraft = null;
        // The inputs for a draft copied from a published version, the plan shown for them, and what to put
        // back over the reread that follows a refused creation.
        this.inheritFields = null;
        this.inheritView = null;
        this.inheritHost = null;
        this.pendingInherit = null;
        // Moving the Template to another published version: the chosen version and each Mapping's decision, the
        // plan they were made on, and decisions held (not lost) while that plan is replaced or refused.
        this.rebaseFields = null;
        this.rebaseView = null;
        this.rebaseHost = null;
        this.pendingRebase = null;
        this.rebaseHeld = new Map();
        // The create form is built once and kept, so what is chosen in it survives folding it away.
        this.createFormEl = null;
        this.generationKinds = [];
    }

    /** Versions to choose from: only whole, positive ids with a name are taken. */
    static versionList(raw) {
        return (Array.isArray(raw) ? raw : [])
            .filter((version) => version !== null && typeof version === 'object' && Number.isInteger(version.id) && version.id > 0
                && typeof version.label === 'string' && version.label !== '')
            .slice(0, 50).map((version) => ({ id: version.id, label: version.label.slice(0, 200) }));
    }

    t(key, params = {}) {
        let text = this.messages[key] ?? '';
        for (const [name, value] of Object.entries(params)) {
            text = text.replace(`:${name}`, String(value));
        }

        return text;
    }

    label(prefix, allowed, value) {
        return this.t(`${prefix}_${allowed.includes(value) ? value : 'unknown'}`) || this.t(`${prefix}_unknown`);
    }

    announce(text) {
        this.statusRegion.textContent = text;
    }

    start() {
        this.buildShell();
        this.loadList(true);

        // Back/forward can restore the page, with its content, from memory. Take the
        // content out when leaving and read it again on return, so what is on
        // screen was authorized by this visit and not by a previous one.
        window.addEventListener('pagehide', () => {
            // What is typed in the page goes too, not only the state kept about it: a
            // restored page must not show, or hand back, what an earlier visit held.
            this.dropDetail(false);
            this.carriedNote = null;
            this.pendingDraft = null;
            this.pendingInherit = null;
            this.pendingRebase = null;
            this.releaseBulk();
            this.bulkResultHost.replaceChildren();
            // Until the page is shown again, nothing is read back on its behalf.
            this.pageHidden = true;
        });
        // Unsent input lives only in this tab (design §4.2): say so before it is lost.
        window.addEventListener('beforeunload', (event) => {
            if (this.hasUnsent()) {
                event.preventDefault();
                event.returnValue = '';
            }
        });
        window.addEventListener('pageshow', (event) => {
            this.pageHidden = false;
            if (event.persisted) {
                const reopen = this.openUuid;
                this.openUuid = null;
                this.loadList(true);
                if (reopen) {
                    this.openDetail(reopen, null);
                }
            }
        });
    }

    // ---------------------------------------------------------------- transport

    /**
     * One GET. Resolves to {kind, status, data, code}; never throws. `kind` is one
     * of ok | session | forbidden | not_found | unavailable | rate | network | unexpected.
     */
    async get(url, options = {}) {
        return this.request('GET', url, null, options);
    }

    /**
     * One request with a JSON body for writes. Same result shape as get().
     *
     * A lost session, a refusal, or (for a read) a missing or unavailable resource
     * takes everything shown away here, whichever flow asked and whether or not
     * that flow is still current: the answer is about the person's access, not
     * about one screen. `soft` marks a lookup whose absence is an ordinary answer.
     */
    async request(method, url, command, { soft = false } = {}) {
        const result = await this.send(method, url, command);
        const lost = ['session', 'forbidden'].includes(result.kind)
            || (method === 'GET' && !soft && ['not_found', 'unavailable'].includes(result.kind));
        if (lost) {
            this.revoke(result.kind);
        }

        return result;
    }

    async send(method, url, command) {
        const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
        if (command !== null) {
            headers['Content-Type'] = 'application/json';
            headers['X-CSRF-TOKEN'] = this.csrfToken;
        }
        let response;
        try {
            response = await fetch(url, {
                method,
                headers,
                body: command === null ? undefined : JSON.stringify(command),
                credentials: 'same-origin',
                cache: 'no-store',
                // A redirect (for example to the sign-in page) is a lost session,
                // not a document to render.
                redirect: 'manual',
            });
        } catch {
            return { kind: 'network' };
        }
        if (response.type === 'opaqueredirect') {
            return { kind: 'session', status: response.status };
        }
        const byStatus = own(STATUS_KINDS, String(response.status)) ?? 'unexpected';
        const type = response.headers.get('content-type') ?? '';
        if (!type.includes('application/json')) {
            return { kind: byStatus, status: response.status };
        }
        let body = null;
        try {
            body = await response.json();
        } catch {
            return { kind: byStatus, status: response.status };
        }
        if (response.ok && body && body.error === null && body.data !== null && typeof body.data === 'object') {
            return { kind: 'ok', status: response.status, data: body.data };
        }
        const code = body?.error?.code ?? null;

        return { kind: byStatus, status: response.status, code };
    }

    failureMessage(kind, code = null) {
        if (kind === 'conflict') {
            if (own(GATE_MESSAGES, code)) {
                return this.t(own(GATE_MESSAGES, code));
            }
            if (typeof code === 'string' && code.startsWith('AI_')) {
                return this.t('gate_unknown');
            }

            return this.t(own({
                proposal_revision_conflict: 'conflict_revision', proposal_stale: 'conflict_stale',
                proposal_idempotency_conflict: 'conflict_idempotency', framework_selection_conflict: 'framework_conflict',
                proposal_target_changed: 'conflict_target_changed', proposal_context_changed: 'conflict_context_changed',
                proposal_intent_conflict: 'conflict_intent', proposal_intent_missing: 'conflict_intent_missing',
                proposal_inheritance_empty: 'inherit_empty',
            }, code) ?? 'conflict_generic');
        }

        return this.t(own({
            session: 'error_session', forbidden: 'error_forbidden', not_found: 'error_not_found',
            unavailable: 'error_unavailable', network: 'error_network', rate: 'error_rate', invalid: 'error_invalid',
        }, kind) ?? 'error_unexpected');
    }

    /**
     * The request id for a command. It is the previous one only when this is the
     * very same command whose outcome was unknown; any change makes it a new one.
     */
    frozenId(scope, fingerprint) {
        const previous = this.pendings.get(scope);
        this.pendings.delete(scope);

        return previous && previous.fingerprint === fingerprint ? previous.requestId : newRequestId();
    }

    rememberUnknown(scope, fingerprint, requestId) {
        this.pendings.set(scope, { fingerprint, requestId });
    }

    /**
     * Empties what a subtree holds before it is taken out of the page. A detached
     * element is still memory: anything that keeps a reference to it, or to a
     * parent of it, could otherwise read a typed value or a shown text later.
     */
    static scrub(root) {
        if (!root) {
            return;
        }
        for (const node of [root, ...root.querySelectorAll('*')]) {
            if (node.value) {
                node.value = '';
            }
            if (node.childElementCount === 0) {
                node.textContent = '';
            }
            for (const child of [...node.childNodes]) {
                if (child.nodeType === 3) {
                    child.textContent = '';
                }
            }
        }
    }

    /** Forgets the bulk-decision bar: its note, its count line, its buttons and the field itself. */
    releaseBulk() {
        this.bulkNote = '';
        if (this.bulkNoteField) {
            this.bulkNoteField.value = '';
        }
        this.bulkNoteField = null;
        this.bulkCountEl = null;
        this.bulkButtons = [];
    }

    /**
     * Access was lost or the service cannot be trusted to answer: take away every
     * piece of content, in the page and in this script, and switch writes off
     * until a list read succeeds again. Drafts and frozen commands are not kept
     * (design §4.2): nothing here can store them safely.
     */
    revoke(kind) {
        this.epoch += 1;
        this.revoked = true;
        this.listSeq += 1;
        this.listBusy = false;
        this.items = [];
        this.nextCursor = null;
        this.listLoaded = false;
        this.reviewed.clear();
        this.selected.clear();
        this.pendings.clear();
        this.generationId = null;
        this.notice = null;
        this.releaseBulk();
        this.bulkResultHost?.replaceChildren();
        this.createResult?.replaceChildren();
        if (this.createPanel) {
            this.createPanel.hidden = true;
            this.createPanel.replaceChildren();
            this.createFormEl = null;
            this.createBoxes = {};
            this.generationKinds = [];
            this.createToggle.setAttribute('aria-expanded', 'false');
        }
        if (this.createToggle) {
            this.createToggle.disabled = true;
        }
        this.dropDetail(true);
        this.renderList(kind);
        this.announce(this.failureMessage(kind));
        this.listHeading?.focus();
    }

    // -------------------------------------------------------------------- shell

    buildShell() {
        this.listHeading = el('h4', { text: this.t('list_title'), attrs: { tabindex: '-1' } });

        const select = (id, labelKey, values, prefix, name) => {
            const control = el('select', { className: 'lf-form-control', attrs: { id } }, [
                el('option', { text: this.t('filter_all'), attrs: { value: '' } }),
                ...values.map((value) => el('option', { text: this.t(`${prefix}_${value}`), attrs: { value } })),
            ]);
            control.addEventListener('change', async () => {
                // A new filter closes the open proposal, so an unsent edit is asked about first.
                if (!(await this.guardDraft())) {
                    control.value = this.filters[name];

                    return;
                }
                this.filters[name] = control.value;
                // Only here, after the person agreed to leave any unsent edit, is the open proposal closed.
                this.loadList(true, { closeDetail: true });
            });

            return el('div', { className: 'admin-form-group' }, [
                el('label', { text: this.t(labelKey), attrs: { for: id } }),
                control,
            ]);
        };

        this.filterBar = el('div', { className: 'ai-authoring__filters' }, [
            select('ai-authoring-filter-status', 'filter_status', STATUSES, 'status', 'status'),
            select('ai-authoring-filter-kind', 'filter_kind', KINDS, 'kind', 'kind'),
        ]);

        this.listBody = el('div', { attrs: { 'aria-live': 'off' } });
        this.detailHost = el('div', { className: 'ai-authoring__detail' });
        this.bulkResultHost = el('div', { className: 'ai-authoring__bulk-result' });
        this.host.replaceChildren(this.listHeading, ...this.buildCreate(), this.filterBar, this.bulkResultHost, this.listBody, this.detailHost);
    }

    // --------------------------------------------------------------------- list

    /**
     * Reads the list. Reading it never closes the proposal that is open, so an
     * unsent edit cannot be lost to a reload, a retry or a refresh; a caller that
     * really means to leave the proposal (a new filter) says so with `closeDetail`
     * and has asked the person first.
     */
    async loadList(reset, { closeDetail = false } = {}) {
        const seq = ++this.listSeq;
        if (reset) {
            // The rows and their selection belong to the old filter or visit; none of it carries over.
            this.items = [];
            this.nextCursor = null;
            this.listLoaded = false;
            if (closeDetail) {
                this.reviewed.clear();
                this.dropDetail(true);
            }
        }
        // A selection made under another filter, on other rows or before another page
        // was added is not carried over (design §4.5).
        this.selected.clear();
        this.listBusy = true;
        this.renderList();
        this.announce(this.t('loading'));

        const query = new URLSearchParams({ limit: String(PAGE_SIZE) });
        for (const name of ['status', 'kind']) {
            if (this.filters[name]) {
                query.set(name, this.filters[name]);
            }
        }
        if (!reset && this.nextCursor) {
            query.set('cursor', this.nextCursor);
        }
        const result = await this.get(`${this.urls.proposals}?${query}`);
        if (seq !== this.listSeq) {
            return;
        }

        this.listBusy = false;
        if (result.kind !== 'ok') {
            // A lost session or refusal was already handled, page-wide, by request().
            this.renderList(result.kind);
            this.announce(this.failureMessage(result.kind));

            return;
        }
        this.revoked = false;
        this.createToggle.disabled = false;
        const items = Array.isArray(result.data.items) ? result.data.items : [];
        this.items = reset ? items : [...this.items, ...items];
        this.nextCursor = typeof result.data.next_cursor === 'string' ? result.data.next_cursor : null;
        this.listLoaded = true;
        this.renderList();
        this.announce(this.items.length === 0
            ? this.t(this.hasFilter() ? 'empty_filtered' : 'empty')
            : this.t('loaded_count', { count: this.items.length }));
    }

    hasFilter() {
        return Boolean(this.filters.status || this.filters.kind);
    }

    renderList(failure = null) {
        const parts = [];
        if (failure) {
            parts.push(el('div', { className: 'admin-alert admin-alert-danger', attrs: { role: 'alert' } }, [
                el('p', { text: this.failureMessage(failure) }),
                ['session', 'forbidden', 'not_found'].includes(failure) ? null : this.button('retry', () => this.loadList(true)),
            ].filter(Boolean)));
        }
        if (this.listBusy && this.items.length === 0 && !failure) {
            parts.push(el('p', { className: 'lf-secondary-text', text: this.t('loading'), attrs: { 'aria-busy': 'true' } }));
        } else if (this.listLoaded && this.items.length === 0 && !failure) {
            parts.push(el('p', { className: 'lf-secondary-text', text: this.t(this.hasFilter() ? 'empty_filtered' : 'empty') }));
        } else if (this.items.length > 0) {
            parts.push(this.bulkBar());
            parts.push(this.table());
            if (this.nextCursor) {
                const more = this.button('load_more', () => this.loadList(false), 'btn btn-secondary');
                more.disabled = this.listBusy;
                parts.push(more);
            }
        }
        this.listBody.replaceChildren(...parts);
        // The loaded count is the toolbar's, at the left of the create action (list standard).
        this.countEl.textContent = this.items.length > 0 ? this.t('loaded_count', { count: this.items.length }) : '';
    }

    /** A short, stable reference for a proposal: its UUID never changes, its position in a list does. */
    static ref(uuid) {
        return String(uuid).slice(0, 8).toLowerCase();
    }

    /** "Kind #ref": what tells two proposals of the same kind apart, for people and for screen readers. */
    named(kind, uuid) {
        return `${this.label('kind', KINDS, kind)} #${AiAuthoring.ref(uuid)}`;
    }

    table() {
        const rows = this.items
            .filter((item) => item && UUID.test(String(item.proposal_uuid)))
            .map((item) => {
                const denied = item.content_denied !== null && item.content_denied !== undefined;
                const uuid = String(item.proposal_uuid).toLowerCase();
                const view = this.button('view', () => this.viewProposal(uuid, view), 'btn btn-secondary');
                view.setAttribute('aria-label', `${this.t('view')}: ${this.named(item.kind, uuid)}, ${this.label('status', STATUSES, item.status)}`);

                // One wrapper per cell, so that on a phone the label and the value are two grid cells.
                const cell = (labelKey, children) => el('td', { attrs: { 'data-label': this.t(labelKey) } }, [el('div', { className: 'ai-authoring__cell' }, children)]);

                return el('tr', {}, [
                    cell('col_kind', [
                        this.selectBox(item),
                        el('span', { text: this.label('kind', KINDS, item.kind) }),
                        el('div', { className: 'lf-secondary-text', text: `#${AiAuthoring.ref(uuid)}` }),
                    ]),
                    cell('col_status', [
                        el('span', { className: `badge ${own(BADGES, item.status) ?? 'badge-secondary'}`, text: this.label('status', STATUSES, item.status) }),
                        Number.isInteger(item.revision_no) ? el('div', { className: 'lf-secondary-text', text: this.t('revision', { n: item.revision_no }) }) : null,
                        denied ? el('div', { className: 'lf-secondary-text', text: this.t('content_hidden') }) : null,
                    ]),
                    cell('col_action', [view]),
                ]);
            });

        return el('div', { className: 'admin-table-wrap' }, [
            el('table', { className: 'table admin-table-has-actions ai-authoring__table' }, [
                el('caption', { className: 'sr-only', text: this.t('list_caption') }),
                el('thead', {}, [el('tr', {}, ['col_kind', 'col_status', 'col_action'].map((key) => el('th', { text: this.t(key), attrs: { scope: 'col' } })))]),
                el('tbody', {}, rows),
            ]),
        ]);
    }

    button(key, onClick, className = 'btn btn-secondary') {
        const node = el('button', { className, text: this.t(key), attrs: { type: 'button' } });
        node.addEventListener('click', onClick);

        return node;
    }

    /**
     * Reads the open proposal again because something else changed it (a bulk
     * decision, say). Unlike a person opening a proposal, this never asks and so
     * must never throw away what was typed: with an unsent edit it says so and
     * leaves the choice to the person. Nothing is read while the page is hidden.
     */
    async refreshOpen(uuid) {
        if (this.pageHidden || uuid !== this.openUuid) {
            return;
        }
        if (this.hasDraft()) {
            this.showMessage('info', this.t('stale_with_draft'), [
                this.button('reread_discard', () => this.viewProposal(uuid, this.trigger), 'btn btn-secondary'),
            ]);
            this.announce(this.t('stale_with_draft'));

            return;
        }
        await this.openDetail(uuid, this.trigger);
    }

    /** Opens a proposal from a list row or a result, after an unsent edit has been asked about. */
    async viewProposal(uuid, trigger) {
        if (await this.guardDraft()) {
            this.openDetail(uuid, trigger);
        }
    }

    /**
     * Whether the open proposal has something typed that exists nowhere else: an
     * edit under way, a command whose outcome is unknown, or a decision note.
     * Every way of leaving the proposal asks about exactly this.
     */
    hasDraft() {
        return this.locked || (this.editor?.dirty() ?? false) || (this.noteField?.value ?? '') !== ''
            || [...this.receiptReasons.values()].some((select) => select.value !== RECEIPT_REASONS[0])
            || (this.draftChoice?.value ?? '') !== ''
            || ['base', 'code', 'title'].some((name) => (this.inheritFields?.[name].value ?? '') !== '')
            || this.rebaseHeld.size > 0
            || (this.rebaseFields !== null && (this.rebaseFields.target.value !== ''
                || [...this.rebaseFields.rows.values()].some((row) => row.choice.value !== '')));
    }

    /**
     * Anything typed or chosen and not sent, on the page as a whole: the open proposal's,
     * the bulk note, or the kinds ticked in the create form.
     */
    hasUnsent() {
        return this.hasDraft() || this.bulkNote !== '' || this.checkedKinds().length > 0;
    }

    /** True when it is fine to leave the open proposal: nothing unsent, or the person agreed to lose it. */
    async guardDraft() {
        if (!this.hasDraft()) {
            return true;
        }
        const trigger = document.activeElement;

        return typeof window.LFConfirm?.open === 'function' && Boolean(await window.LFConfirm.open({
            title: this.t('draft_discard_title'),
            message: this.t('draft_discard_message'),
            confirmLabel: this.t('draft_discard_label'),
            tone: 'danger',
            trigger,
        }));
    }

    // ------------------------------------------------------------------- detail

    /**
     * Removes the open proposal's content from the page and from this script:
     * the elements that held it (a detached input still holds its text) are
     * released too. `forget` also drops the record of what was open.
     */
    dropDetail(forget) {
        this.detailSeq += 1;
        AiAuthoring.scrub(this.detailHost);
        // The decision note is taken out of the page when the edit form opens, but it is
        // still held, so it is emptied here as well.
        if (this.noteField) {
            this.noteField.value = '';
        }
        this.detailHost.replaceChildren();
        this.detailHost.setAttribute('aria-busy', 'false');
        this.detailData = null;
        this.commandArea = null;
        this.actionsHost = null;
        this.noteField = null;
        this.actionButtons = [];
        this.editor = null;
        this.targetView = null;
        this.targetHost = null;
        this.contextView = null;
        this.contextHost = null;
        this.successorView = null;
        this.successorHost = null;
        this.inheritView = null;
        this.inheritHost = null;
        this.inheritFields = null;
        this.rebaseView = null;
        this.rebaseHost = null;
        this.rebaseFields = null;
        this.rebaseHeld = new Map();
        this.receiptReasons = new Map();
        this.receiptHost = null;
        if (this.draftChoice) {
            this.draftChoice.value = '';
        }
        this.draftChoice = null;
        this.locked = false;
        this.busy = false;
        if (forget) {
            this.pendingReason = null;
            this.pendingDraft = null;
            this.pendingInherit = null;
            this.pendingRebase = null;
            this.carriedNote = null;
            this.openUuid = null;
            this.trigger = null;
            this.pendings.delete('detail');
        }
    }

    async openDetail(uuid, trigger) {
        if (!UUID.test(uuid)) {
            return;
        }
        // Nothing of an earlier proposal, or of an earlier read of this one, may
        // survive into this read: not on screen, not in an element this script
        // still holds, and not as the identity of a command that has been overtaken
        // by what is read now (a decision made after reading again is a new command).
        this.dropDetail(false);
        this.pendings.delete('detail');
        const seq = this.detailSeq;
        this.openUuid = uuid;
        this.trigger = trigger;
        this.detailHost.replaceChildren(el('p', { className: 'lf-secondary-text', text: this.t('detail_loading'), attrs: { 'aria-busy': 'true' } }));

        const result = await this.get(`${this.urls.proposals}/${uuid}`);
        if (seq !== this.detailSeq) {
            return;
        }
        if (result.kind !== 'ok') {
            // No earlier copy of this proposal may remain on screen after a failed read.
            this.detailHost.replaceChildren(this.detailFailure(result.kind));
            this.announce(this.failureMessage(result.kind));
            this.focusHeading();

            return;
        }
        this.renderDetail(result.data);
        this.focusHeading();
    }

    detailFailure(kind) {
        return el('div', { className: 'admin-alert admin-alert-danger', attrs: { role: 'alert' } }, [
            el('h4', { text: this.t('detail_title'), attrs: { tabindex: '-1', 'data-ai-heading': '' } }),
            el('p', { text: this.failureMessage(kind) }),
            this.button('detail_close', () => this.closeDetail()),
        ]);
    }

    focusHeading() {
        this.detailHost.querySelector('[data-ai-heading]')?.focus();
    }

    async closeDetail() {
        if (!(await this.guardDraft())) {
            return;
        }
        const trigger = this.trigger;
        this.dropDetail(true);
        if (trigger && document.contains(trigger)) {
            trigger.focus();
        } else {
            this.listHeading?.focus();
        }
    }

    renderDetail(data) {
        const denied = data.content_denied !== null && data.content_denied !== undefined;
        // A hidden proposal is held only to continue it as a successor (its content is null: nothing to guard).
        const continuable = denied && Array.isArray(data.allowed_actions) && data.allowed_actions.includes('create_successor');
        this.detailData = denied && !continuable ? null : data;
        this.editor = null;
        this.locked = false;
        this.busy = false;
        this.recordReviewed(data, denied);
        const parts = [
            el('h4', { text: this.t('detail_title'), attrs: { tabindex: '-1', 'data-ai-heading': '' } }),
            el('p', { className: 'lf-secondary-text', text: [
                this.named(data.kind, data.proposal_uuid),
                this.label('status', STATUSES, data.status),
                Number.isInteger(data.revision_no) ? this.t('revision', { n: data.revision_no }) : null,
            ].filter(Boolean).join(' · ') }),
        ];

        if (denied) {
            parts.push(el('div', { className: 'admin-alert admin-alert-warning', attrs: { role: 'status' } }, [
                el('p', { text: this.t(data.content_denied === 'stale' || data.status === 'stale' ? 'denied_stale' : 'denied_unavailable') }),
            ]));
        } else if (data.payload && typeof data.payload === 'object') {
            parts.push(this.payload(data.payload, AiAuthoring.knownNode(data)));
            parts.push(this.citations(Array.isArray(data.citations) ? data.citations : []));
        }
        if (data.inherited_decision_draft === true) {
            parts.push(el('div', { className: 'admin-alert admin-alert-info', attrs: { role: 'note' } }, [
                el('p', { text: this.t('successor_inherited') }),
                SUCCESSOR_REASONS.includes(data.successor_reason) ? el('p', { text: this.t(`successor_reason_${data.successor_reason}`) }) : null,
            ].filter(Boolean)));
        }
        if (data.context_changed === true) {
            parts.push(el('div', { className: 'admin-alert admin-alert-info', attrs: { role: 'status' } }, [el('p', { text: this.t('context_changed') })]));
        }
        const note = denied ? null : this.stateNote(data);
        if (note) {
            parts.push(note);
        }
        // Where the outcome of a command is written, and where a notice that must
        // survive the reread that follows a command is put back. The actions sit
        // right after the content they act on.
        this.commandArea = el('div', { className: 'ai-authoring__command' });
        this.actionsHost = el('div', { className: 'ai-authoring__actions' });
        parts.push(this.commandArea, this.actionsHost);
        // Hidden content offers no review action; only the successor of a stale proposal is offered.
        if (!denied || continuable) {
            this.showActions();
        }
        if (this.notice) {
            this.showMessage(this.notice.tone, this.notice.text);
            this.notice = null;
        }
        parts.push(this.reviews(Array.isArray(data.reviews) ? data.reviews : []));
        parts.push(this.applications(Array.isArray(data.applications) ? data.applications : []));
        parts.push(this.button('detail_close', () => this.closeDetail()));

        this.detailHost.replaceChildren(el('section', { className: 'ai-authoring__detail-body', attrs: { 'aria-labelledby': 'ai-authoring-detail-title' } }, parts));
        this.detailHost.querySelector('[data-ai-heading]')?.setAttribute('id', 'ai-authoring-detail-title');
    }

    definition(term, value) {
        return [el('dt', { text: term }), el('dd', {}, [value instanceof Node ? value : document.createTextNode(String(value))])];
    }

    payload(payload, node = null) {
        const confidence = typeof payload.confidence === 'number'
            ? `${Math.round(payload.confidence * 100)}%` : this.t('none');
        const list = el('dl', { className: 'admin-readonly-summary' }, [
            ...this.definition(this.t('confidence'), confidence),
            ...this.definition(this.t('rationale'), typeof payload.rationale === 'string' && payload.rationale !== '' ? payload.rationale : this.t('none')),
        ]);

        return el('article', { className: 'ai-authoring__payload' }, [
            el('h5', { text: typeof payload.title === 'string' ? payload.title : '' }),
            // Whitespace is preserved with CSS; the text itself is never parsed.
            el('div', { className: 'ai-authoring__body', text: typeof payload.body === 'string' ? payload.body : '' }),
            list,
            el('p', { className: 'lf-secondary-text', text: this.t('confidence_note') }),
            payload.mapping && typeof payload.mapping === 'object' ? this.mapping(payload.mapping, node) : null,
        ].filter(Boolean));
    }

    /**
     * The Node an existing-Node mapping points at, as the server describes it, or null.
     * It counts only if it is the very Node the proposal names: a name that belongs to
     * another Node is worse than none. The stored numbers themselves are never shown.
     */
    static knownNode(data) {
        const mapping = data?.payload?.mapping;
        const node = data?.mapping_node;
        if (mapping?.mode !== 'reuse_existing' || node === null || typeof node !== 'object'
            || node.node_id !== mapping.node_id || node.definition_id !== mapping.definition_id
            || typeof node.label !== 'string' || node.label === '') {
            return null;
        }

        return node;
    }

    /** True for an existing-Node mapping whose Node cannot be shown, which can then only be rejected. */
    static unidentifiedNode(data) {
        return data?.payload?.mapping?.mode === 'reuse_existing' && AiAuthoring.knownNode(data) === null;
    }

    mappingNode(node) {
        return el('section', { className: 'ai-authoring__node' }, [
            el('h5', { text: this.t('mapping_node_title') }),
            el('dl', { className: 'admin-readonly-summary' }, [
                ...this.definition(this.t('mapping_label'), node.label),
                ...this.definition(this.t('mapping_code'), typeof node.code === 'string' ? node.code : this.t('none')),
                ...this.definition(this.t('mapping_type'), NODE_TYPES.includes(node.node_type) ? this.t(`node_type_${node.node_type}`) : this.t('none')),
                ...(typeof node.description === 'string' && node.description !== '' ? this.definition(this.t('mapping_description'), node.description) : []),
            ]),
        ]);
    }

    mapping(mapping, node = null) {
        const reuse = mapping.mode === 'reuse_existing';
        const rows = [
            ...this.definition(this.t('mapping_mode'), this.t(reuse ? 'mode_reuse_existing' : 'mode_propose_new')),
            ...this.definition(this.t('mapping_role'), MAPPING_ROLES.includes(mapping.role) ? this.t(`role_${mapping.role}`) : this.t('none')),
            ...this.definition(this.t('mapping_weight'), typeof mapping.weight === 'number' ? String(mapping.weight) : this.t('weight_none')),
        ];
        if (!reuse) {
            rows.push(
                ...this.definition(this.t('mapping_code'), typeof mapping.code === 'string' ? mapping.code : this.t('none')),
                ...this.definition(this.t('mapping_label'), typeof mapping.label === 'string' ? mapping.label : this.t('none')),
                ...this.definition(this.t('mapping_type'), NODE_TYPES.includes(mapping.node_type) ? this.t(`node_type_${mapping.node_type}`) : this.t('none')),
            );
            if (mapping.criteria !== null && mapping.criteria !== undefined) {
                // Criteria are structured data; show them as escaped text, not markup.
                rows.push(...this.definition(this.t('mapping_criteria'), JSON.stringify(mapping.criteria)));
            }
        }

        return el('section', { className: 'ai-authoring__mapping' }, [
            el('h5', { text: this.t('mapping') }),
            el('dl', { className: 'admin-readonly-summary' }, rows),
            reuse && node !== null ? this.mappingNode(node) : null,
            // Without a Node the reviewer can recognise, only rejecting is offered; the stored
            // numbers are not shown as if they were a name.
            reuse && node === null ? el('div', { className: 'admin-alert admin-alert-warning', attrs: { role: 'note' } }, [el('p', { text: this.t('mapping_node_unavailable') })]) : null,
        ].filter(Boolean));
    }

    citations(citations) {
        const items = citations.map((citation, index) => {
            // Only values the script knows are written out; an unknown code is left
            // off rather than shown as a raw technical string.
            const known = (allowed, prefix, value) => (allowed.includes(value) ? this.t(`${prefix}_${value}`) : '');
            const locator = citation.locator && typeof citation.locator === 'object'
                ? [known(SOURCE_LOCATORS, 'source_locator', citation.locator.type),
                    ['string', 'number'].includes(typeof citation.locator.value) ? String(citation.locator.value) : ''].filter(Boolean).join(' ') : '';
            const detail = [
                known(SOURCE_USAGES, 'source_usage', citation.usage_type),
                known(SOURCE_CONTENTS, 'source_content', citation.content_type),
                typeof citation.locale === 'string' ? citation.locale : '',
                locator,
            ].filter(Boolean).join(' · ');

            return el('li', { text: `${this.t('source_item', { n: Number.isInteger(citation.ordinal) ? citation.ordinal : index + 1 })}${detail ? `: ${detail}` : ''}` });
        });

        return el('section', {}, [
            el('h5', { text: this.t('sources') }),
            items.length ? el('ol', {}, items) : el('p', { className: 'lf-secondary-text', text: this.t('none') }),
        ]);
    }

    reviews(reviews) {
        const rows = reviews.map((review) => el('li', { text: [
            this.label('review', REVIEW_ACTIONS, review.action),
            Number.isInteger(review.revision_no) ? this.t('revision', { n: review.revision_no }) : null,
            typeof review.created_at === 'string' ? review.created_at.slice(0, 16).replace('T', ' ') : null,
        ].filter(Boolean).join(' · ') }));

        return el('section', {}, [
            el('h5', { text: this.t('reviews') }),
            rows.length ? el('ol', {}, rows) : el('p', { className: 'lf-secondary-text', text: this.t('none') }),
            rows.length >= 100 ? el('p', { className: 'lf-secondary-text', text: this.t('reviews_limit') }) : null,
        ].filter(Boolean));
    }

    // ------------------------------------------------------------------ create

    buildCreate() {
        this.createToggle = this.button('create_open', () => this.toggleCreate(), 'btn btn-primary');
        this.createToggle.setAttribute('aria-expanded', 'false');
        this.createToggle.setAttribute('aria-controls', 'ai-authoring-create');
        this.createPanel = el('div', { className: 'ai-authoring__create', attrs: { id: 'ai-authoring-create', hidden: '' } });
        this.createResult = el('div', { className: 'ai-authoring__create-result' });
        this.countEl = el('p', { className: 'ai-authoring__count lf-secondary-text', attrs: { 'aria-live': 'off' } });

        // Count at the left, the one create action at the right (list standard §25.3).
        return [
            el('div', { className: 'ai-authoring__toolbar' }, [this.countEl, this.createToggle]),
            this.createPanel,
            this.createResult,
        ];
    }

    toggleCreate() {
        if (!this.createPanel.hidden) {
            this.createPanel.hidden = true;
            this.createToggle.setAttribute('aria-expanded', 'false');

            return;
        }
        if (this.createFormEl === null) {
            this.createFormEl = this.createForm();
            this.createPanel.replaceChildren(this.createFormEl);
        }
        this.createPanel.hidden = false;
        this.createToggle.setAttribute('aria-expanded', 'true');
        this.createPanel.querySelector('input:not(:disabled)')?.focus();
    }

    /** The person's own decision to drop what was chosen: clears the choices and folds the form away. */
    resetCreate() {
        for (const box of Object.values(this.createBoxes ?? {})) {
            box.checked = false;
        }
        this.createError.hidden = true;
        this.createPanel.hidden = true;
        this.createToggle.setAttribute('aria-expanded', 'false');
        this.createToggle.focus();
    }

    /** The kinds ticked in the create form right now. */
    checkedKinds() {
        return KINDS.filter((kind) => this.createBoxes?.[kind]?.checked && !this.createBoxes[kind].disabled);
    }

    createForm() {
        this.createBoxes = {};
        const boxes = KINDS.map((kind) => {
            const id = `ai-authoring-create-${kind}`;
            const needsBasis = BASIS_KINDS.includes(kind);
            // These kinds are made against the framework the template selected. Without
            // one they cannot be created, and a teacher cannot select one, so they are
            // off with the reason next to them.
            const blocked = needsBasis && this.framework === null;
            const box = el('input', { attrs: { type: 'checkbox', id, value: kind } });
            box.disabled = blocked;
            if (blocked) {
                box.setAttribute('aria-describedby', `${id}-hint`);
            }
            this.createBoxes[kind] = box;

            return el('div', { className: 'ai-authoring__check' }, [
                box,
                el('label', { text: this.t(`kind_${kind}`), attrs: { for: id } }),
                blocked ? el('span', { className: 'lf-secondary-text', text: this.t('create_framework_needed'), attrs: { id: `${id}-hint` } }) : null,
            ].filter(Boolean));
        });
        this.createError = el('p', { className: 'admin-form-help ai-authoring__error', attrs: { role: 'alert', hidden: '' } });
        const submit = this.button('create_submit', () => this.submitCreate(), 'btn btn-primary');
        this.createSubmit = submit;

        return el('fieldset', { className: 'ai-authoring__fieldset' }, [
            el('legend', { text: this.t('create_kinds') }),
            ...boxes,
            this.createError,
            el('div', { className: 'admin-form-actions' }, [this.button('cancel', () => this.resetCreate(), 'btn btn-secondary'), submit]),
        ]);
    }

    setCreateBusy(busy) {
        this.createBusy = busy;
        for (const control of this.createPanel.querySelectorAll('button, input')) {
            control.disabled = busy || (control.type === 'checkbox' && BASIS_KINDS.includes(control.value) && this.framework === null);
        }
        this.createPanel.setAttribute('aria-busy', busy ? 'true' : 'false');
    }

    showCreateMessage(tone, text, actions = []) {
        const cls = { error: 'admin-alert-danger', success: 'admin-alert-success', info: 'admin-alert-info' }[tone];
        this.createResult.replaceChildren(el('div', { className: `admin-alert ${cls}`, attrs: { role: tone === 'error' ? 'alert' : 'status' } },
            [el('p', { text }), ...actions]));
    }

    async submitCreate() {
        if (this.createBusy) {
            return;
        }
        const kinds = this.checkedKinds();
        if (kinds.length === 0) {
            this.createError.textContent = this.t('create_none');
            this.createError.hidden = false;
            this.announce(this.t('create_none'));
            this.createPanel.querySelector('input:not(:disabled)')?.focus();

            return;
        }
        this.createError.hidden = true;
        const command = { requested_kinds: kinds };
        if (kinds.some((kind) => BASIS_KINDS.includes(kind)) && this.framework) {
            // The server accepts only the template's own selection, so it is handed
            // over as it is and never chosen here.
            command.framework_id = this.framework.id;
            command.framework_version_id = this.framework.versionId;
        }
        await this.runCreate(command);
    }

    async runCreate(command) {
        if (this.revoked || this.createBusy) {
            return;
        }
        const url = this.urls.generation_requests;
        const fingerprint = JSON.stringify(['POST', url, command]);
        const requestId = this.frozenId('generate', fingerprint);
        const epoch = this.epoch;
        this.setCreateBusy(true);
        this.showCreateMessage('info', this.t('create_running'));
        this.announce(this.t('create_running'));

        const result = await this.request('POST', url, { ...command, request_id: requestId });
        if (epoch !== this.epoch) {
            // Access was lost while this was in flight: request() already cleared the
            // page, and nothing of the answer may be shown.
            this.createBusy = false;

            return;
        }
        this.setCreateBusy(false);

        if (result.kind === 'ok') {
            this.generationId = requestId;
            this.generationKinds = [...command.requested_kinds];
            await this.afterGeneration(result.data.request_status, result.data.item_count);

            return;
        }
        if (['network', 'unavailable', 'unexpected'].includes(result.kind)) {
            // No answer, or the service failed: whether the request was recorded is
            // not known, so the same request can be sent again exactly as it was.
            this.rememberUnknown('generate', fingerprint, requestId);
            this.showCreateMessage('error', this.t('unknown_outcome'), [this.button('retry', () => this.runCreate(command))]);
            this.announce(this.t('unknown_outcome'));

            return;
        }
        const text = this.failureMessage(result.kind, result.code);
        const incomplete = result.kind === 'conflict' && own(GATE_MESSAGES, result.code) === 'gate_incomplete';
        if (incomplete) {
            // The request may have been recorded, and the provider may have been called:
            // what happened is read from the request's own status, never guessed from the
            // list. Sending the same choices again reuses this id and so cannot make a second request.
            this.generationId = requestId;
            this.generationKinds = [...command.requested_kinds];
            this.rememberUnknown('generate', fingerprint, requestId);
        }
        this.showCreateMessage('error', text, incomplete ? [this.button('create_status', () => this.refreshGeneration(), 'btn btn-secondary')] : []);
        this.announce(text);
    }

    /** Reports the outcome of a generation request; a pending one is refreshed by hand. */
    async afterGeneration(status, count) {
        const epoch = this.epoch;
        if (status === 'completed') {
            const text = this.t('create_done', { count: Number.isInteger(count) ? count : 0 });
            this.showCreateMessage('success', text);
            // The form is put away only if it still holds what this request was made from; if the
            // person has chosen something else meanwhile, that is a new request being prepared and
            // the answer about the old one must not touch it.
            const same = this.checkedKinds().join(',') === [...this.generationKinds].sort((a, b) => KINDS.indexOf(a) - KINDS.indexOf(b)).join(',');
            if (same) {
                for (const box of Object.values(this.createBoxes ?? {})) {
                    box.checked = false;
                }
                this.createPanel.hidden = true;
                this.createToggle.setAttribute('aria-expanded', 'false');
            }
            await this.loadList(true);
            // A refusal met while reading the list back is the latest word on access.
            if (epoch === this.epoch) {
                this.announce(text);
            }

            return;
        }
        // Accepted for processing, but nothing says a process is working on it.
        const refresh = this.button('create_refresh', () => this.refreshGeneration(), 'btn btn-secondary');
        this.showCreateMessage('info', this.t(status === 'failed' ? 'create_state_failed' : 'create_pending'), status === 'failed' ? [] : [refresh]);
        this.announce(this.t(status === 'failed' ? 'create_state_failed' : 'create_pending'));
    }

    async refreshGeneration() {
        if (this.revoked || !this.generationId || !UUID.test(this.generationId)) {
            return;
        }
        const epoch = this.epoch;
        // Not finding it is an answer about this request, not about access.
        const result = await this.get(`${this.urls.generation_requests}/${this.generationId}`, { soft: true });
        if (epoch !== this.epoch) {
            return;
        }
        if (result.kind === 'not_found') {
            this.showCreateMessage('info', this.t('create_status_missing'));
            this.announce(this.t('create_status_missing'));

            return;
        }
        if (result.kind !== 'ok') {
            this.showCreateMessage('error', this.failureMessage(result.kind, result.code));
            this.announce(this.failureMessage(result.kind, result.code));

            return;
        }
        const status = result.data.request_status;
        if (status === 'completed') {
            await this.afterGeneration('completed', result.data.item_count);
        } else if (status === 'failed') {
            this.showCreateMessage('error', this.t('create_state_failed'));
            this.announce(this.t('create_state_failed'));
        } else {
            const refresh = this.button('create_refresh', () => this.refreshGeneration(), 'btn btn-secondary');
            this.showCreateMessage('info', this.t('create_state_pending'), [refresh]);
            this.announce(this.t('create_state_pending'));
        }
    }

    // -------------------------------------------------------------------- bulk

    /** Remembers which versions a reviewer actually opened; only those may be decided in bulk. */
    recordReviewed(data, denied) {
        const uuid = String(data.proposal_uuid ?? '').toLowerCase();
        if (!UUID.test(uuid)) {
            return;
        }
        const allowed = Array.isArray(data.allowed_actions) ? data.allowed_actions : [];
        if (!denied && data.status === 'pending_review' && Number.isInteger(data.lock_version) && Number.isInteger(data.revision_no)
            && (allowed.includes('accept') || allowed.includes('reject'))) {
            this.reviewed.set(uuid, {
                kind: data.kind,
                lock: data.lock_version,
                rev: data.revision_no,
                reuse: AiAuthoring.unidentifiedNode(data),
            });
        } else {
            this.reviewed.delete(uuid);
            this.selected.delete(uuid);
        }
        if (this.listLoaded) {
            this.renderList();
        }
    }

    selectBox(item) {
        if (item.status !== 'pending_review' || (item.content_denied !== null && item.content_denied !== undefined)) {
            return null;
        }
        const uuid = String(item.proposal_uuid).toLowerCase();
        const seen = this.reviewed.get(uuid);
        // Selectable only for the exact version that was opened; a newer one has to be read again.
        const eligible = Boolean(seen) && seen.lock === item.lock_version && seen.rev === item.revision_no;
        if (!eligible) {
            this.selected.delete(uuid);
        }
        const box = el('input', { attrs: {
            type: 'checkbox', 'aria-describedby': 'ai-authoring-select-hint',
            'aria-label': this.t('bulk_select', { kind: this.named(item.kind, uuid) }),
        } });
        box.checked = eligible && this.selected.has(uuid);
        box.disabled = !eligible || this.bulkBusy;
        box.addEventListener('change', () => {
            if (box.checked && this.selected.size >= BULK_LIMIT) {
                box.checked = false;
                this.announce(this.t('bulk_limit'));

                return;
            }
            if (box.checked) {
                this.selected.add(uuid);
            } else {
                this.selected.delete(uuid);
            }
            this.updateBulkBar();
        });

        return box;
    }

    bulkBar() {
        if (!this.items.some((item) => item.status === 'pending_review')) {
            this.bulkCountEl = null;
            this.bulkButtons = [];
            this.bulkNoteField = null;

            return el('span');
        }
        this.bulkCountEl = el('p', { className: 'lf-secondary-text', attrs: { 'aria-live': 'polite' } });
        const note = el('textarea', { className: 'lf-form-control', attrs: { id: 'ai-authoring-bulk-note', rows: '2', maxlength: '2000' } });
        note.value = this.bulkNote ?? '';
        this.bulkNoteField = note;
        note.addEventListener('input', () => {
            this.bulkNote = note.value;
        });
        this.bulkButtons = [
            this.button('bulk_accept', () => this.bulkDecide('accept'), 'btn btn-primary'),
            this.button('bulk_reject', () => this.bulkDecide('reject'), 'btn btn-danger'),
            this.button('bulk_clear', () => {
                this.selected.clear();
                this.renderList();
            }, 'btn btn-secondary'),
        ];
        const bar = el('div', { className: 'ai-authoring__bulk' }, [
            el('p', { className: 'lf-secondary-text', text: `${this.t('bulk_select_hint')} ${this.t('bulk_limit')}`, attrs: { id: 'ai-authoring-select-hint' } }),
            this.bulkCountEl,
            el('div', { className: 'admin-form-group' }, [el('label', { text: this.t('decision_note'), attrs: { for: 'ai-authoring-bulk-note' } }), note]),
            el('div', { className: 'admin-form-actions' }, this.bulkButtons),
        ]);
        this.updateBulkBar();

        return bar;
    }

    updateBulkBar() {
        if (!this.bulkCountEl) {
            return;
        }
        this.bulkCountEl.textContent = this.selected.size > 0 ? this.t('bulk_selected', { count: this.selected.size }) : '';
        for (const button of this.bulkButtons) {
            button.disabled = this.bulkBusy || this.selected.size === 0;
        }
    }

    async bulkDecide(action) {
        if (this.bulkBusy || this.selected.size === 0) {
            return;
        }
        const picks = [...this.selected].map((uuid) => ({ uuid, ...this.reviewed.get(uuid) })).filter((pick) => pick.lock !== undefined);
        // An existing-Node mapping whose Node was not shown cannot be accepted, so it is left out.
        const targets = action === 'accept' ? picks.filter((pick) => !pick.reuse) : picks;
        const skipped = picks.length - targets.length;
        if (targets.length === 0) {
            this.announce(this.t('bulk_skip_note', { count: skipped }));

            return;
        }
        const message = [this.t(`bulk_confirm_${action}_message`, { count: targets.length }),
            skipped > 0 ? this.t('bulk_skip_note', { count: skipped }) : ''].filter(Boolean).join(' ');
        const epoch = this.epoch;
        const confirmed = typeof window.LFConfirm?.open === 'function' && await window.LFConfirm.open({
            title: this.t(`bulk_confirm_${action}_title`),
            message,
            confirmLabel: this.t(`confirm_${action}_label`),
            tone: action === 'reject' ? 'danger' : undefined,
            trigger: document.activeElement,
        });
        // The versions read belong to the access that read them: if it was lost while the
        // dialog was open, nothing here is decided.
        if (!confirmed || this.bulkBusy || epoch !== this.epoch || this.revoked) {
            return;
        }
        const reason = (this.bulkNote ?? '').trim();
        // Each item is its own command with its own request id and the versions read.
        const items = targets.map((pick) => ({
            proposal_uuid: pick.uuid,
            request_id: newRequestId(),
            expected_lock_version: pick.lock,
            expected_revision_no: pick.rev,
            action,
            ...(reason === '' ? {} : { reason }),
        }));
        const kinds = Object.fromEntries(targets.map((pick) => [pick.uuid, pick.kind]));
        await this.runBulk(items, kinds);
    }

    async runBulk(items, kinds) {
        if (this.revoked) {
            return;
        }
        const epoch = this.epoch;
        this.bulkBusy = true;
        this.updateBulkBar();
        this.announce(this.t('bulk_running'));
        // The outer id only correlates; the items carry the ids that count.
        const result = await this.request('POST', this.urls.bulk_decisions, { request_id: newRequestId(), items });
        this.bulkBusy = false;
        if (epoch !== this.epoch) {
            // Access was lost meanwhile; the page was already cleared and no result of
            // this call is shown or retried from here.
            return;
        }

        const definite = { 403: 'forbidden', 404: 'not_found', 409: 'conflict', 422: 'invalid', 429: 'rate' };
        let outcomes;
        if (result.kind === 'ok' && Array.isArray(result.data.items)) {
            outcomes = items.map((item) => {
                const got = result.data.items.find((row) => String(row.request_id).toLowerCase() === item.request_id);
                const status = got ? Number(got.http_status) : 0;
                if (status === 200 && (got.error === null || got.error === undefined)) {
                    return { item, state: 'ok' };
                }
                if (!got || !definite[status]) {
                    return { item, state: 'unknown' };
                }

                return { item, state: 'failed', text: this.bulkFailureMessage(definite[status], got.error?.code) };
            });
        } else if (['network', 'unavailable', 'unexpected'].includes(result.kind)) {
            // No usable answer: nothing is known about any item.
            outcomes = items.map((item) => ({ item, state: 'unknown' }));
        } else {
            this.updateBulkBar();
            this.announce(this.failureMessage(result.kind, result.code));
            this.bulkResultHost.replaceChildren(el('div', { className: 'admin-alert admin-alert-danger', attrs: { role: 'alert' } },
                [el('p', { text: this.failureMessage(result.kind, result.code) })]));

            return;
        }

        this.showBulkResult(outcomes, kinds);
        // What was decided is read again; nothing of the old selection carries over.
        const reopen = outcomes.some((outcome) => outcome.item.proposal_uuid === this.openUuid) ? this.openUuid : null;
        for (const outcome of outcomes) {
            this.reviewed.delete(outcome.item.proposal_uuid);
        }
        this.selected.clear();
        await Promise.all([this.loadList(true), reopen ? this.refreshOpen(reopen) : Promise.resolve()]);
        if (epoch === this.epoch) {
            this.announce(this.bulkSummary(outcomes));
        }
    }

    /**
     * The reason for one failed row. Unlike a single command, a bulk one does not
     * read the proposal again, so the text must not claim it did.
     */
    bulkFailureMessage(kind, code) {
        if (kind !== 'conflict') {
            return this.failureMessage(kind, code);
        }

        return this.t(own({
            proposal_revision_conflict: 'bulk_conflict_revision',
            proposal_stale: 'bulk_conflict_stale',
            proposal_idempotency_conflict: 'conflict_idempotency',
        }, code) ?? 'bulk_conflict_generic');
    }

    bulkSummary(outcomes) {
        const count = (state) => outcomes.filter((outcome) => outcome.state === state).length;

        return this.t('bulk_summary', { ok: count('ok'), failed: count('failed'), unknown: count('unknown') });
    }

    /** One line for each proposal: an HTTP 200 around the batch does not mean it all worked. */
    showBulkResult(outcomes, kinds) {
        const rows = outcomes.map((outcome) => {
            const text = {
                ok: this.t('bulk_item_ok'),
                unknown: this.t('bulk_item_unknown'),
                failed: this.t('bulk_item_failed', { reason: outcome.text ?? '' }),
            }[outcome.state];

            // Two proposals of one kind are told apart by their reference, and each row can open its own.
            const uuid = outcome.item.proposal_uuid;
            const name = this.named(kinds[uuid], uuid);
            const open = this.button('view', () => this.viewProposal(uuid, open), 'btn btn-secondary');
            open.setAttribute('aria-label', `${this.t('view')}: ${name}`);

            return el('li', {}, [el('span', { text: `${name} — ${text}` }), ' ', open]);
        });
        const unknown = outcomes.filter((outcome) => outcome.state === 'unknown');
        const children = [
            el('h5', { text: this.t('bulk_result_title') }),
            el('p', { text: this.bulkSummary(outcomes) }),
            el('ul', {}, rows),
        ];
        if (unknown.length > 0) {
            // Only the ones with an unknown result, exactly as first sent.
            const retry = this.button('bulk_retry_unknown', () => {
                this.bulkResultHost.replaceChildren();
                this.runBulk(unknown.map((outcome) => outcome.item), kinds);
            }, 'btn btn-secondary');
            children.push(retry);
        }
        this.bulkResultHost.replaceChildren(el('div', { className: 'admin-alert admin-alert-info', attrs: { role: 'status' } }, children));
    }

    // ---------------------------------------------------------------- commands

    /** An informational note about what an accepted proposal does and does not do. */
    stateNote(data) {
        if (data.status !== 'accepted' || !data.payload) {
            return null;
        }
        const mapping = data.payload.mapping;
        let text = null;
        if (data.kind === 'node_mapping') {
            const created = (Array.isArray(data.applications) ? data.applications : []).some((application) => application.operation === 'create_node');
            if (mapping?.mode === 'propose_new' && !created) {
                text = this.t('accepted_node_pending');
            } else if (mapping?.mode === 'reuse_existing' && (Array.isArray(data.allowed_actions) ? data.allowed_actions : []).includes('preview_target')) {
                text = this.t('accepted_reuse_note');
            }
        } else {
            text = this.t('accepted_text_note');
        }

        return text ? el('div', { className: 'admin-alert admin-alert-info', attrs: { role: 'note' } }, [el('p', { text })]) : null;
    }

    /** Buttons for what the server says this actor may do now; it rechecks each one. */
    showActions() {
        const data = this.detailData;
        const allowed = Array.isArray(data?.allowed_actions) ? data.allowed_actions : [];
        // An existing-Node mapping is judged by the Node it names. If that Node cannot be
        // shown, it cannot be edited or accepted; it can still be rejected.
        const unjudgeable = AiAuthoring.unidentifiedNode(data);
        const canEdit = allowed.includes('edit') && !unjudgeable;
        const canAccept = allowed.includes('accept') && !unjudgeable;
        const canReject = allowed.includes('reject');
        this.actionsHost.replaceChildren();
        if (!canEdit && !canAccept && !canReject) {
            const application = this.applicationActions(allowed);
            if (application !== null) {
                this.actionsHost.replaceChildren(application);
            }

            return;
        }

        // The note typed so far is kept when the buttons are rebuilt (Edit then Cancel), and a note
        // carried over a reread of this same proposal is put back.
        const carried = this.carriedNote !== null && this.carriedNote.uuid === this.openUuid ? this.carriedNote.text : '';
        const kept = (this.noteField?.value ?? '') || carried;
        this.carriedNote = null;
        this.noteField = null;
        const parts = [];
        if (canAccept || canReject) {
            this.noteField = el('textarea', { className: 'lf-form-control', attrs: { id: 'ai-authoring-decision-note', rows: '2', maxlength: '2000' } });
            this.noteField.value = kept;
            parts.push(el('div', { className: 'admin-form-group' }, [
                el('label', { text: this.t('decision_note'), attrs: { for: 'ai-authoring-decision-note' } }),
                this.noteField,
            ]));
        }
        const buttons = [];
        if (canEdit) {
            buttons.push(this.button('edit', () => this.showEdit(), 'btn btn-secondary'));
        }
        if (canAccept) {
            buttons.push(this.button('accept', () => this.decide('accept'), 'btn btn-primary'));
        }
        if (canReject) {
            buttons.push(this.button('reject', () => this.decide('reject'), 'btn btn-danger'));
        }
        this.actionButtons = buttons;
        parts.push(el('div', { className: 'admin-form-actions' }, buttons));
        this.actionsHost.replaceChildren(...parts);
    }

    /**
     * The steps after acceptance that the server offers: look at the target, then confirm
     * it, then apply it to the working Template. Confirming and rejecting are reached only
     * from a target that has been shown, so nothing is confirmed unseen.
     */
    applicationActions(allowed) {
        if (allowed.includes('create_successor')) {
            return this.successorPanel();
        }
        const targetSteps = ['preview_target', 'confirm_target', 'reject_target', 'apply_intent'].filter((step) => allowed.includes(step));
        const needsApproval = allowed.includes('approve_node');
        const needsContext = allowed.includes('reconfirm_context');
        const needsInherit = allowed.includes('inherit_draft');
        const needsRebase = allowed.includes('rebase');
        if (targetSteps.length === 0 && !needsApproval && !needsContext && !needsInherit && !needsRebase) {
            return null;
        }
        const parts = [];
        this.targetHost = null;
        this.contextHost = null;
        if (needsContext) {
            this.contextHost = el('div', { className: 'ai-authoring__target' });
            parts.push(el('section', { className: 'ai-authoring__context' }, [
                el('h5', { text: this.t('context_title') }),
                el('p', { className: 'lf-secondary-text', text: this.t('context_intro') }),
                this.contextHost,
                el('div', { className: 'admin-form-actions' }, [this.button('context_preview', () => this.previewContext(), 'btn btn-secondary')]),
            ]));
        }
        if (targetSteps.length > 0 || needsApproval) {
            const buttons = [];
            if (allowed.includes('preview_target')) {
                buttons.push(this.button('preview_target', () => this.previewTarget(), 'btn btn-secondary'));
            }
            if (allowed.includes('apply_intent')) {
                buttons.push(this.button('apply_intent', () => this.targetStep('apply'), 'btn btn-primary'));
            }
            const approval = needsApproval ? this.nodeApproval() : null;
            if (!needsApproval) {
                this.targetHost = el('div', { className: 'ai-authoring__target' });
            }
            parts.push(el('div', { className: 'ai-authoring__application' }, [
                el('h5', { text: this.t('application_title') }),
                el('p', { className: 'lf-secondary-text', text: this.t(approval === null ? 'application_intro' : 'approve_node_intro') }),
                this.targetHost,
                approval,
                el('div', { className: 'admin-form-actions' }, buttons),
            ].filter(Boolean)));
        }
        if (needsInherit) {
            parts.push(this.inheritPanel());
        }
        if (needsRebase) {
            parts.push(this.rebasePanel());
        }

        return el('div', { className: 'ai-authoring__steps' }, parts);
    }

    /**
     * An admin's copy of a published version into a new draft. It changes the Framework, which the whole
     * organization shares, not this Template or this proposal, and says so. Chosen, typed and acknowledged here
     * only: the plan is read first, and what is sent is exactly that plan.
     */
    inheritPanel() {
        this.inheritFields = null;
        this.inheritHost = null;
        const link = this.mappingsUrl === null ? null
            : el('a', { className: 'btn btn-secondary', text: this.t('mappings_open'), attrs: { href: this.mappingsUrl } });
        if (this.framework === null || this.publishedVersions.length === 0) {
            return el('section', { className: 'ai-authoring__inherit' }, [
                el('h5', { text: this.t('inherit_title') }),
                el('p', { className: 'lf-secondary-text', text: this.t('inherit_unavailable') }),
                link,
            ].filter(Boolean));
        }
        const carried = this.pendingInherit !== null && this.pendingInherit.uuid === this.openUuid ? this.pendingInherit : null;
        this.pendingInherit = null;
        const base = el('select', { className: 'lf-form-control', attrs: { id: 'ai-authoring-inherit-base' } }, [
            el('option', { text: this.t('inherit_choose_base'), attrs: { value: '' } }),
            ...this.publishedVersions.map((version) => el('option', { text: version.label, attrs: { value: String(version.id) } })),
        ]);
        base.value = carried !== null && this.publishedVersions.some((version) => String(version.id) === carried.base) ? carried.base : '';
        const code = el('input', { className: 'lf-form-control', attrs: { id: 'ai-authoring-inherit-code', type: 'text', maxlength: '100' } });
        code.value = carried?.code ?? '';
        const title = el('input', { className: 'lf-form-control', attrs: { id: 'ai-authoring-inherit-title', type: 'text', maxlength: '255' } });
        title.value = carried?.title ?? '';
        this.inheritFields = { base, code, title, ack: null };
        // A different base is a different plan: the one shown is no longer the one to create from.
        base.addEventListener('change', () => {
            if (this.inheritView !== null && String(this.inheritView.base) !== base.value) {
                this.inheritView = null;
                this.inheritHost?.replaceChildren();
            }
        });
        this.inheritHost = el('div', { className: 'ai-authoring__target' });
        const field = (id, key, control) => el('div', { className: 'admin-form-group' }, [el('label', { text: this.t(key), attrs: { for: id } }), control]);

        return el('section', { className: 'ai-authoring__inherit' }, [
            el('h5', { text: this.t('inherit_title') }),
            el('p', { className: 'lf-secondary-text', text: this.t('inherit_intro') }),
            field('ai-authoring-inherit-base', 'inherit_base', base),
            field('ai-authoring-inherit-code', 'inherit_code', code),
            field('ai-authoring-inherit-title', 'inherit_name', title),
            this.inheritHost,
            el('div', { className: 'admin-form-actions' }, [this.button('inherit_preview', () => this.previewInherit(), 'btn btn-secondary'), link].filter(Boolean)),
        ]);
    }

    /** What copying the chosen version would keep and lose, with the names an admin needs to judge it. */
    async previewInherit() {
        const fields = this.inheritFields;
        if (this.busy || this.revoked || !this.detailData || !fields || !this.inheritHost) {
            return;
        }
        const baseId = this.publishedVersions.find((version) => String(version.id) === fields.base.value)?.id;
        if (baseId === undefined) {
            this.announce(this.t('inherit_choose_base'));
            fields.base.focus();

            return;
        }
        const seq = this.detailSeq;
        const epoch = this.epoch;
        const uuid = this.openUuid;
        const lock = this.detailData.lock_version;
        this.inheritView = null;
        fields.ack = null;
        this.inheritHost.replaceChildren(el('p', { className: 'lf-secondary-text', text: this.t('inherit_loading'), attrs: { 'aria-busy': 'true' } }));

        const result = await this.get(`${this.urls.proposals}/${uuid}/inherited-draft-preview?base_version_id=${baseId}`);
        if (seq !== this.detailSeq || epoch !== this.epoch || !this.inheritHost || this.inheritFields !== fields) {
            return;
        }
        const plan = result.data?.preview;
        const ids = (value) => Array.isArray(value) && value.every((id) => Number.isInteger(id));
        if (result.kind !== 'ok' || plan === null || typeof plan !== 'object' || !HASH_PATTERN.test(String(plan.source_graph_hash))
            || !HASH_PATTERN.test(String(plan.plan_hash)) || !ids(plan.eligible_node_ids) || !ids(plan.excluded_node_ids)) {
            const text = this.failureMessage(result.kind === 'ok' ? 'unexpected' : result.kind, result.code);
            this.inheritHost.replaceChildren(el('div', { className: 'admin-alert admin-alert-danger', attrs: { role: 'alert' } }, [el('p', { text })]));
            this.announce(text);

            return;
        }
        if (plan.eligible_node_ids.length === 0) {
            this.inheritHost.replaceChildren(el('div', { className: 'admin-alert admin-alert-info', attrs: { role: 'status' } }, [el('p', { text: this.t('inherit_empty') })]));
            this.announce(this.t('inherit_empty'));

            return;
        }
        this.inheritView = { uuid, lock, base: baseId, sourceHash: plan.source_graph_hash, planHash: plan.plan_hash };
        this.renderInherit(plan, result.data.display);
        this.announce(this.t('inherit_shown'));
    }

    renderInherit(plan, display) {
        const text = (value, limit) => (typeof value === 'string' && value !== '' ? value.slice(0, limit) : null);
        const shown = display !== null && typeof display === 'object' ? display : {};
        const lost = (Array.isArray(shown.excluded_nodes) ? shown.excluded_nodes : []).filter((node) => node !== null && typeof node === 'object').slice(0, 100)
            .map((node) => el('li', { text: [
                text(node.code, 120), text(node.label, 255),
                NODE_TYPES.includes(node.node_type) ? this.t(`node_type_${node.node_type}`) : null,
                INHERIT_REASONS.includes(node.reason) ? this.t(`inherit_reason_${node.reason}`) : null,
            ].filter(Boolean).join(' · ') }));
        const touched = (Array.isArray(shown.affected_intents) ? shown.affected_intents : []).filter((intent) => intent !== null && typeof intent === 'object').slice(0, 100)
            .map((intent) => el('li', { text: [text(intent.source_label, 255), text(intent.node_label, 255)].filter(Boolean).join(' → ') }));
        const lostCount = plan.excluded_node_ids.length;
        const list = (key, items) => (items.length === 0 ? [] : [el('h6', { text: this.t(key) }), el('ul', {}, items)]);
        this.inheritFields.ack = lostCount === 0 ? null : el('input', { attrs: { id: 'ai-authoring-inherit-ack', type: 'checkbox' } });
        this.inheritHost.replaceChildren(el('section', { className: 'ai-authoring__node' }, [
            el('p', { text: this.t('inherit_counts', { kept: plan.eligible_node_ids.length, lost: lostCount }) }),
            ...list('inherit_lost', lost),
            ...(touched.length === 0 ? [] : [...list('inherit_touched', touched), el('p', { className: 'lf-secondary-text', text: this.t('inherit_affected_note') })]),
            this.inheritFields.ack === null ? null : el('div', { className: 'admin-form-group' }, [
                this.inheritFields.ack,
                el('label', { text: this.t('inherit_ack'), attrs: { for: 'ai-authoring-inherit-ack' } }),
            ]),
            el('div', { className: 'admin-form-actions' }, [this.button('inherit_create', () => this.inheritStep(), 'btn btn-primary')]),
        ].filter(Boolean)));
        this.syncControls();
    }

    /** Creates the draft from exactly the plan that was shown, once a code, a name and any acknowledgement are given. */
    async inheritStep() {
        const data = this.detailData;
        const fields = this.inheritFields;
        const view = this.inheritView;
        if (!data || !fields || this.busy || this.revoked || view === null || view.uuid !== data.proposal_uuid || view.lock !== data.lock_version
            || String(view.base) !== fields.base.value) {
            return;
        }
        const code = fields.code.value.trim();
        const title = fields.title.value.trim();
        const problem = code === '' || code.length > 100 ? ['inherit_need_code', fields.code]
            : (title === '' || title.length > 255 ? ['inherit_need_name', fields.title]
                : (fields.ack !== null && !fields.ack.checked ? ['inherit_need_ack', fields.ack] : null));
        if (problem !== null) {
            this.showMessage('error', this.t(problem[0]));
            this.announce(this.t(problem[0]));
            problem[1].focus();

            return;
        }
        const seq = this.detailSeq;
        const epoch = this.epoch;
        const confirmed = typeof window.LFConfirm?.open === 'function' && await window.LFConfirm.open({
            title: this.t('inherit_create_title'),
            message: `${this.t('inherit_create_message')} ${code}`,
            confirmLabel: this.t('inherit_create_label'),
            trigger: document.activeElement,
        });
        if (!confirmed || seq !== this.detailSeq || epoch !== this.epoch || !this.detailData || this.inheritView !== view) {
            return;
        }
        // If the creation is refused and the proposal is read again, what was typed is put back.
        this.pendingInherit = { uuid: data.proposal_uuid, base: fields.base.value, code: fields.code.value, title: fields.title.value };
        await this.runCommand('POST', `${this.urls.proposals}/${data.proposal_uuid}/inherited-drafts`, {
            base_version_id: view.base,
            version_code: code,
            title,
            expected_source_graph_hash: view.sourceHash,
            expected_plan_hash: view.planHash,
        }, 'inherit_created', {
            conflicts: { proposal_idempotency_conflict: 'inherit_code_taken' },
            done: (reply) => {
                // It went through: the form starts empty again, there is nothing left to put back.
                this.pendingInherit = null;
                // The new draft can be chosen for approving a Node without reloading the page.
                if (Number.isInteger(reply?.result_version_id) && reply.result_version_id > 0) {
                    this.draftVersions = AiAuthoring.versionList([{ id: reply.result_version_id, label: `${code} — ${title}` }, ...this.draftVersions]);
                }
            },
        });
        this.pendingInherit = null;
    }

    /** The published versions the Template could move to: every one but the version it uses now. */
    rebaseTargets() {
        return this.publishedVersions.filter((version) => version.id !== this.framework?.versionId);
    }

    /**
     * An admin moves the whole Template to another published version of its Framework. It replaces every Mapping of
     * the Template, not only this proposal's, and cannot be undone from here; so the plan is read first, each Mapping
     * is decided by the admin, and what is sent is exactly that plan.
     */
    rebasePanel() {
        this.rebaseFields = null;
        this.rebaseHost = null;
        const link = this.mappingsUrl === null ? null
            : el('a', { className: 'btn btn-secondary', text: this.t('mappings_open'), attrs: { href: this.mappingsUrl } });
        const targets = this.rebaseTargets();
        if (this.framework === null || targets.length === 0) {
            return el('section', { className: 'ai-authoring__rebase' }, [
                el('h5', { text: this.t('rebase_title') }),
                el('p', { className: 'lf-secondary-text', text: this.t('rebase_unavailable') }),
                link,
            ].filter(Boolean));
        }
        const carried = this.pendingRebase !== null && this.pendingRebase.uuid === this.openUuid ? this.pendingRebase : null;
        this.pendingRebase = null;
        this.rebaseHeld = carried === null ? new Map() : new Map(carried.choices);
        const target = el('select', { className: 'lf-form-control', attrs: { id: 'ai-authoring-rebase-target' } }, [
            el('option', { text: this.t('rebase_choose_target'), attrs: { value: '' } }),
            ...targets.map((version) => el('option', { text: version.label, attrs: { value: String(version.id) } })),
        ]);
        target.value = carried !== null && targets.some((version) => String(version.id) === carried.target) ? carried.target : '';
        this.rebaseFields = { target, rows: new Map() };
        // Another version is another plan. The decisions made are held, not thrown away.
        target.addEventListener('change', () => {
            if (this.rebaseView !== null && String(this.rebaseView.target) !== target.value) {
                this.dropRebasePlan();
            }
        });
        this.rebaseHost = el('div', { className: 'ai-authoring__target' });

        return el('section', { className: 'ai-authoring__rebase' }, [
            el('h5', { text: this.t('rebase_title') }),
            el('p', { className: 'lf-secondary-text', text: this.t('rebase_intro') }),
            el('div', { className: 'admin-form-group' }, [el('label', { text: this.t('rebase_target'), attrs: { for: 'ai-authoring-rebase-target' } }), target]),
            this.rebaseHost,
            el('div', { className: 'admin-form-actions' }, [this.button('rebase_preview', () => this.previewRebase(), 'btn btn-secondary'), link].filter(Boolean)),
        ]);
    }

    /** Puts every decision made so far aside, keyed by Mapping, with the replacement it was made for. */
    holdRebaseChoices() {
        const view = this.rebaseView;
        for (const [id, row] of this.rebaseFields?.rows ?? []) {
            if (row.choice.value !== '') {
                this.rebaseHeld.set(id, { choice: row.choice.value, reason: row.reason.value, newHash: view?.rows.get(id)?.newHash ?? null });
            }
        }
    }

    dropRebasePlan() {
        this.holdRebaseChoices();
        this.rebaseView = null;
        if (this.rebaseFields) {
            this.rebaseFields.rows = new Map();
        }
        this.rebaseHost?.replaceChildren();
    }

    /** Every Mapping of the Template with what it would become, named, so that an admin can decide each one. */
    async previewRebase() {
        const fields = this.rebaseFields;
        if (this.busy || this.revoked || !this.detailData || !fields || !this.rebaseHost) {
            return;
        }
        const targetId = this.rebaseTargets().find((version) => String(version.id) === fields.target.value)?.id;
        if (targetId === undefined) {
            this.announce(this.t('rebase_choose_target'));
            fields.target.focus();

            return;
        }
        const seq = this.detailSeq;
        const epoch = this.epoch;
        const uuid = this.openUuid;
        const lock = this.detailData.lock_version;
        this.dropRebasePlan();
        this.rebaseHost.replaceChildren(el('p', { className: 'lf-secondary-text', text: this.t('rebase_loading'), attrs: { 'aria-busy': 'true' } }));

        const result = await this.get(`${this.urls.proposals}/${uuid}/rebase-preview?target_version_id=${targetId}`);
        if (seq !== this.detailSeq || epoch !== this.epoch || !this.rebaseHost || this.rebaseFields !== fields) {
            return;
        }
        const plan = result.data?.preview;
        const rows = plan?.intents;
        const sound = Array.isArray(rows) && rows.every((row) => row !== null && typeof row === 'object' && Number.isInteger(row.intent_id) && row.intent_id > 0
            && (row.proposed_node_id === null || Number.isInteger(row.proposed_node_id)))
            && new Set(rows.map((row) => row.intent_id)).size === rows.length;
        if (result.kind !== 'ok' || plan === null || typeof plan !== 'object' || !HASH_PATTERN.test(String(result.data?.preview_hash)) || !sound) {
            const text = this.failureMessage(result.kind === 'ok' ? 'unexpected' : result.kind, result.code);
            this.rebaseHost.replaceChildren(el('div', { className: 'admin-alert admin-alert-danger', attrs: { role: 'alert' } }, [el('p', { text })]));
            this.announce(text);

            return;
        }
        this.rebaseView = {
            uuid, lock, target: targetId, hash: result.data.preview_hash,
            rows: new Map(rows.map((row) => [row.intent_id, { proposed: row.proposed_node_id, newHash: typeof row.new_target_hash === 'string' ? row.new_target_hash : null }])),
        };
        this.renderRebase(rows, Array.isArray(result.data.display?.intents) ? result.data.display.intents : []);
        this.announce(this.t('rebase_shown'));
    }

    renderRebase(rows, display) {
        const text = (value, limit) => (typeof value === 'string' && value !== '' ? value.slice(0, limit) : null);
        const named = (shown) => (shown !== null && typeof shown === 'object' ? [text(shown.code, 120), text(shown.label, 255)].filter(Boolean).join(' · ') || null : null);
        const shownOf = new Map(display.filter((entry) => entry !== null && typeof entry === 'object').map((entry) => [entry.intent_id, entry]));
        const progress = el('p', { className: 'lf-secondary-text', attrs: { role: 'status' } });
        const refresh = () => {
            const all = [...this.rebaseFields.rows.values()];
            progress.textContent = this.t('rebase_progress', { done: all.filter((row) => row.choice.value !== '').length, total: all.length });
        };
        const items = rows.map((row) => {
            const shown = shownOf.get(row.intent_id) ?? {};
            const hasReplacement = row.proposed_node_id !== null;
            const choice = el('select', { className: 'lf-form-control', attrs: { id: `ai-authoring-rebase-choice-${row.intent_id}` } }, [
                el('option', { text: this.t('rebase_choose'), attrs: { value: '' } }),
                hasReplacement ? el('option', { text: this.t('rebase_map'), attrs: { value: 'map' } }) : null,
                el('option', { text: this.t('rebase_remove'), attrs: { value: 'remove_explicit' } }),
            ].filter(Boolean));
            const reason = el('select', { className: 'lf-form-control', attrs: { id: `ai-authoring-rebase-reason-${row.intent_id}`, 'aria-label': this.t('rebase_reason_choose') } }, [
                el('option', { text: this.t('rebase_reason_choose'), attrs: { value: '' } }),
                ...REBASE_REASONS.map((code) => el('option', { text: this.t(`rebase_reason_${code}`), attrs: { value: code } })),
            ]);
            reason.hidden = true;
            const held = this.rebaseHeld.get(row.intent_id);
            const keepable = held !== undefined && (held.choice === 'remove_explicit' || (held.choice === 'map' && hasReplacement && held.newHash === (row.new_target_hash ?? null)));
            if (keepable) {
                choice.value = held.choice;
                reason.value = REBASE_REASONS.includes(held.reason) ? held.reason : '';
                reason.hidden = held.choice !== 'remove_explicit';
            }
            choice.addEventListener('change', () => {
                reason.hidden = choice.value !== 'remove_explicit';
                if (choice.value !== 'remove_explicit') {
                    reason.value = '';
                }
                refresh();
            });
            this.rebaseFields.rows.set(row.intent_id, { choice, reason });
            const contextChanged = row.origin === 'ai_proposal' && typeof row.course_context_hash === 'string'
                && row.course_context_hash !== row.accepted_course_context_hash;

            return el('li', {}, [
                el('strong', { text: text(shown.source_label, 255) ?? this.t('rebase_source_unknown') }),
                el('div', { className: 'lf-secondary-text', text: [
                    MAPPING_ROLES.includes(row.mapping_role) ? this.t(`role_${row.mapping_role}`) : null,
                    typeof row.weight === 'string' && row.weight !== '' ? `${this.t('mapping_weight')}: ${row.weight}` : null,
                    REBASE_ORIGINS.includes(row.origin) ? this.t(`rebase_origin_${row.origin}`) : null,
                ].filter(Boolean).join(' · ') }),
                contextChanged ? el('div', { className: 'lf-secondary-text', text: this.t('rebase_context_changed') }) : null,
                el('div', { text: `${this.t('rebase_from')}: ${named(shown.old_node) ?? this.t('rebase_node_unknown')}` }),
                el('div', { text: `${this.t('rebase_to')}: ${hasReplacement ? (named(shown.proposed_node) ?? this.t('rebase_node_unknown')) : this.t('rebase_no_equivalent')}` }),
                el('div', { className: 'admin-form-group' }, [
                    el('label', { text: this.t('rebase_decision'), attrs: { for: `ai-authoring-rebase-choice-${row.intent_id}` } }), choice, reason,
                ]),
            ].filter(Boolean));
        });
        this.rebaseHeld = new Map();
        this.rebaseHost.replaceChildren(el('section', { className: 'ai-authoring__node' }, [
            el('div', { className: 'admin-alert admin-alert-warning', attrs: { role: 'note' } }, [el('p', { text: this.t('rebase_scope') })]),
            items.length === 0 ? el('p', { className: 'lf-secondary-text', text: this.t('rebase_none') }) : el('ul', {}, items),
            progress,
            el('div', { className: 'admin-form-actions' }, [
                this.button('rebase_create', () => this.rebaseStep(), 'btn btn-primary'),
                this.button('rebase_discard', () => this.rebaseDiscard(), 'btn btn-secondary'),
            ]),
        ]));
        refresh();
        this.syncControls();
    }

    /** Throws the plan and every decision away, after a question when decisions were made. */
    async rebaseDiscard() {
        const fields = this.rebaseFields;
        if (this.busy || this.revoked || !fields || this.rebaseView === null) {
            return;
        }
        if (fields.rows.size > 0 && [...fields.rows.values()].some((row) => row.choice.value !== '')) {
            const seq = this.detailSeq;
            const asked = typeof window.LFConfirm?.open === 'function' && await window.LFConfirm.open({
                title: this.t('draft_discard_title'),
                message: this.t('draft_discard_message'),
                confirmLabel: this.t('draft_discard_label'),
                tone: 'danger',
                trigger: document.activeElement,
            });
            if (!asked || seq !== this.detailSeq || this.rebaseFields !== fields) {
                return;
            }
        }
        this.rebaseView = null;
        this.rebaseHeld = new Map();
        fields.rows = new Map();
        this.rebaseHost?.replaceChildren();
    }

    /** Sends the plan as decided, once every Mapping has its decision, after a question about the whole Template. */
    async rebaseStep() {
        const data = this.detailData;
        const fields = this.rebaseFields;
        const view = this.rebaseView;
        if (!data || !fields || this.busy || this.revoked || view === null || view.uuid !== data.proposal_uuid || view.lock !== data.lock_version
            || String(view.target) !== fields.target.value) {
            return;
        }
        const decided = [];
        const counts = { map: 0, remove: 0 };
        let undecided = 0;
        let first = null;
        for (const [id, row] of fields.rows) {
            const choice = row.choice.value;
            const ready = (choice === 'map' && view.rows.get(id)?.proposed !== null && view.rows.get(id)?.proposed !== undefined)
                || (choice === 'remove_explicit' && REBASE_REASONS.includes(row.reason.value));
            if (!ready) {
                undecided += 1;
                first ??= choice === 'remove_explicit' ? row.reason : row.choice;
                continue;
            }
            if (choice === 'map') {
                decided.push([id, { disposition: 'map', node_id: view.rows.get(id).proposed }]);
                counts.map += 1;
            } else {
                decided.push([id, { disposition: 'remove_explicit', reason: row.reason.value }]);
                counts.remove += 1;
            }
        }
        if (undecided > 0) {
            this.showMessage('error', this.t('rebase_incomplete', { n: undecided }));
            this.announce(this.t('rebase_incomplete', { n: undecided }));
            first?.focus();

            return;
        }
        const seq = this.detailSeq;
        const epoch = this.epoch;
        const confirmed = typeof window.LFConfirm?.open === 'function' && await window.LFConfirm.open({
            title: this.t('rebase_create_title'),
            message: this.t('rebase_create_message', counts),
            confirmLabel: this.t('rebase_create_label'),
            tone: 'danger',
            trigger: document.activeElement,
        });
        if (!confirmed || seq !== this.detailSeq || epoch !== this.epoch || !this.detailData || this.rebaseView !== view) {
            return;
        }
        // If the command is refused and the proposal is read again, the version and the decisions are put back.
        const choices = new Map();
        for (const [id, row] of fields.rows) {
            choices.set(id, { choice: row.choice.value, reason: row.reason.value, newHash: view.rows.get(id)?.newHash ?? null });
        }
        this.pendingRebase = { uuid: data.proposal_uuid, target: fields.target.value, choices };
        await this.runCommand('POST', `${this.urls.proposals}/${data.proposal_uuid}/rebases`, {
            target_version_id: view.target,
            // Keyed by the whole-number id of each Mapping (they were checked when the plan was read).
            dispositions: Object.fromEntries(decided),
            expected_preview_hash: view.hash,
        }, 'rebase_done', {
            conflicts: {
                proposal_revision_conflict: 'rebase_changed', proposal_successor_required: 'rebase_needs_successor', proposal_stale: 'rebase_stale',
            },
            done: () => {
                // It went through: nothing is left to put back, and the Template now uses the new version.
                this.pendingRebase = null;
                if (this.framework !== null) {
                    this.framework = { ...this.framework, versionId: view.target };
                }
            },
        });
    }

    /**
     * A stale proposal that was once accepted can be continued: the server builds an unapproved draft from the
     * earlier decision, tied to the sources as they are now. Nothing of the old content is shown until the
     * server has agreed to hand it over.
     */
    successorPanel() {
        this.successorHost = el('div', { className: 'ai-authoring__target' });

        return el('section', { className: 'ai-authoring__successor' }, [
            el('h5', { text: this.t('successor_title') }),
            el('p', { className: 'lf-secondary-text', text: this.t('successor_intro') }),
            this.successorHost,
            el('div', { className: 'admin-form-actions' }, [this.button('successor_preview', () => this.previewSuccessor(), 'btn btn-secondary')]),
        ]);
    }

    successorFailure(result) {
        if (result.kind === 'ok') {
            return this.failureMessage('unexpected');
        }
        const own409 = { proposal_stale: 'successor_unavailable', proposal_successor_node_conflict: 'successor_node_conflict' };

        return result.kind === 'conflict' && own(own409, result.code) ? this.t(own(own409, result.code)) : this.failureMessage(result.kind, result.code);
    }

    /** The inherited draft, then every current source (a page at a time), as one reading that is shown or not at all. */
    async previewSuccessor() {
        if (this.busy || this.revoked || !this.detailData || !this.successorHost) {
            return;
        }
        const seq = this.detailSeq;
        const epoch = this.epoch;
        const uuid = this.openUuid;
        const lock = this.detailData.lock_version;
        const current = () => seq === this.detailSeq && epoch === this.epoch && this.successorHost !== null;
        const fail = (text) => {
            this.successorHost.replaceChildren(el('div', { className: 'admin-alert admin-alert-danger', attrs: { role: 'alert' } }, [el('p', { text })]));
            this.announce(text);
        };
        this.successorView = null;
        this.successorHost.replaceChildren(el('p', { className: 'lf-secondary-text', text: this.t('successor_loading'), attrs: { 'aria-busy': 'true' } }));

        const preview = await this.get(`${this.urls.proposals}/${uuid}/successor-preview`);
        if (!current()) {
            return;
        }
        const draft = preview.data?.payload;
        if (preview.kind !== 'ok' || preview.data?.inherited_decision_draft !== true || !SUCCESSOR_REASONS.includes(preview.data?.successor_reason)
            || draft === null || typeof draft !== 'object') {
            fail(this.successorFailure(preview));

            return;
        }

        // Every current source, within the limit; a longer list cannot be sent whole and is not cut.
        const hashes = new Set();
        let cursor = null;
        for (let round = 0; round <= SUCCESSOR_SOURCE_LIMIT / SUCCESSOR_PAGE_SIZE; round += 1) {
            const query = `limit=${SUCCESSOR_PAGE_SIZE}${cursor === null ? '' : `&cursor=${encodeURIComponent(cursor)}`}`;
            const scope = await this.get(`${this.urls.proposals}/${uuid}/successor-source-scope?${query}`);
            if (!current()) {
                return;
            }
            const anchors = scope.data?.anchors;
            if (scope.kind !== 'ok' || !Array.isArray(anchors)) {
                fail(this.successorFailure(scope));

                return;
            }
            for (const entry of anchors) {
                if (entry === null || typeof entry !== 'object' || !HASH_PATTERN.test(String(entry.anchor_hash)) || entry.anchor === null
                    || typeof entry.anchor !== 'object' || hashes.has(entry.anchor_hash)) {
                    fail(this.failureMessage('unexpected'));

                    return;
                }
                hashes.add(entry.anchor_hash);
            }
            cursor = typeof scope.data.next_cursor === 'string' && scope.data.next_cursor !== '' ? scope.data.next_cursor : null;
            if (cursor === null || hashes.size > SUCCESSOR_SOURCE_LIMIT) {
                break;
            }
        }
        if (hashes.size > SUCCESSOR_SOURCE_LIMIT || cursor !== null) {
            fail(this.t('successor_too_many', { n: SUCCESSOR_SOURCE_LIMIT }));

            return;
        }
        if (hashes.size === 0) {
            fail(this.t('successor_no_sources'));

            return;
        }
        this.successorView = { uuid, lock, hashes: [...hashes].sort() };
        this.renderSuccessor(draft, hashes.size);
        this.announce(this.t('successor_shown'));
    }

    /** Only the fields the server may hand over: title, body, kind, and a mapping's role and weight. */
    renderSuccessor(draft, count) {
        const text = (value, limit) => (typeof value === 'string' && value !== '' ? value.slice(0, limit) : null);
        const mapping = draft.mapping !== null && typeof draft.mapping === 'object' ? draft.mapping : null;
        const rows = [
            ...this.definition(this.t('col_kind'), this.label('kind', KINDS, draft.kind)),
            ...this.definition(this.t('field_title'), text(draft.title, 255) ?? this.t('none')),
            ...this.definition(this.t('field_body'), text(draft.body, 5000) ?? this.t('none')),
            ...(mapping === null ? [] : [
                ...this.definition(this.t('mapping_role'), MAPPING_ROLES.includes(mapping.role) ? this.t(`role_${mapping.role}`) : this.t('none')),
                ...this.definition(this.t('mapping_weight'), typeof mapping.weight === 'number' ? String(mapping.weight) : this.t('none')),
            ]),
        ];
        this.successorHost.replaceChildren(el('section', { className: 'ai-authoring__node' }, [
            el('div', { className: 'admin-alert admin-alert-info', attrs: { role: 'note' } }, [el('p', { text: this.t('successor_draft_note') })]),
            el('dl', { className: 'admin-readonly-summary' }, rows),
            el('p', { className: 'lf-secondary-text', text: this.t('successor_sources', { n: count }) }),
            el('div', { className: 'admin-form-actions' }, [this.button('successor_create', () => this.successorStep(), 'btn btn-primary')]),
        ]));
        this.syncControls();
    }

    /** Creates the successor from exactly what was shown, after a question, and opens it. */
    async successorStep() {
        const data = this.detailData;
        const view = this.successorView;
        if (!data || this.busy || this.revoked || view === null || view.uuid !== data.proposal_uuid || view.lock !== data.lock_version) {
            return;
        }
        const seq = this.detailSeq;
        const epoch = this.epoch;
        const confirmed = typeof window.LFConfirm?.open === 'function' && await window.LFConfirm.open({
            title: this.t('successor_create_title'),
            message: this.t('successor_create_message', { n: view.hashes.length }),
            confirmLabel: this.t('successor_create_label'),
            trigger: document.activeElement,
        });
        if (!confirmed || seq !== this.detailSeq || epoch !== this.epoch || !this.detailData || this.successorView !== view) {
            return;
        }
        await this.runCommand('POST', `${this.urls.proposals}/${data.proposal_uuid}/successors`, {
            reason: SUCCESSOR_REASONS[0],
            payload: null,
            selected_anchor_hashes: view.hashes,
        }, 'successor_created', { opens: AiAuthoring.newProposalOf });
    }

    /** The proposal a command created, when the reply names one properly. */
    static newProposalOf(data) {
        const uuid = String(data?.proposal_uuid ?? '').toLowerCase();

        return UUID.test(uuid) ? uuid : null;
    }

    /** What the Course context is now (never compared with the old one), to be confirmed from. */
    async previewContext() {
        if (this.busy || this.revoked || !this.detailData || !this.contextHost) {
            return;
        }
        const seq = this.detailSeq;
        const epoch = this.epoch;
        const uuid = this.openUuid;
        const lock = this.detailData.lock_version;
        this.contextView = null;
        this.contextHost.replaceChildren(el('p', { className: 'lf-secondary-text', text: this.t('context_loading'), attrs: { 'aria-busy': 'true' } }));

        const result = await this.get(`${this.urls.proposals}/${uuid}/context-preview`);
        if (seq !== this.detailSeq || epoch !== this.epoch || !this.contextHost) {
            return;
        }
        const context = result.data?.context;
        if (result.kind !== 'ok' || !HASH_PATTERN.test(String(result.data?.course_context_hash)) || context === null || typeof context !== 'object') {
            const text = this.failureMessage(result.kind === 'ok' ? 'unexpected' : result.kind, result.code);
            this.contextHost.replaceChildren(el('div', { className: 'admin-alert admin-alert-danger', attrs: { role: 'alert' } }, [el('p', { text })]));
            this.announce(text);

            return;
        }
        this.contextView = { uuid, lock, hash: result.data.course_context_hash };
        this.renderContext(context);
        this.announce(this.t('context_shown'));
    }

    /** Only what a person can judge: the Activity's title, instructions and level. Identifiers are for the commands. */
    renderContext(context) {
        const text = (value, limit) => (typeof value === 'string' && value !== '' ? value.slice(0, limit) : null);
        const rows = [
            ...this.definition(this.t('context_activity_title'), text(context.title, 255) ?? this.t('none')),
            ...this.definition(this.t('context_instructions'), text(context.instructions, 1000) ?? this.t('none')),
            ...this.definition(this.t('context_level'), text(context.level, 100) ?? this.t('none')),
        ];
        this.contextHost.replaceChildren(el('section', { className: 'ai-authoring__node' }, [
            el('dl', { className: 'admin-readonly-summary' }, rows),
            el('div', { className: 'admin-form-actions' }, [this.button('confirm_context', () => this.targetStep('context'), 'btn btn-primary')]),
        ]));
        this.syncControls();
    }

    /**
     * An admin's approval of a new Node: the draft it is created in is chosen, never assumed. Without a
     * selected Framework or a draft to put it in, the admin is told so and nothing can be sent.
     */
    nodeApproval() {
        this.draftChoice = null;
        const carried = this.pendingDraft !== null && this.pendingDraft.uuid === this.openUuid ? this.pendingDraft.value : '';
        this.pendingDraft = null;
        if (this.framework === null || this.draftVersions.length === 0) {
            return el('div', { className: 'admin-alert admin-alert-info', attrs: { role: 'status' } }, [
                el('p', { text: this.t(this.framework === null ? 'approve_node_unavailable' : 'approve_node_no_draft') }),
                this.mappingsUrl !== null
                    ? el('a', { className: 'btn btn-secondary', text: this.t('mappings_open'), attrs: { href: this.mappingsUrl } }) : null,
            ].filter(Boolean));
        }
        const id = 'ai-authoring-node-draft';
        const select = el('select', { className: 'lf-form-control', attrs: { id } }, [
            el('option', { text: this.t('approve_node_choose'), attrs: { value: '' } }),
            ...this.draftVersions.map((draft) => el('option', { text: draft.label, attrs: { value: String(draft.id) } })),
        ]);
        select.value = this.draftVersions.some((draft) => String(draft.id) === carried) ? carried : '';
        this.draftChoice = select;

        return el('div', { className: 'admin-form-group' }, [
            el('label', { text: this.t('approve_node_draft'), attrs: { for: id } }),
            select,
            el('div', { className: 'admin-form-actions' }, [this.button('approve_node', () => this.approveNode(), 'btn btn-primary')]),
        ]);
    }

    /** Creates the proposed Node in the chosen draft: a frozen command like any other, after a question. */
    async approveNode() {
        const data = this.detailData;
        const select = this.draftChoice;
        if (!data || !select || this.busy || this.revoked || this.framework === null) {
            return;
        }
        const draft = this.draftVersions.find((candidate) => String(candidate.id) === select.value);
        if (draft === undefined) {
            this.announce(this.t('approve_node_choose'));
            select.focus();

            return;
        }
        const seq = this.detailSeq;
        const epoch = this.epoch;
        const confirmed = typeof window.LFConfirm?.open === 'function' && await window.LFConfirm.open({
            title: this.t('approve_node_title'),
            message: `${this.t('approve_node_message')} ${draft.label}`,
            confirmLabel: this.t('approve_node_label'),
            trigger: document.activeElement,
        });
        if (!confirmed || seq !== this.detailSeq || epoch !== this.epoch || !this.detailData || this.draftChoice !== select) {
            return;
        }
        // If the approval is refused and the proposal is read again, the choice is put back.
        this.pendingDraft = { uuid: data.proposal_uuid, value: String(draft.id) };
        await this.runCommand('POST', `${this.urls.proposals}/${data.proposal_uuid}/node-approvals`, {
            framework_id: this.framework.id,
            framework_version_id: draft.id,
            expected_revision_no: data.revision_no,
            expected_lock_version: data.lock_version,
        }, 'node_approved');
        // The reread that put the choice back is over; it is not kept for any later proposal.
        this.pendingDraft = null;
    }

    async previewTarget() {
        if (this.busy || this.revoked || !this.detailData || !this.targetHost) {
            return;
        }
        const seq = this.detailSeq;
        const epoch = this.epoch;
        const uuid = this.openUuid;
        const lock = this.detailData.lock_version;
        this.targetView = null;
        this.targetHost.replaceChildren(el('p', { className: 'lf-secondary-text', text: this.t('target_loading'), attrs: { 'aria-busy': 'true' } }));

        const result = await this.get(`${this.urls.proposals}/${uuid}/target-preview`);
        if (seq !== this.detailSeq || epoch !== this.epoch || !this.targetHost) {
            return;
        }
        const snapshot = result.data?.target_snapshot;
        if (result.kind !== 'ok' || !HASH_PATTERN.test(String(result.data?.target_hash)) || snapshot === null || typeof snapshot !== 'object'
            || snapshot.node === null || typeof snapshot.node !== 'object') {
            const kind = result.kind === 'ok' ? 'unexpected' : result.kind;
            const text = this.failureMessage(kind, result.code);
            this.targetHost.replaceChildren(el('div', { className: 'admin-alert admin-alert-danger', attrs: { role: 'alert' } }, [el('p', { text })]));
            this.announce(text);

            return;
        }
        this.targetView = { uuid, lock, hash: result.data.target_hash };
        this.renderTarget(result.data);
        this.announce(this.t('target_shown'));
    }

    /** The target as Learning describes it now; identifiers are for the commands, not for people. */
    renderTarget(data) {
        const snapshot = data.target_snapshot;
        const node = snapshot.node;
        const text = (value, limit = 500) => (typeof value === 'string' && value !== '' ? value.slice(0, limit) : null);
        const related = (Array.isArray(snapshot.dependency_nodes) ? snapshot.dependency_nodes : []).slice(0, 10)
            .map((dependency) => text(dependency?.label, 120)).filter(Boolean);
        const criteria = node.criteria === null || node.criteria === undefined ? null : JSON.stringify(node.criteria).slice(0, 1000);
        const allowed = Array.isArray(this.detailData?.allowed_actions) ? this.detailData.allowed_actions : [];
        const rows = [
            ...this.definition(this.t('mapping_label'), text(node.label, 255) ?? this.t('none')),
            ...this.definition(this.t('mapping_code'), text(node.code, 120) ?? this.t('none')),
            ...this.definition(this.t('mapping_type'), NODE_TYPES.includes(node.node_type) ? this.t(`node_type_${node.node_type}`) : this.t('none')),
            ...(text(node.description) === null ? [] : this.definition(this.t('mapping_description'), text(node.description))),
            ...(criteria === null ? [] : this.definition(this.t('mapping_criteria'), criteria)),
            ...this.definition(this.t('target_version'), this.t(data.version_status === 'published' ? 'target_version_published' : 'target_version_draft')),
            ...(related.length === 0 ? [] : this.definition(this.t('target_related'), related.join(', '))),
        ];
        const buttons = [];
        if (allowed.includes('confirm_target')) {
            buttons.push(this.button('confirm_target', () => this.targetStep('confirm'), 'btn btn-primary'));
        }
        if (allowed.includes('reject_target')) {
            buttons.push(this.button('reject_target', () => this.targetStep('reject'), 'btn btn-danger'));
        }
        this.targetHost.replaceChildren(el('section', { className: 'ai-authoring__node' }, [
            el('h5', { text: this.t('target_title') }),
            el('dl', { className: 'admin-readonly-summary' }, rows),
            buttons.length > 0 ? el('div', { className: 'admin-form-actions' }, buttons) : null,
        ]));
        this.syncControls();
    }

    /**
     * Confirm the target, reject it, or apply the confirmed one to the working Template.
     * Each is one frozen command like any other; confirming and rejecting send the hash of
     * the target that was shown, against the version it was shown for.
     */
    async targetStep(kind) {
        const data = this.detailData;
        const step = own(TARGET_STEPS, kind);
        if (!data || this.busy || this.revoked || !step) {
            return;
        }
        // A step that sends a hash is made only from what was shown, for this very version of the proposal.
        const view = step.view ? this[step.view] : null;
        if (step.hash && (view === null || view.uuid !== data.proposal_uuid || view.lock !== data.lock_version)) {
            return;
        }
        const seq = this.detailSeq;
        const epoch = this.epoch;
        const confirmed = typeof window.LFConfirm?.open === 'function' && await window.LFConfirm.open({
            title: this.t(`${step.ask}_title`),
            message: this.t(`${step.ask}_message`),
            confirmLabel: this.t(`${step.ask}_label`),
            tone: step.danger ? 'danger' : undefined,
            trigger: document.activeElement,
        });
        if (!confirmed || seq !== this.detailSeq || epoch !== this.epoch || !this.detailData) {
            return;
        }
        const command = { expected_lock_version: data.lock_version, ...(step.hash ? { [step.field]: view.hash } : {}) };
        await this.runCommand('POST', `${this.urls.proposals}/${data.proposal_uuid}/${step.path}`, command, step.done);
    }

    /** Writes one message into the command area. `tone` is error | success | info; `actions` are buttons. */
    showMessage(tone, text, actions = []) {
        const cls = { error: 'admin-alert-danger', success: 'admin-alert-success', info: 'admin-alert-info' }[tone] ?? 'admin-alert-info';
        this.commandArea?.replaceChildren(el('div', { className: `admin-alert ${cls}`, attrs: { role: tone === 'error' ? 'alert' : 'status' } },
            [el('p', { text }), ...actions]));
    }

    /** The action controls follow two things: a command in flight, and a command whose outcome is unknown. */
    syncControls() {
        for (const host of [this.actionsHost, this.receiptHost]) {
            for (const control of host?.querySelectorAll('button, textarea, input, select') ?? []) {
                control.disabled = this.busy || this.locked;
            }
        }
        this.detailHost.setAttribute('aria-busy', this.busy ? 'true' : 'false');
    }

    setBusy(busy) {
        this.busy = busy;
        this.syncControls();
    }

    /**
     * Gives up the command whose outcome is unknown so that the form can be edited
     * again. The next command gets a new request id; if the first one was in fact
     * recorded, the server answers the new one with a conflict and the proposal is read again.
     */
    discardUnsent() {
        this.pendings.delete('detail');
        this.locked = false;
        this.commandArea?.replaceChildren();
        this.syncControls();
        this.announce(this.t('unknown_discarded'));
        const first = this.editor ? Object.values(this.editor.controls)[0] : this.actionButtons[0];
        first?.focus();
    }

    /**
     * Sends one command and reports what is and is not known afterwards.
     *
     * The request id and body are frozen for the attempt. If the outcome is
     * unknown (no answer, or the service failed), the same command can be sent
     * again with the same id, and only then; any change to the body, or a
     * definite answer, ends that.
     */
    async runCommand(method, url, command, successKey, options = {}) {
        const opens = options.opens ?? null;
        if (this.busy || this.revoked || !this.detailData) {
            return;
        }
        const uuid = this.openUuid;
        const fingerprint = JSON.stringify([method, url, command]);
        const requestId = this.frozenId('detail', fingerprint);
        const seq = this.detailSeq;
        const epoch = this.epoch;
        const hadDraft = this.editor?.dirty() ?? false;
        const note = this.noteField?.value ?? '';
        // The form cannot change under a command: what is sent is what was frozen.
        this.setBusy(true);
        this.announce(this.t('saving'));

        const result = await this.request(method, url, { ...command, request_id: requestId });
        if (epoch !== this.epoch) {
            // Access was lost while this was in flight. request() already took
            // everything away; the answer is not shown and nothing is kept for a retry.
            this.busy = false;

            return;
        }
        const stillHere = seq === this.detailSeq;
        if (stillHere) {
            this.setBusy(false);
        } else {
            this.busy = false;
        }

        if (result.kind === 'ok') {
            options.done?.(result.data);
            // The command changed this proposal, so what was read of it is out of date.
            this.reviewed.delete(uuid);
            this.announce(this.t(successKey));
            this.notice = { tone: 'success', text: this.t(successKey) };
            if (stillHere) {
                // A decision used the note; saving an edit did not, so the note stays for the decision to come.
                if (method === 'PATCH' && note !== '') {
                    this.carriedNote = { uuid, text: note };
                }
                // A command that made a new proposal opens that one; every other command reads this one again.
                await Promise.all([this.loadList(true), this.openDetail((opens ? opens(result.data) : null) ?? uuid, this.trigger)]);
                // A refusal met while reading back is the latest word on access.
                if (epoch === this.epoch) {
                    this.announce(this.t(successKey));
                }
            } else {
                // The person has moved on to another proposal, possibly with an edit
                // of its own under way: only the rows are refreshed, never the open detail.
                this.notice = null;
                this.loadList(true);
            }

            return;
        }
        if (!stillHere) {
            this.announce(this.failureMessage(result.kind, result.code));

            return;
        }
        if (result.kind === 'not_found') {
            // This proposal is gone (a lost session or refusal was handled page-wide).
            this.dropDetail(false);
            this.detailHost.replaceChildren(this.detailFailure(result.kind));
            this.announce(this.failureMessage(result.kind));
            this.focusHeading();
            this.loadList(true);

            return;
        }
        if (['network', 'unavailable', 'unexpected'].includes(result.kind)) {
            // Unknown outcome. The same command may be sent again exactly as it was, and
            // until then the form is locked so that what is shown is what would be sent.
            this.rememberUnknown('detail', fingerprint, requestId);
            this.locked = true;
            this.syncControls();
            this.showMessage('error', this.t('unknown_outcome'), [
                this.button('retry_same', () => this.runCommand(method, url, command, successKey, options), 'btn btn-secondary'),
                this.button('unknown_discard', () => this.discardUnsent(), 'btn btn-secondary'),
            ]);
            this.announce(this.t('unknown_outcome'));

            return;
        }
        if (result.kind === 'invalid' || result.kind === 'rate') {
            // Nothing was recorded; the draft stays for another try.
            this.showMessage('error', this.failureMessage(result.kind));
            this.announce(this.failureMessage(result.kind));

            return;
        }
        // A conflict: the proposal moved on. Show why, then read it again so the
        // reviewer decides on what is there now.
        // The unsent edit goes with the old version: the person is told, not left to find out.
        const own409 = options.conflicts ? own(options.conflicts, result.code) : undefined;
        const text = [own409 ? this.t(own409) : this.failureMessage(result.kind, result.code), hadDraft ? this.t('conflict_draft_lost') : ''].filter(Boolean).join(' ');
        this.reviewed.delete(uuid);
        this.notice = { tone: 'error', text };
        this.announce(text);
        // The command was not applied, so the note typed for it is still wanted.
        if (note !== '') {
            this.carriedNote = { uuid, text: note };
        }
        await Promise.all([this.loadList(true), this.openDetail(uuid, this.trigger)]);
    }

    async decide(action) {
        const data = this.detailData;
        if (!data || this.busy) {
            return;
        }
        const seq = this.detailSeq;
        const trigger = document.activeElement;
        // The shared dialog is required; without it nothing is decided.
        const confirmed = typeof window.LFConfirm?.open === 'function' && await window.LFConfirm.open({
            title: this.t(`confirm_${action}_title`),
            message: this.t(`confirm_${action}_message`),
            confirmLabel: this.t(`confirm_${action}_label`),
            tone: action === 'reject' ? 'danger' : undefined,
            trigger,
        });
        if (!confirmed || seq !== this.detailSeq || !this.detailData) {
            return;
        }
        const command = {
            expected_lock_version: data.lock_version,
            expected_revision_no: data.revision_no,
            action,
        };
        const reason = this.noteField?.value.trim() ?? '';
        if (reason !== '') {
            command.reason = reason;
        }
        await this.runCommand('POST', `${this.urls.proposals}/${data.proposal_uuid}/decisions`, command,
            action === 'accept' ? 'accepted' : 'rejected');
    }

    // -------------------------------------------------------------------- edit

    showEdit() {
        const data = this.detailData;
        if (!data?.payload) {
            return;
        }
        const payload = data.payload;
        // What of a mapping can be edited: everything of a new Node; only the role and weight of an
        // existing one (which Node it is stays as it was proposed).
        const mapping = payload.mapping && ['propose_new', 'reuse_existing'].includes(payload.mapping.mode) ? payload.mapping : null;
        const controls = {};
        const errors = {};

        const field = (name, labelKey, control) => {
            controls[name] = control;
            control.setAttribute('id', `ai-authoring-edit-${name}`);
            errors[name] = el('p', { className: 'admin-form-help ai-authoring__error', attrs: { id: `ai-authoring-edit-${name}-error`, hidden: '' } });

            return el('div', { className: 'admin-form-group' }, [
                el('label', { text: this.t(labelKey), attrs: { for: `ai-authoring-edit-${name}` } }),
                control,
                errors[name],
            ]);
        };
        const input = (extra = {}) => el('input', { className: 'lf-form-control', attrs: { type: 'text', ...extra } });
        const withValue = (node, value) => {
            node.value = value ?? '';

            return node;
        };
        const select = (values, prefix, current) => {
            const node = el('select', { className: 'lf-form-control' }, values.map((value) => el('option', { text: this.t(`${prefix}_${value}`), attrs: { value } })));
            node.value = values.includes(current) ? current : values[0];

            return node;
        };

        const fields = [
            field('title', 'field_title', withValue(input({ maxlength: '255' }), payload.title)),
            field('body', 'field_body', withValue(el('textarea', { className: 'lf-form-control', attrs: { rows: '8', maxlength: '20000' } }), payload.body)),
            field('rationale', 'field_rationale', withValue(el('textarea', { className: 'lf-form-control', attrs: { rows: '3', maxlength: '4000' } }), payload.rationale)),
        ];
        if (mapping?.mode === 'reuse_existing') {
            fields.push(
                field('role', 'field_role', select(MAPPING_ROLES, 'role', mapping.role)),
                field('weight', 'field_weight', withValue(input({ inputmode: 'decimal' }), typeof mapping.weight === 'number' ? String(mapping.weight) : '')),
            );
        } else if (mapping) {
            fields.push(
                field('code', 'field_code', withValue(input({ maxlength: '100' }), mapping.code)),
                field('label', 'field_label', withValue(input({ maxlength: '255' }), mapping.label)),
                field('node_type', 'field_node_type', select(NODE_TYPES, 'node_type', mapping.node_type)),
                field('role', 'field_role', select(MAPPING_ROLES, 'role', mapping.role)),
                field('weight', 'field_weight', withValue(input({ inputmode: 'decimal' }), typeof mapping.weight === 'number' ? String(mapping.weight) : '')),
                field('criteria', 'field_criteria', withValue(el('textarea', { className: 'lf-form-control', attrs: { rows: '3' } }),
                    mapping.criteria === null || mapping.criteria === undefined ? '' : JSON.stringify(mapping.criteria, null, 2))),
            );
        }

        // What the form held when it opened, so that leaving it can tell whether anything typed would be lost.
        const snapshot = () => JSON.stringify(Object.entries(controls).map(([name, control]) => [name, control.value]));
        const initial = snapshot();
        this.editor = { controls, dirty: () => snapshot() !== initial };

        const save = this.button('save', () => this.submitEdit(payload, mapping, controls, errors), 'btn btn-primary');
        const cancel = this.button('cancel', async () => {
            if (!(await this.guardDraft())) {
                return;
            }
            this.editor = null;
            this.commandArea?.replaceChildren();
            this.showActions();
            this.actionButtons?.[0]?.focus();
        }, 'btn btn-secondary');
        this.actionsHost.replaceChildren(
            el('h5', { text: this.t('edit_title') }),
            el('p', { className: 'lf-secondary-text', text: this.t('edit_note') }),
            ...fields,
            el('div', { className: 'admin-form-actions' }, [cancel, save]),
        );
        controls.title.focus();
    }

    /** Checks the draft in the browser for a quick answer; the server is still the authority. */
    submitEdit(payload, mapping, controls, errors) {
        const problems = [];
        const fail = (name, key) => {
            problems.push({ name, key });
        };
        const value = (name) => controls[name].value;

        for (const error of Object.values(errors)) {
            error.hidden = true;
            error.textContent = '';
        }
        for (const control of Object.values(controls)) {
            control.removeAttribute('aria-invalid');
            control.removeAttribute('aria-describedby');
        }

        if (value('title').trim() === '') {
            fail('title', 'validation_title_required');
        }
        const next = {
            kind: payload.kind,
            title: value('title'),
            body: value('body'),
            // Confidence is the model's, and the sources are what it read: neither is edited.
            confidence: payload.confidence,
            rationale: value('rationale'),
            source_refs: payload.source_refs,
        };
        let weight = null;
        if (mapping && value('weight').trim() !== '') {
            weight = Number(value('weight').trim().replace(',', '.'));
            if (!Number.isFinite(weight) || weight < 0 || weight > 1) {
                fail('weight', 'validation_weight');
            }
        }
        if (mapping?.mode === 'reuse_existing') {
            // The Node stays exactly as proposed; only the role and weight are the reviewer's.
            next.mapping = {
                mode: 'reuse_existing', node_id: mapping.node_id, definition_id: mapping.definition_id, role: value('role'), weight,
            };
        } else if (mapping) {
            if (!CODE_PATTERN.test(value('code'))) {
                fail('code', 'validation_code');
            }
            if (value('label').trim() === '') {
                fail('label', 'validation_label_required');
            }
            let criteria = null;
            if (value('criteria').trim() !== '') {
                try {
                    criteria = JSON.parse(value('criteria'));
                } catch {
                    criteria = undefined;
                }
                if (criteria === undefined || criteria === null || typeof criteria !== 'object') {
                    fail('criteria', 'validation_criteria');
                }
            }
            next.mapping = {
                mode: 'propose_new',
                code: value('code'),
                label: value('label'),
                node_type: value('node_type'),
                criteria,
                role: value('role'),
                weight,
            };
        }

        if (problems.length > 0) {
            for (const { name, key } of problems) {
                errors[name].textContent = this.t(key);
                errors[name].hidden = false;
                controls[name].setAttribute('aria-invalid', 'true');
                controls[name].setAttribute('aria-describedby', errors[name].id);
            }
            controls[problems[0].name].focus();
            this.announce(this.t(problems[0].key));

            return;
        }

        const data = this.detailData;
        this.runCommand('PATCH', `${this.urls.proposals}/${data.proposal_uuid}`, {
            expected_lock_version: data.lock_version,
            expected_revision_no: data.revision_no,
            payload_schema_version: PAYLOAD_SCHEMA_VERSION,
            payload: next,
        }, 'saved');
    }

    applications(applications) {
        this.receiptReasons = new Map();
        const carried = this.pendingReason;
        this.pendingReason = null;
        const rows = applications.map((application) => el('li', {}, [
            el('span', { text: [
                this.t(APPLICATION_OPERATIONS.includes(application.operation) ? `application_${application.operation}` : 'source_unknown'),
                this.t(APPLICATION_STATUSES.includes(application.status) ? `application_${application.status}` : 'status_unknown'),
            ].join(' · ') }),
            // What the status means, never more than it does: applied is a Mapping intent in the
            // working Template, not the official Mapping, which only publication makes.
            APPLICATION_STATUSES.includes(application.status)
                ? el('div', { className: 'lf-secondary-text', text: this.t(application.operation === 'create_node' && application.status === 'applied'
                    ? 'application_note_node_created' : `application_note_${application.status}`) }) : null,
            this.receiptControls(application, carried),
        ]));
        const applied = applications.some((application) => application.operation === 'apply_intent' && application.status === 'applied');

        this.receiptHost = el('section', {}, [
            el('h5', { text: this.t('applications') }),
            rows.length ? el('ul', {}, rows) : el('p', { className: 'lf-secondary-text', text: this.t('none') }),
            applied && this.mappingsUrl !== null
                ? el('a', { className: 'btn btn-secondary', text: this.t('mappings_open'), attrs: { href: this.mappingsUrl } }) : null,
        ].filter(Boolean));

        return this.receiptHost;
    }

    /**
     * What one receipt can be asked to do, exactly as the server offered it: retry a failed
     * one, cancel one that is not applied (with a reason from a fixed list).
     */
    receiptControls(application, carried) {
        const allowed = Array.isArray(application.allowed_actions) ? application.allowed_actions : [];
        const uuid = String(application.application_uuid ?? '').toLowerCase();
        if (!UUID.test(uuid) || !(allowed.includes('retry') || allowed.includes('cancel'))) {
            return null;
        }
        const parts = [];
        if (allowed.includes('cancel')) {
            const id = `ai-authoring-reason-${uuid}`;
            const select = el('select', { className: 'lf-form-control', attrs: { id } },
                RECEIPT_REASONS.map((code) => el('option', { text: this.t(`receipt_reason_${code}`), attrs: { value: code } })));
            select.value = carried !== null && carried.uuid === uuid && RECEIPT_REASONS.includes(carried.value) ? carried.value : RECEIPT_REASONS[0];
            this.receiptReasons.set(uuid, select);
            parts.push(el('div', { className: 'admin-form-group' }, [el('label', { text: this.t('receipt_reason'), attrs: { for: id } }), select]));
        }
        const buttons = [];
        if (allowed.includes('retry')) {
            buttons.push(this.button('receipt_retry', () => this.receiptStep('retry', uuid), 'btn btn-secondary'));
        }
        if (allowed.includes('cancel')) {
            buttons.push(this.button('receipt_cancel', () => this.receiptStep('cancel', uuid), 'btn btn-danger'));
        }
        parts.push(el('div', { className: 'admin-form-actions' }, buttons));

        return el('div', { className: 'ai-authoring__receipt' }, parts);
    }

    /** Retry or cancel one receipt: a frozen command like any other, after a question. */
    async receiptStep(kind, uuid) {
        const data = this.detailData;
        const step = own(RECEIPT_STEPS, kind);
        if (!data || this.busy || this.revoked || !step) {
            return;
        }
        const seq = this.detailSeq;
        const epoch = this.epoch;
        const reason = step.withReason ? this.receiptReasons.get(uuid)?.value : null;
        if (step.withReason && !RECEIPT_REASONS.includes(reason)) {
            return;
        }
        const confirmed = typeof window.LFConfirm?.open === 'function' && await window.LFConfirm.open({
            title: this.t(`receipt_${kind}_title`),
            message: this.t(`receipt_${kind}_message`),
            confirmLabel: this.t(`receipt_${kind}_label`),
            tone: kind === 'cancel' ? 'danger' : undefined,
            trigger: document.activeElement,
        });
        if (!confirmed || seq !== this.detailSeq || epoch !== this.epoch || !this.detailData) {
            return;
        }
        // If the command is refused and the proposal is read again, the choice is put back.
        this.pendingReason = step.withReason ? { uuid, value: reason } : null;
        const command = { expected_lock_version: data.lock_version, ...(step.withReason ? { reason_code: reason } : {}) };
        await this.runCommand('POST', `${this.urls.proposals}/${data.proposal_uuid}/applications/${uuid}/${step.path}`, command, step.done);
    }
}

export function initAiAuthoring() {
    for (const root of document.querySelectorAll('[data-ai-authoring]')) {
        const config = readJson(root, 'data-ai-authoring');
        const messages = readJson(root, 'data-ai-authoring-messages');
        if (!config || !messages || typeof config.urls?.proposals !== 'string') {
            continue;
        }
        new AiAuthoring(root, config, messages).start();
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAiAuthoring);
} else {
    initAiAuthoring();
}

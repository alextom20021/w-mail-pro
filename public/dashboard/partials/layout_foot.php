    </div>
</div>

<button class="ai-fab" title="Ask AI Assistant" data-bs-toggle="offcanvas" data-bs-target="#aiAssistant">
    <i class="bi bi-robot"></i>
</button>

<div class="offcanvas offcanvas-end" tabindex="-1" id="aiAssistant" style="width:400px;">
    <div class="offcanvas-header border-bottom">
        <h5 class="offcanvas-title"><i class="bi bi-robot text-primary me-2"></i>AI Assistant</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body d-flex flex-column">
        <div class="flex-grow-1 overflow-auto mb-3 small" id="aiChatLog">
            <div class="mb-3">
                <div class="bg-light rounded-3 p-3">
                    <strong>AI:</strong> Ask me about your deliverability, or tell me to adjust warm-up, clean a
                    list, or draft a template. Actions I take are logged and, depending on your autonomy setting,
                    may need your approval first.
                </div>
            </div>
        </div>
        <div id="aiPendingApprovals"></div>
        <form id="aiChatForm" class="input-group">
            <input type="text" class="form-control" id="aiChatInput" placeholder="Ask anything…" autocomplete="off">
            <button class="btn btn-primary" type="submit"><i class="bi bi-send"></i></button>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Minimal AI chat wiring — posts to the REST API using the client's own
// API key (fetched once via /dashboard/api_key.php, a session-authed
// endpoint that never exposes it outside this authenticated page).
let conversationId = null;

document.getElementById('aiChatForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const input = document.getElementById('aiChatInput');
    const message = input.value.trim();
    if (!message) return;

    const log = document.getElementById('aiChatLog');
    log.insertAdjacentHTML('beforeend', `<div class="mb-2 text-end"><div class="bg-primary text-white rounded-3 p-2 d-inline-block">${escapeHtml(message)}</div></div>`);
    input.value = '';
    log.scrollTop = log.scrollHeight;

    try {
        const res = await fetch('/dashboard/ai_chat_proxy.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ message, conversation_id: conversationId }),
        });
        const payload = await res.json();
        if (payload.data) {
            conversationId = payload.data.conversation_id;
            log.insertAdjacentHTML('beforeend', `<div class="mb-3"><div class="bg-light rounded-3 p-3"><strong>AI:</strong> ${escapeHtml(payload.data.reply)}</div></div>`);

            const pending = document.getElementById('aiPendingApprovals');
            (payload.data.pending_approvals || []).forEach(a => {
                pending.insertAdjacentHTML('beforeend', `
                    <div class="alert alert-warning py-2 px-3 small mb-2" id="approval-${a.audit_id}">
                        Pending approval: <strong>${escapeHtml(a.tool)}</strong> — <code>${escapeHtml(JSON.stringify(a.arguments))}</code>
                        <div class="mt-2 d-flex gap-2">
                            <button class="btn btn-sm btn-success" onclick="mailaiRespondApproval(${a.audit_id}, 'approve')">Approve</button>
                            <button class="btn btn-sm btn-outline-danger" onclick="mailaiRespondApproval(${a.audit_id}, 'reject')">Reject</button>
                        </div>
                    </div>`);
            });
        } else {
            log.insertAdjacentHTML('beforeend', `<div class="mb-3 text-danger small">Error: ${escapeHtml(payload.error || 'unknown error')}</div>`);
        }
    } catch (err) {
        log.insertAdjacentHTML('beforeend', `<div class="mb-3 text-danger small">Request failed.</div>`);
    }
    log.scrollTop = log.scrollHeight;
});

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

async function mailaiRespondApproval(auditId, action) {
    const el = document.getElementById(`approval-${auditId}`);
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    try {
        const res = await fetch('/dashboard/ai_approve.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ audit_id: auditId, action, csrf_token: csrfToken }),
        });
        const payload = await res.json();
        if (el) {
            el.className = payload.error ? 'alert alert-danger py-2 px-3 small mb-2' : 'alert alert-secondary py-2 px-3 small mb-2';
            el.innerHTML = payload.error
                ? `Error: ${escapeHtml(payload.error)}`
                : (action === 'approve' ? `Approved and executed: <code>${escapeHtml(JSON.stringify(payload.output || {}))}</code>` : 'Rejected.');
        }
    } catch (err) {
        if (el) el.innerHTML = 'Request failed.';
    }
}
</script>
</body>
</html>

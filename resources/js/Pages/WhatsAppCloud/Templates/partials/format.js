// Formatting/parsing helpers for the WhatsApp template panel.
// Ported from the standalone admin SPA (web/app.js).

/** Escape HTML so raw template text renders safely. */
export function escapeHtml(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
}

/** Format text WhatsApp-style: highlight {{n}} and apply *bold* _italic_ ~strike~. */
export function formatWa(text) {
    let h = escapeHtml(text)
    h = h.replace(/\{\{\s*(\d+)\s*\}\}/g, '<span class="b-var">{{$1}}</span>')
    h = h.replace(/\*(?=\S)([^*]+?)(?<=\S)\*/g, '<strong>$1</strong>')
    h = h.replace(/_(?=\S)([^_]+?)(?<=\S)_/g, '<em>$1</em>')
    h = h.replace(/~(?=\S)([^~]+?)(?<=\S)~/g, '<s>$1</s>')
    return h
}

/** Highest {{n}} variable index in the text (0 when there are none). */
export function maxVar(text) {
    const m = String(text || '').match(/\{\{\s*(\d+)\s*\}\}/g) || []
    return m.reduce((mx, tok) => Math.max(mx, parseInt(tok.replace(/\D/g, ''), 10) || 0), 0)
}

export function statusClass(s) {
    s = String(s || '').toUpperCase()
    if (s === 'APPROVED') return 'approved'
    if (s === 'REJECTED') return 'rejected'
    if (s === 'PENDING' || s === 'IN_APPEAL' || s === 'PENDING_DELETION') return 'pending'
    return 'paused'
}

export function statusLabel(s) {
    return String(s || '?').toUpperCase()
}

export function catClass(c) {
    return String(c || '').toLowerCase()
}

/**
 * Format an estimated cost. `conversation_analytics` returns a bare number in the
 * account's billing currency; pass the configured ISO code (e.g. 'BRL') to get a
 * localized currency string, or omit it for the plain number.
 */
export function formatMoney(value, currency) {
    const n = Number(value || 0)
    if (currency) {
        try {
            return new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(n)
        } catch {
            // Invalid currency code — fall back to the plain number.
        }
    }
    return n.toFixed(2)
}

/**
 * The `{{n}}` variable label for a 0-based index. Built in JS (not inline in the
 * template) because a literal `}}` inside a Vue mustache collides with the
 * interpolation delimiter.
 */
export function varLabel(i) {
    return `{{${i + 1}}}`
}

/** Flatten a Meta template (with its component array) into a form-friendly model. */
export function parseTemplate(t) {
    const model = {
        name: t.name || '',
        language: t.language || 'pt_BR',
        category: String(t.category || 'UTILITY').toUpperCase(),
        status: String(t.status || '').toUpperCase(),
        id: t.id || '',
        rejected_reason: t.rejected_reason || null,
        header: null,
        body: null,
        footer: null,
        buttons: [],
    }

    for (const c of t.components || []) {
        const type = String(c.type || '').toUpperCase()
        if (type === 'HEADER') {
            model.header = {
                format: String(c.format || 'TEXT').toUpperCase(),
                text: c.text || '',
                examples: (c.example && c.example.header_text) || [],
            }
        } else if (type === 'BODY') {
            model.body = {
                text: c.text || '',
                examples: (c.example && c.example.body_text && c.example.body_text[0]) || [],
            }
        } else if (type === 'FOOTER') {
            model.footer = { text: c.text || '' }
        } else if (type === 'BUTTONS') {
            model.buttons = (c.buttons || []).map((b) => ({
                type: String(b.type || '').toUpperCase(),
                text: b.text || '',
                url: b.url || '',
                phone_number: b.phone_number || '',
            }))
        }
    }
    return model
}

/**
 * Local mirror of the server guard-rails (TemplateInput/TemplateBuilder) so the
 * user gets instant feedback. The server still validates on submit.
 * Returns an error string, or null when the form is valid.
 */
export function validateForm(f) {
    if (!/^[a-z0-9_]+$/.test(f.name)) {
        return 'Nome inválido: use apenas letras minúsculas, números e _.'
    }
    if (f.body.trim() === '') {
        return 'O corpo do template é obrigatório.'
    }
    if (/^\s*\{\{\s*\d+\s*\}\}/.test(f.body)) {
        return 'O corpo não pode COMEÇAR com uma variável — ponha texto fixo antes.'
    }
    if (/\{\{\s*\d+\s*\}\}\s*$/.test(f.body)) {
        return 'O corpo não pode TERMINAR com uma variável — acrescente uma linha fixa depois.'
    }
    const nBody = maxVar(f.body)
    const filledBody = f.bodyExamples.filter((v) => v.trim() !== '').length
    if (nBody > 0 && filledBody !== nBody) {
        return `Preencha 1 exemplo para cada uma das ${nBody} variável(is) do corpo.`
    }
    for (const v of f.bodyExamples) {
        if (/[\n\t]|\s{4,}/.test(v)) return `Exemplo "${v}" tem quebra de linha/tab — a Meta proíbe.`
    }
    const nHeader = maxVar(f.header)
    if (nHeader > 1) return 'O cabeçalho aceita no máximo 1 variável.'
    if (nHeader === 1 && f.headerExamples.filter((v) => v.trim() !== '').length < 1) {
        return 'Informe o exemplo da variável do cabeçalho.'
    }
    for (const b of f.buttons) {
        if (b.type === 'URL' && !b.url) return `Botão de URL "${b.text}" sem endereço.`
        if (b.type === 'PHONE_NUMBER' && !b.phone_number) return `Botão de telefone "${b.text}" sem número.`
    }
    return null
}

/* Self-contained on purpose.
 *
 * The template panel has its own copy of these helpers, but that copy is
 * PUBLISHED — once the host runs vendor:publish it belongs to them, and they are
 * free to rewrite it. Importing across would be depending on code that is no
 * longer ours. So: copied, not imported. */

export function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
}

/** WhatsApp's own markup: *bold*, _italic_, ~strike~, ```mono```.
 *  Goes through v-html — escapeHtml() first, and only ever into a text node. */
export function formatWa(text) {
    return escapeHtml(text)
        .replace(/```([^`]+)```/g, '<code>$1</code>')
        .replace(/\*([^*\n]+)\*/g, '<strong>$1</strong>')
        .replace(/_([^_\n]+)_/g, '<em>$1</em>')
        .replace(/~([^~\n]+)~/g, '<s>$1</s>')
        .replace(/\n/g, '<br>')
}

export function initials(name) {
    return String(name ?? '?')
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((word) => word[0] ?? '')
        .join('')
        .toUpperCase()
}

/** Stable colour per contact, derived rather than stored — the sandbox has no
 *  business keeping a colour column in a database. */
export function colorFor(waId) {
    const palette = ['#0e6f63', '#2d6ee0', '#7a52cf', '#b6801a', '#d1414a', '#1f9d55']
    let hash = 0
    for (const char of String(waId ?? '')) hash = (hash * 31 + char.charCodeAt(0)) >>> 0
    return palette[hash % palette.length]
}

export function clockOf(iso) {
    if (!iso) return ''
    return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

export function dayOf(iso) {
    if (!iso) return ''
    const date = new Date(iso)
    const today = new Date()
    const sameDay = date.toDateString() === today.toDateString()
    return sameDay ? 'Hoje' : date.toLocaleDateString()
}

/** ✓ sent · ✓✓ delivered · ✓✓ (blue) read · ! failed */
export function tickOf(status) {
    return { sent: '✓', delivered: '✓✓', read: '✓✓', failed: '!' }[status] ?? ''
}

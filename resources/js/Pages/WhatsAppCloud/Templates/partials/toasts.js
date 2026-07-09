// Tiny shared toast store for the panel. Imported wherever a component needs to
// signal success/failure, so we avoid prop drilling across the modals.
import { reactive } from 'vue'

let seq = 0

export const toasts = reactive([])

export function pushToast(message, type = 'ok', extraHtml = '') {
    const id = ++seq
    toasts.push({ id, message, type, extraHtml })
    setTimeout(
        () => {
            const i = toasts.findIndex((t) => t.id === id)
            if (i !== -1) toasts.splice(i, 1)
        },
        type === 'err' ? 8000 : 5000,
    )
}

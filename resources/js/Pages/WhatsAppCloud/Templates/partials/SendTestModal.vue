<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { maxVar, parseTemplate, statusClass, statusLabel, varLabel } from './format'
import { pushToast } from './toasts'

const props = defineProps({
    open: { type: Boolean, default: false },
    template: { type: Object, default: null },
    panelUrl: { type: String, required: true },
})

const emit = defineEmits(['close', 'sent'])

const dialog = ref(null)
const localError = ref('')
const serverError = ref('')
const submitting = ref(false)

const state = reactive({
    name: '',
    language: 'pt_BR',
    to: '',
    params: [],
    status: '',
})

const model = computed(() => (props.template ? parseTemplate(props.template) : null))

watch(
    () => props.open,
    (isOpen) => {
        const el = dialog.value
        if (!el) return
        if (isOpen && props.template) {
            const m = parseTemplate(props.template)
            state.name = m.name
            state.language = m.language
            state.status = m.status
            state.to = ''
            localError.value = ''
            serverError.value = ''
            const n = m.body ? maxVar(m.body.text) : 0
            state.params = []
            for (let i = 0; i < n; i++) {
                state.params.push((m.body.examples && m.body.examples[i]) || '')
            }
            if (!el.open) el.showModal()
        } else if (el.open) {
            el.close()
        }
    },
)

function submit() {
    const to = String(state.to).replace(/\D+/g, '')
    if (!/^\d{8,15}$/.test(to)) {
        localError.value = 'Número inválido (só dígitos, com DDI e DDD).'
        return
    }
    localError.value = ''
    serverError.value = ''
    submitting.value = true

    router.post(
        `${props.panelUrl}/send`,
        { name: state.name, to, params: state.params, language: state.language.trim() || 'pt_BR' },
        {
            preserveScroll: true,
            onSuccess: (page) => {
                const sentId = page?.props?.flash?.sent_id
                pushToast(`Mensagem enviada para ${to}.`, 'ok', sentId ? `<br><code>${sentId}</code>` : '')
                emit('sent')
            },
            onError: (errors) => {
                serverError.value = errors.form || errors.meta || 'Falha ao enviar a mensagem.'
            },
            onFinish: () => {
                submitting.value = false
            },
        },
    )
}
</script>

<template>
    <dialog ref="dialog" class="wa-panel-dialog" @close="emit('close')" @click.self="emit('close')">
        <template v-if="model">
            <div class="modal-head">
                <h2>Enviar teste</h2>
                <button class="btn ghost sm x" @click="emit('close')">✕</button>
            </div>
            <div class="modal-body">
                <p class="hint" style="margin-top: 0">
                    Template <b>{{ model.name }}</b> · {{ model.language }} ·
                    <span class="badge" :class="statusClass(model.status)">{{ statusLabel(model.status) }}</span>
                </p>
                <div class="form-grid">
                    <div class="form-field">
                        <label class="lbl">Número de destino</label>
                        <input v-model="state.to" type="text" placeholder="5548999999999" autocomplete="off" inputmode="numeric" />
                        <span class="hint">Só dígitos, com DDI (55) e DDD.</span>
                    </div>
                    <div class="form-field">
                        <label class="lbl">Idioma</label>
                        <input v-model="state.language" type="text" autocomplete="off" />
                    </div>
                    <div v-if="state.params.length" class="form-field full">
                        <label class="lbl">Variáveis do corpo</label>
                        <div class="examples-row">
                            <div v-for="(_, i) in state.params" :key="i" class="ex">
                                <span>{{ varLabel(i) }}</span>
                                <input v-model="state.params[i]" type="text" autocomplete="off" />
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-foot">
                <span class="hint err" style="margin-right: auto">{{ localError || serverError }}</span>
                <button class="btn" @click="emit('close')">Cancelar</button>
                <button class="btn primary" :disabled="submitting" @click="submit">✈ Enviar</button>
            </div>
        </template>
    </dialog>
</template>

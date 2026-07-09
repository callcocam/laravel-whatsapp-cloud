<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { maxVar, parseTemplate, validateForm, varLabel } from './format'
import { pushToast } from './toasts'
import WhatsAppPreview from './WhatsAppPreview.vue'

const props = defineProps({
    open: { type: Boolean, default: false },
    mode: { type: String, default: 'create' }, // 'create' | 'edit'
    template: { type: Object, default: null },
    panelUrl: { type: String, required: true },
})

const emit = defineEmits(['close', 'saved'])

const dialog = ref(null)
const editId = ref(null)
const localError = ref('')
const serverError = ref('')
const submitting = ref(false)

const form = reactive({
    name: '',
    language: 'pt_BR',
    category: 'UTILITY',
    header: '',
    headerExamples: [],
    body: '',
    bodyExamples: [],
    footer: '',
    buttons: [],
})

const isEdit = computed(() => props.mode === 'edit')

// Bound so Vue doesn't try to interpolate the {{1}}/{{2}} tokens as mustaches.
const bodyPlaceholder = 'Olá, {{1}}! Sua reunião é dia {{2}}. Até lá!'
const insertVarLabel = '+ Inserir {{n}}'

const submitLabel = computed(() =>
    submitting.value
        ? 'Enviando…'
        : isEdit.value
            ? 'Salvar (volta p/ análise)'
            : 'Criar (envia p/ análise)',
)

function resetForm() {
    form.name = ''
    form.language = 'pt_BR'
    form.category = 'UTILITY'
    form.header = ''
    form.headerExamples = []
    form.body = ''
    form.bodyExamples = []
    form.footer = ''
    form.buttons = []
    editId.value = null
    localError.value = ''
    serverError.value = ''
}

function fillForm(m) {
    form.name = m.name
    form.language = m.language
    form.category = m.category
    form.header = m.header && m.header.format === 'TEXT' ? m.header.text : ''
    form.headerExamples = m.header && m.header.examples ? [...m.header.examples] : []
    form.body = m.body ? m.body.text : ''
    form.bodyExamples = m.body && m.body.examples ? [...m.body.examples] : []
    form.footer = m.footer ? m.footer.text : ''
    form.buttons = m.buttons.map((b) => ({ ...b }))
}

// Keep the per-variable example inputs in sync with the {{n}} count in body/header.
watch(
    () => form.body,
    () => syncExamples('body'),
)
watch(
    () => form.header,
    () => syncExamples('header'),
)

function syncExamples(which) {
    const source = which === 'body' ? form.body : form.header
    const key = which === 'body' ? 'bodyExamples' : 'headerExamples'
    const n = maxVar(source)
    const next = []
    for (let i = 0; i < n; i++) next.push(form[key][i] ?? '')
    form[key] = next
}

function insertVar() {
    const next = maxVar(form.body) + 1
    form.body = `${form.body}{{${next}}}`
}

function addButton(type = 'QUICK_REPLY') {
    form.buttons.push({ type, text: '', url: '', phone_number: '' })
}

function removeButton(i) {
    form.buttons.splice(i, 1)
}

const previewButtons = computed(() => form.buttons)

watch(
    () => props.open,
    (isOpen) => {
        const el = dialog.value
        if (!el) return
        if (isOpen) {
            resetForm()
            if (isEdit.value && props.template) {
                const m = parseTemplate(props.template)
                editId.value = m.id
                fillForm(m)
                if (m.header && m.header.format !== 'TEXT') {
                    pushToast(
                        `Cabeçalho de mídia (${m.header.format}) não é editável por aqui e será removido se salvar.`,
                        'err',
                    )
                }
            }
            if (!el.open) el.showModal()
        } else if (el.open) {
            el.close()
        }
    },
)

function submit() {
    const err = validateForm(form)
    localError.value = err || ''
    if (err) return

    serverError.value = ''
    submitting.value = true

    const payload = {
        name: form.name.trim(),
        language: form.language,
        category: form.category,
        header: form.header.trim(),
        headerExamples: form.headerExamples,
        body: form.body,
        bodyExamples: form.bodyExamples,
        footer: form.footer.trim(),
        buttons: form.buttons
            .map((b) => {
                const out = { type: b.type, text: (b.text || '').trim() }
                if (b.type === 'URL') out.url = (b.url || '').trim()
                else if (b.type === 'PHONE_NUMBER') out.phone_number = (b.phone_number || '').trim()
                return out
            })
            .filter((b) => b.text !== ''),
    }

    const url = editId.value ? `${props.panelUrl}/${encodeURIComponent(editId.value)}/edit` : props.panelUrl

    router.post(url, payload, {
        preserveScroll: true,
        onSuccess: () => {
            pushToast(
                editId.value
                    ? `Template "${payload.name}" enviado para nova análise.`
                    : `Template "${payload.name}" criado e enviado para análise.`,
            )
            emit('saved')
        },
        onError: (errors) => {
            serverError.value = errors.form || errors.meta || 'Não foi possível salvar o template.'
        },
        onFinish: () => {
            submitting.value = false
        },
    })
}
</script>

<template>
    <dialog ref="dialog" class="wa-panel-dialog" @close="emit('close')" @click.self="emit('close')">
        <div class="modal-head">
            <h2>{{ isEdit ? 'Editar template' : 'Novo template' }}</h2>
            <button class="btn ghost sm x" @click="emit('close')">✕</button>
        </div>
        <div class="modal-body">
            <div v-if="isEdit" class="banner">
                ⚠️ Editar reseta o template para <b>PENDING</b> (nova análise). Nome e idioma não podem mudar.
            </div>
            <div class="builder-cols">
                <div>
                    <div class="form-grid">
                        <div class="form-field">
                            <label class="lbl">Nome</label>
                            <input v-model="form.name" type="text" placeholder="coordena_lembrete" autocomplete="off" :disabled="isEdit" />
                            <span class="hint">a–z, 0–9 e _ (imutável após criar)</span>
                        </div>
                        <div class="form-field">
                            <label class="lbl">Idioma</label>
                            <select v-model="form.language" :disabled="isEdit">
                                <option value="pt_BR">pt_BR — Português (BR)</option>
                                <option value="pt_PT">pt_PT — Português (PT)</option>
                                <option value="en_US">en_US — Inglês (US)</option>
                                <option value="es_ES">es_ES — Espanhol</option>
                            </select>
                        </div>
                        <div class="form-field">
                            <label class="lbl">Categoria</label>
                            <select v-model="form.category">
                                <option value="UTILITY">UTILITY — transacional</option>
                                <option value="MARKETING">MARKETING — promocional</option>
                                <option value="AUTHENTICATION">AUTHENTICATION — códigos</option>
                            </select>
                        </div>
                        <div class="form-field">
                            <label class="lbl">Cabeçalho <span class="hint">(opcional)</span></label>
                            <input v-model="form.header" type="text" placeholder="texto do cabeçalho" autocomplete="off" />
                        </div>
                        <div v-if="form.headerExamples.length" class="form-field full">
                            <label class="lbl">Exemplo do cabeçalho</label>
                            <div class="examples-row">
                                <div v-for="(_, i) in form.headerExamples" :key="i" class="ex">
                                    <span>{{ varLabel(i) }}</span>
                                    <input v-model="form.headerExamples[i]" type="text" placeholder="exemplo" autocomplete="off" />
                                </div>
                            </div>
                        </div>

                        <div class="form-field full">
                            <label class="lbl">Corpo</label>
                            <textarea v-model="form.body" :placeholder="bodyPlaceholder" />
                            <div class="insertvar">
                                <button type="button" class="btn sm" @click="insertVar">{{ insertVarLabel }}</button>
                                <span class="hint">Não comece nem termine o corpo com uma variável.</span>
                            </div>
                        </div>
                        <div v-if="form.bodyExamples.length" class="form-field full">
                            <label class="lbl">Exemplos das variáveis <span class="hint">(1 por variável)</span></label>
                            <div class="examples-row">
                                <div v-for="(_, i) in form.bodyExamples" :key="i" class="ex">
                                    <span>{{ varLabel(i) }}</span>
                                    <input v-model="form.bodyExamples[i]" type="text" placeholder="exemplo" autocomplete="off" />
                                </div>
                            </div>
                        </div>

                        <div class="form-field full">
                            <label class="lbl">Rodapé <span class="hint">(opcional, sem variáveis)</span></label>
                            <input v-model="form.footer" type="text" placeholder="Coordena" autocomplete="off" />
                        </div>

                        <div class="form-field full">
                            <label class="lbl">Botões <span class="hint">(opcional)</span></label>
                            <div class="button-list">
                                <div v-for="(b, i) in form.buttons" :key="i" class="button-item">
                                    <select v-model="b.type">
                                        <option value="QUICK_REPLY">Resposta</option>
                                        <option value="URL">URL</option>
                                        <option value="PHONE_NUMBER">Telefone</option>
                                    </select>
                                    <input v-model="b.text" type="text" placeholder="Texto do botão" />
                                    <input v-if="b.type === 'URL'" v-model="b.url" type="text" placeholder="https://exemplo.com" />
                                    <input v-else-if="b.type === 'PHONE_NUMBER'" v-model="b.phone_number" type="text" placeholder="+5548999999999" />
                                    <button class="iconbtn danger" title="Remover botão" @click="removeButton(i)">🗑️</button>
                                </div>
                            </div>
                            <div class="btn-row" style="margin-top: 8px">
                                <button type="button" class="btn sm" @click="addButton('QUICK_REPLY')">+ Resposta rápida</button>
                                <button type="button" class="btn sm" @click="addButton('URL')">+ URL</button>
                                <button type="button" class="btn sm" @click="addButton('PHONE_NUMBER')">+ Telefone</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="preview-pane">
                    <label class="lbl" style="display: block; margin-bottom: 8px">Pré-visualização</label>
                    <WhatsAppPreview :header="form.header" :body="form.body" :footer="form.footer" :buttons="previewButtons" />
                    <p class="hint" style="margin-top: 10px">
                        A validação final é da Meta. A categoria pode ser reclassificada por conteúdo.
                    </p>
                </div>
            </div>
        </div>
        <div class="modal-foot">
            <span class="hint err" style="margin-right: auto">{{ localError || serverError }}</span>
            <button class="btn" @click="emit('close')">Cancelar</button>
            <button class="btn primary" :disabled="submitting" @click="submit">{{ submitLabel }}</button>
        </div>
    </dialog>
</template>

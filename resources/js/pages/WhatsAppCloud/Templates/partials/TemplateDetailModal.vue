<script setup>
import { computed, ref, watch } from 'vue'
import { catClass, formatWa, parseTemplate } from './format'
import StatusBadge from './StatusBadge.vue'

const props = defineProps({
    template: { type: Object, default: null },
    open: { type: Boolean, default: false },
})

const emit = defineEmits(['close', 'send'])

const dialog = ref(null)

const model = computed(() => (props.template ? parseTemplate(props.template) : null))

watch(
    () => props.open,
    (isOpen) => {
        const el = dialog.value
        if (!el) return
        if (isOpen && !el.open) el.showModal()
        if (!isOpen && el.open) el.close()
    },
)

function buttonLine(b) {
    let extra = ''
    if (b.type === 'URL') extra = ` → ${b.url}`
    else if (b.type === 'PHONE_NUMBER') extra = ` → ${b.phone_number}`
    return `• [${b.type}] ${b.text}${extra}`
}

const buttonsText = computed(() =>
    model.value ? model.value.buttons.map(buttonLine).join('\n') : '',
)
</script>

<template>
    <dialog ref="dialog" class="wa-panel-dialog" @close="emit('close')" @click.self="emit('close')">
        <template v-if="model">
            <div class="modal-head">
                <h2>{{ model.name }}</h2>
                <button class="btn ghost sm x" @click="emit('close')">✕</button>
            </div>
            <div class="modal-body">
                <div class="detail-meta">
                    <div class="m">
                        <span class="k">Status</span>
                        <span class="v"><StatusBadge :status="model.status" /></span>
                    </div>
                    <div class="m">
                        <span class="k">Categoria</span>
                        <span class="v"><span class="cat" :class="catClass(model.category)">{{ model.category }}</span></span>
                    </div>
                    <div class="m">
                        <span class="k">Idioma</span>
                        <span class="v">{{ model.language }}</span>
                    </div>
                    <div class="m">
                        <span class="k">ID</span>
                        <span class="v">{{ model.id || '—' }}</span>
                    </div>
                </div>

                <div v-if="model.status === 'REJECTED' && model.rejected_reason" class="reject-box">
                    <div class="rt">✖ Motivo da rejeição</div>
                    <div>{{ model.rejected_reason }}</div>
                </div>

                <div v-if="model.header" class="comp-block">
                    <div class="ct">Cabeçalho</div>
                    <div v-if="model.header.format === 'TEXT'" class="cv" v-html="formatWa(model.header.text)" />
                    <div v-else class="cv">(cabeçalho de mídia: {{ model.header.format }})</div>
                </div>

                <div v-if="model.body" class="comp-block">
                    <div class="ct">Corpo</div>
                    <div class="cv" v-html="formatWa(model.body.text)" />
                </div>
                <div v-if="model.body && model.body.examples && model.body.examples.length" class="comp-block">
                    <div class="ct">Exemplos</div>
                    <div class="cv">{{ model.body.examples.join('  ·  ') }}</div>
                </div>

                <div v-if="model.footer" class="comp-block">
                    <div class="ct">Rodapé</div>
                    <div class="cv">{{ model.footer.text }}</div>
                </div>

                <div v-if="model.buttons.length" class="comp-block">
                    <div class="ct">Botões</div>
                    <div class="cv">{{ buttonsText }}</div>
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn" @click="emit('close')">Fechar</button>
                <button class="btn primary" @click="emit('send', template)">✈ Enviar teste</button>
            </div>
        </template>
    </dialog>
</template>

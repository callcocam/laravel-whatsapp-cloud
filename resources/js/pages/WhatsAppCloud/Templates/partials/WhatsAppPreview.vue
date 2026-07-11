<script setup>
import { computed } from 'vue'
import { formatWa } from './format'

const props = defineProps({
    header: { type: String, default: '' },
    body: { type: String, default: '' },
    footer: { type: String, default: '' },
    buttons: { type: Array, default: () => [] },
})

const headerHtml = computed(() => formatWa(props.header))
const bodyHtml = computed(() => formatWa(props.body || '…'))

const visibleButtons = computed(() => props.buttons.filter((b) => (b.text || '').trim() !== ''))

function buttonIcon(type) {
    if (type === 'URL') return '🔗 '
    if (type === 'PHONE_NUMBER') return '📞 '
    return '↩️ '
}
</script>

<template>
    <div class="phone">
        <div class="bubble">
            <div v-if="header" class="b-header" v-html="headerHtml" />
            <div class="b-body" v-html="bodyHtml" />
            <div v-if="footer" class="b-footer">{{ footer }}</div>
            <span class="b-time">12:00 ✓✓</span>
        </div>
        <div v-if="visibleButtons.length" class="bubble-buttons">
            <div v-for="(b, i) in visibleButtons" :key="i" class="bb">
                {{ buttonIcon(b.type) }}{{ b.text || 'Botão' }}
            </div>
        </div>
    </div>
</template>

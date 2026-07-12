<script setup>
import { computed } from 'vue'
import { clockOf, formatWa, tickOf } from './format'

const props = defineProps({
    message: { type: Object, required: true },
    selected: { type: Boolean, default: false },
})

const emit = defineEmits(['tap', 'inspect'])

const out = computed(() => props.message.direction === 'outbound')

/* URL and phone buttons open something on the device — Meta never tells you they
 * were pressed. Showing them as tappable would invent a webhook that does not
 * exist. */
const buttons = computed(() =>
    (props.message.template?.buttons ?? []).map((button) => ({
        text: button.text,
        tappable: button.type === 'QUICK_REPLY',
        hint: button.type === 'URL' ? '↗' : button.type === 'PHONE_NUMBER' ? '☎' : '',
    })),
)

/* Only a message WE sent can be replied to. */
const canTap = computed(() => out.value && props.message.status !== 'failed')
</script>

<template>
    <div class="row" :class="out ? 'out' : 'in'">
        <div
            class="bubble"
            :class="{ 'is-on': selected, failed: message.status === 'failed' }"
            @click="emit('inspect', message)"
        >
            <span v-if="message.template" class="b-tag">Template · {{ message.template.name }}</span>

            <div v-if="message.template?.header" class="b-header" v-html="formatWa(message.template.header)" />

            <div class="b-body" v-html="formatWa(message.text)" />

            <div v-if="message.template?.footer" class="b-footer">{{ message.template.footer }}</div>

            <!-- Tapping fires the webhook Meta would fire — and each shape is a
                 DIFFERENT webhook: a template button comes back as type:button, an
                 interactive button as interactive.button_reply, a list row as
                 interactive.list_reply. That is why each option carries its kind. -->
            <div v-if="buttons.length" class="bubble-buttons" @click.stop>
                <button
                    v-for="button in buttons"
                    :key="button.text"
                    class="bb"
                    :disabled="!button.tappable || !canTap"
                    :title="button.tappable ? 'Responder como o contato' : 'Meta não avisa quando este botão é tocado'"
                    @click="emit('tap', { kind: 'template', text: button.text, reply_to: message.wamid })"
                >
                    {{ button.hint }} {{ button.text }}
                </button>
            </div>

            <div v-if="message.options?.length" class="bubble-buttons" @click.stop>
                <button
                    v-for="option in message.options"
                    :key="option.id"
                    class="bb"
                    :disabled="!canTap"
                    @click="emit('tap', { kind: option.kind, id: option.id, text: option.title, reply_to: message.wamid })"
                >
                    {{ option.title }}
                </button>
            </div>

            <div v-for="warning in message.warnings" :key="warning" class="b-warn">⚠ {{ warning }}</div>

            <div class="b-meta">
                <span>{{ clockOf(message.at) }}</span>
                <span
                    v-if="out"
                    class="b-tick"
                    :class="{ read: message.status === 'read', failed: message.status === 'failed' }"
                >{{ tickOf(message.status) }}</span>
                <span v-if="message.error_code" class="b-tick failed">{{ message.error_code }}</span>
            </div>
        </div>
    </div>
</template>

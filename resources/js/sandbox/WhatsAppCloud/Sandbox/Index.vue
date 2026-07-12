<script setup>
import { router, usePage } from '@inertiajs/vue3'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import Bubble from './partials/Bubble.vue'
import Inspector from './partials/Inspector.vue'
import { colorFor, dayOf, initials } from './partials/format'
import './partials/sandbox.css'

const props = defineProps({
    conversations: { type: Array, default: () => [] },
    selected: { type: Number, default: null },
    messages: { type: Array, default: () => [] },
    faults: { type: Array, default: () => [] },
    templates: { type: Array, default: () => [] },
    business: { type: Object, required: true },
})

const page = usePage()
const panelUrl = computed(() => new URL(page.url, window.location.origin).pathname.replace(/\/$/, ''))

/* Server state, refreshed by polling. Inertia props are the first snapshot. */
const state = ref({
    conversations: props.conversations,
    selected: props.selected,
    messages: props.messages,
    faults: props.faults,
    templates: props.templates,
})

const active = computed(() => state.value.conversations.find((c) => c.id === state.value.selected) ?? null)
const inspected = ref(null)
const stream = ref(null)

/* ---------- polling ----------
 * A queued listener sends from a WORKER process — its message lands in the
 * database seconds later and never in a response to anything we did. Polling is
 * the only way to see it, and it keeps the package free of a broadcast
 * dependency. */
let timer = null

async function poll() {
    const id = state.value.selected
    const response = await fetch(`${panelUrl.value}/state${id ? `?conversation=${id}` : ''}`, {
        headers: { Accept: 'application/json' },
    })
    if (!response.ok) return

    const fresh = await response.json()
    const grew = fresh.messages.length !== state.value.messages.length

    state.value = fresh
    if (grew) nextTick(scrollDown)
}

onMounted(() => {
    timer = setInterval(poll, 1500)
    scrollDown()
})

onBeforeUnmount(() => clearInterval(timer))

function scrollDown() {
    if (stream.value) stream.value.scrollTop = stream.value.scrollHeight
}

watch(() => state.value.selected, () => {
    inspected.value = null
    nextTick(scrollDown)
})

/* ---------- acting ---------- */

const act = (path, data = {}) =>
    router.post(`${panelUrl.value}/${path}`, { conversation: state.value.selected, ...data }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: poll,
    })

function select(id) {
    state.value.selected = id
    poll()
}

/* Who you are typing as. The contact is the default — the SYSTEM side is normally
 * driven by the app's own code, and only needs a manual escape hatch. */
const speaker = ref('contact')
const draft = ref('')

function say() {
    const text = draft.value.trim()
    if (!text) return
    draft.value = ''

    /* Answering the bubble you have open, when you have one open — that is what
     * puts context.id on the webhook. */
    const replyTo = inspected.value?.direction === 'outbound' ? inspected.value.wamid : null

    speaker.value === 'contact'
        ? act('reply', { text, reply_to: replyTo })
        : act('send-text', { text })
}

/* ---------- sending a template as the system ---------- */

const picked = ref(null)
const params = ref([])

const template = computed(() => state.value.templates.find((t) => t.name === picked.value) ?? null)

watch(template, (value) => {
    params.value = Array.from({ length: value?.variables ?? 0 }, () => '')
})

function fire() {
    if (!picked.value) return
    act('send-template', {
        name: picked.value,
        language: template.value?.language ?? 'pt_BR',
        params: params.value,
    })
    picked.value = null
}

/* ---------- adding people ---------- */

const adding = ref(false)
const person = ref({ wa_id: '', name: '', role: 'customer' })

function add() {
    router.post(`${panelUrl.value}/participants`, person.value, {
        preserveScroll: true,
        onSuccess: () => {
            adding.value = false
            person.value = { wa_id: '', name: '', role: 'customer' }
            poll()
        },
    })
}

const flash = computed(() => page.props.flash ?? {})

/* Date separators, so a long rehearsal stays readable. */
const withDays = computed(() => {
    let last = null
    return state.value.messages.map((message) => {
        const day = dayOf(message.at)
        const first = day !== last
        last = day
        return { message, day: first ? day : null }
    })
})
</script>

<template>
    <div class="wa-sandbox">
        <header class="sb-top">
            <span class="sb-title">Sandbox WhatsApp</span>
            <span class="sb-badge">Nada sai daqui</span>
            <span class="sb-num">
                {{ business.display_phone_number }} · pnid {{ business.phone_number_id }}
            </span>
            <button class="btn tiny danger" @click="act('reset')">Limpar tudo</button>
        </header>

        <div class="sb-body">
            <!-- ---------- participants ---------- -->
            <nav class="sb-people">
                <button
                    v-for="conversation in state.conversations"
                    :key="conversation.id"
                    class="sb-person"
                    :class="{ 'is-on': conversation.id === state.selected }"
                    @click="select(conversation.id)"
                >
                    <span class="sb-avatar" :style="{ background: colorFor(conversation.wa_id) }">
                        {{ initials(conversation.name) }}
                    </span>
                    <span class="sb-person-main">
                        <span class="sb-person-name">{{ conversation.name }}</span>
                        <span class="sb-person-sub">
                            <span class="sb-dot" :class="conversation.window_open ? 'open' : 'shut'" />
                            {{ conversation.window_open ? 'janela aberta' : 'janela fechada' }}
                        </span>
                    </span>
                    <span v-if="conversation.role" class="sb-role">{{ conversation.role }}</span>
                </button>

                <div style="padding: 12px">
                    <template v-if="adding">
                        <input v-model="person.name" class="sb-field" placeholder="Nome (ex.: Maria)">
                        <input v-model="person.wa_id" class="sb-field" placeholder="Número (5548999999999)">
                        <select v-model="person.role" class="sb-field">
                            <option value="customer">Cliente</option>
                            <option value="operator">Operador / responsável</option>
                            <option value="other">Outro</option>
                        </select>
                        <div class="sb-row">
                            <button class="btn primary" @click="add">Adicionar</button>
                            <button class="btn" @click="adding = false">Cancelar</button>
                        </div>
                    </template>
                    <button v-else class="btn" style="width: 100%" @click="adding = true">
                        + Participante
                    </button>
                </div>
            </nav>

            <!-- ---------- the chat ---------- -->
            <main class="sb-chat">
                <template v-if="active">
                    <div class="sb-chat-top">
                        <span class="sb-avatar" :style="{ background: colorFor(active.wa_id) }">
                            {{ initials(active.name) }}
                        </span>
                        <span>
                            <span class="sb-chat-name">{{ active.name }}</span><br>
                            <span class="sb-chat-sub">{{ active.wa_id }}</span>
                        </span>
                    </div>

                    <div v-if="flash.error" class="sb-alert" :class="{ terminal: flash.terminal }">
                        {{ flash.error }}
                    </div>

                    <div ref="stream" class="sb-stream">
                        <p v-if="!state.messages.length" class="sb-empty">
                            Dispare um template pelo app — ou pelo seletor aqui embaixo — e ele
                            aparece nesta conversa. Depois responda como {{ active.name }}: o webhook
                            entra pela rota real, assinado, e os listeners do app rodam de verdade.
                        </p>

                        <template v-for="({ message, day }) in withDays" :key="message.id">
                            <div v-if="day" class="sb-day">{{ day }}</div>
                            <Bubble
                                :message="message"
                                :selected="inspected?.id === message.id"
                                @inspect="inspected = $event"
                                @tap="act('tap', $event)"
                            />
                        </template>
                    </div>

                    <footer class="sb-composer">
                        <div class="sb-as">
                            <button :class="{ 'is-on': speaker === 'contact' }" @click="speaker = 'contact'">
                                falar como {{ active.name }}
                            </button>
                            <button :class="{ 'is-on': speaker === 'system' }" @click="speaker = 'system'">
                                falar como o sistema
                            </button>
                        </div>

                        <div class="sb-input">
                            <input
                                v-model="draft"
                                :placeholder="speaker === 'contact'
                                    ? 'Responder como o contato…'
                                    : 'Texto livre do sistema (exige a janela aberta)…'"
                                @keyup.enter="say"
                            >
                            <button class="btn primary" :disabled="!draft.trim()" @click="say">Enviar</button>
                        </div>

                        <div class="sb-row" style="margin-top: 8px">
                            <select v-model="picked" class="sb-field" style="flex: 1; margin: 0; width: auto">
                                <option :value="null">Disparar um template como o sistema…</option>
                                <option v-for="item in state.templates" :key="item.name" :value="item.name">
                                    {{ item.name }} ({{ item.language }})
                                </option>
                            </select>
                            <input
                                v-for="(_, index) in params"
                                :key="index"
                                v-model="params[index]"
                                class="sb-field"
                                style="flex: 1; margin: 0; width: auto"
                                :placeholder="`{{${index + 1}}}`"
                            >
                            <button class="btn" :disabled="!picked" @click="fire">Disparar</button>
                        </div>
                        <p v-if="!state.templates.length" class="sb-note">
                            Nenhum template encontrado. Aponte <code>definitions_path</code> pra pasta
                            dos arquivos de definição — eles funcionam aqui antes de irem pra Meta.
                        </p>
                    </footer>
                </template>

                <p v-else class="sb-empty">
                    Adicione um participante pra começar. Um cliente e um responsável já bastam pra
                    encenar o handoff inteiro.
                </p>
            </main>

            <Inspector
                :message="inspected"
                :conversation="active"
                :faults="state.faults"
                :panel-url="panelUrl"
                @arm="act('faults', { fault: $event })"
                @close-window="act('close-window')"
                @status="act('status', { message: inspected.id, ...$event })"
            />
        </div>
    </div>
</template>

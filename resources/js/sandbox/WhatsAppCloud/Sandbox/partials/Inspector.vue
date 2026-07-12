<script setup>
import { computed, ref, watch } from 'vue'

const props = defineProps({
    message: { type: Object, default: null },
    conversation: { type: Object, default: null },
    faults: { type: Array, default: () => [] },
    panelUrl: { type: String, required: true },
})

const emit = defineEmits(['arm', 'close-window', 'status'])

const tab = ref('wire')
const detail = ref(null)

/* The raw payloads are NOT in the polled state — they would be hundreds of KB a
 * second. Fetched for one message, on click. */
watch(
    () => props.message?.id,
    async (id) => {
        detail.value = null
        if (!id) return
        const response = await fetch(`${props.panelUrl}/messages/${id}`, {
            headers: { Accept: 'application/json' },
        })
        if (response.ok) detail.value = await response.json()
    },
    { immediate: true },
)

const pretty = (value) => JSON.stringify(value, null, 2)

const listeners = computed(() => detail.value?.meta?.listeners ?? [])
const failure = computed(() => detail.value?.meta?.failure ?? null)
const out = computed(() => props.message?.direction === 'outbound')
</script>

<template>
    <aside class="sb-inspector">
        <div class="sb-tabs">
            <button :class="{ 'is-on': tab === 'wire' }" @click="tab = 'wire'">Rede</button>
            <button :class="{ 'is-on': tab === 'faults' }" @click="tab = 'faults'">Falhas</button>
        </div>

        <div v-if="tab === 'wire'" class="sb-pane">
            <p v-if="!message" class="sb-note">
                Clique numa bolha para ver o que realmente passou no fio: o envelope que
                enviamos, o webhook que voltou, a assinatura e quem escutou.
            </p>

            <template v-else>
                <div class="sb-kv"><span>wamid</span><span>{{ message.wamid }}</span></div>
                <div class="sb-kv"><span>tipo</span><span>{{ message.type }}</span></div>
                <div class="sb-kv"><span>direção</span><span>{{ message.direction }}</span></div>
                <div v-if="message.status" class="sb-kv"><span>status</span><span>{{ message.status }}</span></div>
                <div v-if="message.template" class="sb-kv">
                    <span>corpo do template</span>
                    <span>{{ message.template.source === 'definition' ? 'arquivo local' : 'Meta' }}</span>
                </div>

                <!-- The reason the simulator calls the controller directly instead of
                     re-entering the kernel: the kernel would have swallowed this. -->
                <template v-if="failure">
                    <div class="sb-h">Um listener do app lançou</div>
                    <div class="sb-fail">
                        <strong>{{ failure.class }}</strong><br>
                        {{ failure.message }}<br>
                        <small>{{ failure.file }}</small>
                    </div>
                </template>

                <template v-if="detail?.envelope">
                    <div class="sb-h">Envelope enviado (POST /messages)</div>
                    <pre>{{ pretty(detail.envelope) }}</pre>
                </template>

                <template v-if="detail?.inbound_payload">
                    <div class="sb-h">Webhook recebido</div>
                    <pre>{{ pretty(detail.inbound_payload) }}</pre>
                    <div class="sb-kv">
                        <span>assinatura</span>
                        <span>{{ (detail.meta?.signature ?? '').slice(0, 24) }}…</span>
                    </div>
                    <div class="sb-kv"><span>HTTP</span><span>{{ detail.meta?.status ?? '—' }}</span></div>
                </template>

                <template v-if="listeners.length">
                    <div class="sb-h">Listeners registrados</div>
                    <pre>{{ listeners.join('\n') }}</pre>
                    <p class="sb-note">
                        Registro, não execução. Um listener <code>ShouldQueue</code> roda noutro
                        processo — o efeito dele aparece depois, pelo polling.
                    </p>
                </template>

                <template v-if="out">
                    <div class="sb-h">Avançar a entrega</div>
                    <p class="sb-note">
                        Na vida real a Meta manda estes num webhook separado, minutos depois.
                        Aqui é um botão, pra você exercitar o caminho de propósito.
                    </p>
                    <div class="sb-row">
                        <button class="btn tiny" @click="emit('status', { status: 'delivered' })">Entregue</button>
                        <button class="btn tiny" @click="emit('status', { status: 'read' })">Lida</button>
                        <button
                            class="btn tiny danger"
                            @click="emit('status', { status: 'failed', fault: 'undeliverable' })"
                        >Falhou</button>
                    </div>
                </template>
            </template>
        </div>

        <div v-else class="sb-pane">
            <div class="sb-h">Janela de 24 horas</div>
            <p class="sb-note">
                Fora dela, só template passa — texto livre volta como <strong>131047</strong>, que é
                terminal. É a surpresa nº 1 em produção, e a única forma de ensaiá-la era esperar
                um dia.
            </p>
            <div class="sb-row">
                <span class="sb-pill" :class="{ retry: conversation?.window_open }">
                    {{ conversation?.window_open ? 'Aberta' : 'Fechada' }}
                </span>
                <button
                    class="btn tiny danger"
                    :disabled="!conversation?.window_open"
                    @click="emit('close-window')"
                >Fechar agora</button>
            </div>

            <div class="sb-h">Armar uma falha no próximo envio</div>
            <p class="sb-note">
                Dispara uma vez e se desarma. O código de cada uma é o que a Meta devolveria de
                verdade — e <code>isTerminal()</code> aqui responde o mesmo que em produção.
            </p>

            <div v-for="fault in faults" :key="fault.key" class="sb-fault">
                <div class="sb-fault-main">
                    <div>{{ fault.title }}</div>
                    <div class="sb-fault-code">{{ fault.code ?? 'sem código (rede)' }}</div>
                </div>
                <span class="sb-pill" :class="{ retry: !fault.terminal }">
                    {{ fault.terminal ? 'terminal' : 'retentável' }}
                </span>
                <button class="btn tiny" @click="emit('arm', fault.key)">Armar</button>
            </div>

            <p v-if="conversation?.armed_fault" class="sb-note">
                <strong>{{ conversation.armed_fault }}</strong> está armada — o próximo envio pra
                este contato vai falhar.
            </p>
        </div>
    </aside>
</template>

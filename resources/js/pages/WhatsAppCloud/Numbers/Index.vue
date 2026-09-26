<script setup>
import { onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import { pushToast, toasts } from '../Templates/partials/toasts'
import '../Templates/partials/panel.css'

const props = defineProps({
    numbers: { type: Array, default: () => [] },
    signup: { type: Object, default: () => ({}) },
    warnings: { type: Array, default: () => [] },
    loadError: { type: String, default: null },
    panelUrl: { type: String, required: true },
    setupUrl: { type: String, default: null },
})

const pin = ref('')
const connecting = ref(false)
const submitted = ref(false)

// FB.login returns the `code`; the popup posts the WABA / phone ids in a
// separate window message. They arrive in either order — submit once both are in.
const session = reactive({ code: null, data: null })

function loadSdk() {
    return new Promise((resolve, reject) => {
        if (window.FB) return resolve(window.FB)

        window.fbAsyncInit = () => {
            window.FB.init({
                appId: props.signup.app_id,
                autoLogAppEvents: true,
                xfbml: false,
                version: props.signup.graph_version,
            })
            resolve(window.FB)
        }

        const script = document.createElement('script')
        script.src = 'https://connect.facebook.net/pt_BR/sdk.js'
        script.async = true
        script.defer = true
        script.crossOrigin = 'anonymous'
        script.onerror = () => reject(new Error('Não foi possível carregar o SDK do Facebook.'))
        document.body.appendChild(script)
    })
}

function onMessage(event) {
    if (!/^https:\/\/([a-z0-9-]+\.)*facebook\.com$/.test(event.origin)) return

    let payload
    try {
        payload = typeof event.data === 'string' ? JSON.parse(event.data) : event.data
    } catch {
        return
    }
    if (!payload || payload.type !== 'WA_EMBEDDED_SIGNUP') return

    if (payload.event === 'FINISH' || payload.event === 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING') {
        session.data = payload.data || {}
        submit()
    } else if (payload.event === 'FINISH_ONLY_WABA') {
        fail('A conta foi conectada, mas nenhum número foi escolhido. Refaça o cadastro escolhendo um número.')
    } else if (payload.event === 'CANCEL') {
        fail('Cadastro cancelado na Meta.')
    } else if (payload.event === 'ERROR') {
        fail((payload.data && payload.data.error_message) || 'A Meta retornou um erro no cadastro.')
    }
}

async function connect() {
    if (!props.signup.ready || connecting.value) return
    if (pin.value && !/^\d{6}$/.test(pin.value)) {
        pushToast('O PIN precisa ter exatamente 6 dígitos.', 'err')
        return
    }

    session.code = null
    session.data = null
    submitted.value = false
    connecting.value = true

    let FB
    try {
        FB = await loadSdk()
    } catch (e) {
        fail(e.message)
        return
    }

    // The callback must not be async — the SDK rejects it.
    FB.login(
        (response) => {
            if (response.authResponse && response.authResponse.code) {
                session.code = response.authResponse.code
                submit()
            } else if (!session.data) {
                fail('Login com o Facebook não concluído.')
            }
        },
        {
            config_id: props.signup.config_id,
            response_type: 'code',
            override_default_response_type: true,
            extras: { setup: {}, featureType: '', sessionInfoVersion: '3' },
        },
    )
}

function submit() {
    if (!connecting.value || submitted.value || !session.code || !session.data) return
    submitted.value = true

    router.post(
        props.panelUrl,
        {
            code: session.code,
            waba_id: session.data.waba_id,
            phone_number_id: session.data.phone_number_id,
            business_id: session.data.business_id,
            pin: pin.value,
        },
        {
            preserveScroll: true,
            onSuccess: () => pushToast('Número conectado.'),
            onError: (errors) => pushToast(errors.meta || errors.form || 'Falha ao conectar.', 'err'),
            onFinish: () => (connecting.value = false),
        },
    )
}

function fail(message) {
    // CANCEL arrives as a message AND as an empty FB.login callback — toast once.
    // Once the POST is out, late popup events no longer matter.
    if (!connecting.value || submitted.value) return
    connecting.value = false
    pushToast(message, 'err')
}

function refreshNumber(n) {
    router.post(`${props.panelUrl}/${n.id}/refresh`, {}, {
        preserveScroll: true,
        onSuccess: () => pushToast('Número atualizado.'),
        onError: (errors) => pushToast(errors.meta || errors.form || 'Falha ao atualizar.', 'err'),
    })
}

function disconnect(n) {
    const label = n.display_phone_number || n.phone_number_id
    if (!window.confirm(`Desconectar ${label}?\nO app deixa de receber os webhooks dessa WABA e as credenciais são apagadas.`)) {
        return
    }
    router.delete(`${props.panelUrl}/${n.id}`, {
        preserveScroll: true,
        onSuccess: () => pushToast(`${label} desconectado.`),
        onError: (errors) => pushToast(errors.meta || errors.form || 'Falha ao desconectar.', 'err'),
    })
}

function qualityClass(rating) {
    const r = String(rating || '').toUpperCase()
    if (r === 'GREEN') return 'approved'
    if (r === 'YELLOW') return 'pending'
    if (r === 'RED') return 'rejected'
    return 'paused'
}

function formatDate(iso) {
    return iso ? new Date(iso).toLocaleDateString('pt-BR') : '—'
}

function expiry(n) {
    if (!n.token_expires_at) return { label: 'Não expira', cls: 'approved' }
    const days = Math.ceil((new Date(n.token_expires_at) - Date.now()) / 86400000)
    if (days <= 0) return { label: 'Expirado — reconecte', cls: 'rejected' }
    if (days <= 7) return { label: `Expira em ${days} dia(s)`, cls: 'pending' }
    return { label: `Até ${formatDate(n.token_expires_at)}`, cls: 'paused' }
}

watch(
    () => props.warnings,
    (list) => (list || []).forEach((w) => pushToast(w, 'err')),
    { immediate: true },
)

watch(
    () => props.loadError,
    (msg) => {
        if (msg) pushToast(msg, 'err')
    },
    { immediate: true },
)

onMounted(() => window.addEventListener('message', onMessage))
onBeforeUnmount(() => window.removeEventListener('message', onMessage))
</script>

<template>
    <Head title="WhatsApp — Números conectados" />

    <div class="wa-panel">
        <header class="topbar">
            <div class="brand">
                <div class="logo">📱</div>
                <div>
                    <h1>Números conectados</h1>
                    <p class="sub">WhatsApp Cloud API · Embedded Signup</p>
                </div>
            </div>
            <div class="spacer" />
            <a v-if="setupUrl" class="btn" :href="setupUrl">⚙️ Configuração</a>
            <input
                v-if="signup.ready && !signup.pin_configured"
                v-model="pin"
                class="pin"
                inputmode="numeric"
                maxlength="6"
                placeholder="PIN (6 dígitos)"
                title="PIN de verificação em duas etapas usado para registrar o número na Cloud API. Opcional."
            />
            <button
                class="btn primary"
                :disabled="!signup.ready || connecting"
                :title="signup.ready ? 'Entrar com o Facebook e escolher o número' : 'Configure app_id, app_secret e config_id'"
                @click="connect"
            >
                {{ connecting ? 'Aguardando a Meta…' : '+ Conectar WhatsApp' }}
            </button>
        </header>

        <main class="wrap">
            <div v-if="!signup.ready" class="card state error">
                Para conectar números, configure o App ID, o App Secret e o config_id do Embedded Signup
                <a v-if="setupUrl" :href="setupUrl">na tela de configuração</a><template v-else>no <code>.env</code></template>.
            </div>

            <div class="card">
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Número</th>
                                <th>Nome verificado</th>
                                <th>IDs</th>
                                <th>Qualidade</th>
                                <th>Token</th>
                                <th>Conectado em</th>
                                <th style="text-align: right">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="n in numbers" :key="n.id">
                                <td>
                                    <b>{{ n.display_phone_number || '—' }}</b>
                                    <div v-if="n.key" class="t-lang">{{ n.key }}</div>
                                </td>
                                <td>{{ n.verified_name || '—' }}</td>
                                <td>
                                    <div class="t-lang">phone {{ n.phone_number_id }}</div>
                                    <div class="t-lang">waba {{ n.waba_id || '—' }}</div>
                                </td>
                                <td>
                                    <span class="badge" :class="qualityClass(n.quality_rating)">{{ n.quality_rating || '?' }}</span>
                                </td>
                                <td>
                                    <span class="badge" :class="expiry(n).cls">{{ expiry(n).label }}</span>
                                </td>
                                <td>{{ formatDate(n.connected_at) }}</td>
                                <td class="actions-cell">
                                    <button class="iconbtn" title="Atualizar dados e reinscrever webhooks" @click="refreshNumber(n)">↻</button>
                                    <button class="iconbtn danger" title="Desconectar" @click="disconnect(n)">🗑️</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="!numbers.length" class="state">
                    <div class="big">📭</div>
                    Nenhum número conectado ainda.
                </div>
            </div>
        </main>

        <div class="wa-panel-toasts">
            <div v-for="t in toasts" :key="t.id" class="toast" :class="t.type">
                <span class="ico">{{ t.type === 'err' ? '⚠️' : '✅' }}</span>
                <div class="msg"><span>{{ t.message }}</span></div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.pin {
    width: 150px;
    padding: 8px 10px;
    border: 1px solid var(--border-strong);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--text);
    font-family: var(--mono);
}
</style>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import { pushToast, toasts } from '../Templates/partials/toasts'
import '../Templates/partials/panel.css'

const props = defineProps({
    settings: { type: Object, default: () => ({}) },
    steps: { type: Object, default: () => ({}) },
    webhook: { type: Object, default: () => ({}) },
    sdkDomain: { type: String, default: '' },
    numbers: { type: Array, default: () => [] },
    lastInbound: { type: Object, default: null },
    notice: { type: Object, default: null },
    diagnostics: { type: Array, default: null },
    links: { type: Object, default: () => ({}) },
    panelUrl: { type: String, required: true },
})

const s = (key) => (props.settings[key] && props.settings[key].value) || ''
const source = (key) => props.settings[key] && props.settings[key].source

const appId = computed(() => s('app_id'))
const metaAppUrl = computed(() =>
    appId.value ? `https://developers.facebook.com/apps/${appId.value}` : 'https://developers.facebook.com/apps',
)

const app = reactive({ app_id: s('app_id'), app_secret: '', graph_version: s('graph_version') || 'v21.0' })
const hook = reactive({ callback_url: props.webhook.callback_url || '' })
const signup = reactive({ config_id: s('embedded_signup_config_id'), register_pin: '' })
const manual = reactive({ open: false, phone_number_id: '', waba_id: '', access_token: '', key: '', make_default: true })
const test = reactive({ to: '', template: 'hello_world', language: 'en_US' })
const importBox = reactive({ open: false, json: '' })
const busy = ref('')

const order = ['app', 'webhook', 'signup', 'number', 'test']
const current = ref(order.find((k) => !props.steps[k]) || 'test')
const doneCount = computed(() => order.filter((k) => props.steps[k]).length)

function post(action, data, key) {
    busy.value = key
    router.post(`${props.panelUrl}/${action}`, data, {
        preserveScroll: true,
        onError: (errors) => pushToast(errors.meta || errors.form || 'Falha.', 'err'),
        onFinish: () => (busy.value = ''),
    })
}

function copy(text) {
    navigator.clipboard && navigator.clipboard.writeText(text)
    pushToast('Copiado.')
}

function doImport(event) {
    const file = event && event.target.files && event.target.files[0]
    if (file) {
        busy.value = 'import'
        router.post(`${props.panelUrl}/import`, { file }, {
            forceFormData: true,
            onError: (errors) => pushToast(errors.form || errors.meta || 'Falha ao importar.', 'err'),
            onFinish: () => (busy.value = ''),
        })
        return
    }
    post('import', { json: importBox.json }, 'import')
}

function exportJson() {
    if (window.confirm('O arquivo contém o App Secret e tokens em texto puro. Guarde em local seguro.\nContinuar?')) {
        window.location.href = `${props.panelUrl}/export`
    }
}

function sourceLabel(key) {
    const src = source(key)
    return src === 'panel' ? 'painel' : src === 'env' ? '.env' : ''
}

watch(
    () => props.notice,
    (n) => {
        if (!n) return
        pushToast(n.message)
        ;(n.warnings || []).forEach((w) => pushToast(w, 'err'))
    },
    { immediate: true },
)

// Jump to the next pending step after a successful save.
watch(
    () => props.steps,
    (steps) => {
        const next = order.find((k) => !steps[k])
        if (next && steps[current.value]) current.value = next
    },
)
</script>

<template>
    <Head title="WhatsApp — Configuração" />

    <div class="wa-panel">
        <header class="topbar">
            <div class="brand">
                <div class="logo">⚙️</div>
                <div>
                    <h1>Configurar WhatsApp Cloud API</h1>
                    <p class="sub">{{ doneCount }} de {{ order.length }} passos concluídos</p>
                </div>
            </div>
            <div class="spacer" />
            <button class="btn" :disabled="busy === 'diagnose'" @click="post('diagnose', {}, 'diagnose')">🩺 Validar tudo</button>
            <button class="btn" @click="importBox.open = !importBox.open">⬆ Importar</button>
            <button class="btn" @click="exportJson">⬇ Exportar</button>
        </header>

        <main class="wrap setup">
            <section v-if="importBox.open" class="card box">
                <h3>Importar configuração (JSON)</h3>
                <p class="hint">Cole o JSON exportado de outro projeto ou escolha o arquivo. Os valores vão para o banco, criptografados.</p>
                <textarea v-model="importBox.json" rows="6" placeholder='{"whatsapp-cloud": {"app_id": "...", "app_secret": "..."}}' />
                <div class="row">
                    <input type="file" accept="application/json,.json" @change="doImport" />
                    <div class="spacer" />
                    <button class="btn primary" :disabled="!importBox.json || busy === 'import'" @click="doImport()">Importar</button>
                </div>
            </section>

            <section v-if="diagnostics" class="card box">
                <h3>Validação</h3>
                <ul class="checks">
                    <li v-for="c in diagnostics" :key="c.label">
                        <span>{{ c.ok ? '✅' : '❌' }}</span>
                        <div><b>{{ c.label }}</b><div class="hint">{{ c.detail }}</div></div>
                    </li>
                </ul>
            </section>

            <!-- 1. App -->
            <section class="card step" :class="{ open: current === 'app', done: steps.app }">
                <button class="step-head" @click="current = 'app'">
                    <span class="num">{{ steps.app ? '✓' : '1' }}</span>
                    <span class="title">App da Meta</span>
                    <span class="hint">{{ appId ? `App ${appId}` : 'App ID e App Secret' }}</span>
                </button>
                <div v-if="current === 'app'" class="step-body">
                    <p class="hint">
                        A Meta não permite criar o app nem ler o secret por API — esses dois valores são copiados à mão.
                        Abra <a :href="metaAppUrl" target="_blank" rel="noopener">o seu app na Meta</a> →
                        <b>Configurações do app → Básico</b>. Sem app ainda?
                        <a href="https://developers.facebook.com/apps/creation/" target="_blank" rel="noopener">Criar app</a> (tipo <b>Empresa</b>, com o produto WhatsApp).
                    </p>
                    <label>App ID <small>{{ sourceLabel('app_id') }}</small><input v-model="app.app_id" inputmode="numeric" /></label>
                    <label>
                        App Secret <small>{{ sourceLabel('app_secret') }}</small>
                        <input v-model="app.app_secret" type="password" autocomplete="off" :placeholder="s('app_secret') ? `${s('app_secret')} (deixe vazio para manter)` : ''" />
                    </label>
                    <label>Versão da Graph API <input v-model="app.graph_version" placeholder="v21.0" /></label>
                    <div class="row">
                        <div class="spacer" />
                        <button class="btn primary" :disabled="busy === 'app'" @click="post('app', app, 'app')">Validar e salvar</button>
                    </div>
                </div>
            </section>

            <!-- 2. Webhook -->
            <section class="card step" :class="{ open: current === 'webhook', done: steps.webhook }">
                <button class="step-head" @click="current = 'webhook'">
                    <span class="num">{{ steps.webhook ? '✓' : '2' }}</span>
                    <span class="title">Webhook</span>
                    <span class="hint">{{ steps.webhook ? 'Registrado na Meta' : 'Receber mensagens e status' }}</span>
                </button>
                <div v-if="current === 'webhook'" class="step-body">
                    <p class="hint">
                        O sistema gera o verify token e registra a URL abaixo no seu app pela API da Meta — nada de copiar e colar.
                        A Meta exige <b>HTTPS público</b> (em ambiente local, use um túnel e informe a URL dele).
                    </p>
                    <label>
                        URL de callback
                        <div class="row"><input v-model="hook.callback_url" /><button class="btn" @click="copy(hook.callback_url)">Copiar</button></div>
                    </label>
                    <p v-if="s('verify_token')" class="hint">Verify token: <code>{{ s('verify_token') }}</code> <small>{{ sourceLabel('verify_token') }}</small></p>
                    <div class="row">
                        <div class="spacer" />
                        <button class="btn primary" :disabled="!steps.app || busy === 'webhook'" @click="post('webhook', hook, 'webhook')">
                            Registrar automaticamente
                        </button>
                    </div>
                </div>
            </section>

            <!-- 3. Embedded Signup -->
            <section class="card step" :class="{ open: current === 'signup', done: steps.signup }">
                <button class="step-head" @click="current = 'signup'">
                    <span class="num">{{ steps.signup ? '✓' : '3' }}</span>
                    <span class="title">Embedded Signup</span>
                    <span class="hint">{{ steps.signup ? `config_id ${s('embedded_signup_config_id')}` : 'Conectar números com o Facebook' }}</span>
                </button>
                <div v-if="current === 'signup'" class="step-body">
                    <ol class="hint guide">
                        <li>No app da Meta: <b>Adicionar produto → Login do Facebook para Empresas</b>.</li>
                        <li><b>Login do Facebook para Empresas → Modelos</b> → "Configuração do cadastro incorporado do WhatsApp…" → <b>Usar modelo</b>. Copie a <b>Identificação da configuração</b>.</li>
                        <li>
                            <b>Login do Facebook para Empresas → Configurações</b>: ligue <b>Entrar com o SDK do JavaScript</b> e adicione este domínio em
                            <b>Domínios permitidos</b>: <code>{{ sdkDomain }}</code> <button class="btn tiny" @click="copy(sdkDomain)">Copiar</button>
                        </li>
                    </ol>
                    <label>config_id <small>{{ sourceLabel('embedded_signup_config_id') }}</small><input v-model="signup.config_id" inputmode="numeric" /></label>
                    <label>
                        PIN de registro (opcional, 6 dígitos)
                        <input v-model="signup.register_pin" inputmode="numeric" maxlength="6" :placeholder="s('register_pin') ? `${s('register_pin')} (deixe vazio para remover)` : ''" />
                    </label>
                    <div class="row">
                        <div class="spacer" />
                        <button class="btn primary" :disabled="busy === 'signup'" @click="post('signup', signup, 'signup')">Salvar</button>
                    </div>
                </div>
            </section>

            <!-- 4. Número -->
            <section class="card step" :class="{ open: current === 'number', done: steps.number }">
                <button class="step-head" @click="current = 'number'">
                    <span class="num">{{ steps.number ? '✓' : '4' }}</span>
                    <span class="title">Número</span>
                    <span class="hint">{{ steps.number ? `Padrão: ${s('default_phone_number_id')}` : 'Conectar e escolher o padrão' }}</span>
                </button>
                <div v-if="current === 'number'" class="step-body">
                    <div class="row">
                        <a v-if="links.numbers" class="btn primary" :href="links.numbers">📱 Conectar com o Facebook</a>
                        <button class="btn" @click="manual.open = !manual.open">✍️ Cadastrar manualmente</button>
                    </div>

                    <div v-if="manual.open" class="manual">
                        <p class="hint">Valores em <b>WhatsApp → Configuração da API</b> no app da Meta. Use um token permanente (usuário do sistema).</p>
                        <label>Phone Number ID <input v-model="manual.phone_number_id" inputmode="numeric" /></label>
                        <label>WABA ID <input v-model="manual.waba_id" inputmode="numeric" /></label>
                        <label>Access token <input v-model="manual.access_token" type="password" autocomplete="off" /></label>
                        <label>Chave (opcional, ex.: tenant) <input v-model="manual.key" /></label>
                        <label class="check"><input v-model="manual.make_default" type="checkbox" /> Usar como número padrão</label>
                        <div class="row">
                            <div class="spacer" />
                            <button class="btn primary" :disabled="busy === 'number'" @click="post('number', manual, 'number')">Validar e salvar</button>
                        </div>
                    </div>

                    <table v-if="numbers.length">
                        <thead><tr><th>Número</th><th>Nome</th><th>Phone Number ID</th><th /></tr></thead>
                        <tbody>
                            <tr v-for="n in numbers" :key="n.id">
                                <td>{{ n.display_phone_number || '—' }} <span v-if="n.key" class="t-lang">{{ n.key }}</span></td>
                                <td>{{ n.verified_name || '—' }}</td>
                                <td><span class="t-lang">{{ n.phone_number_id }}</span></td>
                                <td style="text-align: right">
                                    <span v-if="n.is_default" class="badge approved">padrão</span>
                                    <button v-else class="btn tiny" @click="post(`default/${n.id}`, {}, 'default')">Tornar padrão</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-else class="hint">Nenhum número salvo ainda.</p>
                </div>
            </section>

            <!-- 5. Teste -->
            <section class="card step" :class="{ open: current === 'test', done: steps.test }">
                <button class="step-head" @click="current = 'test'">
                    <span class="num">{{ steps.test ? '✓' : '5' }}</span>
                    <span class="title">Teste</span>
                    <span class="hint">{{ steps.test ? 'Mensagem recebida pelo webhook' : 'Enviar e receber' }}</span>
                </button>
                <div v-if="current === 'test'" class="step-body">
                    <p class="hint">
                        Envia um template aprovado do número padrão. Depois <b>responda no celular</b> e clique em "Verificar resposta" —
                        se ela aparecer, o webhook está funcionando.
                    </p>
                    <label>Seu WhatsApp (com DDI) <input v-model="test.to" inputmode="numeric" placeholder="5548999999999" /></label>
                    <div class="row">
                        <label>Template <input v-model="test.template" /></label>
                        <label>Idioma <input v-model="test.language" /></label>
                    </div>
                    <div class="row">
                        <button class="btn" @click="router.reload({ only: ['lastInbound', 'steps'] })">↻ Verificar resposta</button>
                        <div class="spacer" />
                        <button class="btn primary" :disabled="!steps.number || busy === 'test'" @click="post('test', test, 'test')">Enviar teste</button>
                    </div>
                    <div v-if="lastInbound" class="inbound">
                        <b>Última mensagem recebida</b>
                        <div>{{ lastInbound.name || lastInbound.from }}: “{{ lastInbound.text }}”</div>
                        <div class="hint">{{ new Date(lastInbound.at).toLocaleString('pt-BR') }}</div>
                    </div>
                </div>
            </section>

            <p v-if="doneCount === order.length" class="card box done-all">
                🎉 Tudo configurado.
                <a v-if="links.templates" :href="links.templates">Gerenciar templates</a>
                <template v-if="links.templates && links.numbers"> · </template>
                <a v-if="links.numbers" :href="links.numbers">Números conectados</a>
            </p>
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
.setup { max-width: 860px; }
.box { padding: 16px 18px; margin-bottom: 14px; }
.box h3 { margin: 0 0 8px; font-size: 16px; }
.step { margin-bottom: 12px; overflow: hidden; }
.step-head {
    display: flex; align-items: center; gap: 12px; width: 100%;
    padding: 14px 18px; background: none; border: 0; cursor: pointer;
    color: var(--text); font: inherit; text-align: left;
}
.step-head .num {
    display: grid; place-items: center; width: 28px; height: 28px; border-radius: 50%;
    background: var(--gray-soft); color: var(--muted); font-weight: 600; flex: none;
}
.step.done .step-head .num { background: var(--ok-soft); color: var(--ok); }
.step.open .step-head .num { background: var(--accent); color: var(--accent-contrast); }
.step-head .title { font-weight: 600; }
.step-head .hint { margin-left: auto; }
.step-body { padding: 0 18px 18px 58px; display: grid; gap: 12px; }
.hint { color: var(--muted); font-size: 13px; margin: 0; }
.guide { padding-left: 18px; display: grid; gap: 6px; }
label { display: grid; gap: 4px; font-size: 13px; font-weight: 500; flex: 1; }
label small { color: var(--faint); font-weight: 400; }
label.check { display: flex; align-items: center; gap: 8px; }
input:not([type='checkbox']):not([type='file']), textarea {
    width: 100%; padding: 8px 10px; border: 1px solid var(--border-strong); border-radius: var(--radius-sm);
    background: var(--surface); color: var(--text); font: inherit; box-sizing: border-box;
}
textarea { font-family: var(--mono); font-size: 12px; }
.row { display: flex; gap: 8px; align-items: flex-end; flex-wrap: wrap; }
.spacer { flex: 1; }
.btn.tiny { padding: 2px 8px; font-size: 12px; }
.manual { display: grid; gap: 10px; padding: 12px; border: 1px dashed var(--border-strong); border-radius: var(--radius-sm); }
.checks { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
.checks li { display: flex; gap: 10px; }
.inbound { padding: 10px 12px; background: var(--ok-soft); border-radius: var(--radius-sm); }
.done-all { text-align: center; }
code { font-family: var(--mono); font-size: 12px; }
a { color: var(--accent); }
@media (max-width: 640px) { .step-body { padding-left: 18px; } }
</style>

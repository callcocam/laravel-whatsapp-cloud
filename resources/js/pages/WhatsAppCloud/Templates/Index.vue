<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import { catClass, formatMoney, statusClass } from './partials/format'
import { pushToast, toasts } from './partials/toasts'
import StatusBadge from './partials/StatusBadge.vue'
import TemplateDetailModal from './partials/TemplateDetailModal.vue'
import TemplateFormModal from './partials/TemplateFormModal.vue'
import SendTestModal from './partials/SendTestModal.vue'
import './partials/panel.css'

const props = defineProps({
    templates: { type: Array, default: () => [] },
    waConfig: { type: Object, default: () => ({}) },
    loadError: { type: String, default: null },
    costs: { type: Object, default: null },
    panelUrl: { type: String, required: true },
})

function money(value) {
    return formatMoney(value, props.costs && props.costs.currency)
}

const filters = reactive({ status: '', category: '', search: '' })

const detail = reactive({ open: false, template: null })
const formModal = reactive({ open: false, mode: 'create', template: null })
const sendModal = reactive({ open: false, template: null })

const wabaLabel = computed(() =>
    props.waConfig && props.waConfig.waba_id ? `WABA ${props.waConfig.waba_id}` : '(configuração indisponível)',
)

const counters = computed(() => {
    const c = { approved: 0, pending: 0, rejected: 0, paused: 0 }
    for (const t of props.templates) c[statusClass(t.status)]++
    return [
        { cls: 'approved', label: 'Aprovados', n: c.approved },
        { cls: 'pending', label: 'Pendentes', n: c.pending },
        { cls: 'rejected', label: 'Rejeitados', n: c.rejected },
        { cls: 'paused', label: 'Pausados', n: c.paused },
    ]
})

const filtered = computed(() => {
    const q = filters.search.trim().toLowerCase()
    return props.templates.filter((t) => {
        if (filters.status && String(t.status || '').toUpperCase() !== filters.status) return false
        if (filters.category && String(t.category || '').toUpperCase() !== filters.category) return false
        if (q && !String(t.name || '').toLowerCase().includes(q)) return false
        return true
    })
})

function isPending(t) {
    return statusClass(t.status) === 'pending'
}

function openDetail(t) {
    detail.template = t
    detail.open = true
}

function openForm(mode, t = null) {
    formModal.mode = mode
    formModal.template = t
    formModal.open = true
}

function openSend(t) {
    detail.open = false
    sendModal.template = t
    sendModal.open = true
}

function confirmDelete(t) {
    if (!window.confirm(`Apagar o template "${t.name}"?\nIsso remove TODOS os idiomas com esse nome na WABA.`)) {
        return
    }
    router.delete(`${props.panelUrl}/${encodeURIComponent(t.name)}`, {
        preserveScroll: true,
        onSuccess: () => pushToast(`Template "${t.name}" apagado.`),
        onError: (errors) => pushToast(errors.meta || errors.form || 'Falha ao apagar.', 'err'),
    })
}

function refresh() {
    router.reload({ only: ['templates'] })
}

// Surface a Meta load error (bad credentials, etc.) once.
watch(
    () => props.loadError,
    (msg) => {
        if (msg) pushToast(msg, 'err')
    },
    { immediate: true },
)
</script>

<template>
    <Head title="WhatsApp Templates" />

    <div class="wa-panel">
        <header class="topbar">
            <div class="brand">
                <div class="logo">💬</div>
                <div>
                    <h1>WhatsApp Templates</h1>
                    <p class="sub">{{ wabaLabel }}</p>
                </div>
            </div>
            <div class="spacer" />
            <button class="btn" title="Recarregar a lista" @click="refresh">↻ Atualizar</button>
            <button class="btn primary" @click="openForm('create')">+ Novo template</button>
        </header>

        <main class="wrap">
            <section class="counters">
                <div v-for="c in counters" :key="c.cls" class="counter">
                    <span class="dot" :class="c.cls" />
                    <div>
                        <div class="num">{{ c.n }}</div>
                        <div class="lbl">{{ c.label }}</div>
                    </div>
                </div>
                <div class="counter total">
                    <div>
                        <div class="num">{{ templates.length }}</div>
                        <div class="lbl">Total</div>
                    </div>
                </div>
            </section>

            <section v-if="costs" class="costs">
                <div class="costs-total">
                    <span class="lbl">Gastos <span class="est">(estimado)</span> · mês atual</span>
                    <span class="amount">{{ money(costs.total) }}</span>
                    <span class="sub">{{ costs.conversations }} conversas</span>
                </div>
                <div class="costs-cats">
                    <span v-for="c in costs.byCategory" :key="c.category" class="cost-chip">
                        <span class="cat" :class="catClass(c.category)">{{ c.category }}</span>
                        <b>{{ money(c.cost) }}</b>
                    </span>
                </div>
            </section>

            <div class="toolbar">
                <div class="field">
                    <select v-model="filters.status" aria-label="Filtrar por status">
                        <option value="">Todos os status</option>
                        <option value="APPROVED">Aprovado</option>
                        <option value="PENDING">Pendente</option>
                        <option value="REJECTED">Rejeitado</option>
                        <option value="PAUSED">Pausado</option>
                    </select>
                </div>
                <div class="field">
                    <select v-model="filters.category" aria-label="Filtrar por categoria">
                        <option value="">Todas as categorias</option>
                        <option value="UTILITY">Utility</option>
                        <option value="MARKETING">Marketing</option>
                        <option value="AUTHENTICATION">Authentication</option>
                    </select>
                </div>
                <div class="search field">
                    <input v-model="filters.search" type="search" placeholder="Buscar por nome…" aria-label="Buscar por nome" />
                </div>
            </div>

            <div class="card">
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th>Idioma</th>
                                <th>Categoria</th>
                                <th>Status</th>
                                <th style="text-align: right">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="t in filtered" :key="t.id || t.name">
                                <td>
                                    <span class="t-name" title="Ver detalhe" @click="openDetail(t)">{{ t.name || '(sem nome)' }}</span>
                                </td>
                                <td><span class="t-lang">{{ t.language || '?' }}</span></td>
                                <td><span class="cat" :class="catClass(t.category)">{{ t.category || '?' }}</span></td>
                                <td><StatusBadge :status="t.status" /></td>
                                <td class="actions-cell">
                                    <button class="iconbtn" title="Ver detalhe" @click="openDetail(t)">👁️</button>
                                    <button
                                        class="iconbtn"
                                        :disabled="isPending(t)"
                                        :title="isPending(t) ? 'Não editável enquanto PENDING' : 'Editar'"
                                        @click="openForm('edit', t)"
                                    >
                                        ✏️
                                    </button>
                                    <button class="iconbtn" title="Enviar teste" @click="openSend(t)">✈️</button>
                                    <button class="iconbtn danger" title="Apagar" @click="confirmDelete(t)">🗑️</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="!filtered.length" class="state">
                    <div class="big">📭</div>
                    Nenhum template encontrado.
                </div>
            </div>
        </main>

        <div class="wa-panel-toasts">
            <div v-for="t in toasts" :key="t.id" class="toast" :class="t.type">
                <span class="ico">{{ t.type === 'err' ? '⚠️' : '✅' }}</span>
                <div class="msg"><span>{{ t.message }}</span><span v-html="t.extraHtml" /></div>
            </div>
        </div>

        <TemplateDetailModal
            :open="detail.open"
            :template="detail.template"
            @close="detail.open = false"
            @send="openSend"
        />

        <TemplateFormModal
            :open="formModal.open"
            :mode="formModal.mode"
            :template="formModal.template"
            :panel-url="panelUrl"
            @close="formModal.open = false"
            @saved="formModal.open = false"
        />

        <SendTestModal
            :open="sendModal.open"
            :template="sendModal.template"
            :panel-url="panelUrl"
            @close="sendModal.open = false"
            @sent="sendModal.open = false"
        />
    </div>
</template>

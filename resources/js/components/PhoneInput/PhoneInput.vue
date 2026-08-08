<script setup>
/**
 * Campo de telefone no padrão da WhatsApp Cloud API (Meta).
 *
 * - Exibe uma máscara amigável (`+55 (48) 99999-9999`).
 * - Emite via `v-model` SEMPRE os dígitos normalizados (`wa_id`), ex.: `5548999999999`.
 * - Emite `valid` (boolean) conforme `isValidMetaPhone`.
 *
 * Autocontido em CSS puro: não depende de nenhum design system do host.
 * Personalize pelas CSS custom properties `--wa-phone-*` (veja o <style> abaixo).
 */
import { computed, ref, watch } from 'vue'
import { formatPhoneBR, isValidMetaPhone, normalizeMetaPhone } from './phone'

const props = defineProps({
    /** Dígitos normalizados (wa_id). Ex.: '5548999999999'. */
    modelValue: { type: String, default: '' },
    /** DDI padrão a prepender quando o número vier sem ele. */
    defaultCountry: { type: String, default: '55' },
    placeholder: { type: String, default: '+55 (48) 99999-9999' },
    disabled: { type: Boolean, default: false },
    /** Marca o campo como inválido (borda de erro). */
    invalid: { type: Boolean, default: false },
    id: { type: String, default: undefined },
    inputClass: { type: [String, Array, Object], default: undefined },
})

const emit = defineEmits(['update:modelValue', 'valid', 'enter'])

/** Texto visível (mascarado). */
const display = ref(formatPhoneBR(props.modelValue))

// Reflete mudanças externas do modelValue na máscara, sem sobrescrever a digitação em curso.
watch(
    () => props.modelValue,
    (next) => {
        const normalizedFromDisplay = normalizeMetaPhone(display.value, { defaultCountry: props.defaultCountry })
        if ((next || '') !== normalizedFromDisplay) {
            display.value = formatPhoneBR(next)
        }
    },
)

const isInvalid = computed(() => props.invalid)

function onInput(event) {
    const raw = event.target.value
    const normalized = normalizeMetaPhone(raw, { defaultCountry: props.defaultCountry })

    display.value = formatPhoneBR(normalized)
    emit('update:modelValue', normalized)
    emit('valid', isValidMetaPhone(normalized))
}
</script>

<template>
    <input
        :id="id"
        v-model="display"
        type="tel"
        inputmode="tel"
        autocomplete="tel"
        class="wa-phone-input"
        :class="[inputClass, { 'is-invalid': isInvalid }]"
        :placeholder="placeholder"
        :disabled="disabled"
        :aria-invalid="isInvalid || undefined"
        @input="onInput"
        @keyup.enter="emit('enter')"
    />
</template>

<style scoped>
.wa-phone-input {
    /* Tokens temáticos — sobrescreva no host via `--wa-phone-*`. */
    --wa-phone-bg: transparent;
    --wa-phone-color: inherit;
    --wa-phone-border: color-mix(in srgb, currentColor 25%, transparent);
    --wa-phone-radius: 0.5rem;
    --wa-phone-height: 2.25rem;
    --wa-phone-padding-x: 0.75rem;
    --wa-phone-font-size: 0.875rem;
    --wa-phone-placeholder: color-mix(in srgb, currentColor 45%, transparent);
    --wa-phone-ring: #3b82f6;
    --wa-phone-invalid: #ef4444;

    box-sizing: border-box;
    width: 100%;
    min-width: 0;
    height: var(--wa-phone-height);
    padding: 0 var(--wa-phone-padding-x);
    font: inherit;
    font-size: var(--wa-phone-font-size);
    line-height: 1.25rem;
    color: var(--wa-phone-color);
    background: var(--wa-phone-bg);
    border: 1px solid var(--wa-phone-border);
    border-radius: var(--wa-phone-radius);
    outline: none;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}

.wa-phone-input::placeholder {
    color: var(--wa-phone-placeholder);
}

.wa-phone-input:focus-visible {
    border-color: var(--wa-phone-ring);
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--wa-phone-ring) 35%, transparent);
}

.wa-phone-input.is-invalid {
    border-color: var(--wa-phone-invalid);
}

.wa-phone-input.is-invalid:focus-visible {
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--wa-phone-invalid) 35%, transparent);
}

.wa-phone-input:disabled {
    cursor: not-allowed;
    opacity: 0.5;
}
</style>

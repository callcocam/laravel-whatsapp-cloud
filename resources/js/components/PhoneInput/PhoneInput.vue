<script setup>
/**
 * Campo de telefone no padrão da WhatsApp Cloud API (Meta).
 *
 * - O DDI (ex.: `+55`) aparece como prefixo FIXO, fora do <input>. O usuário
 *   digita só o número nacional (DDD + número), mascarado como `(48) 99999-9999`.
 *   Isso evita reconsumir os dígitos do DDI ao reformatar (um DDD `55` deixaria
 *   de ser confundido com o código do país).
 * - Emite via `v-model` SEMPRE o `wa_id` em dígitos puros, ex.: `5548999999999`.
 * - Emite `valid` (boolean) conforme `isValidMetaPhone`.
 *
 * Autocontido em CSS puro: não depende de nenhum design system do host.
 * Personalize pelas CSS custom properties `--wa-phone-*` (veja o <style> abaixo).
 */
import { computed, ref, watch } from 'vue'
import { formatNationalBR, isValidMetaPhone, nationalToWaId, onlyDigits, toNationalDigits } from './phone'

const props = defineProps({
    /** Dígitos normalizados com DDI (wa_id). Ex.: '5548999999999'. */
    modelValue: { type: String, default: '' },
    /** DDI padrão (só dígitos). Aparece como prefixo `+<ddi>`. */
    defaultCountry: { type: String, default: '55' },
    placeholder: { type: String, default: '(48) 99999-9999' },
    disabled: { type: Boolean, default: false },
    /** Marca o campo como inválido (borda de erro). */
    invalid: { type: Boolean, default: false },
    required: { type: Boolean, default: false },
    id: { type: String, default: undefined },
})

const emit = defineEmits(['update:modelValue', 'valid', 'enter'])

const ddi = computed(() => onlyDigits(props.defaultCountry) || '55')

/** Texto visível do campo editável = número nacional mascarado. */
const display = ref(formatNationalBR(toNationalDigits(props.modelValue, { defaultCountry: props.defaultCountry })))

// Reflete mudanças externas do modelValue, sem atropelar a digitação em curso.
watch(
    () => props.modelValue,
    (next) => {
        const currentWaId = nationalToWaId(toNationalDigits(display.value, { defaultCountry: props.defaultCountry }), {
            defaultCountry: props.defaultCountry,
        })
        if ((next || '') !== currentWaId) {
            display.value = formatNationalBR(toNationalDigits(next, { defaultCountry: props.defaultCountry }))
        }
    },
)

const isInvalid = computed(() => props.invalid)

function onInput(event) {
    const opts = { defaultCountry: props.defaultCountry }
    const national = toNationalDigits(event.target.value, opts)
    const waId = nationalToWaId(national, opts)

    display.value = formatNationalBR(national)
    emit('update:modelValue', waId)
    emit('valid', isValidMetaPhone(waId))
}
</script>

<template>
    <label class="wa-phone-input" :class="{ 'is-invalid': isInvalid, 'is-disabled': disabled }">
        <span class="wa-phone-ddi" aria-hidden="true">+{{ ddi }}</span>
        <input
            :id="id"
            v-model="display"
            type="tel"
            inputmode="tel"
            autocomplete="tel-national"
            class="wa-phone-native"
            :placeholder="placeholder"
            :disabled="disabled"
            :required="required || undefined"
            :aria-invalid="isInvalid || undefined"
            @input="onInput"
            @keyup.enter="emit('enter')"
        />
    </label>
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
    --wa-phone-ddi-color: color-mix(in srgb, currentColor 60%, transparent);
    --wa-phone-ring: #3b82f6;
    --wa-phone-invalid: #ef4444;

    box-sizing: border-box;
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    width: 100%;
    min-width: 0;
    /* Flex-safe: num flex-row (ex.: campo + botão) o campo encolhe até 0 se
       preciso, em vez de estourar a largura ou colapsar num quadradinho. */
    flex: 1 1 auto;
    height: var(--wa-phone-height);
    padding: 0 var(--wa-phone-padding-x);
    font-size: var(--wa-phone-font-size);
    color: var(--wa-phone-color);
    background: var(--wa-phone-bg);
    border: 1px solid var(--wa-phone-border);
    border-radius: var(--wa-phone-radius);
    cursor: text;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}

.wa-phone-ddi {
    flex: 0 0 auto;
    color: var(--wa-phone-ddi-color);
    user-select: none;
}

.wa-phone-native {
    flex: 1 1 auto;
    min-width: 0;
    width: 100%;
    height: 100%;
    margin: 0;
    padding: 0;
    font: inherit;
    font-size: var(--wa-phone-font-size);
    color: inherit;
    background: transparent;
    border: 0;
    outline: none;
}

.wa-phone-native::placeholder {
    color: var(--wa-phone-placeholder);
}

.wa-phone-input:focus-within {
    border-color: var(--wa-phone-ring);
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--wa-phone-ring) 35%, transparent);
}

.wa-phone-input.is-invalid {
    border-color: var(--wa-phone-invalid);
}

.wa-phone-input.is-invalid:focus-within {
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--wa-phone-invalid) 35%, transparent);
}

.wa-phone-input.is-disabled {
    cursor: not-allowed;
    opacity: 0.5;
}
</style>

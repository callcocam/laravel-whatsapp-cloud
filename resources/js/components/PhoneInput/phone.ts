/**
 * Padrão de telefone para a WhatsApp Cloud API (Meta).
 *
 * A Meta espera o destinatário (`wa_id`) em DÍGITOS PUROS no formato
 * `DDI + DDD + número`, sem `+`, com 8 a 15 dígitos — ex.: `5548999999999`.
 *
 * Este módulo é agnóstico de framework (não importa Vue) para poder ser
 * reutilizado por qualquer app, com ou sem design system.
 *
 * O componente separa o DDI (prefixo visual, ex.: `+55`) da parte editável
 * (o número NACIONAL: DDD + número). Isso evita reconsumir os dígitos do DDI
 * ao formatar — no Brasil o DDD também pode ser `55`, então misturar os dois
 * num único texto gera ambiguidade e duplica o código do país.
 */

export interface NormalizeOptions {
    /** Código do país (DDI). Padrão: Brasil (55). */
    defaultCountry?: string
}

/** Remove tudo que não é dígito. */
export function onlyDigits(value: string | null | undefined): string {
    return String(value ?? '').replace(/\D+/g, '')
}

function ddiOf(options: NormalizeOptions = {}): string {
    return onlyDigits(options.defaultCountry ?? '55') || '55'
}

/**
 * Extrai o número NACIONAL (DDD + número) a partir de qualquer entrada,
 * removendo o DDI quando presente. Limita a 11 dígitos (DDD 2 + celular 9).
 */
export function toNationalDigits(raw: string | null | undefined, options: NormalizeOptions = {}): string {
    const ddi = ddiOf(options)
    let d = onlyDigits(raw)

    if (d === '') {
        return ''
    }

    // Só remove o DDI quando o número é longo o suficiente para contê-lo
    // (além dos 11 dígitos possíveis de um nacional). Assim um DDD igual ao
    // DDI (ex.: 55) não é confundido com o código do país.
    if (d.startsWith(ddi) && d.length > 11) {
        d = d.slice(ddi.length)
    }

    return d.slice(0, 11)
}

/**
 * Garante o 9º dígito do celular BR num número nacional (DDD + número).
 * Se o assinante tem 8 dígitos e começa com 6–9 (celular), insere o `9`.
 */
export function ensureBrazilNinthDigit(national: string): string {
    const d = onlyDigits(national)

    if (d.length !== 10) {
        return d
    }

    const ddd = d.slice(0, 2)
    const sub = d.slice(2) // 8 dígitos

    if (/^[6-9]/.test(sub)) {
        return `${ddd}9${sub}`
    }

    return d
}

/**
 * Monta o `wa_id` (dígitos puros com DDI) a partir do número nacional.
 * Aplica o 9º dígito do celular BR quando o DDI é 55.
 */
export function nationalToWaId(national: string | null | undefined, options: NormalizeOptions = {}): string {
    const ddi = ddiOf(options)
    let n = onlyDigits(national)

    if (n === '') {
        return ''
    }

    if (ddi === '55') {
        n = ensureBrazilNinthDigit(n)
    }

    return ddi + n
}

/**
 * Normaliza um telefone digitado (em qualquer forma) para o `wa_id` da Meta.
 * Aceita entrada com ou sem DDI e devolve dígitos puros `DDI+DDD+número`.
 */
export function normalizeMetaPhone(raw: string | null | undefined, options: NormalizeOptions = {}): string {
    return nationalToWaId(toNationalDigits(raw, options), options)
}

/** Valida o `wa_id` no mesmo critério do pacote em PHP: 8 a 15 dígitos. */
export function isValidMetaPhone(waId: string | null | undefined): boolean {
    return /^\d{8,15}$/.test(onlyDigits(waId))
}

/**
 * Máscara de exibição do número NACIONAL (sem DDI): `(48) 99999-9999`.
 * Usada no campo editável, onde o DDI aparece como prefixo à parte.
 */
export function formatNationalBR(national: string | null | undefined): string {
    const d = onlyDigits(national)

    if (d === '') {
        return ''
    }

    const ddd = d.slice(0, 2)
    const sub = d.slice(2)

    if (d.length <= 2) {
        return `(${d}`
    }
    if (sub.length <= 4) {
        return `(${ddd}) ${sub}`
    }
    if (sub.length <= 8) {
        // Durante a digitação (ou fixo): 4+4.
        return `(${ddd}) ${sub.slice(0, 4)}-${sub.slice(4)}`
    }
    // Celular (9 dígitos): 5+4.
    return `(${ddd}) ${sub.slice(0, sub.length - 4)}-${sub.slice(-4)}`
}

/**
 * Máscara de exibição a partir de um `wa_id` COMPLETO (com DDI): `+55 (48) 99999-9999`.
 * Útil para exibir números já salvos fora do campo de edição.
 */
export function formatPhoneBR(raw: string | null | undefined, options: NormalizeOptions = {}): string {
    const d = onlyDigits(raw)

    if (d === '') {
        return ''
    }

    const ddi = ddiOf(options)

    if (d.startsWith(ddi)) {
        const national = d.slice(ddi.length)
        const masked = formatNationalBR(national)
        return masked === '' ? `+${ddi}` : `+${ddi} ${masked}`
    }

    return `+${d}`
}

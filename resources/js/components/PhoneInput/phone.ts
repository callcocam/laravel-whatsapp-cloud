/**
 * Padrão de telefone para a WhatsApp Cloud API (Meta).
 *
 * A Meta espera o destinatário (`wa_id`) em DÍGITOS PUROS no formato
 * `DDI + DDD + número`, sem `+`, com 8 a 15 dígitos — ex.: `5548999999999`.
 *
 * Este módulo é agnóstico de framework (não importa Vue) para poder ser
 * reutilizado por qualquer app, com ou sem design system.
 */

export interface NormalizeOptions {
    /** Código do país (DDI) a prepender quando o número vier sem ele. Padrão: Brasil (55). */
    defaultCountry?: string
}

/** Remove tudo que não é dígito. */
export function onlyDigits(value: string | null | undefined): string {
    return String(value ?? '').replace(/\D+/g, '')
}

/**
 * Normaliza um telefone digitado para o `wa_id` da Meta (dígitos puros).
 *
 * Regras (com `defaultCountry='55'`, padrão BR):
 *  1. Tira não-dígitos.
 *  2. Se já começa com o DDI e tem tamanho de número completo, mantém.
 *  3. Se veio só com DDD + número (10 ou 11 dígitos), prepende o DDI.
 *  4. Garante o 9º dígito do celular BR: se, após DDI+DDD, a parte local tem
 *     8 dígitos e começa com 6–9 (celular), insere o `9`.
 *
 * Números de outros países (defaultCountry != '55') sofrem apenas os passos 1–3,
 * sem a lógica do 9º dígito.
 */
export function normalizeMetaPhone(raw: string | null | undefined, options: NormalizeOptions = {}): string {
    const ddi = onlyDigits(options.defaultCountry ?? '55') || '55'
    let d = onlyDigits(raw)

    if (d === '') {
        return ''
    }

    // Já contém o DDI e tem cauda plausível (DDD + número) → não prepende de novo.
    const hasDdi = d.startsWith(ddi) && d.length >= ddi.length + 10

    if (!hasDdi) {
        // DDD (2) + número local (8 fixo ou 9 celular) = 10 ou 11 dígitos.
        if (d.length === 10 || d.length === 11) {
            d = ddi + d
        } else if (d.length < 10) {
            // Sem DDD reconhecível — prepende o DDI mesmo assim e deixa a validação decidir.
            d = ddi + d
        }
        // length > 11 sem DDI conhecido: assume que já é internacional, mantém como está.
    }

    if (ddi === '55') {
        d = ensureBrazilNinthDigit(d)
    }

    return d
}

/**
 * Insere o 9º dígito em celulares BR quando ausente.
 * Espera `55 + DDD(2) + local`. Se o local tem 8 dígitos e começa com 6–9,
 * vira 9 dígitos com o `9` na frente.
 */
export function ensureBrazilNinthDigit(waId: string): string {
    const d = onlyDigits(waId)

    if (!d.startsWith('55') || d.length !== 12) {
        return d
    }

    const ddd = d.slice(2, 4)
    const local = d.slice(4) // 8 dígitos

    if (local.length === 8 && /^[6-9]/.test(local)) {
        return `55${ddd}9${local}`
    }

    return d
}

/** Valida o `wa_id` no mesmo critério do pacote em PHP: 8 a 15 dígitos. */
export function isValidMetaPhone(waId: string | null | undefined): boolean {
    return /^\d{8,15}$/.test(onlyDigits(waId))
}

/**
 * Máscara de exibição a partir de dígitos.
 *  - Celular BR (55 + DDD + 9 dígitos): `+55 (48) 99999-9999`
 *  - Fixo BR    (55 + DDD + 8 dígitos): `+55 (48) 9999-9999`
 *  - Outros DDIs / tamanhos parciais: agrupamento simples com `+DDI ...`.
 */
export function formatPhoneBR(raw: string | null | undefined): string {
    const d = onlyDigits(raw)

    if (d === '') {
        return ''
    }

    if (d.startsWith('55')) {
        const rest = d.slice(2)
        const ddd = rest.slice(0, 2)
        const local = rest.slice(2)

        if (ddd === '') {
            return '+55'
        }
        if (local === '') {
            return `+55 (${ddd}`
        }
        if (local.length <= 4) {
            return `+55 (${ddd}) ${local}`
        }
        if (local.length <= 8) {
            // Fixo: 4+4
            return `+55 (${ddd}) ${local.slice(0, 4)}-${local.slice(4)}`
        }
        // Celular: 5+4 (usa os últimos 4 como sufixo).
        const head = local.slice(0, local.length - 4)
        const tail = local.slice(-4)
        return `+55 (${ddd}) ${head}-${tail}`
    }

    // Fallback para outros países: mostra `+` e os dígitos.
    return `+${d}`
}

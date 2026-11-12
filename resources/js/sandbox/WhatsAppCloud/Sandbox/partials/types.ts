/* Shapes do sandbox do WhatsApp Cloud.
 *
 * Espelham o que o painel devolve em /state e /messages/{id}. Ficam num arquivo à
 * parte porque as três telas (Index, Bubble, Inspector) falam dos mesmos objetos —
 * e porque tipar o `ref(null)` de uma mensagem inspecionada é o que impede o
 * `message.wamid` de virar erro só em produção. */

export type Direction = 'inbound' | 'outbound';

export type MessageStatus = 'sent' | 'delivered' | 'read' | 'failed';

/** Um botão do template, como a Meta o descreve. */
export interface TemplateButton {
    text: string;
    type: 'QUICK_REPLY' | 'URL' | 'PHONE_NUMBER' | string;
}

export interface MessageTemplate {
    name: string;
    header?: string | null;
    footer?: string | null;
    /** 'definition' = veio de um arquivo local; senão, da Meta. */
    source?: string | null;
    buttons?: TemplateButton[];
}

/** Opção de uma mensagem interativa (botão ou linha de lista). */
export interface MessageOption {
    id: string;
    /** Cada forma volta num webhook diferente — por isso a opção carrega o tipo. */
    kind: string;
    title: string;
}

/** A photo, file or audio the contact sent, served at /messages/{id}/media. */
export interface MessageMedia {
    id: string;
    kind: 'image' | 'document' | 'audio' | string;
    mime_type: string;
    filename?: string | null;
    /** A voice note recorded in WhatsApp (audio only). */
    voice?: boolean;
}

export interface SandboxMessage {
    id: number;
    wamid: string;
    type: string;
    direction: Direction;
    status?: MessageStatus | null;
    text: string;
    at: string;
    error_code?: string | number | null;
    warnings?: string[];
    template?: MessageTemplate | null;
    options?: MessageOption[];
    media?: MessageMedia | null;
}

export interface SandboxConversation {
    id: number;
    wa_id: string;
    name: string;
    role?: string | null;
    /** A janela de 24 h da Meta: fora dela, só template passa. */
    window_open: boolean;
    armed_fault?: string | null;
}

export interface SandboxTemplate {
    name: string;
    language: string;
    /** Quantas variáveis {{n}} o corpo tem. */
    variables: number;
}

export interface SandboxFault {
    key: string;
    title: string;
    code?: string | number | null;
    terminal: boolean;
}

export interface SandboxBusiness {
    display_phone_number: string;
    phone_number_id: string;
}

/** O que um listener do app lançou ao processar o webhook. */
export interface ListenerFailure {
    class: string;
    message: string;
    file: string;
}

/**
 * Detalhe de uma mensagem, buscado sob demanda: os payloads crus não vêm no
 * polling porque seriam centenas de KB por segundo.
 */
export interface MessageDetail {
    envelope?: unknown;
    inbound_payload?: unknown;
    meta?: {
        listeners?: string[];
        failure?: ListenerFailure | null;
        signature?: string;
        status?: number;
    };
}

/** Um contato do app oferecido para importar (SandboxRecipientProvider). */
export interface SandboxRecipient {
    wa_id: string;
    name: string;
    role: string;
    group?: string | null;
    /** Já existe uma conversa com este número. */
    imported?: boolean;
}

/** O estado do painel, tal como /state o devolve. */
export interface SandboxState {
    conversations: SandboxConversation[];
    selected: number | null;
    messages: SandboxMessage[];
    faults: SandboxFault[];
    templates: SandboxTemplate[];
    recipients?: SandboxRecipient[];
}

/** O flash que o painel devolve quando uma ação falha. */
export interface SandboxFlash {
    error?: string;
    terminal?: boolean;
}

/**
 * Payload do evento `tap`: um toque num botão vira webhook.
 *
 * `type` e não `interface` de propósito — só um type alias é atribuível a
 * Record<string, unknown>, que é o que o `act()` do painel recebe.
 */
export type TapPayload = {
    kind: string;
    text: string;
    reply_to: string;
    id?: string;
};

# Meta Developer Tools MCP

Integração com o **Meta Developer Tools MCP** para gerenciar aplicações Meta (WhatsApp, Facebook, Instagram) diretamente do Claude Code.

## 📋 O que é Meta Developer Tools MCP?

O Meta Developer Tools MCP é um servidor de protocolo MCP (Model Context Protocol) que permite você:

- **Inspecionar aplicações Meta** - Ver configurações, permissões e status
- **Monitorar saúde da Graph API** - Rate limits, deprecações, mudanças
- **Gerenciar webhooks** - Configurar e testar subscrições
- **Preparar App Review** - Checagem de conformidade
- **Acessar documentação Meta** - Buscar respostas nos docs oficiais

Referência oficial: https://developers.facebook.com/documentation/mcp/devtools-mcp

## 🚀 Setup Rápido

### Pré-requisitos

- Claude Code (CLI ou VSCode)
- Node.js 18+ (para executar o MCP server via npx)
- Uma conta Meta Developer com apps criados

### 1. Instalação Automática

```bash
# Clone/entre no diretório do projeto
cd /home/caltj/projects/laravel-whatsapp-cloud

# O arquivo `.claude/mcp.json` já vem configurado!
# Reinicie o Claude Code para ativar:
```

Se usar Claude Code CLI:
```bash
claude restart
```

Se usar VSCode: reload a janela (Cmd+R / Ctrl+Shift+R)

### 2. Authorize com Meta Developer Platform

Na primeira vez que usar, o MCP server pedirá autorização:

```
Claude Code → MCP Servers → meta-devtools → [Connect]
```

Você será levado para a Meta Developer Platform para:
1. Fazer login com sua conta Meta
2. Escolher qual app Meta deseja gerenciar
3. Autorizar o acesso

> **Nota:** A sessão fica salva em `~/.claude/mcp-cache/`, então você só faz isso uma vez.

## 📝 Uso Básico

### Descobrir seus apps

Após autorizar, você pode usar o Meta Developer Tools MCP no Claude Code:

```
@devtools list my apps
```

Isso mostra os App IDs de todas as aplicações Meta que você controla.

### Exemplos de Tarefas

**Inspecionar configuração de um app:**
```
Check the settings and permissions of my WhatsApp Business app (App ID: 123456789)
```

**Monitorar rate limits da Graph API:**
```
What are the current rate limit status and any deprecations for my apps?
```

**Configurar webhook:**
```
Help me set up a webhook subscription for WhatsApp message_status events
```

**Preparar para App Review:**
```
Run a compliance check on my WhatsApp app - what do I need to fix for App Review?
```

## 🔧 Configuração Avançada

### Variáveis de Ambiente

Se precisar de debugging detalhado, edite `.claude/mcp.json`:

```json
{
  "mcpServers": {
    "meta-devtools": {
      "command": "npx",
      "args": ["-y", "@anthropic-ai/mcp-server-meta-devtools"],
      "env": {
        "DEBUG": "mcp:*",
        "LOG_LEVEL": "debug"
      }
    }
  }
}
```

### Usar em Settings Locais

Se tiver múltiplos projetos, pode também configurar em `.claude/settings.local.json`:

```json
{
  "mcp": {
    "meta-devtools": {
      "command": "npx",
      "args": ["-y", "@anthropic-ai/mcp-server-meta-devtools"]
    }
  }
}
```

## 🎯 Workflow Recomendado

### Desenvolvimento de Webhooks

1. **Inspecionar eventos suportados:**
   ```
   What webhook events are available for WhatsApp?
   ```

2. **Configurar subscrição:**
   ```
   Subscribe to message_status, message_echo, and message_echo events for my webhook
   ```

3. **Testar webhook:**
   ```
   Send a test event to my webhook endpoint to verify it's working
   ```

### Diagnóstico de Problemas

```
My WhatsApp app is getting rate-limited. Check the current API health and suggest fixes.
```

### Preparação para Produção

```
Do a compliance audit of my app before we go live. What's missing?
```

## 🔐 Segurança

- **Tokens salvos localmente** em `~/.claude/mcp-cache/`
- **Sem exposição em arquivos do projeto** - `.claude/mcp.json` contém apenas configuração
- **Escopo limitado** - Você só acessa apps que você controla na Meta Developer Platform
- **Revogável** - Remova o acesso a qualquer hora no Meta Developer Settings

## 🐛 Troubleshooting

### MCP server não inicia

```bash
# Teste a instalação manualmente
npx -y @anthropic-ai/mcp-server-meta-devtools
```

Se falhar, verifique:
- [ ] Node.js 18+ está instalado: `node --version`
- [ ] Internet está funcionando
- [ ] Nenhum firewall bloqueando conexões

### "Not authenticated with Meta"

Você precisa autorizar novamente:
1. Remova cache: `rm -rf ~/.claude/mcp-cache/meta-devtools`
2. Reinicie Claude Code
3. Tente usar novamente - vai pedir autorização

### Permissões insuficientes

Se um comando falhar com "Insufficient permissions":

1. Vá para [developers.facebook.com](https://developers.facebook.com)
2. Verifique seu papel na app (precisa ser Admin ou Developer)
3. Verifique as permissões do token

## 📚 Recursos

- [Meta Developer Tools MCP Docs](https://developers.facebook.com/documentation/mcp/devtools-mcp)
- [Meta Graph API Reference](https://developers.facebook.com/docs/graph-api)
- [WhatsApp Cloud API Docs](https://developers.facebook.com/docs/whatsapp/cloud-api)
- [Claude Code - MCP Setup](https://claude.com/docs/claude-code)

## 🤝 Integração com Laravel WhatsApp Cloud

Este pacote fornece a integração pronta para:

```php
// Seu código Laravel pode agora consultar o MCP para:
// - Validar credenciais com Meta
// - Verificar limite de requisições da API
// - Auditar configurações de webhook
// - Buscar status de App Review
```

Exemplo de workflow recomendado:

1. **Desenvolvimento local** - Use MCP para testar webhooks
2. **QA** - Use MCP para auditar permissões antes de staging
3. **Produção** - Use MCP para monitorar saúde da API em tempo real

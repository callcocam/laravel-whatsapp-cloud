# Meta Developer Tools MCP - Quick Start

**Quer integrar com Meta Developer Platform rapidinho?** 3 passos.

## 1️⃣ Run Setup Script

```bash
./bin/setup-mcp.sh
```

Isso valida seu ambiente e cria a configuração do MCP.

## 2️⃣ Restart Claude Code

**CLI:**
```bash
claude restart
```

**VSCode:**
- Reload window: Cmd+R (Mac) / Ctrl+Shift+R (Windows/Linux)

## 3️⃣ Authorize Meta

Na próxima vez que você tentar usar o Meta DevTools:
1. Claude Code pedirá permissão
2. Você vai pro Meta Developer Platform (login)
3. Escolha qual app Meta você quer gerenciar
4. Pronto! 🎉

---

## Exemplos de Uso

Agora você pode pedir coisas como:

```
@devtools List my apps
```

```
@devtools Check the webhooks configuration for my WhatsApp app
```

```
@devtools What are the current API rate limits?
```

```
@devtools Run a compliance audit of my app
```

---

## 📖 Full Documentation

Para exemplos mais avançados e troubleshooting:
→ [DEVTOOLS_MCP.md](DEVTOOLS_MCP.md)

---

## Isso é tudo?

Sim! O arquivo `.claude/mcp.json` já está no repositório com tudo configurado.

Se algo não funcionar:
```bash
rm -rf ~/.claude/mcp-cache/meta-devtools
# Reinicie e tente novamente
```

Pronto! 🚀

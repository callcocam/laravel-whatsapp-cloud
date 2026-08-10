#!/bin/bash
set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${BLUE}🔧 Meta Developer Tools MCP Setup${NC}"
echo ""

# Check if we're in the right directory
if [ ! -f "composer.json" ]; then
    echo -e "${RED}✗ Error: composer.json not found${NC}"
    echo "Please run this script from the project root directory"
    exit 1
fi

# Check if .claude directory exists
if [ ! -d ".claude" ]; then
    echo -e "${YELLOW}→ Creating .claude directory${NC}"
    mkdir -p .claude
fi

# Check if mcp.json already exists
if [ -f ".claude/mcp.json" ]; then
    echo -e "${GREEN}✓ .claude/mcp.json already exists${NC}"
else
    echo -e "${YELLOW}→ Creating .claude/mcp.json${NC}"
    cat > .claude/mcp.json << 'EOF'
{
  "mcpServers": {
    "meta-devtools": {
      "command": "npx",
      "args": [
        "-y",
        "@anthropic-ai/mcp-server-meta-devtools"
      ],
      "env": {
        "DEBUG": "mcp:*"
      }
    }
  }
}
EOF
    echo -e "${GREEN}✓ Created .claude/mcp.json${NC}"
fi

# Check Node.js
echo ""
echo -e "${BLUE}→ Checking requirements${NC}"

if ! command -v node &> /dev/null; then
    echo -e "${RED}✗ Node.js is not installed${NC}"
    echo "Please install Node.js 18+ from https://nodejs.org/"
    exit 1
fi

NODE_VERSION=$(node -v | cut -d 'v' -f 2 | cut -d '.' -f 1)
if [ "$NODE_VERSION" -lt 18 ]; then
    echo -e "${RED}✗ Node.js 18+ is required (you have v$NODE_VERSION)${NC}"
    exit 1
fi

echo -e "${GREEN}✓ Node.js $(node -v) installed${NC}"

# Test MCP server can be downloaded
echo ""
echo -e "${BLUE}→ Testing MCP server availability${NC}"
if npx -y @anthropic-ai/mcp-server-meta-devtools --version &> /dev/null; then
    echo -e "${GREEN}✓ Meta Developer Tools MCP server is available${NC}"
else
    echo -e "${YELLOW}! Warning: Could not test MCP server (might need internet)${NC}"
fi

# Final instructions
echo ""
echo -e "${GREEN}✅ Setup complete!${NC}"
echo ""
echo -e "${BLUE}Next steps:${NC}"
echo "1. Restart Claude Code:"
echo "   - CLI: run 'claude restart'"
echo "   - VSCode: Reload window (Cmd+R / Ctrl+Shift+R)"
echo ""
echo "2. Authorize with Meta Developer Platform:"
echo "   - Go to Claude Code → MCP Servers → meta-devtools → [Connect]"
echo "   - You'll be guided through Meta login and app selection"
echo ""
echo "3. Start using Meta Developer Tools!"
echo "   - Check the DEVTOOLS_MCP.md file for usage examples"
echo ""
echo -e "${BLUE}Documentation:${NC}"
echo "   📖 Read more: $(pwd)/DEVTOOLS_MCP.md"
echo ""

# Mudlet ↔ ChatGPT: Local MCP sandbox smoke tests

**Scope.** This is a small test kit authored using ChatGPT. By default it tests a simulated Mudlet-compatible MCP endpoint **inside a localhost-only sandbox**. It does **not** run or modify Mudlet and does **not** connect to the OpenAI API or claim that a full ChatGPT↔Mudlet integration has been verified. The simulated server is intentionally stricter than the real Mudlet server; its allowlist is only a test harness safeguard.

## Run simulated sandbox tests

```shell
python3 mudlet_mcp_sandbox.py
```

The script checks tool discovery, harmless Lua responses, `print()` output, rejection of unapproved expressions **in the mock only**, unauthorized requests, non-local and spoofed-loopback `Origin` headers, legacy GET, older protocol versions, bad tool names, and Bearer header authentication. No external network or API key is required.

## Run harmless live test against your own Mudlet (optional)

1. Install a Mudlet public test build with the merged PR #8576, or a later stable release containing the feature.
2. In Mudlet Preferences → General → AI assistant access (MCP), enable **Let an AI assistant run Lua in Mudlet**.
3. Under **Something else**, copy the temporary local URL (contains a secret token).
4. On the **same computer as Mudlet**, run:

```shell
python3 mudlet_mcp_sandbox.py --live 'http://127.0.0.1:11235/mcp/REPLACE_WITH_CURRENT_TOKEN'
```

Live mode calls `tools/list`, then runs `return 2 + 2` in the current Mudlet profile. It does not send MUD commands or write data. **Do not publicly share the URL/token.** You must run this file on your machine; ChatGPT in this chat does not have access to your local Mudlet process.

## Test ChatGPT Desktop (separate manual step)

Mudlet Preferences → General → AI assistant access (MCP) → **ChatGPT** button → restart ChatGPT desktop. In a thread using a local ChatGPT plugin, ask: “Use Mudlet MCP to run only `return 2 + 2` in my current profile; show the result. Do not edit scripts or send game commands.” This step depends on the ChatGPT desktop app's available plugins and permissions. It is not the same as running the Python smoke-test client.

## Extra conservative testing policy

- Only connect to your own local server and only with authorization.
- Prefer isolated test profiles, never real accounts or private game credentials.
- No `os.execute`, `io.popen`, shell commands, arbitrary file changes, downloads, destructive or malware tests, or unapproved game actions.
- Read-only requests by default; get explicit consent before changing any scripts or sending game commands.
- Protect tokens, logs, and any collected personal data. Follow the platform's rate and safety limits; sandboxing does not waive terms or policy obligations.
- Mudlet's **real** `lua` tool allows arbitrary Lua when enabled; the mock test's restricted allowlist does **not** constrain Mudlet's real implementation. Never assume the real server enforces the extra policy above.

## Sources

- Mudlet documentation: https://wiki.mudlet.org/w/Area_51 (Model Context Protocol)
- Mudlet pull request: https://github.com/Mudlet/Mudlet/pull/8576 (merged October 10, 2026)
- OpenAI Terms of Use (individuals): https://openai.com/policies/row-terms-of-use/
- OpenAI Developer Apps Terms: https://openai.com/policies/developer-apps-terms/
- MCP protocol version: https://modelcontextprotocol.io/specification/2026-07-28/basic/versioning

## License and provenance

**SPDX license:** `CC0-1.0` — Creative Commons Zero 1.0 Universal Public Domain Dedication (see [LICENSE](LICENSE)).
To the extent allowed by law, the publisher dedicates the original code, documentation, and test materials in this kit to the public domain. Reuse, modify, redistribute, and use commercially without requesting permission or providing attribution. CC0 includes a fallback license where a public-domain dedication is not legally effective.

This project was **generated with assistance from OpenAI ChatGPT** and then inspected and run in a local sandbox. It is **not an official project or product** of Mudlet, OpenAI, or the MCP maintainers, and they do not endorse it. The CC0 declaration covers only the original material in this repository, not Mudlet, ChatGPT, the MCP specification, third-party content linked for reference, logos, trademarks, patents, or other parties' rights.

**Safety note:** This is a smoke-test harness, not a secure general-purpose Lua sandbox. The mock server's allowlist does not exist in Mudlet's actual MCP server. Never point an untrusted assistant at a live Mudlet profile with sensitive data.

## License references

- https://creativecommons.org/publicdomain/zero/1.0/
- https://spdx.org/licenses/CC0-1.0.html

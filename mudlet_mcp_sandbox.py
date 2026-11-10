#!/usr/bin/env python3
"""Mudlet MCP sandbox smoke-test client and isolated mock server.

DEFAULT: local in-process mock, not the real Mudlet application.
LIVE: - connects only to explicit loopback Mudlet MCP endpoints, and performs
      tools/list + the harmless Lua expression `return 2 + 2`.
No OpenAI credentials, API calls, downloads, game commands, or Lua file access.
Requires Python 3.9+ standard library only.

SPDX-License-Identifier: CC0-1.0
"""
import argparse
import contextlib
import http.client
import json
import secrets
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlparse

PROTOCOL = "2026-07-28"
VERSION_META = "io.modelcontextprotocol/protocolVersion"
CAP_META = "io.modelcontextprotocol/clientCapabilities"
TOOL = {"name": "lua", "description": "Evaluate Lua within a Mudlet profile", "inputSchema": {"type": "object", "properties": {"code": {"type": "string"}, "profile": {"type": "string"}}, "required": ["code"]}}


def rpc(method, params=None, request_id=1):
    payload = {"jsonrpc": "2.0", "id": request_id, "method": method, "params": (params or {})}
    payload["params"]["_meta"] = {
        VERSION_META: PROTOCOL,
        CAP_META: {"tools": {}},
        "io.modelcontextprotocol/clientInfo": {"name": "MudletSandboxTest", "version": "1.0"},
    }
    return payload


def error(code, message, request_id):
    return {"jsonrpc": "2.0", "id": request_id, "error": {"code": code, "message": message}}


def ok(result, request_id):
    return {"jsonrpc": "2.0", "id": request_id, "result": result}


class MockMudlet(BaseHTTPRequestHandler):
    token = None
    def log_message(self, *args):
        # Avoid echoing the bearer token to logs.
        return

    def respond(self, status, payload=None):
        message = json.dumps(payload or {}).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(message)))
        self.end_headers()
        self.wfile.write(message)

    def do_GET(self):
        self.respond(405, {"error": "Method Not Allowed"})

    def do_DELETE(self):
        self.respond(405, {"error": "Method Not Allowed"})

    def do_POST(self):
        origin = self.headers.get("Origin", "")
        if origin and not (urlparse(origin).scheme == "http" and urlparse(origin).hostname in ("127.0.0.1", "localhost")):
            return self.respond(403, {"error": "Non-local Origin refused"})
        bearer = self.headers.get("Authorization", "")
        authorized = (self.path == "/mcp" and bearer == "Bearer " + self.token) or self.path == "/mcp/" + self.token
        if not authorized:
            return self.respond(401, {"error": "Invalid token"})
        try:
            size = int(self.headers.get("Content-Length", "0"))
            if size <= 0 or size > 50000:
                return self.respond(413, {"error": "Invalid request length"})
            msg = json.loads(self.rfile.read(size))
        except (ValueError, json.JSONDecodeError):
            return self.respond(400, error(-32700, "Parse error", None))
        mid = msg.get("id")
        params = msg.get("params") or {}
        meta = params.get("_meta") or {}
        if self.headers.get("MCP-Protocol-Version") != PROTOCOL or meta.get(VERSION_META) != PROTOCOL:
            return self.respond(400, error(-32022, "Unsupported protocol version", mid))
        if CAP_META not in meta:
            return self.respond(400, error(-32602, "Missing capabilities", mid))
        method = msg.get("method")
        if self.headers.get("Mcp-Method") != method:
            return self.respond(400, error(-32602, "Method header mismatch", mid))
        if method == "tools/list":
            return self.respond(200, ok({"tools": [TOOL], "resultType": "complete"}, mid))
        if method == "tools/call":
            if self.headers.get("Mcp-Name") != params.get("name"):
                return self.respond(400, error(-32602, "Tool header mismatch", mid))
            if params.get("name") != "lua":
                return self.respond(400, error(-32601, "Unknown tool", mid))
            code = (params.get("arguments") or {}).get("code")
            if code == "return 2 + 2":
                return self.respond(200, ok({"content": [{"type": "text", "text": "4"}], "isError": False}, mid))
            if code == 'print("Sandbox test only")':
                return self.respond(200, ok({"content": [{"type": "text", "text": "Sandbox test only"}], "isError": False}, mid))
            return self.respond(200, ok({"content": [{"type": "text", "text": "Sandbox policy: only allowlisted, non-mutating test expressions"}], "isError": True}, mid))
        return self.respond(400, error(-32601, "Method not found", mid))


def send(url, method, payload=None, token=None, headers=None):
    parsed = urlparse(url)
    if parsed.scheme != "http" or parsed.hostname not in ("127.0.0.1", "localhost"):
        raise ValueError("Refusing non-local connection. This kit only accesses 127.0.0.1/localhost over HTTP.")
    connection = http.client.HTTPConnection(parsed.hostname, parsed.port, timeout=3)
    body = json.dumps(payload).encode() if payload else None
    h = {"Content-Type": "application/json", "Accept": "application/json, text/event-stream", "MCP-Protocol-Version": PROTOCOL}
    if payload:
        h["Mcp-Method"] = payload["method"]
        if payload["method"] == "tools/call":
            h["Mcp-Name"] = payload["params"]["name"]
    if token:
        h["Authorization"] = "Bearer " + token
    h.update(headers or {})
    try:
        connection.request(method, parsed.path, body=body, headers=h)
        res = connection.getresponse()
        raw = res.read()
        try:
            decoded = json.loads(raw)
        except json.JSONDecodeError:
            decoded = {"non_json_response": raw[:300].decode("utf-8", "replace")}
        return res.status, decoded
    finally:
        connection.close()


def run_mock():
    token = secrets.token_hex(24)
    MockMudlet.token = token
    server = ThreadingHTTPServer(("127.0.0.1", 0), MockMudlet)
    task = threading.Thread(target=server.serve_forever, daemon=True)
    task.start()
    root = "http://127.0.0.1:%d/mcp" % server.server_address[1]
    path_url = root + "/" + token
    results = []
    def assert_test(name, condition):
        results.append((name, bool(condition)))
        print(("PASS" if condition else "FAIL") + " - " + name)
    try:
        status, data = send(path_url, "POST", rpc("tools/list"))
        assert_test("tools/list offers lua tool", status == 200 and any(t.get("name") == "lua" for t in data.get("result", {}).get("tools", [])))
        status, data = send(path_url, "POST", rpc("tools/call", {"name": "lua", "arguments": {"code": "return 2 + 2"}}))
        assert_test("safe Lua example returns 4", status == 200 and "4" in str(data.get("result", {}).get("content", [])))
        status, data = send(path_url, "POST", rpc("tools/call", {"name": "lua", "arguments": {"code": 'print("Sandbox test only")'}}))
        assert_test("print output returned", status == 200 and "Sandbox test only" in str(data))
        status, data = send(path_url, "POST", rpc("tools/call", {"name": "lua", "arguments": {"code": 'os.execute("example")'}}))
        assert_test("sandbox blocks unapproved execution", status == 200 and data.get("result", {}).get("isError") is True)
        status, data = send(root + "/wrong-token", "POST", rpc("tools/list"))
        assert_test("bad token rejected", status == 401)
        status, data = send(path_url, "POST", rpc("tools/list"), headers={"Origin": "https://not-local.invalid"})
        assert_test("nonlocal Origin rejected", status == 403)
        status, data = send(path_url, "POST", rpc("tools/list"), headers={"Origin": "http://127.0.0.1.evil.invalid"})
        assert_test("spoofed loopback Origin rejected", status == 403)
        status, data = send(path_url, "GET")
        assert_test("legacy GET rejected", status == 405)
        status, data = send(path_url, "POST", rpc("tools/list"), headers={"MCP-Protocol-Version": "2025-06-18"})
        assert_test("old MCP version rejected", status == 400)
        status, data = send(path_url, "POST", rpc("tools/call", {"name": "not-a-tool", "arguments": {}}))
        assert_test("unknown MCP tool rejected", status == 400)
        status, data = send(root, "POST", rpc("tools/list"), token=token)
        assert_test("Bearer auth header accepted", status == 200)
    finally:
        server.shutdown()
        server.server_close()
        task.join(timeout=2)
    good = sum(p for _, p in results)
    print(f"\nSandbox mock tests: {good}/{len(results)} PASS")
    return good == len(results)


def run_live(endpoint):
    print("LIVE MODE: harmless calls to a running Mudlet MCP server; does not use the ChatGPT API.")
    status, data = send(endpoint, "POST", rpc("tools/list"))
    tools = data.get("result", {}).get("tools", [])
    if status != 200 or not any(t.get("name") == "lua" for t in tools):
        print("FAIL: Mudlet MCP tools/list did not return lua. Status:", status, "Body:", str(data)[:500])
        return False
    print("PASS: Mudlet advertises Lua tool")
    status, data = send(endpoint, "POST", rpc("tools/call", {"name": "lua", "arguments": {"code": "return 2 + 2"}}, 2))
    success = status == 200 and "4" in str(data.get("result", {}).get("content", [])) and not data.get("result", {}).get("isError", False)
    print(("PASS" if success else "FAIL") + ": harmless Lua calculation", "Status:", status, "Body:", str(data)[:500])
    return success


def main():
    parser = argparse.ArgumentParser(description="Test a Mudlet-compatible MCP server locally")
    parser.add_argument("--live", metavar="LOCAL_MCP_URL", help="Connect to actual local Mudlet at http://127.0.0.1:PORT/mcp/TOKEN")
    args = parser.parse_args()
    try:
        success = run_live(args.live) if args.live else run_mock()
    except (OSError, ValueError, TimeoutError) as exc:
        print("FAIL:", exc)
        success = False
    raise SystemExit(0 if success else 1)


if __name__ == "__main__":
    main()

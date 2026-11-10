#!/usr/bin/env python3
"""Exercise the PHP HTTP boundary on an ephemeral loopback server.

Usage: python3 tests/http_api.py
Optional: PHP_BIN=/path/to/php python3 tests/http_api.py
Requires Python 3 and PHP 8.2+, no Python packages or external network.
"""

import http.cookiejar
import json
import os
from pathlib import Path
import secrets
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request


ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get("PHP_BIN", "php")
if not shutil.which(PHP):
    raise SystemExit("PHP não encontrado. Instale PHP 8.2+ ou defina PHP_BIN=/caminho/php.")

with socket.socket() as listener:
    listener.bind(("127.0.0.1", 0))
    port = listener.getsockname()[1]

base = f"http://127.0.0.1:{port}"
password = secrets.token_urlsafe(32)
jar = http.cookiejar.CookieJar()
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
checks = 0


def request(action, data=None, origin=None, method=None):
    headers = {}
    if data is not None:
        headers["Content-Type"] = "application/json"
    if origin is not None:
        headers["Origin"] = origin
    req = urllib.request.Request(
        base + "/api.php?action=" + action,
        data=json.dumps(data).encode() if data is not None else None,
        headers=headers,
        method=method,
    )
    try:
        with client.open(req, timeout=5) as response:
            return response.status, json.load(response), response.headers
    except urllib.error.HTTPError as error:
        return error.code, json.load(error), error.headers


def check(condition, message):
    global checks
    if not condition:
        raise RuntimeError(message)
    checks += 1


with tempfile.TemporaryDirectory(prefix="mudlet-http-") as directory:
    environment = os.environ.copy()
    environment.update(
        APP_PASSWORD=password,
        APP_DEV_LOCAL="0",
        APP_STORAGE_DIR=str(Path(directory) / "storage"),
        APP_URL=base,
    )
    with open(Path(directory) / "server.log", "w+b") as log:
        process = subprocess.Popen(
            [PHP, "-S", f"127.0.0.1:{port}", "-t", str(ROOT / "public"), str(ROOT / "public/router.php")],
            cwd=ROOT,
            env=environment,
            stdin=subprocess.DEVNULL,
            stdout=log,
            stderr=subprocess.STDOUT,
        )
        try:
            deadline = time.monotonic() + 10
            while True:
                try:
                    status, session, _ = request("auth/session")
                    if status == 200:
                        break
                except (OSError, urllib.error.URLError):
                    pass
                if process.poll() is not None or time.monotonic() >= deadline:
                    log.seek(0)
                    raise RuntimeError("Servidor PHP não iniciou: " + log.read().decode(errors="replace"))
                time.sleep(0.1)

            check(session["configured"] and not session["authenticated"], "Metadata de autenticação")
            check(request("bootstrap")[0] == 401, "Acesso anônimo rejeitado")
            check(request("login", {"password": password})[0] == 403, "CSRF exigido no login")
            check(request("login", {"password": "incorrect", "csrf": session["csrf"]})[0] == 401, "Senha incorreta rejeitada")
            previous_csrf = session["csrf"]
            status, session, _ = request("login", {"password": password, "csrf": previous_csrf})
            check(status == 200 and session["authenticated"], "Login")
            check(session["csrf"] != previous_csrf, "CSRF renovado após login")
            csrf = session["csrf"]
            status, state, headers = request("bootstrap")
            check(status == 200 and all(key in state for key in ["issues", "runs", "integration_tests", "availability", "filters", "activity", "meta"]), "Coleções do bootstrap")
            check(headers.get("Cache-Control") == "no-store, private", "Dados privados sem cache")
            issue = {"id": 123456789, "title": "HTTP isolated fixture"}
            check(request("issue.save", {"csrf": csrf, "issue": issue}, origin="https://evil.invalid")[0] == 403, "Origem externa rejeitada")
            check(request("issue.save", {"csrf": csrf, "issue": issue})[0] == 200, "Escrita persistente")
            check(any(item["id"] == issue["id"] for item in request("bootstrap")[1]["issues"]), "Escrita mantida")
            check(request("filters.save", {"csrf": "wrong", "name": "x", "filters": {}})[0] == 403, "CSRF incorreto rejeitado")
            check(request("compare", {"csrf": csrf, "base": "short", "head": "bad"})[0] == 422, "Hashes inválidos rejeitados")
            status, exported, headers = request("export")
            check(status == 200 and "attachment" in headers.get("Content-Disposition", "") and any(item["id"] == issue["id"] for item in exported["issues"]), "Exportação persistente como anexo")
            check(request("bootstrap", method="DELETE")[0] == 405, "Método não suportado rejeitado")
            for path in ["/storage/state.json", "/%2e%2e/src/Store.php", "/router.php"]:
                try:
                    with client.open(base + path, timeout=5) as response:
                        code = response.status
                except urllib.error.HTTPError as error:
                    code = error.code
                check(code == 404, "Caminho privado rejeitado: " + path)
            check(request("logout", {"csrf": csrf})[0] == 200, "Logout")
            check(request("bootstrap")[0] == 401, "Logout invalida sessão")
            session = request("auth/session")[1]
            for _ in range(10):
                request("login", {"csrf": session["csrf"], "password": "incorrect"})
            status, _, headers = request("login", {"csrf": session["csrf"], "password": "incorrect"})
            check(status == 429 and int(headers["Retry-After"]) > 0, "Tentativas de login limitadas entre sessões")
            print(f"HTTP API: {checks} verificações aprovadas.")
        finally:
            process.terminate()
            try:
                process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait(timeout=5)

# Enviar ao seu GitHub

O projeto está pronto para publicar. A conexão desta sessão permitiu leitura, mas o GitHub recusou criação de repositório e escrita de branch com HTTP 403. Nenhum arquivo remoto foi alterado por esta entrega.

## Pelo navegador

1. Extraia `mudlet-control-room.zip`.
2. No seu GitHub, crie um repositório chamado `mudlet-control-room`.
3. Use **Add file → Upload files** e envie o conteúdo da pasta extraída. O arquivo `README.md`, `public/`, `src/` e `config/` devem ficar na raiz do repositório.
4. Inclua também os arquivos ocultos, especialmente `.github/workflows/validate.yml`, `.gitignore`, `.dockerignore` e `.env.example`. Não envie um arquivo `.env` com senha nem o conteúdo de `storage/`, exceto `.gitkeep`.
5. Confirme o commit. A aba **Actions** mostrará os testes do painel. O workflow diário executa na branch padrão; ele publica snapshots como artefatos, sem atualizar uma hospedagem PHP separada.

O seed contém 12.644 issues/PRs, tem aproximadamente 12 MB e pode ser enviado ao GitHub. Comentários e builds detalhados estão presentes para os seis itens solicitados; use o CLI para outros IDs.

## Usando Git

Depois de criar um repositório vazio no GitHub:

```bash
cd mudlet-control-room
git init -b main
git add .
git commit -m "Adiciona painel PHP de validação Mudlet MCP"
git remote add origin https://github.com/SEU-USUARIO/mudlet-control-room.git
git push -u origin main
```

Autentique o Git usando os métodos normais do GitHub. Não coloque tokens na URL nem no código.

## Hospedar

GitHub distribui o código; GitHub Pages não executa PHP. Para o site funcionar, use PHP 8.2+ localmente, uma hospedagem PHP com raiz em `public/`, ou Docker. Instruções no README e em `docs/DEPLOY.md`.

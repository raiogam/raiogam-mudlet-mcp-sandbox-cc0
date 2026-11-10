# Hospedagem PHP

## Docker

```bash
cp .env.example .env
# Edite APP_PASSWORD e APP_URL.
docker compose up -d --build
```

O serviço responde em http://localhost:8080 e persiste o JSON em volume Docker. Para expor externamente use um proxy HTTPS, configure APP_URL e o indicador HTTPS no servidor. Para sincronizar o armazenamento do contêiner, passe GH_TOKEN pelo ambiente, sem colocá-lo em argumentos ou no repositório:

```bash
docker compose exec -e GH_TOKEN web php bin/github-sync.php --all --details
```

O Docker hospeda o painel; `127.0.0.1` dentro do contêiner é o próprio contêiner. Execute o smoke live diretamente no computador do Mudlet. O arquivo exportado pelo runner pode ser usado para preencher o formulário de evidências; o site não importa nem executa Lua automaticamente.

## Nginx + PHP-FPM

Exemplo (ajuste domínio, caminho e socket da sua versão PHP):

```nginx
server {
    listen 80;
    server_name mudlet.exemplo.invalid;
    root /srv/mudlet-control-room/public;
    index index.php;
    location / { try_files $uri $uri/ =404; }
    location ~ \.php$ {
        try_files $uri =404;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }
    location ~ /\. { deny all; }
}
```

Configure variáveis `APP_PASSWORD`, `APP_URL` e `APP_STORAGE_DIR` no pool PHP-FPM (atenção a `clear_env`). Configure TLS para o domínio antes de acesso externo. O arquivo `.env` não é lido automaticamente pelo PHP.

## Atualização contínua no servidor

Exemplo de cron a cada 30 minutos, com token configurado no ambiente protegido do serviço:

```cron
*/30 * * * * cd /srv/mudlet-control-room && flock -n /tmp/mudlet-sync.lock php bin/github-sync.php --pages=3 --details >> /var/log/mudlet-sync.log 2>&1
```

Faça uma sincronização inicial `--all`. A atualização limitada pode ser parcial se mais de 300 itens mudarem entre coletas; o painel registra cobertura. Refaça `--all` periodicamente ou use `--since` com paginação completa. Não execute o cron como root. Armazene logs fora de `public/`.

GitHub Actions não conecta ao seu computador nem altera a hospedagem PHP automaticamente. Os resultados de lá ficam em artefatos. Testes Mudlet com GUI/parser/sanitizers exigem uma máquina de teste adequada e são registrados separadamente.

## Smoke live contínuo na máquina do Mudlet

Com o site e Mudlet na mesma máquina, o runner pode gravar diretamente no mesmo `APP_STORAGE_DIR`. Configure `MUDLET_MCP_TOKEN` no ambiente protegido do serviço e atualize o SHA quando trocar o binário. Exemplo de cron a cada cinco minutos (substitua o SHA; não coloque o token na linha):

```cron
*/5 * * * * cd /srv/mudlet-control-room && flock -n /tmp/mudlet-mcp-check.lock php bin/mcp-check.php --mode=live --endpoint=http://127.0.0.1:11235/mcp --commit=SHA_COMPLETO_DO_BINARIO --profile=Sandbox --save >> /var/log/mudlet-mcp-check.log 2>&1
```

Use um perfil de sandbox desconectado do jogo. O comando realiza somente duas operações Lua fixas de leitura. Se o Mudlet estiver fechado ou MCP indisponível, registra falha de transporte. Falha pode ser indisponibilidade do ambiente; revise a evidência antes de atribuí-la ao código. O smoke continua sendo isolado e não fecha os quatro testes GUI/parser/portal.

Esse cron é um exemplo de configuração; não foi instalado automaticamente. Em outra hospedagem, mantenha o runner na máquina do Mudlet e transporte os relatórios sem expor o endpoint loopback.

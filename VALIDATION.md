# Validação e limites

Verificação de entrega em 10/11/2026 com PHP 8.4.27, Node e Python 3, em Linux.

## Painel

- **43 verificações backend**: gravação atômica, rollback, quatro escritores concorrentes, validação de SHA e endpoint, filtros, notas UTF-8 e rastreabilidade.
- **21 verificações HTTP**: autenticação, sessão, CSRF, origem, exportação, persistência, limitação de tentativas e bloqueio de arquivos privados.
- **13 verificações do runner**: mock explícito, armazenamento isolado, rejeição de endpoint externo/token no caminho, protocolo incompatível, SHA obrigatório em live, ausência de token no relatório e hash de fonte reproduzível.
- **11 verificações de paginação GitHub**: cursores da API, paginação clássica, fim da coleção e rejeição de links para outra origem/coleção.
- Sintaxe de todos os arquivos PHP e do JavaScript verificada.
- Renderização das sete áreas e paginação de grande inventário verificadas em um harness JavaScript, sem navegador.
- Sincronização GitHub consultada com extensão cURL e com fallback executável cURL; cobertura por coleção registrada.
- Inventário real de 12.644 issues/PRs coletado por 127 páginas por cursor; detalhamento dos seis itens solicitado completo nas coleções consultadas. A API HTTP foi retestada com esse snapshot completo.

Os testes usam diretórios temporários e não inserem resultados simulados no seed entregue. A automação em `.github/workflows/validate.yml` repete os testes do painel e publica o relatório **mock** como artefato. Ela não aprova testes do Mudlet.

## Mudlet e integração MCP

Nenhum binário Mudlet foi compilado, instalado ou executado nesta entrega. Não houve execução MCP live, GUI, parser, suite completa, portal SNAP nem E2E Mudlet–ChatGPT. A identidade do binário é informada pelo operador e precisa ser conferida com o commit real.

As quatro integrações são um **plano local proposto**, inicialmente pendente. A documentação pesquisada não afirma que o upstream tenha exatamente quatro testes de integração faltantes. Fechamento de issue, merge, checks do GitHub, relatos de terceiros e resultados mock têm seu próprio escopo.

Os hashes no seed foram calculados sobre fontes fixadas em commit e conferidos contra blobs Git; isso prova a integridade do texto consultado, não execução do código.

## Interface e hospedagem

A infraestrutura de controle do navegador não estava disponível nesta sessão. A interface passou por verificação de sintaxe e renderização em harness, sem inspeção visual ou teste de interação em navegador real. Docker e configuração Nginx são fornecidos como caminhos de instalação e não foram executados nesta entrega.

O repositório GitHub distribui o código PHP. É necessário executar em uma hospedagem PHP; GitHub Pages não oferece execução PHP. Veja README e DEPLOY.md.

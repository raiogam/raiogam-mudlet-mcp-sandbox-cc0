# Pesquisa de referência Mudlet / MCP

Coleta: 2026-11-10T14:16:29.051147+00:00. Fonte primária: GitHub Mudlet/Mudlet.

Development consultado: `29f14a1e6e59d15cf52c80ab36c6e043d96b9224`. Commit dos relatos originais: `232fad36aafeb49094c28491bd2202b4aecc7a02`.

## Estado confirmado

- [issue #12189](https://github.com/Mudlet/Mudlet/issues/12189): MXP mailto pode anexar arquivos locais. GitHub: **closed**. Validação local/E2E pendente.
- [issue #12187](https://github.com/Mudlet/Mudlet/issues/12187): MXP SEND perde o comando após link OSC 8 aninhado. GitHub: **closed**. Validação local/E2E pendente.
- [issue #12185](https://github.com/Mudlet/Mudlet/issues/12185): Menu OSC 8 com um item e selection executa comando incorreto. GitHub: **closed**. Validação local/E2E pendente.
- [issue #12186](https://github.com/Mudlet/Mudlet/issues/12186): Spoiler OSC 8 com MXP SEND corrompe texto revelado. GitHub: **closed**. Validação local/E2E pendente.
- [pr #8576](https://github.com/Mudlet/Mudlet/pull/8576): Controle do Mudlet por assistentes via MCP. GitHub: **closed**; merged `e176c649fb32a060f3a35dc09e4edaa8743fcfb0`. Validação local/E2E pendente.
- [pr #12128](https://github.com/Mudlet/Mudlet/pull/12128): OSC 8 abre endereços mailto com filtro compartilhado. GitHub: **closed**; merged `7d52e37bb2972705e832801461271db1fd9b9723`. Validação local/E2E pendente.

Ordem solicitada de prioridade: **#12189 → #12187 → #12185 → #12186**.

## Sanitizer compartilhado

Pedido em [comentário do PR #12128](https://github.com/Mudlet/Mudlet/pull/12128#issuecomment-6477711284); atendido por `MailtoUri::withOnlyMessageFields` compartilhado entre OSC 8 e MXP. Estado atual mantém `to`, `cc`, `bcc`, `subject`, `body`, recusa delimitadores decodificados nos destinatários e descarta outros campos. Não repetir como atual a decisão intermediária subject/body-only.

## Testes e limites

O comentário [relato MCP em f5960cb79](https://github.com/Mudlet/Mudlet/pull/8576#issuecomment-5652221142) relata 143 casos em TMCPServerTest + TMCPBridgeTest, retorno Lua, erros e print; echo/cecho/display ficam na janela. É relato de terceiro em SHA específico, não execução local nem suite inteira.

O relato [#12185](https://github.com/Mudlet/Mudlet/issues/12185) menciona TOscTest 148/148 após modificação local. Isto não comprova parser/GUI/suite completa no SHA atual.

O comentário [PR #12128](https://github.com/Mudlet/Mudlet/pull/12128#issuecomment-6545044932) declara testes da URL em openUrl; não houve reteste do portal SNAP naquele relato.

## Quatro integrações locais propostas (pendentes)

Quatro testes são um plano de validação local proposto para as quatro issues. Não há fonte que confirme que o upstream ainda tenha exatamente quatro testes faltantes; todos continuam pendentes neste projeto até evidência local.

### E2E-01 · #12189 · Portal mailto e sanitizer OSC 8/MXP

- MXP e OSC 8 encaminham apenas destinatários, subject e body permitidos.
- attachment/attach e campos escondidos por percent-encoding não chegam ao portal.
- Stub do portal recebe zero attachment_fds; nenhum aplicativo de e-mail real é aberto.

Reprodução: Usar endereço .invalid e arquivo fictício em diretório temporário; registrar URL antes de openUrl e stub org.freedesktop.portal.Email.ComposeEmail.

### E2E-02 · #12187 · MXP SEND com OSC 8 interno e retorno ao link externo

- Texto antes e depois do OSC 8 interno conserva o id e comando do SEND externo.
- Link interno aceito, dois links internos e link interno recusado não contaminam o sufixo.
- Clique interceptado por stub aciona só o comando esperado; nenhuma conexão ao jogo.

Reprodução: Parser recebe SEND aninhado com OSC 8; clicar virtualmente cada trecho e comparar mapa de link ids + send stub.

### E2E-03 · #12185 · Menu com um item, selection e controle de link simples

- Menu de um item com selection executa o item e preserva o comando do menu.
- Item URL chama apenas openUrl stub; item comando chama apenas send stub.
- Link simples sem menu ainda recompõe selected=true corretamente; menu de dois itens mantém comportamento.

Reprodução: Gerar matriz um item/dois itens/sem menu × selection presente/ausente e executar via menu contextual/teclado com stubs.

### E2E-04 · #12186 · Revelar spoiler com MXP SEND preserva todo texto

- Texto restaurado igual à entrada, inclusive caracteres do SEND interno e sufixo.
- Fecho explícito e começo de próximo OSC 8 funcionam.
- Revelar duas vezes não desloca texto e ids internos permanecem corretos.

Reprodução: FeedTriggers em perfil descartável, snapshot de texto/link ids, revealSpoilerLink via GUI e comparação de texto/células.

## Disponibilidade

MCP nativo confirmado por merge e release oficial [Mudlet 5.1.1](https://github.com/Mudlet/Mudlet/releases/tag/Mudlet-5.1.1). Integração ChatGPT/Codex documentada por bridge `mudlet --mcp-bridge`. HTTP nativo usa protocolo 2026-07-28 stateless; a bridge stdio adapta para 2025-06-18. Catálogo de plugin e .mpackage específico não confirmados. O repositório independente inclui um harness de testes Python mock/live, distinto de uma integração instalável completa. Ver o registro de disponibilidade no seed para evidências.

O endpoint padrão é loopback `http://127.0.0.1:11235/mcp`; token Bearer ou token no caminho. O token deve ser ocultado em toda evidência exportada. Não enviar initialize ao servidor HTTP nativo atual; usar _meta e mirror headers conforme fonte.

## Arquivo inicial do aplicativo

`config/seed.json` contém o inventário de **12.644 issues/PRs**, coletado integralmente em **127 páginas por cursor** em 10/11/2026, com detalhamento adicional dos seis itens solicitados. A coleta usou 153 requisições e não teve erros de detalhamento. Cada coleção de comentários, eventos, commits, revisões e builds registra sua própria cobertura. Os demais itens têm metadados básicos e podem ter seus detalhes importados por ID. Risco `unknown` indica item não avaliado; P3 é a fila inicial de triagem. Não há execuções locais (`runs: []`), filtros ou aprovações inventadas. O evento de atividade inicial registra a sincronização real. As quatro integrações são um plano local proposto.

## Hashes compatíveis com a ferramenta do projeto

A tabela abaixo usa SHA-256 dos bytes das linhas inclusivas, incluindo o LF final. Este é o campo `hash`/`method_sha256` do seed e o formato produzido por `bin/source-hash.php`. O campo `method_sha256_without_final_lf` preserva o formato alternativo da pesquisa original, sem o LF final; não compare hashes de formatos diferentes. O SHA Git de cada arquivo foi conferido contra a árvore do commit fixado.

| Método | Commit | Linhas inclusivas | SHA-256 das linhas |
| --- | --- | --- | --- |
| [MailtoUri::withOnlyMessageFields](https://github.com/Mudlet/Mudlet/blob/29f14a1e6e59d15cf52c80ab36c6e043d96b9224/src/MailtoUri.cpp#L27) | `29f14a1e6e59d15cf52c80ab36c6e043d96b9224` | 27–59 | `2edaeb830e26463d6230a446da7b3e74d21b104f38592d7e1615dc8984573895` |
| [TMxpLinkTagHandler::openableAddress](https://github.com/Mudlet/Mudlet/blob/29f14a1e6e59d15cf52c80ab36c6e043d96b9224/src/TMxpLinkTagHandler.cpp#L129) | `29f14a1e6e59d15cf52c80ab36c6e043d96b9224` | 129–142 | `bd8b63993341363a60bb261ae1179678ce0153d7802334d766a2b3352b32deea` |
| [TBuffer::styleForCurrentLink](https://github.com/Mudlet/Mudlet/blob/29f14a1e6e59d15cf52c80ab36c6e043d96b9224/src/TBuffer.cpp#L9578) | `29f14a1e6e59d15cf52c80ab36c6e043d96b9224` | 9578–9708 | `8c9eb9800211cb3a2c766ec4518beaef4133251dfdebea393a31da6a432552ae` |
| [TBuffer::maskSpoilerText](https://github.com/Mudlet/Mudlet/blob/29f14a1e6e59d15cf52c80ab36c6e043d96b9224/src/TBuffer.cpp#L7694) | `29f14a1e6e59d15cf52c80ab36c6e043d96b9224` | 7694–7708 | `5f84498092cb4108680512b187c826c0a01e0a2f5bad428b31f1b1b5151ee8f6` |
| [TBuffer::revealSpoilerLink](https://github.com/Mudlet/Mudlet/blob/29f14a1e6e59d15cf52c80ab36c6e043d96b9224/src/TBuffer.cpp#L10505) | `29f14a1e6e59d15cf52c80ab36c6e043d96b9224` | 10505–10578 | `8b4d85d248fb0e22c9ffb8a61eaa7cbd9b2665b0011925a94064d89de0eb0ede` |
| [THyperlinkSelectionManager::modifyUriForSelection](https://github.com/Mudlet/Mudlet/blob/29f14a1e6e59d15cf52c80ab36c6e043d96b9224/src/THyperlinkSelectionManager.cpp#L101) | `29f14a1e6e59d15cf52c80ab36c6e043d96b9224` | 101–121 | `3f8567e25cee12ea9f35d3dd4c90f0559c42f70ccb2da1c3af3e613e2d838f6f` |
| [TMCPServer::handleMessage](https://github.com/Mudlet/Mudlet/blob/29f14a1e6e59d15cf52c80ab36c6e043d96b9224/src/TMCPServer.cpp#L177) | `29f14a1e6e59d15cf52c80ab36c6e043d96b9224` | 177–271 | `eeb75c0d159097f11ba8fb60b43bdcd8985ef103ca520119855520143c302526` |
| [TMCPServer::callTool](https://github.com/Mudlet/Mudlet/blob/29f14a1e6e59d15cf52c80ab36c6e043d96b9224/src/TMCPServer.cpp#L330) | `29f14a1e6e59d15cf52c80ab36c6e043d96b9224` | 330–358 | `1e3745ef9ba41d4b47c19a688036e77043f7df315d6dd38c816a8ee0f29221fd` |
| [TMCPLuaBridge::runLua](https://github.com/Mudlet/Mudlet/blob/29f14a1e6e59d15cf52c80ab36c6e043d96b9224/src/TMCPLuaBridge.cpp#L295) | `29f14a1e6e59d15cf52c80ab36c6e043d96b9224` | 295–396 | `f4d488660ba51117852c836bca0005560572365a9421b3e6db20ccd93590cff9` |
| [TTextEdit::applyHyperlinkSelectionGroupState](https://github.com/Mudlet/Mudlet/blob/232fad36aafeb49094c28491bd2202b4aecc7a02/src/TTextEdit.cpp#L4242) | `232fad36aafeb49094c28491bd2202b4aecc7a02` | 4242–4305 | `3301e3293e32338f9ac4429de72b232d217f8b3af01df84f8a88d92a04aa66db` |

Para reproduzir um hash, faça checkout limpo do SHA indicado em um clone Mudlet e execute a ferramenta deste projeto apontando para o arquivo desse clone:

```sh
php bin/source-hash.php --file=/caminho/Mudlet/src/MailtoUri.cpp --start=27 --end=59 --commit=29f14a1e6e59d15cf52c80ab36c6e043d96b9224 --symbol=MailtoUri::withOnlyMessageFields
```

A ferramenta registra o commit informado pelo operador. Verifique separadamente `git rev-parse HEAD` e `git status --porcelain` no clone para confirmar que os bytes pertencem ao commit e não há modificações locais. Hash de fonte comprova identidade dos bytes; não comprova execução, correção ou resultado E2E.

## Disponibilidade por categoria

- **native · confirmed**: [MCP nativo do Mudlet](https://github.com/Mudlet/Mudlet/releases/tag/Mudlet-5.1.1). Instalar Mudlet 5.1.1 de uma release oficial; ativar acesso MCP nas preferências. Instalabilidade confirmada por artefatos publicados; funcionamento no ambiente do usuário ainda precisa de teste live. Evidência: release + código + documentação oficial. Sem instalação ou teste live nesta coleta.
- **connector · documented**: [Configuração ChatGPT Desktop / Codex](https://wiki.mudlet.org/w/Manual%3ASupported_Protocols#MCP). O botão ChatGPT nas preferências registra mudlet --mcp-bridge em ~/.codex/config.toml; reiniciar o cliente. Compatibilidade do cliente deve ser testada. A documentação Mudlet descreve integração desktop. Isto não comprova um plugin Mudlet publicado no catálogo de ChatGPT nem acesso direto da versão web a loopback. Evidência: documentação oficial Mudlet + implementação TMCPBridge. Sem instalação ou teste live nesta coleta.
- **plugin · unconfirmed**: [Plugin Mudlet no catálogo ChatGPT](https://help.openai.com/en/articles/12584461-connecting-custom-mcp-servers-in-chatgpt). Nenhum plugin específico de catálogo confirmado. OpenAI documenta conexão personalizada MCP, sujeita a políticas da conta e rede; a capacidade geral não prova a existência de plugin oficial Mudlet. Evidência: não confirmado na pesquisa pública. Sem instalação ou teste live nesta coleta.
- **package · unconfirmed**: [Pacote .mpackage Mudlet–ChatGPT](https://wiki.mudlet.org/w/Manual%3ATechnical_Manual#Package_Development). Não é necessário pacote separado para o servidor MCP nativo. O formato .mpackage e o repositório de pacotes existem, mas isso não confirma pacote específico ChatGPT/MCP. Evidência: nenhum artefato específico confirmado. Sem instalação ou teste live nesta coleta.
- **repository · unconfirmed**: [raiogam/raiogam-mudlet-mcp-sandbox-cc0](https://github.com/raiogam/raiogam-mudlet-mcp-sandbox-cc0). A consulta Git atualizada no commit `ace003088bfecbda389269e0fcb6356d0e31ebfe` contém um harness Python de smoke mock/live, README e resultados publicados. O snapshot inicial havia consultado um commit anterior com somente .gitignore; a árvore foi atualizada na verificação final. Um harness de testes não confirma uma integração ChatGPT instalável completa. O painel Control Room é adicionado em subdiretório separado. Sem instalação nem teste live nesta coleta.

## Mudanças e incertezas

Os relatos de março/2026 de PR em espera e de dezembro/2025 de ausência de retorno são históricos. O PR #8576 foi integrado em outubro/2026. O PR #12128 foi integrado em novembro/2026, inclusive o filtro compartilhado solicitado. O estado inicial distingue estes eventos upstream de validação local ainda pendente. A wiki e o código development diferem sobre ativação MCP por padrão no build; registre configuração e SHA do binário instalado. Posts reciclados ou rumores não elevam uma categoria a confirmada.

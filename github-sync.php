#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/GitHub.php';

use Mudlet\GitHub;
use Mudlet\Store;

$options = getopt('', ['help', 'repo:', 'pages:', 'all', 'details::', 'detail-pages:', 'since:', 'dry-run']);
if (isset($options['help'])) {
    echo <<<'HELP'
Sincronização somente leitura do GitHub (PHP 8.2+).

  php bin/github-sync.php --pages=1
  php bin/github-sync.php --all
  php bin/github-sync.php --details=8576,12189,12185,12186,12187,12128
  php bin/github-sync.php --pages=2 --detail-pages=2 --dry-run

--repo=owner/name  Padrão Mudlet/Mudlet. Não altera GitHub.
--pages=N          Páginas de 100 issues/PRs; padrão 1. Preserva registros locais.
--all              Percorre TODAS as páginas de issues/PRs, inclusive fechados.
--details[=ids]    Comentários, eventos, revisões, commits, checks e builds por ID.
                   Sem IDs, usa 8576,12189,12185,12186,12187,12128.
--detail-pages=N  Limite por coleção de detalhes; padrão 1, 0 sem limite.
--since=ISO8601   Filtra issues/PRs atualizados desde data; não é inventário completo.
--dry-run          Consulta fontes e mostra resumo; não modifica armazenamento.

Credencial opcional GH_TOKEN ou GITHUB_TOKEN, somente variável de ambiente.
Tokens nunca entram nos arquivos. Requisições sem token possuem limite menor.
Paginação incompleta e erros por coleção são registrados explicitamente.
GitHub fechado/merged ou checks verdes NÃO aprovam testes locais/E2E.

HELP;
    exit(0);
}

try {
    $repo = (string) ($options['repo'] ?? 'Mudlet/Mudlet');
    $pages = isset($options['all']) ? 0 : filter_var($options['pages'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
    $detailPages = filter_var($options['detail-pages'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1000]]);
    if ($pages === false || $detailPages === false) { throw new InvalidArgumentException('Limite de páginas inválido.'); }
    $client = new GitHub($repo, getenv('GH_TOKEN') ?: getenv('GITHUB_TOKEN') ?: null);
    $store = new Store();
    $query = ['state' => 'all', 'sort' => 'updated', 'direction' => 'desc'];
    if (isset($options['since'])) {
        $parsedDate = strtotime((string) $options['since']);
        if ($parsedDate === false) { throw new InvalidArgumentException('Data --since inválida.'); }
        $query['since'] = gmdate('c', $parsedDate);
    }
    fwrite(STDERR, "Consultando {$repo}; nenhuma escrita no GitHub.\n");
    $collection = $client->collection('issues', $pages, $query, null, [GitHub::class, 'issue']);
    $issues = [];
    foreach ($collection['items'] as $item) { $issues[(int) $item['id']] = $item; }
    // The ID-indexed map becomes the single inventory owner before details load.
    unset($collection['items']);
    $detailIds = [];
    if (array_key_exists('details', $options)) {
        $detailArgument = $options['details'] ?: '8576,12189,12185,12186,12187,12128';
        foreach (explode(',', (string) $detailArgument) as $part) {
            if (!preg_match('/^[0-9]+$/D', $part) || (int) $part < 1) { throw new InvalidArgumentException('IDs --details inválidos.'); }
            $detailIds[(int) $part] = true;
        }
    }
    $detailErrors = [];
    foreach (array_keys($detailIds) as $id) {
        try {
            if (!isset($issues[$id])) {
                $raw = $client->request("/repos/{$repo}/issues/{$id}")['data'];
                $issues[$id] = GitHub::issue($raw);
            }
            fwrite(STDERR, "Detalhes #{$id}\n");
            $issues[$id]['github'] = $client->details($id, $issues[$id]['kind'] === 'pr', $detailPages);
            if (!empty($issues[$id]['github']['pull_request']['merged'])) {
                $issues[$id]['github_state'] = 'merged';
                $issues[$id]['merged_at'] = $issues[$id]['github']['pull_request']['merged_at'];
            }
        } catch (Throwable $exception) {
            $detailErrors[(string) $id] = $exception->getMessage();
        }
    }
    $sync = [
        'repository' => $repo, 'checked_at' => gmdate('c'),
        'complete' => $collection['pagination']['complete'] && !isset($options['since']),
        'issues_pagination' => $collection['pagination'], 'since' => $query['since'] ?? null,
        'imported_count' => count($issues), 'detail_ids' => array_keys($detailIds),
        'detail_page_limit' => $detailPages, 'detail_errors' => $detailErrors,
        'requests_count' => count($client->requests()),
        'note' => 'Consulta de metadados públicos. Verificação humana e testes locais preservados; detalhes têm cobertura própria por coleção.',
    ];
    if (!isset($options['dry-run'])) {
        $store->mutate(static function (array &$state) use ($issues, $sync, $repo): void {
            $byId = [];
            foreach ($state['issues'] ?? [] as $index => $issue) { $byId[(int) $issue['id']] = $index; }
            foreach ($issues as $id => $incoming) {
                if (isset($byId[$id])) {
                    $index = $byId[$id];
                    // Only external source fields are replaced; manual notes, priorities,
                    // fix tracking, runs and verification attestations remain untouched.
                    $state['issues'][$index] = array_replace($state['issues'][$index], $incoming);
                } else {
                    $state['issues'][] = array_merge([
                        'priority' => 'P3', 'risk' => 'unknown', 'fix_status' => 'pending',
                        'validation_status' => 'pending', 'notes' => '',
                    ], $incoming);
                }
            }
            $state['meta']['github_sync'] = $sync;
            $state['meta']['repository'] = $repo;
            $state['meta']['updated_at'] = gmdate('c');
            Store::activity($state, 'github.sync',
                'Sincronizados ' . count($issues) . ' registros de ' . $repo . ($sync['complete'] ? ' (inventário completo).' : ' (inventário parcial; consulte paginação).'),
                ['actor' => 'CLI GitHub (somente leitura)', 'repository' => $repo, 'complete' => $sync['complete']]);
        });
    }
    echo json_encode(['dry_run' => isset($options['dry-run']), 'github_sync' => $sync], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    exit($detailErrors ? 2 : 0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Sincronização interrompida: ' . $exception->getMessage() . "\n");
    exit(1);
}

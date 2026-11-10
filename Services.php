<?php
declare(strict_types=1);

namespace Mudlet;

use InvalidArgumentException;

final class Services
{
    public function __construct(private Store $store) {}

    public function write(string $action, array $input): array
    {
        if ($action === 'compare') { return $this->compare($input); }
        if ($action === 'note.generate') { return $this->note($input); }
        return $this->store->mutate(function (array &$state) use ($action, $input): array {
            switch ($action) {
                case 'issue.save':
                    $issue = Validation::issue($this->object($input, 'issue'));
                    $index = $this->find($state['issues'], $issue['id']);
                    $issue = array_replace($index === null ? ['title' => 'Issue #' . $issue['id'], 'kind' => 'issue', 'validation_status' => 'pending'] : $state['issues'][$index], $issue);
                    $issue['local_updated_at'] = gmdate('c');
                    if ($index === null) { $state['issues'][] = $issue; } else { $state['issues'][$index] = $issue; }
                    Store::activity($state, 'issue', 'Issue #' . $issue['id'] . ' atualizada.', ['issue_id' => $issue['id']]);
                    return ['issue' => $issue];
                case 'run.save':
                    $run = Validation::run($this->object($input, 'run'));
                    if ($this->find($state['issues'], $run['issue_id']) === null) { throw new InvalidArgumentException('Cadastre ou sincronize a issue antes de registrar o resultado.'); }
                    if ($run['status'] === 'passed' && (count($run['assertions_passed']) === 0 || count($run['assertions_failed']) > 0)) {
                        throw new InvalidArgumentException('Resultado aprovado exige ao menos uma asserção aprovada e nenhuma falhada.');
                    }
                    if ($run['status'] === 'failed' && count($run['assertions_failed']) === 0) { throw new InvalidArgumentException('Resultado falhado exige ao menos uma asserção falhada.'); }
                    $index = $this->find($state['runs'], $run['id']);
                    $run['created_at'] = $index === null ? gmdate('c') : ($state['runs'][$index]['created_at'] ?? gmdate('c'));
                    $run['updated_at'] = gmdate('c');
                    $mcpTools = array_filter($run['tools'], static function (mixed $tool): bool {
                        $name = strtolower(is_array($tool) ? (string)($tool['name'] ?? '') : (string)$tool);
                        return $name === 'lua' || str_contains($name, 'mcp');
                    });
                    $run['evidence_complete'] = $run['commit_sha'] !== '' && ($run['method_hash'] !== '' || count($run['method_hashes']) > 0)
                        && is_string($run['os']) && $run['os'] !== '' && $run['protocol_version'] !== ''
                        && is_string($run['reproduction']) && $run['reproduction'] !== '' && count($run['tools']) > 0
                        && (count($mcpTools) === 0 || $run['endpoint'] !== '');
                    if ($index === null) { $state['runs'][] = $run; } else { $state['runs'][$index] = $run; }
                    $this->reconcileIntegrations($state, $run['id']);
                    Store::activity($state, 'run', 'Resultado ' . $run['id'] . ' registrado; verificação humana pendente.', ['issue_id' => $run['issue_id'], 'run_id' => $run['id'], 'mode' => $run['mode']]);
                    return ['run' => $run];
                case 'run.verify':
                    $id = Validation::text($input['id'] ?? '', 'id', 100);
                    $index = $this->find($state['runs'], $id);
                    if ($index === null) { throw new InvalidArgumentException('Resultado não encontrado.'); }
                    if (!is_bool($input['verified'] ?? null)) { throw new InvalidArgumentException('verified deve ser booleano.'); }
                    $reviewer = Validation::text($input['reviewer'] ?? '', 'reviewer', 120);
                    if ($input['verified'] && strlen($reviewer) < 2) { throw new InvalidArgumentException('Informe quem verificou as evidências.'); }
                    $state['runs'][$index]['verified'] = $input['verified'];
                    $state['runs'][$index]['reviewer'] = $input['verified'] ? $reviewer : '';
                    $state['runs'][$index]['verified_at'] = $input['verified'] ? gmdate('c') : null;
                    $this->reconcileIntegrations($state, $id);
                    Store::activity($state, 'verification', ($input['verified'] ? 'Verificação registrada por ' . $reviewer : 'Verificação removida') . ' em ' . $id . '.', ['run_id' => $id]);
                    return ['run' => $state['runs'][$index]];
                case 'filters.save':
                    $name = Validation::text($input['name'] ?? '', 'name', 100);
                    if ($name === '') { throw new InvalidArgumentException('Dê um nome ao filtro.'); }
                    $filters = $this->object($input, 'filters');
                    if (strlen(json_encode($filters, JSON_THROW_ON_ERROR)) > 10000) { throw new InvalidArgumentException('Filtro muito longo.'); }
                    $filter = ['id' => 'filter-' . bin2hex(random_bytes(8)), 'name' => $name, 'filters' => $filters, 'created_at' => gmdate('c')];
                    $state['filters'][] = $filter;
                    if (count($state['filters']) > 200) { throw new InvalidArgumentException('Limite de 200 filtros salvos.'); }
                    Store::activity($state, 'filter', 'Filtro salvo: ' . $name);
                    return ['filter' => $filter];
                case 'filters.remove':
                    $id = Validation::text($input['id'] ?? '', 'id', 100);
                    $state['filters'] = array_values(array_filter($state['filters'], fn(array $item): bool => (string)($item['id'] ?? '') !== $id));
                    return ['ok' => true];
                case 'integration.update':
                    $id = Validation::text($input['id'] ?? '', 'id', 100);
                    $index = $this->find($state['integration_tests'], $id);
                    if ($index === null) { throw new InvalidArgumentException('Teste de integração não encontrado.'); }
                    $status = Validation::enum($input['status'] ?? '', ['pending','passed','failed','blocked','in_progress'], 'status');
                    $notes = Validation::text($input['notes'] ?? '', 'notes');
                    $runIds = Validation::list($input['run_ids'] ?? $state['integration_tests'][$index]['run_ids'] ?? [], 'run_ids', 100);
                    if ($status === 'passed') {
                        if (!$this->hasIntegrationEvidence($state, $state['integration_tests'][$index], $runIds)) {
                            throw new InvalidArgumentException('Marcar integração como aprovada exige run_ids da mesma issue com run live aprovado, verificado, evidência completa e escopo GUI/parser/suite/e2e.');
                        }
                    }
                    $state['integration_tests'][$index] = array_replace($state['integration_tests'][$index], ['status' => $status, 'notes' => $notes, 'run_ids' => $runIds, 'updated_at' => gmdate('c')]);
                    Store::activity($state, 'integration', 'Teste ' . $id . ': ' . $status, ['integration_id' => $id]);
                    return ['integration_test' => $state['integration_tests'][$index]];
                case 'availability.save':
                    $entry = Validation::availability($this->object($input, 'entry'));
                    $index = $this->find($state['availability'], $entry['id']);
                    $entry['updated_at'] = gmdate('c');
                    $entry['verification_basis'] = 'human_recorded_evidence';
                    if ($index === null) { $state['availability'][] = $entry; } else { $state['availability'][$index] = $entry; }
                    Store::activity($state, 'availability', 'Evidência de disponibilidade atualizada: ' . $entry['name']);
                    return ['entry' => $entry];
                default: throw new InvalidArgumentException('Ação desconhecida.');
            }
        });
    }

    private function compare(array $input): array
    {
        $base = Validation::sha($input['base'] ?? '', 'base', true);
        $head = Validation::sha($input['head'] ?? '', 'head', true);
        if ($base === $head) { throw new InvalidArgumentException('Escolha dois commits diferentes.'); }
        $state = $this->store->all();
        $runs = array_filter($state['runs'], function (array $run) use ($base, $head, $input): bool {
            return in_array($run['commit_sha'] ?? $run['commit'] ?? '', [$base, $head], true)
                && (!isset($input['issue_id']) || (int)$run['issue_id'] === Validation::id($input['issue_id']));
        });
        $grouped = [];
        $summary = ['base_runs' => 0, 'head_runs' => 0, 'passed_delta' => 0, 'failed_delta' => 0];
        foreach ($runs as $run) {
            $side = ($run['commit_sha'] ?? $run['commit'] ?? '') === $base ? 'base' : 'head';
            $key = (string)$run['issue_id'];
            $grouped[$key] ??= ['issue_id' => (int)$key, 'base' => $this->emptyCounts(), 'head' => $this->emptyCounts()];
            $counts =& $grouped[$key][$side];
            $counts['total']++;
            $status = $run['status'] ?? 'pending';
            if (array_key_exists($status, $counts)) { $counts[$status]++; }
            $counts['mock'] += (($run['mode'] ?? '') === 'mock' ? 1 : 0);
            $counts['live'] += (($run['mode'] ?? '') === 'live' ? 1 : 0);
            $counts['verified'] += (!empty($run['verified']) ? 1 : 0);
            $scope = $run['scope'] ?? 'unknown';
            $counts['scopes'][$scope] = ($counts['scopes'][$scope] ?? 0) + 1;
            $summary[$side . '_runs']++;
            if ($status === 'passed') { $summary['passed_delta'] += $side === 'head' ? 1 : -1; }
            if ($status === 'failed') { $summary['failed_delta'] += $side === 'head' ? 1 : -1; }
            unset($counts);
        }
        $changes = [];
        foreach ($grouped as &$row) {
            $row['change'] = $row['base']['total'] === 0 || $row['head']['total'] === 0 ? 'evidência incompleta entre commits' : 'compare escopos e modos antes de concluir regressão';
            $changes[] = '#' . $row['issue_id'] . ': ' . $row['base']['passed'] . ' → ' . $row['head']['passed'] . ' aprovados; ' . $row['base']['failed'] . ' → ' . $row['head']['failed'] . ' falhados. ' . $row['change'] . '.';
        }
        unset($row);
        return ['base' => $base, 'head' => $head, 'summary' => $summary, 'rows' => array_values($grouped), 'changes' => $changes,
            'warning' => 'Comparação de registros locais, sem diff de código. Contagens misturam escopos e modos apenas no total; não demonstram equivalência ou causalidade.'];
    }

    private function emptyCounts(): array { return ['total' => 0, 'passed' => 0, 'failed' => 0, 'pending' => 0, 'blocked' => 0, 'mock' => 0, 'live' => 0, 'verified' => 0, 'scopes' => []]; }

    private function hasIntegrationEvidence(array $state, array $test, array $runIds): bool
    {
        foreach ($state['runs'] as $run) {
            if (in_array($run['id'], $runIds, true) && ($run['mode'] ?? '') === 'live' && ($run['status'] ?? '') === 'passed'
                && ($run['verified'] ?? false) === true && ($run['evidence_complete'] ?? false) === true
                && (!isset($test['issue_id']) || (int)$test['issue_id'] === (int)($run['issue_id'] ?? 0))
                && in_array($run['scope'] ?? '', ['gui','parser','full','e2e'], true)) { return true; }
        }
        return false;
    }

    private function reconcileIntegrations(array &$state, string $runId): void
    {
        foreach ($state['integration_tests'] as &$test) {
            $runIds = $test['run_ids'] ?? [];
            if (($test['status'] ?? '') === 'passed' && in_array($runId, $runIds, true) && !$this->hasIntegrationEvidence($state, $test, $runIds)) {
                $test['status'] = 'pending';
                $test['updated_at'] = gmdate('c');
                $test['notes'] = trim(($test['notes'] ?? '') . "\nAprovação invalidada: a evidência vinculada foi editada ou deixou de atender à verificação.");
                Store::activity($state, 'integration', 'Aprovação invalidada no teste ' . ($test['id'] ?? '?') . '.', ['run_id' => $runId]);
            }
        }
        unset($test);
    }

    private function note(array $input): array
    {
        $id = Validation::id($input['issue_id'] ?? null);
        $state = $this->store->all();
        $index = $this->find($state['issues'], $id);
        if ($index === null) { throw new InvalidArgumentException('Issue não encontrada.'); }
        $issue = $state['issues'][$index];
        $runs = array_reverse(array_values(array_filter($state['runs'], fn(array $r): bool => (int)($r['issue_id'] ?? 0) === $id)));
        usort($runs, fn(array $a, array $b): int => strcmp($b['updated_at'] ?? $b['created_at'] ?? '', $a['updated_at'] ?? $a['created_at'] ?? ''));
        $latest = $runs[0] ?? [];
        $impact = $this->short($this->firstText($latest['impact'] ?? null, $issue['impact'] ?? null, $issue['summary'] ?? null, $issue['notes'] ?? null, $issue['title'] ?? null, 'Impacto a confirmar.'));
        $reproduction = $this->short($this->firstText($latest['reproduction'] ?? null, $issue['reproduction'] ?? null, 'Registrar entrada mínima, commit e ambiente para reprodução.'));
        $action = $this->short($this->firstText($latest['action'] ?? null, $issue['action'] ?? null, 'Executar teste de integração live, anexar asserções e revisar as evidências.'));
        $evidence = count($runs) === 0 ? 'Sem execução registrada.' : 'Último registro: ' . ($latest['mode'] ?? '?') . '/' . ($latest['scope'] ?? '?') . ', ' . ($latest['status'] ?? '?') . ', commit ' . $this->firstText($latest['commit_sha'] ?? null, 'não informado') . '.';
        $text = "#$id — " . ($issue['title'] ?? '') . "\nImpacto: $impact\nReprodução: $reproduction\nAção: $action\n$evidence";
        return compact('text','impact','reproduction','action','evidence');
    }

    private function short(mixed $value): string
    {
        $text = is_string($value) ? trim(preg_replace('/\s+/u', ' ', $value) ?? '') : '';
        if ($text === '') { return 'A confirmar com evidência.'; }
        $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false) { return 'Texto em UTF-8 inválido.'; }
        return count($characters) > 360 ? implode('', array_slice($characters, 0, 357)) . '…' : $text;
    }

    private function firstText(mixed ...$values): string
    {
        foreach ($values as $value) { if (is_string($value) && trim($value) !== '') { return $value; } }
        return '';
    }

    private function find(array $items, mixed $id): ?int
    {
        foreach ($items as $index => $item) { if ((string)($item['id'] ?? '') === (string)$id) { return $index; } }
        return null;
    }

    private function object(array $input, string $key): array
    {
        if (!isset($input[$key]) || !is_array($input[$key])) { throw new InvalidArgumentException("Objeto ausente: $key."); }
        return $input[$key];
    }
}

<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use Mudlet\Services;
use Mudlet\Store;
use Mudlet\Validation;

$checks = 0;
function check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
}
function rejects(callable $call, string $message): void {
    try { $call(); } catch (InvalidArgumentException | JsonException $error) { check(true, $message); return; }
    throw new RuntimeException('Não rejeitou: ' . $message);
}
function cleanup(string $directory): void {
    foreach (scandir($directory) ?: [] as $name) {
        if ($name === '.' || $name === '..') { continue; }
        $path = $directory . '/' . $name;
        if (is_dir($path)) { cleanup($path); } else { unlink($path); }
    }
    rmdir($directory);
}

$directory = sys_get_temp_dir() . '/mudlet-backend-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$seedPath = $directory . '/seed.json';
file_put_contents($seedPath, json_encode(['issues' => [['id' => 12189, 'title' => 'Regression A'], ['id' => 12187, 'title' => 'Regression B']], 'integration_tests' => [['id' => 'gui-a', 'issue_id' => 12189, 'status' => 'pending']]], JSON_THROW_ON_ERROR));
$store = new Store($directory . '/data', $seedPath);
$service = new Services($store);

try {
    check(count($store->all()['issues']) === 2, 'Seed carregado.');
    check(!is_file($directory . '/data/state.json'), 'Leitura não grava implicitamente.');
    $service->write('issue.save', ['issue' => ['id' => 12189, 'priority' => 'P0', 'notes' => 'Manual note']]);
    check($store->all()['issues'][0]['title'] === 'Regression A', 'Atualização parcial preserva título.');
    check(is_file($directory . '/data/state.json'), 'Gravação persistente.');
    $before = file_get_contents($directory . '/data/state.json');
    try { $store->mutate(function (array &$state): void { $state['issues'] = []; throw new RuntimeException('rollback'); }); } catch (RuntimeException $error) {}
    check(file_get_contents($directory . '/data/state.json') === $before, 'Exceção não persiste transação parcial.');

    $baseRun = ['issue_id' => 12189, 'commit_sha' => str_repeat('a', 40), 'method_hash' => str_repeat('b', 64), 'os' => 'Linux', 'mode' => 'live', 'scope' => 'gui',
        'endpoint' => 'http://127.0.0.1:10000/mcp', 'protocol_version' => '2026-07-28', 'tools' => ['lua'], 'safe_lua' => ['return 1 + 1'],
        'assertions_passed' => ['returns 2'], 'assertions_failed' => [], 'reproduction' => 'Start disconnected test profile; run fixture.', 'status' => 'passed'];
    rejects(fn() => Validation::run($baseRun + ['unrelated' => true]) && Validation::run(array_replace($baseRun, ['endpoint' => 'https://example.com/mcp'])), 'Endpoint remoto rejeitado.');
    foreach (['http://127.0.0.1/mcp/private-token', 'http://localhost/mcp?token=secret', 'http://user:pass@127.0.0.1/mcp', 'http://127.0.0.1/mcp#token'] as $endpoint) {
        rejects(fn() => Validation::run(array_replace($baseRun, ['endpoint' => $endpoint])), 'Segredos no endpoint rejeitados.');
    }
    rejects(fn() => Validation::run(array_replace($baseRun, ['method_hash' => 'invalid'])), 'Hash inválido rejeitado.');
    rejects(fn() => Validation::run(array_replace($baseRun, ['method_hashes' => [['hash' => 'not-a-hash']]])), 'Lista de hashes validada.');
    rejects(fn() => $service->write('run.save', ['run' => array_replace($baseRun, ['assertions_passed' => []])]), 'Aprovação sem asserções rejeitada.');
    rejects(fn() => $service->write('run.save', ['run' => array_replace($baseRun, ['assertions_failed' => ['fails']])]), 'Aprovação com falha rejeitada.');
    rejects(fn() => $service->write('run.save', ['run' => array_replace($baseRun, ['issue_id' => 999999])]), 'Run órfão rejeitado.');
    $run = $service->write('run.save', ['run' => $baseRun])['run'];
    check(!$run['verified'] && $run['evidence_complete'], 'Resultado novo requer verificação.');
    rejects(fn() => $service->write('run.verify', ['id' => $run['id'], 'verified' => true]), 'Verificação anônima rejeitada.');
    rejects(fn() => $service->write('integration.update', ['id' => 'gui-a', 'status' => 'passed', 'run_ids' => [$run['id']]]), 'Integração sem verificação rejeitada.');
    $service->write('run.verify', ['id' => $run['id'], 'verified' => true, 'reviewer' => 'Tester']);
    $test = $service->write('integration.update', ['id' => 'gui-a', 'status' => 'passed', 'run_ids' => [$run['id']]])['integration_test'];
    check($test['status'] === 'passed', 'Integração com evidência completa aprovada.');
    $service->write('run.verify', ['id' => $run['id'], 'verified' => false]);
    check($store->all()['integration_tests'][0]['status'] === 'pending', 'Remover verificação invalida integração.');
    $service->write('run.verify', ['id' => $run['id'], 'verified' => true, 'reviewer' => 'Tester']);
    $service->write('integration.update', ['id' => 'gui-a', 'status' => 'passed', 'run_ids' => [$run['id']]]);
    $service->write('run.save', ['run' => $run]);
    check($store->all()['integration_tests'][0]['status'] === 'pending', 'Editar evidência invalida integração.');
    check(!$store->all()['runs'][0]['verified'], 'Editar evidência reseta verificação.');

    foreach ([['mode' => 'mock'], ['scope' => 'isolated'], ['issue_id' => 12187], ['method_hash' => '']] as $changes) {
        $candidate = $service->write('run.save', ['run' => array_replace($baseRun, $changes)])['run'];
        $service->write('run.verify', ['id' => $candidate['id'], 'verified' => true, 'reviewer' => 'Tester']);
        rejects(fn() => $service->write('integration.update', ['id' => 'gui-a', 'status' => 'passed', 'run_ids' => [$candidate['id']]]), 'Run sem escopo/modo/issue/evidência compatível não libera integração.');
    }
    $second = $service->write('run.save', ['run' => array_replace($baseRun, ['commit_sha' => str_repeat('c', 40), 'status' => 'failed', 'assertions_passed' => [], 'assertions_failed' => ['parser fixture mismatch']])])['run'];
    $comparison = $service->write('compare', ['base' => str_repeat('a', 40), 'head' => str_repeat('c', 40)]);
    check($comparison['summary']['head_runs'] === 1 && $comparison['summary']['failed_delta'] === 1, 'Comparação soma por commit.');
    check(isset($comparison['rows'][0]['base']['mock']), 'Comparação distingue mock e live.');
    $note = $service->write('note.generate', ['issue_id' => 12189]);
    check(isset($note['impact'], $note['reproduction'], $note['action']) && str_contains($note['text'], '#12189'), 'Nota curta possui contexto acionável.');
    $service->write('issue.save', ['issue' => ['id' => 12189, 'impact' => str_repeat('á', 181), 'action' => 'Run linked GUI reproduction.']]);
    $note = $service->write('note.generate', ['issue_id' => 12189]);
    check($note['action'] === 'Run linked GUI reproduction.', 'Ação vazia do resultado não bloqueia fallback da issue.');
    check(preg_match('//u', $note['text']) === 1 && is_string(json_encode($note, JSON_THROW_ON_ERROR)), 'Nota com acentos permanece UTF-8 válida.');
    $service->write('issue.save', ['issue' => ['id' => 12189, 'impact' => str_repeat('漢', 500)]]);
    $note = $service->write('note.generate', ['issue_id' => 12189]);
    check(preg_match('//u', $note['impact']) === 1 && strlen($note['impact']) < 1500, 'Truncação de texto multibyte é segura sem mbstring.');
    rejects(fn() => Validation::availability(['name' => 'Rumor', 'category' => 'plugin', 'status' => 'confirmed']), 'Rumor não vira confirmação sem evidência.');
    rejects(fn() => Validation::availability(['name' => 'Recycled', 'category' => 'plugin', 'status' => 'confirmed', 'source_url' => 'https://github.com/example/repo', 'checked_at' => '2026-11-10', 'evidence_type' => 'secondary', 'install_url' => 'https://github.com/example/repo/releases']), 'Material secundário não confirma.');
    rejects(fn() => Validation::availability(['name' => 'No artifact', 'category' => 'package', 'status' => 'confirmed', 'source_url' => 'https://github.com/example/repo', 'checked_at' => '2026-11-10', 'evidence_type' => 'primary']), 'Instalável sem artefato não confirma.');
    $filter = $service->write('filters.save', ['name' => 'Live failed', 'filters' => ['mode' => 'live', 'status' => 'failed']])['filter'];
    check(count($store->all()['filters']) === 1, 'Filtro salvo.');
    $service->write('filters.remove', ['id' => $filter['id']]);
    check(count($store->all()['filters']) === 0, 'Filtro removido.');

    // Concurrent CLI writers use the same lock, preserving every increment.
    $worker = $directory . '/worker.php';
    file_put_contents($worker, '<?php require ' . var_export(dirname(__DIR__) . '/src/bootstrap.php', true) . '; $s=new Mudlet\\Store($argv[1],$argv[2]); for($i=0;$i<25;$i++){$s->mutate(function(array &$s){$s["meta"]["counter"]=($s["meta"]["counter"]??0)+1;});}');
    $processes = [];
    for ($i = 0; $i < 4; $i++) {
        $processes[] = proc_open([PHP_BINARY, $worker, $directory . '/data', $seedPath], [0 => ['file','/dev/null','r'], 1 => ['file','/dev/null','w'], 2 => ['file',$directory . '/workers.log','a']], $pipes);
    }
    foreach ($processes as $process) { check(is_resource($process) && proc_close($process) === 0, 'Writer concorrente conclui.'); }
    check($store->all()['meta']['counter'] === 100, 'Lock preserva 100 mutações concorrentes.');
    file_put_contents($directory . '/data/state.json', '{broken json');
    rejects(fn() => $store->all(), 'Corrupção nunca substituída silenciosamente por seed.');
    echo "Backend: $checks verificações aprovadas.\n";
} finally { cleanup($directory); }

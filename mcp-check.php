#!/usr/bin/env php
<?php
declare(strict_types=1);
// Read-only smoke check. This deliberately does not test GUI/parser/full-suite behavior.
require dirname(__DIR__) . '/src/bootstrap.php';

$opts = getopt('', ['help','mode:','endpoint:','issue:','commit:','protocol:','profile:','save','output:','source-root:']);
if (isset($opts['help'])) {
    echo "Mudlet MCP smoke check (PHP 8.2+)\n";
    echo "php bin/mcp-check.php --mode=mock [--save]\n";
    echo "MUDLET_MCP_TOKEN=... php bin/mcp-check.php --mode=live --endpoint=http://127.0.0.1:11235/mcp --commit=<40-char-sha> [--profile=Sandbox] [--save]\n";
    echo "Options: --issue=8576 --protocol=2026-07-28 --source-root=/path/to/Mudlet --output=report.json\n";
    echo "Only the stateless native HTTP protocol is supported here. The stdio bridge is separate. Tokens are never stored.\n";
    exit(0);
}

function endpoint(string $raw): string {
    $p = parse_url($raw);
    if (!$p || ($p['scheme'] ?? '') !== 'http' || !in_array($p['host'] ?? '', ['127.0.0.1','[::1]'], true)
        || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])
        || !in_array($p['path'] ?? '/', ['/mcp','/mcp/'], true) || ($p['port'] ?? 80) < 1 || ($p['port'] ?? 80) > 65535) {
        throw new RuntimeException('Use an explicit loopback address (127.0.0.1 or [::1]), http, and /mcp. Supply the token through MUDLET_MCP_TOKEN.');
    }
    return $raw;
}

function rpc(string $url, string $token, string $method, array $params, int $id): array {
    $headers = ['Content-Type: application/json','Accept: application/json','MCP-Protocol-Version: 2026-07-28','Mcp-Method: '.$method];
    if ($method === 'tools/call') $headers[] = 'Mcp-Name: lua';
    if ($token !== '') $headers[] = 'Authorization: Bearer '.$token;
    $params['_meta'] = ['io.modelcontextprotocol/protocolVersion'=>'2026-07-28','io.modelcontextprotocol/clientCapabilities'=>(object)[]];
    $body = json_encode(['jsonrpc'=>'2.0','id'=>$id,'method'=>$method,'params'=>$params], JSON_THROW_ON_ERROR);
    $ctx = stream_context_create(['http'=>['method'=>'POST','header'=>implode("\r\n",$headers), 'content'=>$body,
        'timeout'=>10,'ignore_errors'=>true,'follow_location'=>0,'max_redirects'=>0]]);
    // Suppress PHP's URL-containing transport errors; the diagnostic below never contains secrets.
    $raw = @file_get_contents($url, false, $ctx, 0, 2 * 1024 * 1024);
    if ($raw === false) throw new RuntimeException('Loopback transport unavailable; check that Mudlet and MCP are enabled locally.');
    $status = $http_response_header[0] ?? '';
    if (!preg_match('/^HTTP\/\S+ 200\b/', $status)) throw new RuntimeException('MCP HTTP request failed: '.substr($status,0,80));
    $result = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($result) || ($result['jsonrpc'] ?? '') !== '2.0' || ($result['id'] ?? null) !== $id || isset($result['error']) || !isset($result['result'])) {
        throw new RuntimeException('Invalid JSON-RPC response or server error.');
    }
    return $result['result'];
}

try {
    $mode = $opts['mode'] ?? 'mock';
    if (!in_array($mode,['mock','live'],true)) throw new RuntimeException('Mode must be mock or live.');
    $protocol = $opts['protocol'] ?? '2026-07-28';
    if ($protocol !== '2026-07-28') throw new RuntimeException('This runner supports native stateless HTTP MCP 2026-07-28 only.');
    $commit = $opts['commit'] ?? '';
    if ($commit !== '' && !preg_match('/^[0-9a-f]{40}$/i',$commit)) throw new RuntimeException('Commit must be a full 40-character Git SHA.');
    if ($mode === 'live' && $commit === '') throw new RuntimeException('--commit is required for a live check.');
    $url = endpoint($opts['endpoint'] ?? 'http://127.0.0.1:11235/mcp');
    $token = getenv('MUDLET_MCP_TOKEN') ?: '';
    if (preg_match('/[\r\n]/',$token)) throw new RuntimeException('Invalid token.');
    $commands = ['return 1 + 1','return "mudlet-mcp-read-only"'];
    $expected = ['2','mudlet-mcp-read-only'];
    $run = ['id'=>'mcp-'.gmdate('YmdHis').'-'.bin2hex(random_bytes(3)), 'issue_id'=>(int)($opts['issue'] ?? 8576),
        'commit_sha'=>$commit,'method_hash'=>'','method_hashes'=>[], 'os'=>PHP_OS_FAMILY.' / '.php_uname('r'),
        'mode'=>$mode,'endpoint'=>$url,'protocol_version'=>$protocol,'tools'=>['lua'],'safe_lua'=>$commands,
        'assertions_passed'=>[],'assertions_failed'=>[], 'reproduction'=>'Executar bin/mcp-check.php com os mesmos parâmetros, SHA e perfil de sandbox desconectado. Token via variável de ambiente.',
        'risk'=>'low','priority'=>'P1','fix_status'=>'pending','scope'=>'isolated','e2e_coverage'=>'0/4; smoke de transporte/Lua não valida GUI, parser nem suite completa.',
        'status'=>'pending','verified'=>false,'created_at'=>gmdate('c'), 'artifacts'=>[],
        'notes'=>$mode === 'mock' ? 'SIMULAÇÃO do contrato local. Nenhuma conexão com Mudlet e nenhuma evidência live.' : 'Smoke real de ferramentas MCP. Commit informado pelo operador; identidade do binário não atestada.'];
    if (isset($opts['source-root'])) {
        $root = realpath($opts['source-root']);
        if (!$root) throw new RuntimeException('Source directory not found.');
        foreach (['src/TMCPServer.cpp','src/TMCPLuaBridge.cpp'] as $file) {
            if (is_file($root.'/'.$file)) $run['method_hashes'][] = ['file'=>$file,'algorithm'=>'sha256','hash'=>hash_file('sha256',$root.'/'.$file),'scope'=>'whole-source-file; use source-hash.php for exact method'];
        }
    }
    try {
        $list = $mode === 'mock' ? ['tools'=>[['name'=>'lua']]] : rpc($url,$token,'tools/list',[],1);
        if (!in_array('lua',array_column($list['tools'] ?? [],'name'),true)) throw new RuntimeException('tools/list did not advertise lua.');
        $run['assertions_passed'][] = ($mode === 'mock' ? '[MOCK] ' : '').'tools/list anuncia lua';
        foreach ($commands as $i=>$code) {
            $arguments = ['code'=>$code];
            if (isset($opts['profile'])) $arguments['profile'] = (string)$opts['profile'];
            $result = $mode === 'mock' ? ['isError'=>false,'content'=>[['type'=>'text','text'=>$expected[$i]]]] : rpc($url,$token,'tools/call',['name'=>'lua','arguments'=>$arguments],$i+2);
            $texts = array_map(fn($part)=>(string)($part['text'] ?? ''),$result['content'] ?? []);
            $actual = trim(implode("\n",$texts));
            // Lua strings may be JSON quoted; never accept arbitrary substring matches.
            if (($result['isError'] ?? false) || ($actual !== $expected[$i] && $actual !== json_encode($expected[$i]))) throw new RuntimeException('Unexpected return value for read-only assertion '.($i+1));
            $run['assertions_passed'][] = ($mode === 'mock' ? '[MOCK] ' : '').$code.' => '.$expected[$i];
        }
        $run['status'] = 'passed';
    } catch (Throwable $e) {
        $run['status'] = 'failed';
        $run['assertions_failed'][] = $e->getMessage();
    }
    if (isset($opts['save'])) {
        $store = new Mudlet\Store();
        $store->mutate(function(array &$state) use ($run): void {
            $state['runs'][] = $run;
            $state['activity'][] = ['id'=>bin2hex(random_bytes(8)),'type'=>'mcp.check','message'=>'Smoke MCP '.$run['mode'].' registrado; '.$run['status'],'created_at'=>gmdate('c')];
        });
    }
    $json = json_encode($run, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    if (isset($opts['output']) && file_put_contents($opts['output'],$json) === false) throw new RuntimeException('Cannot write report.');
    echo $json;
    exit($run['status'] === 'passed' ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR,'MCP check: '.$e->getMessage()."\n");
    exit(2);
}

<?php
declare(strict_types=1);
$project = dirname(__DIR__);
$passed = 0;
function check(bool $condition, string $message): void {
    global $passed;
    if (!$condition) { fwrite(STDERR,"FAIL $message\n"); exit(1); }
    $passed++;
    echo "PASS $message\n";
}
function runCommand(array $args, array $env = []): array {
    global $project;
    $pipes = [];
    $process = proc_open([PHP_BINARY, ...$args], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, $project, array_merge(getenv(),$env));
    if (!is_resource($process)) throw new RuntimeException('Cannot start PHP');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return [proc_close($process),$stdout,$stderr];
}
$temp = sys_get_temp_dir().'/mudlet-runner-'.bin2hex(random_bytes(6));
mkdir($temp,0700);
try {
    [$code,$out] = runCommand(['bin/mcp-check.php','--mode=mock','--save'],['APP_STORAGE_DIR'=>$temp]);
    $run = json_decode($out,true,512,JSON_THROW_ON_ERROR);
    check($code === 0 && $run['mode'] === 'mock' && $run['status'] === 'passed','mock contract passes and is explicitly labeled');
    check($run['verified'] === false && $run['scope'] === 'isolated','mock cannot claim verified or E2E');
    check(count($run['assertions_passed']) === 3 && count($run['assertions_failed']) === 0,'mock has three contract assertions');
    $state = json_decode(file_get_contents($temp.'/state.json'),true,512,JSON_THROW_ON_ERROR);
    check(count($state['runs']) === 1 && $state['runs'][0]['mode'] === 'mock','saved mock persists in isolated test storage');
    foreach (['http://example.com/mcp','http://127.0.0.1.evil.invalid/mcp','http://127.0.0.1:11235/mcp/secret','http://127.0.0.1/mcp?token=secret','http://user:secret@127.0.0.1/mcp'] as $bad) {
        [$code] = runCommand(['bin/mcp-check.php','--endpoint='.$bad]);
        check($code === 2,'reject unsafe endpoint '.$bad);
    }
    [$code] = runCommand(['bin/mcp-check.php','--mode=live']);
    check($code === 2,'live requires an explicit full commit SHA');
    [$code] = runCommand(['bin/mcp-check.php','--protocol=2025-06-18']);
    check($code === 2,'unsupported native protocol fails explicitly');
    [$code,$out,$err] = runCommand(['bin/mcp-check.php','--mode=mock'],['MUDLET_MCP_TOKEN'=>'do-not-export-this-token']);
    check(!str_contains($out.$err,'do-not-export-this-token'),'token is absent from exported report');
    file_put_contents($temp.'/source.cpp',"signature() {\n  return 2;\n}\n");
    [$code,$out] = runCommand(['bin/source-hash.php','--file='.$temp.'/source.cpp','--start=1','--end=3','--commit='.str_repeat('a',40),'--symbol=signature']);
    $hash = json_decode($out,true,512,JSON_THROW_ON_ERROR);
    check($code === 0 && $hash['hash'] === hash_file('sha256',$temp.'/source.cpp'),'method source hash covers exact bytes including final newline');
    echo "$passed runner checks passed. No live Mudlet was accessed.\n";
} finally {
    foreach (glob($temp.'/*') ?: [] as $file) if (is_file($file)) unlink($file);
    rmdir($temp);
}

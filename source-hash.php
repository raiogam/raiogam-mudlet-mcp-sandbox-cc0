#!/usr/bin/env php
<?php
declare(strict_types=1);
$opts = getopt('', ['file:','start:','end:','commit:','symbol:','help']);
if (isset($opts['help']) || !isset($opts['file'],$opts['start'],$opts['end'],$opts['commit'],$opts['symbol'])) {
    echo "php bin/source-hash.php --file=src/File.cpp --start=100 --end=160 --commit=<40-char-sha> --symbol='Class::method'\nExact byte SHA-256 of the selected source line range, not a semantic C++ parser. Use a clean checkout at the specified commit.\n";
    exit(isset($opts['help']) ? 0 : 2);
}
try {
    if (!preg_match('/^[a-f0-9]{40}$/i',$opts['commit'])) throw new RuntimeException('Full Git commit SHA required.');
    $lines = @file($opts['file']);
    $start = filter_var($opts['start'],FILTER_VALIDATE_INT);
    $end = filter_var($opts['end'],FILTER_VALIDATE_INT);
    if (!$lines || !$start || !$end || $start<1 || $end<$start || $end>count($lines)) throw new RuntimeException('Invalid source file or line range.');
    $bytes = implode('',array_slice($lines,$start-1,$end-$start+1));
    echo json_encode(['commit_sha'=>$opts['commit'],'file'=>$opts['file'],'symbol'=>$opts['symbol'],'start_line'=>$start,'end_line'=>$end,'algorithm'=>'sha256','hash'=>hash('sha256',$bytes),'scope'=>'exact source bytes in line range','commit_attestation'=>'operator-supplied; verify clean git checkout'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage()."\n"); exit(1); }

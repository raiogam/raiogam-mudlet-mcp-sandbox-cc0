<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/GitHub.php';

use Mudlet\GitHub;

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
}

$path = '/repos/Mudlet/Mudlet/issues';
$next = GitHub::nextQuery('<https://api.github.com/repositories/15108812/issues?state=all&per_page=100&after=cursor%2Bvalue%3D&page=101>; rel="next"', $path);
check($next['after'] === 'cursor+value=', 'Cursor opaco deve preservar codificação e caracteres.');
check(!isset($next['page']), 'Cursor deve remover page obsoleto que falha em grandes inventários.');
check($next['state'] === 'all' && $next['per_page'] === '100', 'Filtros da coleção devem persistir.');
$classic = GitHub::nextQuery('<https://api.github.com/repos/Mudlet/Mudlet/issues?per_page=100&page=2>; rel="next"', $path);
check($classic['page'] === '2', 'Coleções clássicas precisam manter paginação numérica.');
check(GitHub::nextQuery('<https://api.github.com/repos/Mudlet/Mudlet/issues?page=1>; rel="prev"', $path) === null, 'Ausência de next deve encerrar coleção.');
foreach ([
    'https://evil.example/repositories/15108812/issues?after=x',
    'http://api.github.com/repositories/15108812/issues?after=x',
    'https://api.github.com/repositories/15108812/pulls?after=x',
    'https://user@api.github.com/repositories/15108812/issues?after=x',
    'https://api.github.com:444/repositories/15108812/issues?after=x',
    'https://api.github.com/repositories/15108812/issues?access_token=x',
] as $unsafe) {
    $rejected = false;
    try { GitHub::nextQuery('<' . $unsafe . '>; rel="next"', $path); }
    catch (RuntimeException) { $rejected = true; }
    check($rejected, 'Link inseguro deve ser recusado.');
}
echo "GitHub paginação: {$checks} verificações aprovadas.\n";

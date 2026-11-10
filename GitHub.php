<?php
declare(strict_types=1);

namespace Mudlet;

/** Read-only GitHub REST client. Credentials are never returned or persisted. */
final class GitHub
{
    private string $repo;
    private ?string $token;
    private array $requests = [];

    public function __construct(string $repo = 'Mudlet/Mudlet', ?string $token = null)
    {
        if (!preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~D', $repo)) {
            throw new \InvalidArgumentException('Repositório inválido; use proprietário/nome.');
        }
        $this->repo = $repo;
        $this->token = $token ?: null;
    }

    public function repository(): string { return $this->repo; }
    public function requests(): array { return $this->requests; }

    /**
     * curl CLI preserves inherited HTTPS proxy/CA settings when ext-curl is absent.
     * Headers (including the credential) travel through stdin, never argv/files.
     * No shell is involved; stdout/stderr use anonymous temporary streams to avoid
     * pipe deadlocks on large issue pages. Only response metadata is returned.
     */
    private function curlProcess(string $url, array $headers): array
    {
        $stdout = tmpfile();
        $stderr = tmpfile();
        if ($stdout === false || $stderr === false) { throw new \RuntimeException('Falha ao preparar cliente HTTP.'); }
        $process = @proc_open([
            'curl', '--config', '-', '--silent', '--show-error',
            '--include', '--suppress-connect-headers', '--proto', '=https',
            '--connect-timeout', '15', '--max-time', '45',
            '--max-filesize', '20000000',
            '--write-out', "\n__MUDLET_HTTP_STATUS__:%{http_code}", $url,
        ], [0 => ['pipe', 'r'], 1 => $stdout, 2 => $stderr], $pipes);
        if (!is_resource($process)) {
            fclose($stdout); fclose($stderr);
            throw new \RuntimeException('Instale a extensão PHP curl ou o executável curl para usar o proxy HTTPS.');
        }
        try {
            foreach ($headers as $header) {
                if (strpbrk($header, "\r\n\0") !== false) { throw new \InvalidArgumentException('Cabeçalho HTTP inválido.'); }
                $line = 'header = "' . addcslashes($header, "\\\"") . '"' . "\n";
                if (fwrite($pipes[0], $line) !== strlen($line)) { throw new \RuntimeException('Falha ao configurar cliente HTTP.'); }
            }
            fclose($pipes[0]);
            $exit = proc_close($process);
            $process = null;
            rewind($stdout);
            $output = stream_get_contents($stdout);
            if ($output === false) { throw new \RuntimeException('Falha ao ler cliente HTTP.'); }
            $status = 0;
            if (preg_match('/\n__MUDLET_HTTP_STATUS__:(\d{3})$/', $output, $match, PREG_OFFSET_CAPTURE)) {
                $status = (int) $match[1][0];
                $output = substr($output, 0, $match[0][1]);
            }
            $responseHeaders = [];
            // Skip interim response blocks if an upstream supplies them.
            while (preg_match('~^HTTP/\S+\s+\d+[^\r\n]*\r?\n~', $output)) {
                $separator = strpos($output, "\r\n\r\n");
                $separatorLength = 4;
                if ($separator === false) { $separator = strpos($output, "\n\n"); $separatorLength = 2; }
                if ($separator === false) { break; }
                $block = substr($output, 0, $separator);
                $responseHeaders = [];
                foreach (preg_split('/\r?\n/', $block) ?: [] as $line) {
                    $parts = explode(':', $line, 2);
                    if (count($parts) === 2) { $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]); }
                }
                $output = substr($output, $separator + $separatorLength);
            }
            return ['body' => $output, 'status' => $status, 'headers' => $responseHeaders, 'failed' => $exit !== 0];
        } finally {
            if (isset($pipes[0]) && is_resource($pipes[0])) { fclose($pipes[0]); }
            if (is_resource($process)) { proc_terminate($process); proc_close($process); }
            fclose($stdout); fclose($stderr);
        }
    }

    /**
     * Only the query is followed; credentials always go to the original fixed
     * repository path. GitHub may canonicalize /repos/owner/name to a numeric
     * /repositories/id path in its Link header.
     */
    public static function nextQuery(string $link, string $originalPath): ?array
    {
        if (!preg_match('/<([^>]+)>;\s*rel="next"/', $link, $match)) { return null; }
        $parts = parse_url($match[1]);
        $suffix = preg_replace('~^/repos/[^/]+/[^/]+/~', '', $originalPath);
        $canonicalPath = '~^/repositories/[0-9]+/' . preg_quote((string) $suffix, '~') . '$~D';
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || ($parts['host'] ?? '') !== 'api.github.com'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || (($parts['path'] ?? '') !== $originalPath && !preg_match($canonicalPath, $parts['path'] ?? ''))) {
            throw new \RuntimeException('Link de paginação GitHub fora da origem ou coleção esperada.');
        }
        parse_str($parts['query'] ?? '', $query);
        if (!$query || count($query) > 32) { throw new \RuntimeException('Link de paginação GitHub inválido.'); }
        foreach ($query as $key => $value) {
            if (!is_string($value) || strlen($value) > 4096 || !preg_match('/^[a-z_]+$/iD', (string) $key)
                || in_array(strtolower((string) $key), ['token', 'access_token', 'authorization', 'client_secret'], true)) {
                throw new \RuntimeException('Parâmetro de paginação GitHub inválido.');
            }
        }
        // Cursor pagination must not accumulate the obsolete numeric page.
        if (isset($query['after']) || isset($query['before'])) { unset($query['page']); }
        return $query;
    }

    /** @return array{data:array,has_next:bool,next_query:?array,rate_remaining:?string} */
    public function request(string $path, array $query = []): array
    {
        if (!str_starts_with($path, '/repos/' . $this->repo . '/') || str_contains($path, '..')) {
            throw new \InvalidArgumentException('Caminho GitHub fora do repositório autorizado.');
        }
        $url = 'https://api.github.com' . $path . ($query ? '?' . http_build_query($query) : '');
        $headers = ['Accept: application/vnd.github+json', 'User-Agent: Mudlet-Observatory-PHP/1.0', 'X-GitHub-Api-Version: 2022-11-28'];
        if ($this->token) { $headers[] = 'Authorization: Bearer ' . $this->token; }
        $responseHeaders = [];
        if (extension_loaded('curl')) {
            $handle = curl_init($url);
            curl_setopt_array($handle, [
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => 45,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                    $parts = explode(':', $line, 2);
                    if (count($parts) === 2) { $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]); }
                    return strlen($line);
                },
            ]);
            $ca = getenv('CURL_CA_BUNDLE') ?: getenv('SSL_CERT_FILE');
            if ($ca && is_file($ca)) { curl_setopt($handle, CURLOPT_CAINFO, $ca); }
            $body = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $failed = $body === false;
            curl_close($handle);
        } elseif (function_exists('proc_open') && (getenv('HTTPS_PROXY') || getenv('https_proxy') || getenv('HTTP_PROXY') || getenv('http_proxy'))) {
            $response = $this->curlProcess($url, $headers);
            $body = $response['body'];
            $status = $response['status'];
            $responseHeaders = $response['headers'];
            $failed = $response['failed'];
        } else {
            $context = stream_context_create([
                'http' => ['method' => 'GET', 'header' => implode("\r\n", $headers), 'timeout' => 45, 'ignore_errors' => true, 'follow_location' => 0],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);
            $body = @file_get_contents($url, false, $context);
            $status = 0;
            foreach ($http_response_header ?? [] as $line) {
                if (preg_match('~^HTTP/\S+\s+(\d+)~', $line, $matches)) { $status = (int) $matches[1]; }
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) { $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]); }
            }
            $failed = $body === false;
        }
        $this->requests[] = ['path' => $path, 'query' => $query, 'status' => $status, 'checked_at' => gmdate('c')];
        if ($failed || $status !== 200) {
            $hint = match ($status) {
                401 => 'credencial ausente, inválida ou expirada',
                403, 429 => 'limite de requisições ou acesso negado; aguarde e verifique permissões',
                404 => 'recurso não encontrado ou não acessível',
                default => 'falha de rede ou resposta inesperada',
            };
            throw new \RuntimeException("GitHub HTTP {$status}: {$hint} ({$path}).");
        }
        $data = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) { throw new \RuntimeException('Resposta GitHub inválida.'); }
        $nextQuery = self::nextQuery($responseHeaders['link'] ?? '', $path);
        return ['data' => $data, 'has_next' => $nextQuery !== null, 'next_query' => $nextQuery, 'rate_remaining' => $responseHeaders['x-ratelimit-remaining'] ?? null];
    }

    /** All source collections carry an explicit pagination/completeness record. */
    public function collection(string $suffix, int $maxPages = 1, array $query = [], ?string $arrayKey = null, ?callable $mapper = null): array
    {
        $items = [];
        $pagesFetched = 0;
        $hasNext = false;
        $remaining = null;
        $path = '/repos/' . $this->repo . '/' . $suffix;
        $nextQuery = array_merge($query, ['per_page' => 100]);
        $seen = [];
        do {
            $signature = hash('sha256', json_encode($nextQuery, JSON_THROW_ON_ERROR));
            if (isset($seen[$signature])) { throw new \RuntimeException('GitHub repetiu o cursor de paginação; inventário não concluído.'); }
            $seen[$signature] = true;
            $response = $this->request($path, $nextQuery);
            $batch = $arrayKey ? ($response['data'][$arrayKey] ?? []) : $response['data'];
            if (!is_array($batch) || !array_is_list($batch)) { throw new \RuntimeException('Coleção GitHub inesperada: ' . $suffix); }
            // Compact inventory rows immediately: a full repository can include
            // thousands of issue bodies which must not remain resident in memory.
            if ($mapper !== null) { $batch = array_map($mapper, $batch); }
            array_push($items, ...$batch);
            $hasNext = $response['has_next'];
            $nextQuery = $response['next_query'];
            $remaining = $response['rate_remaining'];
            $pagesFetched++;
            if ($suffix === 'issues' && $pagesFetched % 10 === 0 && defined('STDERR')) {
                fwrite(STDERR, 'Inventário: ' . count($items) . ' registros, ' . $pagesFetched . " páginas consultadas.\n");
            }
        } while ($hasNext && ($maxPages === 0 || $pagesFetched < $maxPages));
        return ['items' => $items, 'pagination' => ['complete' => !$hasNext, 'pages' => $pagesFetched, 'count' => count($items),
            'next_page' => $hasNext && isset($nextQuery['page']) ? (int) $nextQuery['page'] : null,
            'next_cursor' => $hasNext ? ($nextQuery['after'] ?? $nextQuery['before'] ?? null) : null,
            'cursor_parameter' => $hasNext ? (isset($nextQuery['after']) ? 'after' : (isset($nextQuery['before']) ? 'before' : null)) : null,
            'per_page' => 100, 'rate_remaining' => $remaining, 'checked_at' => gmdate('c')]];
    }

    public static function excerpt(?string $text, int $words = 22): string
    {
        $plain = trim(preg_replace('/\s+/u', ' ', strip_tags($text ?? '')) ?? '');
        $parts = preg_split('/\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return implode(' ', array_slice($parts, 0, $words)) . (count($parts) > $words ? ' …' : '');
    }

    public static function issue(array $raw): array
    {
        $isPullRequest = isset($raw['pull_request']);
        $mergedAt = $isPullRequest ? ($raw['pull_request']['merged_at'] ?? null) : null;
        return [
            'id' => (int) $raw['number'], 'title' => $raw['title'],
            'kind' => $isPullRequest ? 'pr' : 'issue',
            'status' => $raw['state'], 'github_state' => $mergedAt ? 'merged' : $raw['state'],
            'merged_at' => $mergedAt,
            'state_reason' => $raw['state_reason'] ?? null,
            'source_url' => $raw['html_url'], 'url' => $raw['html_url'],
            'summary' => self::excerpt($raw['body'] ?? ''),
            'author' => $raw['user']['login'] ?? '',
            'labels' => array_values(array_map(static fn(array $label): string => $label['name'], $raw['labels'] ?? [])),
            'created_at' => $raw['created_at'], 'updated_at' => $raw['updated_at'],
            'closed_at' => $raw['closed_at'], 'source_checked_at' => gmdate('c'),
            'evidence_status' => 'source_confirmed',
        ];
    }

    private function collectSafely(string $suffix, int $pages, callable $map, array $query = [], ?string $key = null): array
    {
        try {
            $result = $this->collection($suffix, $pages, $query, $key);
            return ['items' => array_map($map, $result['items']), 'pagination' => $result['pagination']];
        } catch (\Throwable $exception) {
            return ['items' => [], 'pagination' => ['complete' => false, 'count' => 0, 'checked_at' => gmdate('c')], 'error' => $exception->getMessage()];
        }
    }

    public function details(int $number, bool $isPullRequest, int $pages = 1): array
    {
        if ($number < 1) { throw new \InvalidArgumentException('Número inválido.'); }
        $result = ['checked_at' => gmdate('c')];
        $result['comments'] = $this->collectSafely("issues/{$number}/comments", $pages, static fn(array $item): array => [
            'id' => $item['id'], 'author' => $item['user']['login'] ?? '', 'excerpt' => self::excerpt($item['body'] ?? '', 18), 'url' => $item['html_url'], 'created_at' => $item['created_at'], 'updated_at' => $item['updated_at'],
        ]);
        $result['events'] = $this->collectSafely("issues/{$number}/events", $pages, static fn(array $item): array => [
            'id' => $item['id'], 'event' => $item['event'], 'actor' => $item['actor']['login'] ?? '', 'commit_sha' => $item['commit_id'] ?? null, 'created_at' => $item['created_at'], 'label' => $item['label']['name'] ?? null,
        ]);
        if (!$isPullRequest) { return $result; }
        try {
            $pull = $this->request("/repos/{$this->repo}/pulls/{$number}")['data'];
            $head = $pull['head']['sha'];
            $result['pull_request'] = ['state' => $pull['state'], 'merged' => $pull['merged'], 'merged_at' => $pull['merged_at'], 'merge_commit_sha' => $pull['merge_commit_sha'], 'head_sha' => $head, 'base_sha' => $pull['base']['sha'], 'draft' => $pull['draft'], 'url' => $pull['html_url']];
            $result['commits'] = $this->collectSafely("pulls/{$number}/commits", $pages, static fn(array $item): array => [
                'sha' => $item['sha'], 'message' => self::excerpt(explode("\n", $item['commit']['message'])[0]), 'author' => $item['author']['login'] ?? $item['commit']['author']['name'] ?? '', 'date' => $item['commit']['committer']['date'] ?? null, 'url' => $item['html_url'],
            ]);
            $result['reviews'] = $this->collectSafely("pulls/{$number}/reviews", $pages, static fn(array $item): array => [
                'id' => $item['id'], 'author' => $item['user']['login'] ?? '', 'state' => $item['state'], 'excerpt' => self::excerpt($item['body'] ?? '', 18), 'commit_sha' => $item['commit_id'], 'submitted_at' => $item['submitted_at'] ?? null, 'url' => $item['html_url'],
            ]);
            $result['review_comments'] = $this->collectSafely("pulls/{$number}/comments", $pages, static fn(array $item): array => [
                'id' => $item['id'], 'author' => $item['user']['login'] ?? '', 'path' => $item['path'], 'line' => $item['line'], 'commit_sha' => $item['commit_id'], 'excerpt' => self::excerpt($item['body'] ?? '', 18), 'created_at' => $item['created_at'], 'url' => $item['html_url'],
            ]);
            $result['checks'] = $this->collectSafely("commits/{$head}/check-runs", $pages, static fn(array $item): array => [
                'id' => $item['id'], 'name' => $item['name'], 'head_sha' => $item['head_sha'], 'status' => $item['status'], 'conclusion' => $item['conclusion'], 'started_at' => $item['started_at'], 'completed_at' => $item['completed_at'], 'url' => $item['html_url'] ?? $item['details_url'] ?? null,
            ], [], 'check_runs');
            $result['statuses'] = $this->collectSafely("commits/{$head}/statuses", $pages, static fn(array $item): array => [
                'id' => $item['id'], 'context' => $item['context'], 'state' => $item['state'], 'description' => self::excerpt($item['description'] ?? ''), 'created_at' => $item['created_at'], 'url' => $item['target_url'],
            ]);
            $result['builds'] = $this->collectSafely('actions/runs', $pages, static fn(array $item): array => [
                'id' => $item['id'], 'name' => $item['name'], 'head_sha' => $item['head_sha'], 'status' => $item['status'], 'conclusion' => $item['conclusion'], 'event' => $item['event'], 'run_number' => $item['run_number'], 'created_at' => $item['created_at'], 'updated_at' => $item['updated_at'], 'url' => $item['html_url'],
            ], ['head_sha' => $head], 'workflow_runs');
            $result['build_scope'] = ['commit_sha' => $head, 'note' => 'Resultados públicos do HEAD do PR; não equivalem a execução local, GUI, parser ou E2E.'];
        } catch (\Throwable $exception) {
            $result['pull_request_error'] = $exception->getMessage();
        }
        return $result;
    }
}

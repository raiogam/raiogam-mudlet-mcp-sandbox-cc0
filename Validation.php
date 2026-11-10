<?php
declare(strict_types=1);

namespace Mudlet;

use InvalidArgumentException;

final class Validation
{
    public static function text(mixed $value, string $field, int $max = 20000): string
    {
        if (!is_string($value) && !is_numeric($value)) { throw new InvalidArgumentException("Campo inválido: $field."); }
        $value = trim((string)$value);
        if (strlen($value) > $max || str_contains($value, "\0")) { throw new InvalidArgumentException("Campo muito longo ou inválido: $field."); }
        return $value;
    }

    public static function id(mixed $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
        if ($id === false) { throw new InvalidArgumentException('Informe um número de issue/PR válido.'); }
        return $id;
    }

    public static function enum(mixed $value, array $allowed, string $field): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) { throw new InvalidArgumentException("Valor inválido em $field."); }
        return $value;
    }

    public static function list(mixed $value, string $field, int $max = 500): array
    {
        if (!is_array($value) || count($value) > $max || !array_is_list($value)) { throw new InvalidArgumentException("Lista inválida em $field."); }
        foreach ($value as $item) {
            if (is_string($item)) { self::text($item, $field, 20000); }
            elseif (!is_array($item)) { throw new InvalidArgumentException("Elemento inválido em $field."); }
            elseif (strlen(json_encode($item, JSON_THROW_ON_ERROR)) > 30000) { throw new InvalidArgumentException("Elemento muito longo em $field."); }
        }
        return $value;
    }

    public static function sha(mixed $value, string $field = 'commit_sha', bool $required = false): string
    {
        $text = strtolower(self::text($value, $field, 128));
        if (($required && $text === '') || ($text !== '' && !preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/D', $text))) {
            throw new InvalidArgumentException("$field deve conter o hash completo (40 ou 64 caracteres hexadecimais).");
        }
        return $text;
    }

    public static function run(array $input): array
    {
        $run = [];
        $run['id'] = isset($input['id']) ? self::text($input['id'], 'id', 100) : 'run-' . bin2hex(random_bytes(10));
        if ($run['id'] === '') { throw new InvalidArgumentException('ID de resultado vazio.'); }
        $run['issue_id'] = self::id($input['issue_id'] ?? null);
        $run['status'] = self::enum($input['status'] ?? 'pending', ['passed','failed','blocked','pending'], 'status');
        $run['commit_sha'] = self::sha($input['commit_sha'] ?? $input['commit'] ?? '', 'commit_sha');
        $run['commit'] = $run['commit_sha'];
        $run['method_hash'] = self::sha($input['method_hash'] ?? '', 'method_hash');
        $run['method_hashes'] = self::list($input['method_hashes'] ?? [], 'method_hashes');
        foreach ($run['method_hashes'] as $hash) {
            self::sha(is_array($hash) ? ($hash['hash'] ?? '') : $hash, 'method_hashes.hash', true);
        }
        $run['mode'] = self::enum($input['mode'] ?? 'mock', ['mock','live'], 'mode');
        $run['scope'] = self::enum($input['scope'] ?? 'isolated', ['isolated','gui','parser','full','e2e'], 'scope');
        foreach (['os','protocol_version','fix_status','risk','priority','title','source_method','build_id','sandbox_id','reproduction','impact','action','notes','e2e_coverage','sanitizer','command','started_at','finished_at'] as $field) {
            $value = $input[$field] ?? '';
            $run[$field] = is_array($value) ? self::list($value, $field) : self::text($value, $field);
        }
        $endpoint = self::text($input['endpoint'] ?? '', 'endpoint', 500);
        if ($endpoint !== '') {
            $parts = parse_url($endpoint);
            if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http','https'], true)
                || !in_array(strtolower($parts['host'] ?? ''), ['localhost','127.0.0.1','[::1]','::1'], true)
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || isset($parts['query'])
                || !in_array($parts['path'] ?? '', ['', '/', '/mcp', '/mcp/'], true)) {
                throw new InvalidArgumentException('Use endpoint HTTP(S) de loopback /mcp sem token, query ou credenciais. O token deve ser configurado em MUDLET_MCP_TOKEN no ambiente do runner.');
            }
        }
        $run['endpoint'] = $endpoint;
        $run['build_url'] = self::text($input['build_url'] ?? '', 'build_url', 2000);
        if ($run['build_url'] !== '' && (!filter_var($run['build_url'], FILTER_VALIDATE_URL) || !str_starts_with($run['build_url'], 'https://'))) {
            throw new InvalidArgumentException('A URL do build deve usar HTTPS.');
        }
        $run['protocol_version'] = self::text($input['protocol_version'] ?? $input['protocol'] ?? '', 'protocol_version', 100);
        foreach (['tools','safe_lua','assertions_passed','assertions_failed','artifacts','reproduction_inputs'] as $field) {
            $run[$field] = self::list($input[$field] ?? [], $field);
        }
        $run['verified'] = false;
        $run['reviewer'] = '';
        $run['verified_at'] = null;
        return $run;
    }

    public static function issue(array $input): array
    {
        $issue = ['id' => self::id($input['id'] ?? $input['number'] ?? null)];
        foreach (['title','risk','priority','fix_status','status','validation_status','notes','impact','reproduction','action','kind','github_state','url','body','updated_at','created_at','source_checked_at','source_status','sanitizer_status','summary','source_url','evidence_status'] as $field) {
            if (array_key_exists($field, $input)) { $issue[$field] = self::text($input[$field], $field, $field === 'body' ? 100000 : 20000); }
        }
        if (isset($issue['url']) && $issue['url'] !== '' && !preg_match('~^https://github\.com/Mudlet/Mudlet/(issues|pull)/[0-9]+$~iD', $issue['url'])) {
            throw new InvalidArgumentException('A URL de issue deve pertencer a Mudlet/Mudlet no GitHub.');
        }
        foreach (['commits','prs','comments','reviews','builds','sandbox_results','labels','source_methods','links','integration_test_ids'] as $field) {
            if (array_key_exists($field, $input)) { $issue[$field] = self::list($input[$field], $field, 1000); }
        }
        if (isset($input['recommended_order'])) { $issue['recommended_order'] = (int)$input['recommended_order']; }
        return $issue;
    }

    public static function availability(array $input): array
    {
        $entry = [];
        $entry['id'] = isset($input['id']) ? self::text($input['id'], 'id', 100) : 'availability-' . bin2hex(random_bytes(8));
        foreach (['name','category','status','description','source_url','checked_at','version','install_url','notes','evidence_type'] as $field) {
            $entry[$field] = self::text($input[$field] ?? '', $field);
        }
        if ($entry['category'] !== '') { self::enum($entry['category'], ['native','plugin','connector','package','repository'], 'category'); }
        foreach (['source_url','install_url'] as $field) {
            if ($entry[$field] !== '' && (!filter_var($entry[$field], FILTER_VALIDATE_URL) || !str_starts_with($entry[$field], 'https://'))) {
                throw new InvalidArgumentException('Use uma URL HTTPS válida para evidências.');
            }
        }
        if (in_array($entry['status'], ['confirmed','verified','available','confirmed_available'], true)) {
            if ($entry['source_url'] === '' || $entry['checked_at'] === '' || $entry['evidence_type'] !== 'primary') {
                throw new InvalidArgumentException('Uma confirmação exige fonte primária, data de verificação e evidência primária.');
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}(?:T.*)?$/D', $entry['checked_at'])) { throw new InvalidArgumentException('Data de verificação inválida.'); }
            if (in_array($entry['category'], ['plugin','connector','package','repository'], true) && $entry['install_url'] === '') {
                throw new InvalidArgumentException('Confirmação de componente instalável exige URL do artefato/repositório.');
            }
        }
        return $entry;
    }
}

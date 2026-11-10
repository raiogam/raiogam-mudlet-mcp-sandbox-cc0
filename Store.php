<?php
declare(strict_types=1);

namespace Mudlet;

use RuntimeException;

/** Small, durable single-node store. Never place this directory under public/. */
final class Store
{
    private string $directory;
    private string $path;

    public function __construct(?string $directory = null, private ?string $seedPath = null)
    {
        $this->directory = $directory ?? (getenv('APP_STORAGE_DIR') ?: dirname(__DIR__) . '/storage');
        $this->seedPath ??= dirname(__DIR__) . '/config/seed.json';
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Não foi possível criar o armazenamento.');
        }
        $realStorage = realpath($this->directory);
        $public = realpath(dirname(__DIR__) . '/public');
        if ($realStorage !== false && $public !== false && ($realStorage === $public || str_starts_with($realStorage, $public . DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('O armazenamento deve ficar fora da pasta public.');
        }
        $this->path = $this->directory . '/state.json';
    }

    public function all(): array
    {
        $lock = $this->lock(LOCK_SH);
        try { return $this->read(); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    /** Callback receives state by reference and may return an API result. */
    public function mutate(callable $callback): mixed
    {
        $lock = $this->lock(LOCK_EX);
        $temporary = null;
        try {
            $state = $this->read();
            $result = $callback($state);
            $state['meta']['revision'] = (int)($state['meta']['revision'] ?? 0) + 1;
            $state['meta']['updated_at'] = gmdate('c');
            $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $temporary = $this->directory . '/.state-' . bin2hex(random_bytes(12)) . '.tmp';
            $file = fopen($temporary, 'x+b');
            if ($file === false) { throw new RuntimeException('Falha ao iniciar gravação.'); }
            try {
                chmod($temporary, 0600);
                $offset = 0;
                while ($offset < strlen($json)) {
                    $written = fwrite($file, substr($json, $offset));
                    if ($written === false || $written === 0) { throw new RuntimeException('Falha ao gravar dados.'); }
                    $offset += $written;
                }
                fflush($file);
                if (function_exists('fsync')) { fsync($file); }
            } finally { fclose($file); }
            if (!rename($temporary, $this->path)) { throw new RuntimeException('Falha ao concluir gravação.'); }
            $temporary = null;
            return $result;
        } finally {
            if ($temporary !== null && is_file($temporary)) { unlink($temporary); }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function lock(int $mode)
    {
        $handle = fopen($this->directory . '/state.lock', 'c+b');
        if ($handle === false || !flock($handle, $mode)) { throw new RuntimeException('Falha ao bloquear armazenamento.'); }
        chmod($this->directory . '/state.lock', 0600);
        return $handle;
    }

    private function read(): array
    {
        $path = is_file($this->path) ? $this->path : $this->seedPath;
        $state = [];
        if ($path !== null && is_file($path)) {
            $raw = file_get_contents($path);
            if ($raw === false) { throw new RuntimeException('Falha ao ler armazenamento.'); }
            $state = json_decode($raw, true, 256, JSON_THROW_ON_ERROR);
            if (!is_array($state)) { throw new RuntimeException('Armazenamento inválido.'); }
        }
        foreach (['issues', 'runs', 'integration_tests', 'availability', 'filters', 'activity'] as $key) {
            if (!isset($state[$key])) { $state[$key] = []; }
            if (!is_array($state[$key])) { throw new RuntimeException('Coleção inválida: ' . $key); }
        }
        $state['meta'] = is_array($state['meta'] ?? null) ? $state['meta'] : [];
        $state['meta']['schema_version'] = 1;
        return $state;
    }

    public static function activity(array &$state, string $type, string $message, array $context = []): void
    {
        array_unshift($state['activity'], ['id' => bin2hex(random_bytes(8)), 'type' => $type, 'message' => $message,
            'at' => gmdate('c'), 'context' => $context]);
        $state['activity'] = array_slice($state['activity'], 0, 2000);
    }
}

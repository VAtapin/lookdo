<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;
use ZipArchive;

class BackupService
{
    public function create(): array
    {
        $this->ensurePath();
        $prefix = 'lookdo-'.now()->format('Y-m-d_H-i-s');
        $sql = $this->path($prefix.'.sql');
        $database = $this->dumpDatabase($sql);
        $databaseGzip = $this->gzip($sql);
        $storageZip = $this->archiveStorage($prefix);
        $manifest = [
            'name' => $prefix,
            'created_at' => now()->toIso8601String(),
            'database' => $database,
            'files' => [
                basename($databaseGzip) => ['bytes' => File::size($databaseGzip), 'sha256' => hash_file('sha256', $databaseGzip)],
                basename($storageZip) => ['bytes' => File::size($storageZip), 'sha256' => hash_file('sha256', $storageZip)],
            ],
        ];
        File::put($this->path($prefix.'.json'), json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->rotate();

        return $manifest;
    }

    public function verify(?string $name = null): array
    {
        $name ??= collect($this->list())->first()['name'] ?? null;
        if (! $name || basename($name) !== $name) {
            throw new RuntimeException('Backup was not found.');
        }
        $manifestPath = $this->path($name.'.json');
        if (! File::exists($manifestPath)) {
            throw new RuntimeException('Backup manifest was not found.');
        }
        $manifest = json_decode(File::get($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $errors = [];
        foreach ($manifest['files'] ?? [] as $file => $expected) {
            $path = $this->path(basename($file));
            if (! File::exists($path)) {
                $errors[] = $file.' is missing';
            } elseif (! hash_equals((string) $expected['sha256'], (string) hash_file('sha256', $path))) {
                $errors[] = $file.' checksum mismatch';
            }
        }

        return ['name' => $name, 'valid' => $errors === [], 'errors' => $errors];
    }

    public function list(): array
    {
        $this->ensurePath();

        return collect(File::glob($this->path('lookdo-*.json')))
            ->sortDesc()
            ->map(function (string $path) {
                $manifest = json_decode(File::get($path), true);

                return ['name' => pathinfo($path, PATHINFO_FILENAME), 'created_at' => $manifest['created_at'] ?? null, 'files' => $manifest['files'] ?? []];
            })->values()->all();
    }

    public function delete(string $name): void
    {
        if (basename($name) !== $name || ! str_starts_with($name, 'lookdo-')) {
            throw new RuntimeException('Invalid backup name.');
        }
        foreach (['.json', '.sql.gz', '.storage.zip'] as $suffix) {
            File::delete($this->path($name.$suffix));
        }
    }

    private function dumpDatabase(string $target): array
    {
        if (config('database.default') !== 'mysql') {
            throw new RuntimeException('Automated database backup currently requires MySQL/MariaDB.');
        }
        $connection = config('database.connections.mysql');
        $command = [config('backup.mysqldump_path'), '--single-transaction', '--quick', '--skip-lock-tables', '--no-tablespaces', '--host='.$connection['host'], '--port='.(string) $connection['port'], '--user='.$connection['username'], $connection['database']];
        $handle = fopen($target.'.partial', 'wb');
        if (! $handle) {
            throw new RuntimeException('Cannot create the database dump.');
        }
        $process = new Process($command, base_path(), ['MYSQL_PWD' => (string) $connection['password']], null, 3600);
        $process->run(function (string $type, string $buffer) use ($handle) {
            if ($type === Process::OUT) {
                fwrite($handle, $buffer);
            }
        });
        fclose($handle);
        if (! $process->isSuccessful()) {
            File::delete($target.'.partial');
            throw new RuntimeException('mysqldump failed: '.trim($process->getErrorOutput()));
        }
        File::move($target.'.partial', $target);

        return ['driver' => 'mysql', 'database' => $connection['database'], 'host' => $connection['host']];
    }

    private function gzip(string $source): string
    {
        $target = $source.'.gz';
        $input = fopen($source, 'rb');
        $output = gzopen($target.'.partial', 'wb9');
        if (! $input || ! $output) {
            throw new RuntimeException('Cannot compress the database dump.');
        }
        while (! feof($input)) {
            gzwrite($output, (string) fread($input, 1024 * 1024));
        }
        fclose($input);
        gzclose($output);
        File::delete($source);
        File::move($target.'.partial', $target);

        return $target;
    }

    private function archiveStorage(string $prefix): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP zip extension is required.');
        }
        $target = $this->path($prefix.'.storage.zip');
        $zip = new ZipArchive;
        if ($zip->open($target.'.partial', ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create the storage archive.');
        }
        $root = storage_path('app');
        if (File::isDirectory($root)) {
            foreach (File::allFiles($root) as $file) {
                $zip->addFile($file->getPathname(), str_replace('\\', '/', $file->getRelativePathname()));
            }
        }
        $zip->close();
        File::move($target.'.partial', $target);

        return $target;
    }

    private function rotate(): void
    {
        collect($this->list())->slice(max(1, (int) config('backup.keep')))->each(fn (array $backup) => $this->delete($backup['name']));
    }

    private function ensurePath(): void
    {
        File::ensureDirectoryExists((string) config('backup.path'), 0750, true);
    }

    private function path(string $file): string
    {
        return rtrim((string) config('backup.path'), '/\\').DIRECTORY_SEPARATOR.$file;
    }
}

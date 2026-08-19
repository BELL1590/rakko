<?php

declare(strict_types=1);

namespace App\Database;

use App\Support\Time;

/** database/migrations/*.sql を順に適用する簡易マイグレータ。 */
final class Migrator
{
    public function __construct(
        private readonly Db $db,
        private readonly string $migrationsDir
    ) {
    }

    /** @return list<string> 適用したファイル名 */
    public function migrate(): array
    {
        $this->ensureTable();

        $applied = [];
        foreach ($this->pendingFiles() as $file) {
            $sql = (string) file_get_contents($this->migrationsDir . '/' . $file);
            foreach ($this->splitStatements($sql) as $statement) {
                $this->db->pdo()->exec($statement);
            }
            $this->db->run(
                'INSERT INTO schema_migrations (filename, applied_at) VALUES (?, ?)',
                [$file, Time::nowUtc()]
            );
            $applied[] = $file;
        }
        return $applied;
    }

    /** @return list<string> */
    public function pendingFiles(): array
    {
        $this->ensureTable();
        $done = [];
        foreach ($this->db->all('SELECT filename FROM schema_migrations') as $row) {
            $done[(string) $row['filename']] = true;
        }

        $files = glob($this->migrationsDir . '/*.sql') ?: [];
        sort($files);

        $pending = [];
        foreach ($files as $path) {
            $name = basename($path);
            if (!isset($done[$name])) {
                $pending[] = $name;
            }
        }
        return $pending;
    }

    private function ensureTable(): void
    {
        $this->db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
               filename   VARCHAR(200) NOT NULL,
               applied_at DATETIME NOT NULL,
               PRIMARY KEY (filename)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /**
     * `;` 区切りでSQL文へ分割する。
     * 文字列リテラル・行コメント内の `;` は区切りとみなさない。
     *
     * @return list<string>
     */
    private function splitStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $length = strlen($sql);
        $inSingle = false;
        $inDouble = false;
        $inLineComment = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($inLineComment) {
                if ($char === "\n") {
                    $inLineComment = false;
                    $buffer .= $char;
                }
                continue;
            }

            if (!$inSingle && !$inDouble && $char === '-' && $next === '-') {
                $inLineComment = true;
                continue;
            }

            if (!$inDouble && $char === "'" && ($i === 0 || $sql[$i - 1] !== '\\')) {
                $inSingle = !$inSingle;
            } elseif (!$inSingle && $char === '"' && ($i === 0 || $sql[$i - 1] !== '\\')) {
                $inDouble = !$inDouble;
            }

            if ($char === ';' && !$inSingle && !$inDouble) {
                $trimmed = trim($buffer);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        $trimmed = trim($buffer);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }
        return $statements;
    }
}

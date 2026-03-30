<?php

declare(strict_types=1);

namespace App\Database;

use RuntimeException;

final class SchemaInstaller
{
    public function __construct(
        private readonly Database $db,
        private readonly string $schemaFile,
    ) {}

    public function install(): void
    {
        if (!is_file($this->schemaFile)) {
            throw new RuntimeException('Schema file not found: ' . $this->schemaFile);
        }

        $sql = file_get_contents($this->schemaFile);

        if ($sql === false || trim($sql) === '') {
            throw new RuntimeException('Schema file is empty or unreadable.');
        }

        $this->db->pdo()->exec($sql);
    }
}

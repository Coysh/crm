<?php

declare(strict_types=1);

namespace CoyshCRM\Models;

use PDO;

abstract class Model
{
    protected string $table;
    protected PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findAll(array $where = [], string $orderBy = ''): array
    {
        $sql = "SELECT * FROM {$this->table}";
        $params = [];

        if ($where) {
            $clauses = [];
            foreach ($where as $col => $val) {
                $clauses[] = "$col = ?";
                $params[] = $val;
            }
            $sql .= ' WHERE ' . implode(' AND ', $clauses);
        }

        if ($orderBy) {
            $sql .= " ORDER BY $orderBy";
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function insert(array $data): int
    {
        $cols   = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $stmt   = $this->db->prepare("INSERT INTO {$this->table} ($cols) VALUES ($placeholders)");
        $stmt->execute(array_values($data));
        return (int)$this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $sets   = implode(', ', array_map(fn($col) => "$col = ?", array_keys($data)));
        $stmt   = $this->db->prepare("UPDATE {$this->table} SET $sets WHERE id = ?");
        $stmt->execute([...array_values($data), $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare("DELETE FROM {$this->table} WHERE id = ?");
        $stmt->execute([$id]);
    }

    protected function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
}

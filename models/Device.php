<?php
require_once __DIR__ . '/../config/database.php';

class Device {
    private $db;
    private $table = 'devices';

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function create($data) {
        $sql = "INSERT INTO {$this->table} (company_id, name, serial_number, description, device_type, is_active)
                VALUES (:company_id, :name, :serial_number, :description, :device_type, :is_active)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':company_id' => $data['company_id'],
            ':name' => $data['name'],
            ':serial_number' => $data['serial_number'],
            ':description' => isset($data['description']) ? $data['description'] : null,
            ':device_type' => isset($data['device_type']) ? $data['device_type'] : null,
            ':is_active' => isset($data['is_active']) ? $data['is_active'] : 1
        ]);

        return $this->db->lastInsertId();
    }

    public function getById($id) {
        $sql = "SELECT d.*, c.name as company_name, c.code as company_code
                FROM {$this->table} d
                LEFT JOIN companies c ON d.company_id = c.id
                WHERE d.id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }

    public function getBySerialNumber($serialNumber) {
        $sql = "SELECT d.*, c.name as company_name, c.code as company_code, c.is_active as company_active
                FROM {$this->table} d
                LEFT JOIN companies c ON d.company_id = c.id
                WHERE d.serial_number = :serial_number";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':serial_number' => $serialNumber]);
        return $stmt->fetch();
    }

    public function getAll($filters = array()) {
        $sql = "SELECT d.*, c.name as company_name, c.code as company_code
                FROM {$this->table} d
                LEFT JOIN companies c ON d.company_id = c.id
                WHERE 1=1";
        $params = [];

        if (!empty($filters['company_id'])) {
            $sql .= " AND d.company_id = :company_id";
            $params[':company_id'] = $filters['company_id'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (d.name LIKE :search OR d.serial_number LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        if (isset($filters['is_active'])) {
            $sql .= " AND d.is_active = :is_active";
            $params[':is_active'] = $filters['is_active'];
        }

        $sql .= " ORDER BY d.created_at DESC";

        if (!empty($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
            if (!empty($filters['offset'])) {
                $sql .= " OFFSET " . (int)$filters['offset'];
            }
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function update($id, $data) {
        $fields = [];
        $params = [':id' => $id];

        foreach (['company_id', 'name', 'serial_number', 'description', 'device_type', 'is_active'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $sql = "UPDATE {$this->table} SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function updateLastSeen($id) {
        $sql = "UPDATE {$this->table} SET last_seen_at = CURRENT_TIMESTAMP WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':id' => $id]);
    }

    public function delete($id) {
        $sql = "DELETE FROM {$this->table} WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':id' => $id]);
    }

    public function count($filters = array()) {
        $sql = "SELECT COUNT(*) FROM {$this->table} d WHERE 1=1";
        $params = [];

        if (!empty($filters['company_id'])) {
            $sql .= " AND d.company_id = :company_id";
            $params[':company_id'] = $filters['company_id'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (d.name LIKE :search OR d.serial_number LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        if (isset($filters['is_active'])) {
            $sql .= " AND d.is_active = :is_active";
            $params[':is_active'] = $filters['is_active'];
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public function isValidAndActive($serialNumber) {
        $device = $this->getBySerialNumber($serialNumber);
        return $device && $device['is_active'] && $device['company_active'];
    }
}

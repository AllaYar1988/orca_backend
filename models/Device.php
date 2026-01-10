<?php
require_once __DIR__ . '/../config/database.php';

class Device {
    private $db;
    private $table = 'devices';

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function create($data) {
        // Generate API key if not provided
        $apiKey = isset($data['api_key']) ? $data['api_key'] : $this->generateApiKey();

        // Hash device secret (password) if provided
        $deviceSecret = null;
        if (!empty($data['device_secret'])) {
            $deviceSecret = password_hash($data['device_secret'], PASSWORD_DEFAULT);
        }

        $sql = "INSERT INTO {$this->table} (company_id, name, serial_number, api_key, device_secret, description, device_type, is_active)
                VALUES (:company_id, :name, :serial_number, :api_key, :device_secret, :description, :device_type, :is_active)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':company_id' => $data['company_id'],
            ':name' => $data['name'],
            ':serial_number' => $data['serial_number'],
            ':api_key' => $apiKey,
            ':device_secret' => $deviceSecret,
            ':description' => isset($data['description']) ? $data['description'] : null,
            ':device_type' => isset($data['device_type']) ? $data['device_type'] : null,
            ':is_active' => isset($data['is_active']) ? $data['is_active'] : 1
        ]);

        return $this->db->lastInsertId();
    }

    /**
     * Generate a random API key (64 hex chars = 32 bytes)
     */
    public function generateApiKey() {
        return bin2hex(random_bytes(32));
    }

    /**
     * Regenerate API key for a device
     */
    public function regenerateApiKey($id) {
        $newKey = $this->generateApiKey();
        $sql = "UPDATE {$this->table} SET api_key = :api_key WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':api_key' => $newKey, ':id' => $id]);
        return $newKey;
    }

    /**
     * Verify HMAC signature for device authentication
     * Device sends: signature = SHA256(api_key + timestamp)
     * We verify by computing the same hash with stored api_key
     *
     * @param string $serialNumber Device serial number
     * @param int $timestamp Unix timestamp from request
     * @param string $signature HMAC signature from device
     * @param int $toleranceSeconds Allow timestamp within this window (default 5 min)
     * @return array ['valid' => bool, 'device' => array|null, 'error' => string|null]
     */
    public function verifyHmac($serialNumber, $timestamp, $signature, $toleranceSeconds = 300) {
        $device = $this->getBySerialNumber($serialNumber);

        if (!$device) {
            return ['valid' => false, 'device' => null, 'error' => 'Device not found'];
        }

        if (!$device['is_active']) {
            return ['valid' => false, 'device' => $device, 'error' => 'Device is inactive'];
        }

        if (!$device['company_active']) {
            return ['valid' => false, 'device' => $device, 'error' => 'Company is inactive'];
        }

        // Check timestamp is within tolerance (prevents replay attacks)
        $now = time();
        if (abs($now - $timestamp) > $toleranceSeconds) {
            return ['valid' => false, 'device' => $device, 'error' => 'Timestamp expired'];
        }

        // Compute expected signature: SHA256(api_key + timestamp)
        $expectedSignature = hash('sha256', $device['api_key'] . $timestamp);

        if (!hash_equals($expectedSignature, $signature)) {
            return ['valid' => false, 'device' => $device, 'error' => 'Invalid signature'];
        }

        return ['valid' => true, 'device' => $device, 'error' => null];
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

        foreach (['company_id', 'name', 'serial_number', 'api_key', 'description', 'device_type', 'is_active'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }

        // Handle device_secret (password) separately - hash it
        if (!empty($data['device_secret'])) {
            $fields[] = "device_secret = :device_secret";
            $params[':device_secret'] = password_hash($data['device_secret'], PASSWORD_DEFAULT);
        }

        if (empty($fields)) {
            return false;
        }

        $sql = "UPDATE {$this->table} SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function updateLastSeen($id, $timestamp = null) {
        if ($timestamp) {
            // Convert Unix timestamp to UTC datetime string in PHP (avoids MySQL timezone issues)
            $utcDatetime = gmdate('Y-m-d H:i:s', $timestamp);
            $sql = "UPDATE {$this->table} SET last_seen_at = :dt WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([':dt' => $utcDatetime, ':id' => $id]);
        } else {
            $sql = "UPDATE {$this->table} SET last_seen_at = UTC_TIMESTAMP() WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([':id' => $id]);
        }
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

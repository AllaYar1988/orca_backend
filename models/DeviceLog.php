<?php
require_once __DIR__ . '/../config/database.php';

class DeviceLog {
    private $db;
    private $table = 'device_logs';

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function create($data) {
        $sql = "INSERT INTO {$this->table} (device_id, serial_number, log_key, log_value, log_data, ip_address, logged_at)
                VALUES (:device_id, :serial_number, :log_key, :log_value, :log_data, :ip_address, :logged_at)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':device_id' => $data['device_id'],
            ':serial_number' => $data['serial_number'],
            ':log_key' => isset($data['log_key']) ? $data['log_key'] : null,
            ':log_value' => isset($data['log_value']) ? $data['log_value'] : null,
            ':log_data' => isset($data['log_data']) ? json_encode($data['log_data']) : null,
            ':ip_address' => isset($data['ip_address']) ? $data['ip_address'] : null,
            ':logged_at' => isset($data['logged_at']) ? $data['logged_at'] : date('Y-m-d H:i:s')
        ]);

        return $this->db->lastInsertId();
    }

    public function getById($id) {
        $sql = "SELECT l.*, d.name as device_name, c.name as company_name
                FROM {$this->table} l
                LEFT JOIN devices d ON l.device_id = d.id
                LEFT JOIN companies c ON d.company_id = c.id
                WHERE l.id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        $log = $stmt->fetch();
        if ($log && $log['log_data']) {
            $log['log_data'] = json_decode($log['log_data'], true);
        }
        return $log;
    }

    public function getAll($filters = array()) {
        $sql = "SELECT l.*, d.name as device_name, d.serial_number, c.name as company_name, c.id as company_id
                FROM {$this->table} l
                LEFT JOIN devices d ON l.device_id = d.id
                LEFT JOIN companies c ON d.company_id = c.id
                WHERE 1=1";
        $params = [];

        if (!empty($filters['device_id'])) {
            $sql .= " AND l.device_id = :device_id";
            $params[':device_id'] = $filters['device_id'];
        }

        if (!empty($filters['device_ids']) && is_array($filters['device_ids'])) {
            $placeholders = [];
            foreach ($filters['device_ids'] as $i => $deviceId) {
                $placeholders[] = ":device_id_$i";
                $params[":device_id_$i"] = $deviceId;
            }
            if (!empty($placeholders)) {
                $sql .= " AND l.device_id IN (" . implode(',', $placeholders) . ")";
            }
        }

        if (!empty($filters['serial_number'])) {
            $sql .= " AND l.serial_number = :serial_number";
            $params[':serial_number'] = $filters['serial_number'];
        }

        if (!empty($filters['company_id'])) {
            $sql .= " AND c.id = :company_id";
            $params[':company_id'] = $filters['company_id'];
        }

        if (!empty($filters['log_key'])) {
            $sql .= " AND l.log_key LIKE :log_key";
            $params[':log_key'] = '%' . $filters['log_key'] . '%';
        }

        if (!empty($filters['date_from'])) {
            $sql .= " AND l.logged_at >= :date_from";
            $params[':date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $sql .= " AND l.logged_at <= :date_to";
            $params[':date_to'] = $filters['date_to'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (l.log_key LIKE :search OR l.log_value LIKE :search OR l.serial_number LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        $sql .= " ORDER BY l.logged_at DESC";

        if (!empty($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
            if (!empty($filters['offset'])) {
                $sql .= " OFFSET " . (int)$filters['offset'];
            }
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $logs = $stmt->fetchAll();

        foreach ($logs as &$log) {
            if ($log['log_data']) {
                $log['log_data'] = json_decode($log['log_data'], true);
            }
        }

        return $logs;
    }

    public function count($filters = array()) {
        $sql = "SELECT COUNT(*) FROM {$this->table} l
                LEFT JOIN devices d ON l.device_id = d.id
                LEFT JOIN companies c ON d.company_id = c.id
                WHERE 1=1";
        $params = [];

        if (!empty($filters['device_id'])) {
            $sql .= " AND l.device_id = :device_id";
            $params[':device_id'] = $filters['device_id'];
        }

        if (!empty($filters['device_ids']) && is_array($filters['device_ids'])) {
            $placeholders = [];
            foreach ($filters['device_ids'] as $i => $deviceId) {
                $placeholders[] = ":device_id_$i";
                $params[":device_id_$i"] = $deviceId;
            }
            if (!empty($placeholders)) {
                $sql .= " AND l.device_id IN (" . implode(',', $placeholders) . ")";
            }
        }

        if (!empty($filters['serial_number'])) {
            $sql .= " AND l.serial_number = :serial_number";
            $params[':serial_number'] = $filters['serial_number'];
        }

        if (!empty($filters['company_id'])) {
            $sql .= " AND c.id = :company_id";
            $params[':company_id'] = $filters['company_id'];
        }

        if (!empty($filters['log_key'])) {
            $sql .= " AND l.log_key LIKE :log_key";
            $params[':log_key'] = '%' . $filters['log_key'] . '%';
        }

        if (!empty($filters['date_from'])) {
            $sql .= " AND l.logged_at >= :date_from";
            $params[':date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $sql .= " AND l.logged_at <= :date_to";
            $params[':date_to'] = $filters['date_to'];
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public function delete($id) {
        $sql = "DELETE FROM {$this->table} WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':id' => $id]);
    }

    public function deleteByDeviceId($deviceId) {
        $sql = "DELETE FROM {$this->table} WHERE device_id = :device_id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':device_id' => $deviceId]);
    }

    public function getUniqueKeys($deviceId = null) {
        $sql = "SELECT DISTINCT log_key FROM {$this->table} WHERE log_key IS NOT NULL AND log_key != ''";
        $params = [];

        if ($deviceId) {
            $sql .= " AND device_id = :device_id";
            $params[':device_id'] = $deviceId;
        }

        $sql .= " ORDER BY log_key";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}

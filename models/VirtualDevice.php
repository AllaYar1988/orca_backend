<?php
require_once __DIR__ . '/../config/database.php';

class VirtualDevice {
    private $db;
    private $table = 'virtual_devices';
    private $sensorsTable = 'virtual_device_sensors';

    // Threshold in minutes for online status (matches real devices)
    private $onlineThresholdMinutes = 60;
    // Threshold in minutes for stale status
    private $staleThresholdMinutes = 1440; // 24 hours

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Create a new virtual device
     */
    public function create($data) {
        $sql = "INSERT INTO {$this->table} (company_id, name, description, is_active)
                VALUES (:company_id, :name, :description, :is_active)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':company_id' => $data['company_id'],
            ':name' => $data['name'],
            ':description' => isset($data['description']) ? $data['description'] : null,
            ':is_active' => isset($data['is_active']) ? $data['is_active'] : 1
        ]);

        return $this->db->lastInsertId();
    }

    /**
     * Get virtual device by ID with company info
     */
    public function getById($id) {
        $sql = "SELECT vd.*, c.name as company_name, c.code as company_code
                FROM {$this->table} vd
                LEFT JOIN companies c ON vd.company_id = c.id
                WHERE vd.id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }

    /**
     * Get all virtual devices with filters
     */
    public function getAll($filters = []) {
        $sql = "SELECT vd.*, c.name as company_name, c.code as company_code,
                       (SELECT COUNT(*) FROM {$this->sensorsTable} WHERE virtual_device_id = vd.id) as sensor_count
                FROM {$this->table} vd
                LEFT JOIN companies c ON vd.company_id = c.id
                WHERE 1=1";
        $params = [];

        if (!empty($filters['company_id'])) {
            $sql .= " AND vd.company_id = :company_id";
            $params[':company_id'] = $filters['company_id'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (vd.name LIKE :search OR vd.description LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        if (isset($filters['is_active'])) {
            $sql .= " AND vd.is_active = :is_active";
            $params[':is_active'] = $filters['is_active'];
        }

        $sql .= " ORDER BY vd.created_at DESC";

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

    /**
     * Update virtual device
     */
    public function update($id, $data) {
        $fields = [];
        $params = [':id' => $id];

        foreach (['company_id', 'name', 'description', 'is_active'] as $field) {
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

    /**
     * Delete virtual device (cascades to sensors and user assignments)
     */
    public function delete($id) {
        $sql = "DELETE FROM {$this->table} WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Count virtual devices with filters
     */
    public function count($filters = []) {
        $sql = "SELECT COUNT(*) FROM {$this->table} vd WHERE 1=1";
        $params = [];

        if (!empty($filters['company_id'])) {
            $sql .= " AND vd.company_id = :company_id";
            $params[':company_id'] = $filters['company_id'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (vd.name LIKE :search OR vd.description LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        if (isset($filters['is_active'])) {
            $sql .= " AND vd.is_active = :is_active";
            $params[':is_active'] = $filters['is_active'];
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    // ==================== SENSOR MAPPING METHODS ====================

    /**
     * Add a sensor to virtual device
     */
    public function addSensor($virtualDeviceId, $sourceDeviceId, $sourceLogKey, $displayLabel = null, $displayOrder = 0) {
        $sql = "INSERT INTO {$this->sensorsTable}
                (virtual_device_id, source_device_id, source_log_key, display_label, display_order)
                VALUES (:virtual_device_id, :source_device_id, :source_log_key, :display_label, :display_order)
                ON DUPLICATE KEY UPDATE display_label = :display_label2, display_order = :display_order2";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':virtual_device_id' => $virtualDeviceId,
            ':source_device_id' => $sourceDeviceId,
            ':source_log_key' => $sourceLogKey,
            ':display_label' => $displayLabel,
            ':display_order' => $displayOrder,
            ':display_label2' => $displayLabel,
            ':display_order2' => $displayOrder
        ]);
    }

    /**
     * Remove a sensor from virtual device
     */
    public function removeSensor($virtualDeviceId, $sourceDeviceId, $sourceLogKey) {
        $sql = "DELETE FROM {$this->sensorsTable}
                WHERE virtual_device_id = :virtual_device_id
                AND source_device_id = :source_device_id
                AND source_log_key = :source_log_key";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':virtual_device_id' => $virtualDeviceId,
            ':source_device_id' => $sourceDeviceId,
            ':source_log_key' => $sourceLogKey
        ]);
    }

    /**
     * Remove all sensors from virtual device
     */
    public function removeAllSensors($virtualDeviceId) {
        $sql = "DELETE FROM {$this->sensorsTable} WHERE virtual_device_id = :virtual_device_id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':virtual_device_id' => $virtualDeviceId]);
    }

    /**
     * Set sensors for virtual device (replaces all existing)
     * @param int $virtualDeviceId
     * @param array $sensors Array of ['source_device_id', 'source_log_key', 'display_label', 'display_order']
     */
    public function setSensors($virtualDeviceId, $sensors) {
        $this->db->beginTransaction();
        try {
            // Remove all existing sensors
            $this->removeAllSensors($virtualDeviceId);

            // Add new sensors
            foreach ($sensors as $index => $sensor) {
                $this->addSensor(
                    $virtualDeviceId,
                    $sensor['source_device_id'],
                    $sensor['source_log_key'],
                    isset($sensor['display_label']) ? $sensor['display_label'] : null,
                    isset($sensor['display_order']) ? $sensor['display_order'] : $index
                );
            }

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Get sensor mappings for a virtual device
     */
    public function getSensorMappings($virtualDeviceId) {
        $sql = "SELECT vds.*, d.name as source_device_name, d.serial_number as source_serial_number,
                       sc.label as config_label, sc.unit, sc.sensor_type, sc.decimals
                FROM {$this->sensorsTable} vds
                LEFT JOIN devices d ON vds.source_device_id = d.id
                LEFT JOIN sensor_configs sc ON vds.source_device_id = sc.device_id AND vds.source_log_key = sc.log_key
                WHERE vds.virtual_device_id = :virtual_device_id
                ORDER BY vds.display_order ASC, vds.id ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':virtual_device_id' => $virtualDeviceId]);
        return $stmt->fetchAll();
    }

    // ==================== STATUS AND DATA METHODS ====================

    /**
     * Get virtual device with full status (is_online, seconds_ago, live_count, etc.)
     */
    public function getWithStatus($id) {
        $device = $this->getById($id);
        if (!$device) {
            return null;
        }

        // Get status summary
        $status = $this->getStatusSummary($id);

        return array_merge($device, $status);
    }

    /**
     * Get status summary for a virtual device
     * Returns: is_online, all_online, live_count, total_count, seconds_ago
     */
    public function getStatusSummary($virtualDeviceId) {
        $sql = "SELECT
                    COUNT(vds.id) as total_count,
                    SUM(CASE
                        WHEN latest.logged_at >= UTC_TIMESTAMP() - INTERVAL {$this->onlineThresholdMinutes} MINUTE
                        THEN 1 ELSE 0
                    END) as live_count,
                    MAX(latest.logged_at) as last_seen_at,
                    TIMESTAMPDIFF(SECOND, MAX(latest.logged_at), UTC_TIMESTAMP()) as seconds_ago
                FROM {$this->sensorsTable} vds
                LEFT JOIN (
                    SELECT device_id, log_key, MAX(logged_at) as logged_at
                    FROM device_logs
                    GROUP BY device_id, log_key
                ) latest ON vds.source_device_id = latest.device_id AND vds.source_log_key = latest.log_key
                WHERE vds.virtual_device_id = :virtual_device_id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':virtual_device_id' => $virtualDeviceId]);
        $result = $stmt->fetch();

        $totalCount = (int)$result['total_count'];
        $liveCount = (int)$result['live_count'];

        return [
            'is_online' => $liveCount > 0,
            'all_online' => $totalCount > 0 && $liveCount === $totalCount,
            'live_count' => $liveCount,
            'total_count' => $totalCount,
            'last_seen_at' => $result['last_seen_at'],
            'seconds_ago' => $result['seconds_ago'] !== null ? (int)$result['seconds_ago'] : null
        ];
    }

    /**
     * Get sensors with latest data and status for a virtual device
     */
    public function getSensorsWithData($virtualDeviceId) {
        $sql = "SELECT
                    vds.id,
                    vds.source_device_id,
                    vds.source_log_key,
                    vds.display_label,
                    vds.display_order,
                    d.name as source_device_name,
                    d.serial_number as source_serial_number,
                    sc.label as config_label,
                    sc.unit,
                    sc.sensor_type,
                    sc.decimals,
                    sc.data_type,
                    sc.zero_value,
                    sc.span_value,
                    latest.log_value,
                    latest.logged_at,
                    TIMESTAMPDIFF(SECOND, latest.logged_at, UTC_TIMESTAMP()) as seconds_ago,
                    CASE
                        WHEN latest.logged_at >= UTC_TIMESTAMP() - INTERVAL {$this->onlineThresholdMinutes} MINUTE THEN 1
                        ELSE 0
                    END as is_online,
                    CASE
                        WHEN latest.logged_at >= UTC_TIMESTAMP() - INTERVAL {$this->onlineThresholdMinutes} MINUTE THEN 'live'
                        WHEN latest.logged_at >= UTC_TIMESTAMP() - INTERVAL {$this->staleThresholdMinutes} MINUTE THEN 'stale'
                        WHEN latest.logged_at IS NOT NULL THEN 'offline'
                        ELSE 'unknown'
                    END as status
                FROM {$this->sensorsTable} vds
                LEFT JOIN devices d ON vds.source_device_id = d.id
                LEFT JOIN sensor_configs sc ON vds.source_device_id = sc.device_id AND vds.source_log_key = sc.log_key
                LEFT JOIN (
                    SELECT dl1.device_id, dl1.log_key, dl1.log_value, dl1.logged_at
                    FROM device_logs dl1
                    INNER JOIN (
                        SELECT device_id, log_key, MAX(logged_at) as max_logged_at
                        FROM device_logs
                        GROUP BY device_id, log_key
                    ) dl2 ON dl1.device_id = dl2.device_id
                        AND dl1.log_key = dl2.log_key
                        AND dl1.logged_at = dl2.max_logged_at
                ) latest ON vds.source_device_id = latest.device_id AND vds.source_log_key = latest.log_key
                WHERE vds.virtual_device_id = :virtual_device_id
                ORDER BY vds.display_order ASC, vds.id ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':virtual_device_id' => $virtualDeviceId]);
        $results = $stmt->fetchAll();

        // Process results to apply 4-20mA conversion and format values
        foreach ($results as &$row) {
            $row['is_online'] = (bool)$row['is_online'];
            $row['seconds_ago'] = $row['seconds_ago'] !== null ? (int)$row['seconds_ago'] : null;

            // Determine display label (priority: custom > config > log_key)
            $row['label'] = $row['display_label'] ?: ($row['config_label'] ?: $row['source_log_key']);

            // Apply 4-20mA conversion if applicable
            if ($row['log_value'] !== null && $row['data_type'] === '4-20') {
                $rawValue = (float)$row['log_value'];
                $zeroValue = (float)($row['zero_value'] ?? 0);
                $spanValue = (float)($row['span_value'] ?? 100);
                // Convert: real_value = zero_value + ((raw_mA - 4) / 16) * span_value
                $row['value'] = $zeroValue + (($rawValue - 4) / 16) * $spanValue;
            } else {
                $row['value'] = $row['log_value'] !== null ? (float)$row['log_value'] : null;
            }

            // Round to configured decimals
            if ($row['value'] !== null && isset($row['decimals'])) {
                $row['value'] = round($row['value'], (int)$row['decimals']);
            }
        }

        return $results;
    }

    /**
     * Get full virtual device data with status and sensor readings
     */
    public function getFullData($virtualDeviceId) {
        $device = $this->getById($virtualDeviceId);
        if (!$device) {
            return null;
        }

        $status = $this->getStatusSummary($virtualDeviceId);
        $sensors = $this->getSensorsWithData($virtualDeviceId);

        return [
            'id' => (int)$device['id'],
            'name' => $device['name'],
            'description' => $device['description'],
            'company_id' => (int)$device['company_id'],
            'company_name' => $device['company_name'],
            'is_active' => (bool)$device['is_active'],
            'is_online' => $status['is_online'],
            'all_online' => $status['all_online'],
            'live_count' => $status['live_count'],
            'total_count' => $status['total_count'],
            'seconds_ago' => $status['seconds_ago'],
            'last_seen_at' => $status['last_seen_at'],
            'sensors' => $sensors
        ];
    }

    /**
     * Get available sensors from a device for mapping
     * Returns sensors from sensor_configs and unique log_keys from device_logs
     */
    public function getAvailableSensors($deviceId) {
        // Get configured sensors
        $sql1 = "SELECT
                    :device_id as device_id,
                    log_key,
                    label,
                    unit,
                    sensor_type,
                    1 as is_configured
                 FROM sensor_configs
                 WHERE device_id = :device_id2";

        // Get sensors from logs that aren't configured
        $sql2 = "SELECT DISTINCT
                    device_id,
                    log_key,
                    NULL as label,
                    NULL as unit,
                    NULL as sensor_type,
                    0 as is_configured
                 FROM device_logs
                 WHERE device_id = :device_id3
                 AND log_key NOT IN (SELECT log_key FROM sensor_configs WHERE device_id = :device_id4)";

        $stmt1 = $this->db->prepare($sql1);
        $stmt1->execute([':device_id' => $deviceId, ':device_id2' => $deviceId]);
        $configured = $stmt1->fetchAll();

        $stmt2 = $this->db->prepare($sql2);
        $stmt2->execute([':device_id3' => $deviceId, ':device_id4' => $deviceId]);
        $unconfigured = $stmt2->fetchAll();

        return array_merge($configured, $unconfigured);
    }
}

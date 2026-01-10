<?php
/**
 * SensorConfig Model
 *
 * Manages sensor configuration for 4-20mA scaling and alarm thresholds
 */

require_once __DIR__ . '/../config/database.php';

class SensorConfig {
    private $db;
    private $table = 'sensor_configs';

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Get config for a specific sensor
     */
    public function getConfig($deviceId, $logKey) {
        $sql = "SELECT * FROM {$this->table} WHERE device_id = :device_id AND log_key = :log_key";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':device_id' => $deviceId, ':log_key' => $logKey]);
        return $stmt->fetch();
    }

    /**
     * Get all configs for a device
     */
    public function getByDevice($deviceId) {
        $sql = "SELECT * FROM {$this->table} WHERE device_id = :device_id ORDER BY log_key";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':device_id' => $deviceId]);
        return $stmt->fetchAll();
    }

    /**
     * Create or update sensor config
     */
    public function upsert($data) {
        $sql = "INSERT INTO {$this->table}
                (device_id, log_key, data_type, zero_value, span_value, unit, decimals,
                 min_alarm, max_alarm, alarm_enabled, label, sensor_type)
                VALUES
                (:device_id, :log_key, :data_type, :zero_value, :span_value, :unit, :decimals,
                 :min_alarm, :max_alarm, :alarm_enabled, :label, :sensor_type)
                ON DUPLICATE KEY UPDATE
                    data_type = VALUES(data_type),
                    zero_value = VALUES(zero_value),
                    span_value = VALUES(span_value),
                    unit = VALUES(unit),
                    decimals = VALUES(decimals),
                    min_alarm = VALUES(min_alarm),
                    max_alarm = VALUES(max_alarm),
                    alarm_enabled = VALUES(alarm_enabled),
                    label = VALUES(label),
                    sensor_type = VALUES(sensor_type),
                    updated_at = CURRENT_TIMESTAMP";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':device_id' => $data['device_id'],
            ':log_key' => $data['log_key'],
            ':data_type' => $data['data_type'] ?? 'real',
            ':zero_value' => $data['zero_value'] ?? 0,
            ':span_value' => $data['span_value'] ?? 100,
            ':unit' => $data['unit'] ?? '',
            ':decimals' => $data['decimals'] ?? 2,
            ':min_alarm' => $data['min_alarm'] ?? null,
            ':max_alarm' => $data['max_alarm'] ?? null,
            ':alarm_enabled' => $data['alarm_enabled'] ?? false,
            ':label' => $data['label'] ?? null,
            ':sensor_type' => $data['sensor_type'] ?? 'GEN',
        ]);
    }

    /**
     * Delete sensor config
     */
    public function delete($deviceId, $logKey) {
        $sql = "DELETE FROM {$this->table} WHERE device_id = :device_id AND log_key = :log_key";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':device_id' => $deviceId, ':log_key' => $logKey]);
    }

    /**
     * Convert raw 4-20mA value to real value using zero-span
     * Formula: real_value = zero + ((raw_mA - 4) / 16) * span
     *
     * @param float $rawValue Raw mA value (4-20)
     * @param array $config Sensor config with zero_value and span_value
     * @return float Converted real value
     */
    public function convertValue($rawValue, $config) {
        if (!$config || $config['data_type'] !== '4-20') {
            return $rawValue; // Return as-is for 'real' type
        }

        $raw = floatval($rawValue);
        $zero = floatval($config['zero_value']);
        $span = floatval($config['span_value']);

        // Clamp raw value to 4-20 range
        $raw = max(4, min(20, $raw));

        // Apply zero-span conversion
        $realValue = $zero + (($raw - 4) / 16) * $span;

        return $realValue;
    }

    /**
     * Check if value is in alarm state
     *
     * @param float $value The value to check
     * @param array $config Sensor config with alarm thresholds
     * @return array ['alarm' => bool, 'type' => 'low'|'high'|null, 'message' => string|null]
     */
    public function checkAlarm($value, $config) {
        if (!$config || !$config['alarm_enabled']) {
            return ['alarm' => false, 'type' => null, 'message' => null];
        }

        $minAlarm = $config['min_alarm'];
        $maxAlarm = $config['max_alarm'];

        if ($minAlarm !== null && $value < floatval($minAlarm)) {
            return [
                'alarm' => true,
                'type' => 'low',
                'message' => "Value {$value} below minimum {$minAlarm}"
            ];
        }

        if ($maxAlarm !== null && $value > floatval($maxAlarm)) {
            return [
                'alarm' => true,
                'type' => 'high',
                'message' => "Value {$value} above maximum {$maxAlarm}"
            ];
        }

        return ['alarm' => false, 'type' => null, 'message' => null];
    }

    /**
     * Get all unique log keys for a device from device_logs
     */
    public function getDeviceLogKeys($deviceId) {
        $sql = "SELECT DISTINCT log_key FROM device_logs WHERE device_id = :device_id ORDER BY log_key";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':device_id' => $deviceId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}

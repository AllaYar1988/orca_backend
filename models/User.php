<?php
require_once __DIR__ . '/../config/database.php';

class User {
    private $db;
    private $table = 'users';

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    // Role constants
    const ROLE_ADMIN = 'admin';
    const ROLE_USER = 'user';
    const ROLE_VIEWER = 'viewer';

    // Valid roles array for validation
    public static $validRoles = ['admin', 'user', 'viewer'];

    public function create($data) {
        // Validate role if provided
        $role = isset($data['role']) && in_array($data['role'], self::$validRoles)
            ? $data['role']
            : self::ROLE_USER;

        // Create user (companies are assigned via user_companies table)
        $sql = "INSERT INTO {$this->table} (username, password_hash, name, email, phone, is_active, role)
                VALUES (:username, :password_hash, :name, :email, :phone, :is_active, :role)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':username' => $data['username'],
            ':password_hash' => password_hash($data['password'], PASSWORD_DEFAULT),
            ':name' => isset($data['name']) ? $data['name'] : null,
            ':email' => isset($data['email']) ? $data['email'] : null,
            ':phone' => isset($data['phone']) ? $data['phone'] : null,
            ':is_active' => isset($data['is_active']) ? $data['is_active'] : 1,
            ':role' => $role
        ]);

        $userId = $this->db->lastInsertId();

        // Assign companies if provided (array of company IDs)
        if ($userId && !empty($data['company_ids'])) {
            foreach ($data['company_ids'] as $companyId) {
                $this->assignCompany($userId, $companyId);
            }
        }
        // Backwards compatibility: if single company_id provided
        elseif ($userId && !empty($data['company_id'])) {
            $this->assignCompany($userId, $data['company_id']);
        }

        return $userId;
    }

    public function getById($id) {
        $sql = "SELECT u.* FROM {$this->table} u WHERE u.id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }

    public function getByUsername($username) {
        $sql = "SELECT u.* FROM {$this->table} u WHERE u.username = :username";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch();

        if ($user) {
            // Check if user has at least one active company
            $companies = $this->getAssignedCompanies($user['id']);
            $user['company_active'] = count($companies) > 0 ? 1 : 0;
        }

        return $user;
    }

    public function getAll($filters = array()) {
        $sql = "SELECT u.* FROM {$this->table} u WHERE 1=1";
        $params = [];

        if (!empty($filters['company_id'])) {
            // Filter by company via user_companies table
            $sql .= " AND u.id IN (SELECT user_id FROM user_companies WHERE company_id = :company_id)";
            $params[':company_id'] = $filters['company_id'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (u.username LIKE :search OR u.name LIKE :search OR u.email LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        if (isset($filters['is_active'])) {
            $sql .= " AND u.is_active = :is_active";
            $params[':is_active'] = $filters['is_active'];
        }

        $sql .= " ORDER BY u.created_at DESC";

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

        foreach (['username', 'name', 'email', 'phone', 'is_active'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }

        // Handle role update with validation
        if (array_key_exists('role', $data) && in_array($data['role'], self::$validRoles)) {
            $fields[] = "role = :role";
            $params[':role'] = $data['role'];
        }

        // Handle password separately
        if (!empty($data['password'])) {
            $fields[] = "password_hash = :password_hash";
            $params[':password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
        }

        // Update user fields if any
        if (!empty($fields)) {
            $sql = "UPDATE {$this->table} SET " . implode(', ', $fields) . " WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
        }

        // Update company assignments if provided
        if (isset($data['company_ids'])) {
            $this->removeAllCompanies($id);
            foreach ($data['company_ids'] as $companyId) {
                $this->assignCompany($id, $companyId);
            }
        }

        return true;
    }

    public function updateLastLogin($id) {
        $sql = "UPDATE {$this->table} SET last_login_at = CURRENT_TIMESTAMP WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':id' => $id]);
    }

    public function delete($id) {
        $sql = "DELETE FROM {$this->table} WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':id' => $id]);
    }

    public function count($filters = array()) {
        $sql = "SELECT COUNT(*) FROM {$this->table} u WHERE 1=1";
        $params = [];

        if (!empty($filters['company_id'])) {
            $sql .= " AND u.company_id = :company_id";
            $params[':company_id'] = $filters['company_id'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (u.username LIKE :search OR u.name LIKE :search OR u.email LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        if (isset($filters['is_active'])) {
            $sql .= " AND u.is_active = :is_active";
            $params[':is_active'] = $filters['is_active'];
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public function verifyPassword($username, $password) {
        $user = $this->getByUsername($username);
        if ($user && password_verify($password, $user['password_hash'])) {
            return $user;
        }
        return false;
    }

    // Device access methods
    public function assignDevice($userId, $deviceId) {
        $sql = "INSERT IGNORE INTO user_devices (user_id, device_id) VALUES (:user_id, :device_id)";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':user_id' => $userId, ':device_id' => $deviceId]);
    }

    public function removeDevice($userId, $deviceId) {
        $sql = "DELETE FROM user_devices WHERE user_id = :user_id AND device_id = :device_id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':user_id' => $userId, ':device_id' => $deviceId]);
    }

    public function removeAllDevices($userId) {
        $sql = "DELETE FROM user_devices WHERE user_id = :user_id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':user_id' => $userId]);
    }

    public function getAssignedDevices($userId) {
        // Calculate is_online and seconds_ago dynamically
        // Use UTC_TIMESTAMP() since last_seen_at is stored in UTC
        $sql = "SELECT d.id, d.company_id, d.name, d.serial_number, d.description,
                d.device_type, d.is_active, d.last_seen_at, d.created_at, d.updated_at,
                c.name as company_name,
                CASE
                    WHEN d.last_seen_at IS NOT NULL
                    AND d.last_seen_at >= UTC_TIMESTAMP() - INTERVAL 60 MINUTE
                    THEN 1
                    ELSE 0
                END as is_online,
                CASE
                    WHEN d.last_seen_at IS NOT NULL
                    THEN TIMESTAMPDIFF(SECOND, d.last_seen_at, UTC_TIMESTAMP())
                    ELSE NULL
                END as seconds_ago
                FROM devices d
                INNER JOIN user_devices ud ON d.id = ud.device_id
                LEFT JOIN companies c ON d.company_id = c.id
                WHERE ud.user_id = :user_id AND d.is_active = 1
                ORDER BY d.name";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll();
    }

    public function getAssignedDeviceIds($userId) {
        $sql = "SELECT device_id FROM user_devices WHERE user_id = :user_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function hasAccessToDevice($userId, $deviceId) {
        $sql = "SELECT COUNT(*) FROM user_devices WHERE user_id = :user_id AND device_id = :device_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':user_id' => $userId, ':device_id' => $deviceId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    // Company access methods (new multi-company support)
    public function assignCompany($userId, $companyId) {
        $sql = "INSERT IGNORE INTO user_companies (user_id, company_id) VALUES (:user_id, :company_id)";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':user_id' => $userId, ':company_id' => $companyId]);
    }

    public function removeCompany($userId, $companyId) {
        $sql = "DELETE FROM user_companies WHERE user_id = :user_id AND company_id = :company_id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':user_id' => $userId, ':company_id' => $companyId]);
    }

    public function removeAllCompanies($userId) {
        $sql = "DELETE FROM user_companies WHERE user_id = :user_id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':user_id' => $userId]);
    }

    public function getAssignedCompanies($userId) {
        // Calculate online_count dynamically based on last_seen_at (within 5 minutes = online)
        $sql = "SELECT c.*,
                (SELECT COUNT(*) FROM devices d WHERE d.company_id = c.id AND d.is_active = 1) as device_count,
                (SELECT COUNT(*) FROM devices d WHERE d.company_id = c.id AND d.is_active = 1
                    AND d.last_seen_at IS NOT NULL
                    AND d.last_seen_at >= UTC_TIMESTAMP() - INTERVAL 60 MINUTE) as online_count
                FROM companies c
                INNER JOIN user_companies uc ON c.id = uc.company_id
                WHERE uc.user_id = :user_id AND c.is_active = 1
                ORDER BY c.name";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll();
    }

    public function getAssignedCompanyIds($userId) {
        $sql = "SELECT company_id FROM user_companies WHERE user_id = :user_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function hasAccessToCompany($userId, $companyId) {
        $sql = "SELECT COUNT(*) FROM user_companies WHERE user_id = :user_id AND company_id = :company_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':user_id' => $userId, ':company_id' => $companyId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function getCompanyDevices($userId, $companyId) {
        // First check if user has access to this company
        if (!$this->hasAccessToCompany($userId, $companyId)) {
            return [];
        }

        // Calculate is_online and seconds_ago dynamically
        // Use UTC_TIMESTAMP() since last_seen_at is stored in UTC
        $sql = "SELECT d.id, d.company_id, d.name, d.serial_number, d.description,
                d.device_type, d.is_active, d.last_seen_at, d.created_at, d.updated_at,
                c.name as company_name,
                CASE
                    WHEN d.last_seen_at IS NOT NULL
                    AND d.last_seen_at >= UTC_TIMESTAMP() - INTERVAL 60 MINUTE
                    THEN 1
                    ELSE 0
                END as is_online,
                CASE
                    WHEN d.last_seen_at IS NOT NULL
                    THEN TIMESTAMPDIFF(SECOND, d.last_seen_at, UTC_TIMESTAMP())
                    ELSE NULL
                END as seconds_ago
                FROM devices d
                INNER JOIN user_devices ud ON d.id = ud.device_id
                LEFT JOIN companies c ON d.company_id = c.id
                WHERE ud.user_id = :user_id
                AND d.company_id = :company_id
                AND d.is_active = 1
                ORDER BY d.name";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':user_id' => $userId, ':company_id' => $companyId]);
        return $stmt->fetchAll();
    }

    // ==========================================
    // Token-based Authentication Methods
    // ==========================================

    /**
     * Generate and store a new auth token for user
     * @param int $userId
     * @param int $expiryHours - Token validity in hours (default 24)
     * @param string|null $ipAddress
     * @param string|null $userAgent
     * @return string - The generated token
     */
    public function createToken($userId, $expiryHours = 24, $ipAddress = null, $userAgent = null) {
        // Generate secure random token
        $token = bin2hex(random_bytes(32));

        // Use MySQL's NOW() + INTERVAL to avoid PHP/MySQL timezone mismatch
        // Note: INTERVAL value must be concatenated, not bound as parameter
        $expiryHours = (int)$expiryHours;
        $sql = "INSERT INTO user_tokens (user_id, token, expires_at, ip_address, user_agent)
                VALUES (:user_id, :token, NOW() + INTERVAL {$expiryHours} HOUR, :ip_address, :user_agent)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':token' => $token,
            ':ip_address' => $ipAddress,
            ':user_agent' => $userAgent
        ]);

        return $token;
    }

    /**
     * Validate token and return user data if valid
     * @param string $token
     * @return array|false - User data or false if invalid
     */
    public function validateToken($token) {
        if (empty($token)) {
            return false;
        }

        $sql = "SELECT t.*, u.id as user_id, u.username, u.name, u.email, u.is_active, u.role
                FROM user_tokens t
                INNER JOIN users u ON t.user_id = u.id
                WHERE t.token = :token
                AND t.expires_at > NOW()
                AND u.is_active = 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':token' => $token]);
        $result = $stmt->fetch();

        if ($result) {
            // Update last_used_at
            $this->updateTokenLastUsed($token);
            return $result;
        }

        return false;
    }

    /**
     * Update token's last used timestamp
     * @param string $token
     */
    public function updateTokenLastUsed($token) {
        $sql = "UPDATE user_tokens SET last_used_at = NOW() WHERE token = :token";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':token' => $token]);
    }

    /**
     * Invalidate/delete a specific token (logout)
     * @param string $token
     * @return bool
     */
    public function deleteToken($token) {
        $sql = "DELETE FROM user_tokens WHERE token = :token";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':token' => $token]);
    }

    /**
     * Invalidate all tokens for a user (logout all devices)
     * @param int $userId
     * @return bool
     */
    public function deleteAllUserTokens($userId) {
        $sql = "DELETE FROM user_tokens WHERE user_id = :user_id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':user_id' => $userId]);
    }

    /**
     * Clean up expired tokens (call periodically)
     * @return int - Number of deleted tokens
     */
    public function cleanupExpiredTokens() {
        $sql = "DELETE FROM user_tokens WHERE expires_at < NOW()";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->rowCount();
    }

    /**
     * Extend token expiry time
     * @param string $token
     * @param int $expiryHours
     * @return bool
     */
    public function extendToken($token, $expiryHours = 24) {
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$expiryHours} hours"));
        $sql = "UPDATE user_tokens SET expires_at = :expires_at WHERE token = :token";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':expires_at' => $expiresAt, ':token' => $token]);
    }

    /**
     * Get user by token (for quick access)
     * @param string $token
     * @return array|false
     */
    public function getUserByToken($token) {
        $tokenData = $this->validateToken($token);
        if ($tokenData) {
            return $this->getById($tokenData['user_id']);
        }
        return false;
    }

    // ==========================================
    // Role-based Permission Methods
    // ==========================================

    /**
     * Check if user has admin role
     * @param int $userId
     * @return bool
     */
    public function isAdmin($userId) {
        $user = $this->getById($userId);
        return $user && $user['role'] === self::ROLE_ADMIN;
    }

    /**
     * Check if user has viewer role (read-only)
     * @param int $userId
     * @return bool
     */
    public function isViewer($userId) {
        $user = $this->getById($userId);
        return $user && $user['role'] === self::ROLE_VIEWER;
    }

    /**
     * Check if user can edit (admin or user, not viewer)
     * @param int $userId
     * @return bool
     */
    public function canEdit($userId) {
        $user = $this->getById($userId);
        return $user && in_array($user['role'], [self::ROLE_ADMIN, self::ROLE_USER]);
    }

    /**
     * Check if user can manage (admin only)
     * @param int $userId
     * @return bool
     */
    public function canManage($userId) {
        return $this->isAdmin($userId);
    }

    /**
     * Get user's role
     * @param int $userId
     * @return string|null
     */
    public function getRole($userId) {
        $user = $this->getById($userId);
        return $user ? $user['role'] : null;
    }

    // ==========================================
    // Sensor Access Methods
    // ==========================================

    /**
     * Assign specific sensor access for a user on a device
     * @param int $userId
     * @param int $deviceId
     * @param string $logKey - The sensor identifier (e.g., 'temperature')
     * @return bool
     */
    public function assignSensor($userId, $deviceId, $logKey) {
        $sql = "INSERT IGNORE INTO user_device_sensors (user_id, device_id, log_key) VALUES (:user_id, :device_id, :log_key)";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':user_id' => $userId, ':device_id' => $deviceId, ':log_key' => $logKey]);
    }

    /**
     * Remove specific sensor access for a user on a device
     * @param int $userId
     * @param int $deviceId
     * @param string $logKey
     * @return bool
     */
    public function removeSensor($userId, $deviceId, $logKey) {
        $sql = "DELETE FROM user_device_sensors WHERE user_id = :user_id AND device_id = :device_id AND log_key = :log_key";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':user_id' => $userId, ':device_id' => $deviceId, ':log_key' => $logKey]);
    }

    /**
     * Remove all sensor restrictions for a user on a device (grants access to all sensors)
     * @param int $userId
     * @param int $deviceId
     * @return bool
     */
    public function removeAllSensorRestrictions($userId, $deviceId) {
        $sql = "DELETE FROM user_device_sensors WHERE user_id = :user_id AND device_id = :device_id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':user_id' => $userId, ':device_id' => $deviceId]);
    }

    /**
     * Set sensor restrictions for a user on a device
     * Pass empty array to remove all restrictions (grant access to all sensors)
     * @param int $userId
     * @param int $deviceId
     * @param array $logKeys - Array of sensor log_keys to allow
     * @return bool
     */
    public function setSensorAccess($userId, $deviceId, $logKeys) {
        // Remove existing restrictions
        $this->removeAllSensorRestrictions($userId, $deviceId);

        // If empty array, user gets access to all sensors (no restrictions)
        if (empty($logKeys)) {
            return true;
        }

        // Add new restrictions
        foreach ($logKeys as $logKey) {
            $this->assignSensor($userId, $deviceId, $logKey);
        }
        return true;
    }

    /**
     * Get allowed sensors for a user on a device
     * Returns empty array if no restrictions (user can see all sensors)
     * @param int $userId
     * @param int $deviceId
     * @return array - Array of log_keys, or empty if no restrictions
     */
    public function getAllowedSensors($userId, $deviceId) {
        $sql = "SELECT log_key FROM user_device_sensors WHERE user_id = :user_id AND device_id = :device_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':user_id' => $userId, ':device_id' => $deviceId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Check if user has sensor restrictions for a device
     * @param int $userId
     * @param int $deviceId
     * @return bool - True if restricted, false if has access to all sensors
     */
    public function hasSensorRestrictions($userId, $deviceId) {
        $sql = "SELECT COUNT(*) FROM user_device_sensors WHERE user_id = :user_id AND device_id = :device_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':user_id' => $userId, ':device_id' => $deviceId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Check if user has access to a specific sensor on a device
     * @param int $userId
     * @param int $deviceId
     * @param string $logKey
     * @return bool
     */
    public function hasAccessToSensor($userId, $deviceId, $logKey) {
        // First check if user has access to the device
        if (!$this->hasAccessToDevice($userId, $deviceId)) {
            return false;
        }

        // Check if there are sensor restrictions
        if (!$this->hasSensorRestrictions($userId, $deviceId)) {
            // No restrictions = access to all sensors
            return true;
        }

        // Check if this specific sensor is in the allowed list
        $sql = "SELECT COUNT(*) FROM user_device_sensors WHERE user_id = :user_id AND device_id = :device_id AND log_key = :log_key";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':user_id' => $userId, ':device_id' => $deviceId, ':log_key' => $logKey]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Filter sensor data based on user's access
     * @param int $userId
     * @param int $deviceId
     * @param array $sensorData - Array of sensor records with 'log_key' field
     * @return array - Filtered array with only allowed sensors
     */
    public function filterSensorsByAccess($userId, $deviceId, $sensorData) {
        // Check if there are restrictions
        $allowedSensors = $this->getAllowedSensors($userId, $deviceId);

        // No restrictions = return all data
        if (empty($allowedSensors)) {
            return $sensorData;
        }

        // Filter to only allowed sensors
        return array_filter($sensorData, function($sensor) use ($allowedSensors) {
            return isset($sensor['log_key']) && in_array($sensor['log_key'], $allowedSensors);
        });
    }
}

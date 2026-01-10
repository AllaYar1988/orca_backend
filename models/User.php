<?php
require_once __DIR__ . '/../config/database.php';

class User {
    private $db;
    private $table = 'users';

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function create($data) {
        // Create user (companies are assigned via user_companies table)
        $sql = "INSERT INTO {$this->table} (username, password_hash, name, email, phone, is_active)
                VALUES (:username, :password_hash, :name, :email, :phone, :is_active)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':username' => $data['username'],
            ':password_hash' => password_hash($data['password'], PASSWORD_DEFAULT),
            ':name' => isset($data['name']) ? $data['name'] : null,
            ':email' => isset($data['email']) ? $data['email'] : null,
            ':phone' => isset($data['phone']) ? $data['phone'] : null,
            ':is_active' => isset($data['is_active']) ? $data['is_active'] : 1
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
        $sql = "SELECT d.*, c.name as company_name
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
        $sql = "SELECT c.*,
                (SELECT COUNT(*) FROM devices d WHERE d.company_id = c.id AND d.is_active = 1) as device_count,
                (SELECT COUNT(*) FROM devices d WHERE d.company_id = c.id AND d.is_active = 1 AND d.is_online = 1) as online_count
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

        $sql = "SELECT d.*, c.name as company_name
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
        $sql = "INSERT INTO user_tokens (user_id, token, expires_at, ip_address, user_agent)
                VALUES (:user_id, :token, NOW() + INTERVAL :hours HOUR, :ip_address, :user_agent)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':token' => $token,
            ':hours' => $expiryHours,
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

        $sql = "SELECT t.*, u.id as user_id, u.username, u.name, u.email, u.is_active
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
}

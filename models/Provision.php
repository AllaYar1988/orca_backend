<?php
require_once __DIR__ . '/../config/database.php';

/**
 * Provision
 *
 * One row per chip, ever. The record of every grant this server issued, and
 * the quota and serial-allocation bookkeeping that goes with it.
 *
 * The invariants the model keeps:
 *   - uid is unique: a chip asked about twice gets the same answer.
 *   - a serial is live on at most one chip (retired rows may share it).
 *   - a serial is allocated under a row lock on the company, so two benches
 *     asking at once cannot be handed the same number.
 */
class Provision {
    private $db;
    private $table = 'provisions';

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function getConnection() {
        return $this->db;
    }

    /**
     * The row for a chip, with its company, or false.
     */
    public function findByUid($uid) {
        $sql = "SELECT p.*, c.name AS company_name, c.code AS company_code
                FROM {$this->table} p
                LEFT JOIN companies c ON c.id = p.company_id
                WHERE p.uid = :uid";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':uid' => strtoupper($uid)]);
        return $stmt->fetch();
    }

    /**
     * The chip currently holding a serial, or false.
     */
    public function findLiveBySerial($serial) {
        $sql = "SELECT * FROM {$this->table}
                WHERE serial_number = :serial AND retired_at IS NULL";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':serial' => $serial]);
        return $stmt->fetch();
    }

    /**
     * Live grants a company holds - what its quota is measured against.
     */
    public function countLive($companyId) {
        $sql = "SELECT COUNT(*) FROM {$this->table}
                WHERE company_id = :cid AND retired_at IS NULL";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':cid' => $companyId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Lock the company row and return it. Call inside a transaction; the
     * lock is what makes allocation and the quota check atomic.
     */
    public function lockCompany($companyId) {
        $sql = "SELECT * FROM companies WHERE id = :id FOR UPDATE";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $companyId]);
        return $stmt->fetch();
    }

    /**
     * Next serial from the company's range: prefix + zero-padded counter,
     * eight characters in all (A2 + 000137 = A2000137), the shape every
     * existing serial has and the shape the application's own buffers
     * assume. Skips numbers already live, which happens after a vendor has
     * supplied some serials by hand.
     *
     * Company row must already be locked.
     */
    public function allocateSerial(array $company) {
        $prefix = (string)($company['serial_prefix'] ?? '');
        if ($prefix === '') {
            throw new RuntimeException('company has no serial_prefix; the vendor must supply a serial');
        }
        $width = max(1, 8 - strlen($prefix));
        $next  = (int)$company['serial_next'];

        for ($tries = 0; $tries < 10000; $tries++) {
            $serial = $prefix . str_pad((string)$next, $width, '0', STR_PAD_LEFT);
            $next++;
            if (!$this->findLiveBySerial($serial)) {
                $stmt = $this->db->prepare("UPDATE companies SET serial_next = :n WHERE id = :id");
                $stmt->execute([':n' => $next, ':id' => $company['id']]);
                return $serial;
            }
        }
        throw new RuntimeException('serial range exhausted for prefix ' . $prefix);
    }

    /**
     * Record a freshly issued grant.
     */
    public function create(array $d) {
        $sql = "INSERT INTO {$this->table}
                (company_id, uid, serial_number, grant_b64, issued_utc,
                 tool, tool_version, fw_version, ip_address)
                VALUES
                (:company_id, :uid, :serial_number, :grant_b64, :issued_utc,
                 :tool, :tool_version, :fw_version, :ip_address)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':company_id'    => $d['company_id'],
            ':uid'           => strtoupper($d['uid']),
            ':serial_number' => $d['serial_number'],
            ':grant_b64'     => $d['grant_b64'],
            ':issued_utc'    => $d['issued_utc'],
            ':tool'          => $d['tool'] ?? null,
            ':tool_version'  => $d['tool_version'] ?? null,
            ':fw_version'    => $d['fw_version'] ?? null,
            ':ip_address'    => $d['ip_address'] ?? null,
        ]);
        return $this->db->lastInsertId();
    }

    /**
     * The same chip asked again. Free - but counted, so a board that is
     * re-provisioned forty times shows up.
     */
    public function markReissued($id, $tool = null, $toolVersion = null, $fwVersion = null) {
        $sql = "UPDATE {$this->table}
                SET reissue_count = reissue_count + 1,
                    last_reissued_at = NOW(),
                    tool = COALESCE(:tool, tool),
                    tool_version = COALESCE(:tool_version, tool_version),
                    fw_version = COALESCE(:fw_version, fw_version)
                WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':id' => $id, ':tool' => $tool,
            ':tool_version' => $toolVersion, ':fw_version' => $fwVersion,
        ]);
    }

    /**
     * MCU replaced: retire this chip's grant so its serial can be issued to
     * the new chip. Admin action, never automatic - "my MCU broke" must not
     * become a free-device button.
     */
    public function retire($id, $replacedBy = null) {
        $sql = "UPDATE {$this->table}
                SET retired_at = NOW(), replaced_by = :rb
                WHERE id = :id AND retired_at IS NULL";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id, ':rb' => $replacedBy]);
        return $stmt->rowCount() > 0;
    }

    public function getById($id) {
        $sql = "SELECT p.*, c.name AS company_name, c.code AS company_code
                FROM {$this->table} p
                LEFT JOIN companies c ON c.id = p.company_id
                WHERE p.id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }

    /**
     * Listing for the admin page.
     */
    public function getAll(array $filters = []) {
        $sql = "SELECT p.*, c.name AS company_name, c.code AS company_code
                FROM {$this->table} p
                LEFT JOIN companies c ON c.id = p.company_id
                WHERE 1=1";
        $params = [];

        if (!empty($filters['company_id'])) {
            $sql .= " AND p.company_id = :cid";
            $params[':cid'] = $filters['company_id'];
        }
        if (!empty($filters['search'])) {
            $sql .= " AND (p.uid LIKE :s OR p.serial_number LIKE :s)";
            $params[':s'] = '%' . $filters['search'] . '%';
        }
        if (isset($filters['live'])) {
            $sql .= $filters['live'] ? " AND p.retired_at IS NULL" : " AND p.retired_at IS NOT NULL";
        }

        $sql .= " ORDER BY p.created_at DESC";

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

    public function count(array $filters = []) {
        $sql = "SELECT COUNT(*) FROM {$this->table} p WHERE 1=1";
        $params = [];
        if (!empty($filters['company_id'])) {
            $sql .= " AND p.company_id = :cid";
            $params[':cid'] = $filters['company_id'];
        }
        if (!empty($filters['search'])) {
            $sql .= " AND (p.uid LIKE :s OR p.serial_number LIKE :s)";
            $params[':s'] = '%' . $filters['search'] . '%';
        }
        if (isset($filters['live'])) {
            $sql .= $filters['live'] ? " AND p.retired_at IS NULL" : " AND p.retired_at IS NOT NULL";
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Per company: live grants against quota, and this month's issues.
     * The invoice, essentially.
     */
    public function statsByCompany() {
        $sql = "SELECT c.id, c.name, c.code, c.is_active,
                       c.provision_key IS NOT NULL AS can_provision,
                       c.device_quota, c.serial_prefix, c.serial_next,
                       COUNT(p.id) AS total,
                       SUM(p.retired_at IS NULL) AS live,
                       SUM(p.retired_at IS NULL
                           AND p.created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')) AS this_month,
                       SUM(p.reissue_count) AS reissues,
                       MAX(p.created_at) AS last_issued_at
                FROM companies c
                LEFT JOIN {$this->table} p ON p.company_id = c.id
                GROUP BY c.id
                ORDER BY live DESC, c.name";
        return $this->db->query($sql)->fetchAll();
    }

    /**
     * Serials seen in the field with no live grant behind them: devices
     * built outside the process. Reads the view the migration creates.
     */
    public function unprovisionedDevices() {
        return $this->db->query(
            "SELECT * FROM unprovisioned_devices ORDER BY last_seen_at DESC"
        )->fetchAll();
    }
}

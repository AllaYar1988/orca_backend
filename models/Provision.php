<?php
require_once __DIR__ . '/../config/database.php';

/**
 * Provision
 *
 * One row per chip, ever. The record of every grant this server issued.
 *
 * There is one vendor, so there is no owner column to group by: every row
 * here is ours. company_id survives, nullable and unwritten, for the question
 * it can answer later - which CUSTOMER a device was sold to.
 *
 * The invariants the model keeps:
 *   - uid is unique: a chip asked about twice gets the same answer.
 *   - a serial is live on at most one chip (retired rows may share it).
 *   - allocation reads the highest serial FOR UPDATE, so two benches asking
 *     at once cannot be handed the same number.
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
     * The row for a chip, or false.
     */
    public function findByUid($uid) {
        $sql = "SELECT * FROM {$this->table} WHERE uid = :uid";
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
     * Live grants in total - how many devices exist because of us.
     */
    public function countLive() {
        return (int)$this->db->query(
            "SELECT COUNT(*) FROM {$this->table} WHERE retired_at IS NULL")->fetchColumn();
    }

    /**
     * Next serial: prefix + zero-padded counter, eight characters in all
     * (A + 0020707 = A0020707), the shape every existing serial has and the
     * shape the application's own buffers assume.
     *
     * Derived from the highest live serial rather than a stored counter, so
     * there is no second number to keep in step with the rows. The SELECT
     * takes FOR UPDATE, which is what stops two benches being handed the
     * same number; fixed-width zero padding is what makes string ordering
     * and numeric ordering the same thing.
     *
     * Call inside a transaction.
     */
    public function allocateSerial($prefix) {
        $prefix = strtoupper($prefix);
        $width  = max(1, 8 - strlen($prefix));

        $stmt = $this->db->prepare(
            "SELECT serial_number FROM {$this->table}
             WHERE serial_number LIKE :like AND CHAR_LENGTH(serial_number) = :len
             ORDER BY serial_number DESC LIMIT 1 FOR UPDATE");
        $stmt->execute([':like' => $prefix . '%', ':len' => strlen($prefix) + $width]);
        $highest = $stmt->fetchColumn();

        $next = $highest === false ? 1 : ((int)substr($highest, strlen($prefix)) + 1);

        for ($tries = 0; $tries < 10000; $tries++) {
            $serial = $prefix . str_pad((string)$next, $width, '0', STR_PAD_LEFT);
            if (!$this->findLiveBySerial($serial)) {
                return $serial;
            }
            $next++;
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
            ':company_id'    => $d['company_id'] ?? null,
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
        $sql = "SELECT * FROM {$this->table} WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }

    /**
     * Listing for the admin page.
     */
    public function getAll(array $filters = []) {
        $sql = "SELECT * FROM {$this->table} p WHERE 1=1";
        $params = [];

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
     * The production numbers: how many devices exist, and how lately.
     */
    public function stats() {
        $sql = "SELECT COUNT(*) AS total,
                       SUM(retired_at IS NULL) AS live,
                       SUM(retired_at IS NOT NULL) AS retired,
                       SUM(retired_at IS NULL
                           AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')) AS this_month,
                       SUM(reissue_count) AS reissues,
                       MIN(created_at) AS first_issued_at,
                       MAX(created_at) AS last_issued_at
                FROM {$this->table}";
        $r = $this->db->query($sql)->fetch();
        foreach (['total', 'live', 'retired', 'this_month', 'reissues'] as $k) {
            $r[$k] = (int)$r[$k];
        }
        return $r;
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

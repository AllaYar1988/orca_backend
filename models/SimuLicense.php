<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/SimuSigner.php';

/**
 * A refusal the caller can name. The API turns $apiCode into its JSON
 * "code" and $http into the status; the admin page shows the message.
 */
class SimuLicenseRefused extends RuntimeException {
    public $apiCode;
    public $http;
    public function __construct($apiCode, $message, $http = 409) {
        parent::__construct($message);
        $this->apiCode = $apiCode;
        $this->http    = $http;
    }
}

/**
 * SimuLicense
 *
 * One row per PC, ever. The record of every Simu licence this server issued,
 * and of every request it has not (yet) granted.
 *
 * The invariants the model keeps:
 *   - machine_id is unique: one row per PC, so a PC is one licence however
 *     many times it asks. request() on a known PC updates its row rather than
 *     adding one.
 *   - a document is signed exactly once, at approve() or issueManual(), and
 *     stored verbatim; every later request returns those same bytes.
 *   - status moves pending -> active | rejected, rejected -> pending (the PC
 *     asked again), active <-> disabled (an admin). Nothing else.
 *   - every decision is written to simu_license_history before the row that
 *     it describes changes.
 */
class SimuLicense {
    const PENDING  = 'pending';
    const ACTIVE   = 'active';
    const REJECTED = 'rejected';
    const DISABLED = 'disabled';
    const STATUSES = [self::PENDING, self::ACTIVE, self::REJECTED, self::DISABLED];

    private $db;
    private $table = 'simu_licenses';

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function getConnection() {
        return $this->db;
    }

    // ------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------

    /**
     * The row for a PC, or false.
     */
    public function findByMachine($machineId) {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE machine_id = :m");
        $stmt->execute([':m' => strtoupper($machineId)]);
        return $stmt->fetch();
    }

    /**
     * One row with its company name, or false.
     */
    public function getById($id) {
        $sql = "SELECT l.*, c.name AS company_name
                FROM {$this->table} l
                LEFT JOIN companies c ON c.id = l.company_id
                WHERE l.id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => (int)$id]);
        return $stmt->fetch();
    }

    /**
     * What is waiting for a decision, oldest first.
     */
    public function pending() {
        $sql = "SELECT * FROM {$this->table} WHERE status = 'pending' ORDER BY requested_at ASC, id ASC";
        return $this->db->query($sql)->fetchAll();
    }

    // ------------------------------------------------------------------
    // What Simu does
    // ------------------------------------------------------------------

    /**
     * A PC asks for a licence in the customer's name.
     *
     * Returns ['row' => the row after the call, 'created' => bool].
     * Throws SimuLicenseRefused for a disabled PC.
     */
    public function request($machineId, $customer, $contact, $hostname, $version, $ip) {
        $mid = strtoupper($machineId);
        $row = $this->findByMachine($mid);

        if (!$row) {
            $sql = "INSERT INTO {$this->table}
                    (machine_id, customer, contact, hostname, simu_version, status, source,
                     requested_at, ip_address)
                    VALUES
                    (:m, :c, :ct, :h, :v, 'pending', 'online', NOW(), :ip)";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':m' => $mid, ':c' => $customer, ':ct' => $contact,
                            ':h' => $hostname, ':v' => $version, ':ip' => $ip]);
            $id = (int)$this->db->lastInsertId();
            $this->logHistory(['license_id' => $id, 'event' => 'requested', 'machine_id' => $mid,
                               'customer' => $customer, 'actor' => 'simu', 'ip_address' => $ip]);
            return ['row' => $this->getById($id), 'created' => true];
        }

        switch ($row['status']) {
            case self::DISABLED:
                throw new SimuLicenseRefused('DISABLED',
                    'The licence for this computer was disabled by Almas Electronic', 403);

            case self::ACTIVE:
                // A reinstall, or a wiped folder: the same document, free -
                // but counted, so a PC that asks forty times shows up.
                $sql = "UPDATE {$this->table}
                        SET hostname = COALESCE(:h, hostname),
                            simu_version = COALESCE(:v, simu_version),
                            reissue_count = reissue_count + 1,
                            last_poll_at = NOW(),
                            ip_address = COALESCE(:ip, ip_address)
                        WHERE id = :id";
                $this->db->prepare($sql)->execute([':h' => $hostname, ':v' => $version,
                                                   ':ip' => $ip, ':id' => $row['id']]);
                $this->logHistory(['license_id' => $row['id'], 'event' => 'reissued', 'machine_id' => $mid,
                                   'customer' => $row['customer'], 'actor' => 'simu', 'ip_address' => $ip]);
                break;

            case self::PENDING:
            case self::REJECTED:
                // Asked again: take the latest name and contact, and a refused
                // PC goes back in the queue - the admin decides again.
                $sql = "UPDATE {$this->table}
                        SET status = 'pending', customer = :c, contact = COALESCE(:ct, contact),
                            hostname = COALESCE(:h, hostname),
                            simu_version = COALESCE(:v, simu_version),
                            requested_at = NOW(), ip_address = COALESCE(:ip, ip_address)
                        WHERE id = :id";
                $this->db->prepare($sql)->execute([':c' => $customer, ':ct' => $contact, ':h' => $hostname,
                                                   ':v' => $version, ':ip' => $ip, ':id' => $row['id']]);
                $this->logHistory(['license_id' => $row['id'], 'event' => 'requested', 'machine_id' => $mid,
                                   'customer' => $customer, 'actor' => 'simu', 'ip_address' => $ip,
                                   'note' => $row['status'] === self::REJECTED
                                             ? 'asked again after a refusal' : 'asked again']);
                break;
        }
        return ['row' => $this->getById($row['id']), 'created' => false];
    }

    /**
     * The PC checked back. Counted, so a pending row that stopped polling
     * can be told from one that is still waiting.
     */
    public function markPolled($id) {
        $sql = "UPDATE {$this->table} SET last_poll_at = NOW(), poll_count = poll_count + 1 WHERE id = :id";
        $this->db->prepare($sql)->execute([':id' => (int)$id]);
    }

    // ------------------------------------------------------------------
    // What an admin does
    // ------------------------------------------------------------------

    /**
     * Approve a request: sign the document and make the row active.
     * Idempotent on an active row. Refuses a disabled one (restore instead).
     */
    public function approve($id, SimuSigner $signer, $actor, $ip, $note = null) {
        $row = $this->getById($id);
        if (!$row) {
            throw new SimuLicenseRefused('NOT_FOUND', "No licence #$id", 404);
        }
        if ($row['status'] === self::ACTIVE) {
            return $row;
        }
        if ($row['status'] === self::DISABLED) {
            throw new SimuLicenseRefused('DISABLED', "Licence #$id is disabled - restore it instead", 409);
        }

        $issued = time();
        $doc = $signer->issue($row['id'], $row['machine_id'], $row['customer'], $issued);

        $sql = "UPDATE {$this->table}
                SET status = 'active', license_doc = :doc, issued_utc = :issued,
                    approved_at = NOW(), disabled_at = NULL
                WHERE id = :id";
        $this->db->prepare($sql)->execute([':doc' => $doc, ':issued' => $issued, ':id' => $row['id']]);
        $this->logHistory(['license_id' => $row['id'], 'event' => 'approved', 'machine_id' => $row['machine_id'],
                           'customer' => $row['customer'], 'license_doc' => $doc, 'actor' => $actor,
                           'ip_address' => $ip, 'note' => $note]);
        return $this->getById($row['id']);
    }

    /**
     * Refuse a request. The PC can ask again, which makes it pending again.
     */
    public function reject($id, $actor, $ip, $note = null) {
        $row = $this->getById($id);
        if (!$row) {
            throw new SimuLicenseRefused('NOT_FOUND', "No licence #$id", 404);
        }
        if ($row['status'] !== self::PENDING) {
            throw new SimuLicenseRefused('NOT_PENDING', "Licence #$id is {$row['status']}, not pending", 409);
        }
        $this->db->prepare("UPDATE {$this->table} SET status = 'rejected' WHERE id = :id")
                 ->execute([':id' => $row['id']]);
        $this->logHistory(['license_id' => $row['id'], 'event' => 'rejected', 'machine_id' => $row['machine_id'],
                           'customer' => $row['customer'], 'actor' => $actor, 'ip_address' => $ip,
                           'note' => $note]);
        return $this->getById($row['id']);
    }

    /**
     * The offline path: the admin typed a Machine ID the customer emailed.
     * The row is born active with its document; download it and send it.
     */
    public function issueManual($machineId, $customer, $companyId, $notes, SimuSigner $signer, $actor, $ip) {
        $mid = strtoupper($machineId);
        $existing = $this->findByMachine($mid);
        if ($existing) {
            throw new SimuLicenseRefused('EXISTS',
                "Machine ID $mid already has licence #{$existing['id']} ({$existing['status']}) - "
                . "approve, restore or download it there", 409);
        }

        $sql = "INSERT INTO {$this->table}
                (machine_id, customer, company_id, status, source, requested_at, approved_at, ip_address, notes)
                VALUES
                (:m, :c, :co, 'active', 'manual', NOW(), NOW(), :ip, :n)";
        $this->db->prepare($sql)->execute([':m' => $mid, ':c' => $customer,
                                           ':co' => $companyId ?: null, ':ip' => $ip, ':n' => $notes ?: null]);
        $id = (int)$this->db->lastInsertId();

        $issued = time();
        $doc = $signer->issue($id, $mid, $customer, $issued);
        $this->db->prepare("UPDATE {$this->table} SET license_doc = :doc, issued_utc = :issued WHERE id = :id")
                 ->execute([':doc' => $doc, ':issued' => $issued, ':id' => $id]);
        $this->logHistory(['license_id' => $id, 'event' => 'issued_manual', 'machine_id' => $mid,
                           'customer' => $customer, 'license_doc' => $doc, 'actor' => $actor,
                           'ip_address' => $ip, 'note' => $notes ?: null]);
        return $this->getById($id);
    }

    /**
     * Revoke. Simu is refused from now on; the file already on the PC goes
     * on working, because nothing expires yet - this is bookkeeping and a
     * closed door, not a kill switch.
     */
    public function disable($id, $actor, $ip, $note = null) {
        $row = $this->getById($id);
        if (!$row) {
            throw new SimuLicenseRefused('NOT_FOUND', "No licence #$id", 404);
        }
        if ($row['status'] !== self::ACTIVE) {
            throw new SimuLicenseRefused('NOT_ACTIVE', "Licence #$id is {$row['status']}, not active", 409);
        }
        $this->db->prepare("UPDATE {$this->table} SET status = 'disabled', disabled_at = NOW() WHERE id = :id")
                 ->execute([':id' => $row['id']]);
        $this->logHistory(['license_id' => $row['id'], 'event' => 'disabled', 'machine_id' => $row['machine_id'],
                           'customer' => $row['customer'], 'actor' => $actor, 'ip_address' => $ip,
                           'note' => $note]);
        return $this->getById($row['id']);
    }

    /**
     * Undo a disable. The stored document is handed out again unchanged.
     */
    public function restore($id, $actor, $ip, $note = null) {
        $row = $this->getById($id);
        if (!$row) {
            throw new SimuLicenseRefused('NOT_FOUND', "No licence #$id", 404);
        }
        if ($row['status'] !== self::DISABLED) {
            throw new SimuLicenseRefused('NOT_DISABLED', "Licence #$id is {$row['status']}, not disabled", 409);
        }
        $this->db->prepare("UPDATE {$this->table} SET status = 'active', disabled_at = NULL WHERE id = :id")
                 ->execute([':id' => $row['id']]);
        $this->logHistory(['license_id' => $row['id'], 'event' => 'restored', 'machine_id' => $row['machine_id'],
                           'customer' => $row['customer'], 'actor' => $actor, 'ip_address' => $ip,
                           'note' => $note]);
        return $this->getById($row['id']);
    }

    // ------------------------------------------------------------------
    // History
    // ------------------------------------------------------------------

    public function logHistory(array $d) {
        $sql = "INSERT INTO simu_license_history
                (license_id, event, machine_id, customer, license_doc, actor, ip_address, note)
                VALUES
                (:license_id, :event, :machine_id, :customer, :license_doc, :actor, :ip_address, :note)";
        $this->db->prepare($sql)->execute([
            ':license_id'  => (int)$d['license_id'],
            ':event'       => $d['event'],
            ':machine_id'  => strtoupper($d['machine_id']),
            ':customer'    => $d['customer'] ?? null,
            ':license_doc' => $d['license_doc'] ?? null,
            ':actor'       => $d['actor'] ?? null,
            ':ip_address'  => $d['ip_address'] ?? null,
            ':note'        => isset($d['note']) ? substr((string)$d['note'], 0, 255) : null,
        ]);
    }

    /**
     * The timeline of one licence, newest first.
     */
    public function historyFor($id, $limit = 50) {
        $sql = "SELECT * FROM simu_license_history WHERE license_id = :id
                ORDER BY created_at DESC, id DESC LIMIT " . (int)$limit;
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => (int)$id]);
        return $stmt->fetchAll();
    }

    /**
     * The note on the latest event of a kind - what the admin wrote when
     * refusing, for Simu to show.
     */
    public function lastNote($id, $event) {
        $sql = "SELECT note FROM simu_license_history WHERE license_id = :id AND event = :e
                ORDER BY created_at DESC, id DESC LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => (int)$id, ':e' => $event]);
        $r = $stmt->fetch();
        return $r ? $r['note'] : null;
    }

    // ------------------------------------------------------------------
    // Listing
    // ------------------------------------------------------------------

    private function whereFrom(array $filters, array &$params) {
        $where = " WHERE 1=1";
        if (!empty($filters['search'])) {
            $where .= " AND (l.machine_id LIKE :search OR l.customer LIKE :search
                            OR l.hostname LIKE :search OR l.contact LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }
        if (!empty($filters['status']) && in_array($filters['status'], self::STATUSES, true)) {
            $where .= " AND l.status = :status";
            $params[':status'] = $filters['status'];
        }
        return $where;
    }

    public function getAll(array $filters = []) {
        $params = [];
        $sql = "SELECT l.*, c.name AS company_name
                FROM {$this->table} l
                LEFT JOIN companies c ON c.id = l.company_id"
             . $this->whereFrom($filters, $params)
             . " ORDER BY l.created_at DESC, l.id DESC";
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
        $params = [];
        $sql = "SELECT COUNT(*) AS n FROM {$this->table} l" . $this->whereFrom($filters, $params);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetch()['n'];
    }

    /**
     * The numbers on the admin page.
     */
    public function stats() {
        $sql = "SELECT
                    SUM(status = 'pending')  AS pending,
                    SUM(status = 'active')   AS active,
                    SUM(status = 'rejected') AS rejected,
                    SUM(status = 'disabled') AS disabled,
                    SUM(status = 'active' AND approved_at >= DATE_FORMAT(NOW(), '%Y-%m-01')) AS this_month,
                    MAX(approved_at) AS last_approved_at
                FROM {$this->table}";
        $r = $this->db->query($sql)->fetch();
        foreach (['pending', 'active', 'rejected', 'disabled', 'this_month'] as $k) {
            $r[$k] = (int)($r[$k] ?? 0);
        }
        return $r;
    }
}

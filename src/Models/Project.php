<?php

declare(strict_types=1);

namespace CoyshCRM\Models;

class Project extends Model
{
    protected string $table = 'projects';

    public function findAllWithClient(?int $clientId = null, ?string $status = null): array
    {
        $sql = "
            SELECT p.*, c.name AS client_name
            FROM projects p
            JOIN clients c ON c.id = p.client_id
            WHERE 1=1
        ";
        $params = [];

        if ($clientId) {
            $sql .= ' AND p.client_id = ?';
            $params[] = $clientId;
        }
        if ($status) {
            $sql .= ' AND p.status = ?';
            $params[] = $status;
        }

        $sql .= ' ORDER BY p.created_at DESC';
        return $this->query($sql, $params)->fetchAll();
    }

    /**
     * IDs of the FreeAgent invoices linked to a project.
     *
     * @return int[]
     */
    public function linkedInvoiceIds(int $projectId): array
    {
        try {
            return array_map('intval', $this->query(
                "SELECT freeagent_invoice_id FROM project_invoice_links WHERE project_id = ?",
                [$projectId]
            )->fetchAll(\PDO::FETCH_COLUMN));
        } catch (\Throwable) {
            return []; // table not migrated yet
        }
    }

    /**
     * The invoices a project could be linked to — every invoice belonging to
     * its client — annotated with whether they're already linked here or to
     * another project.
     */
    public function invoiceOptions(int $clientId, ?int $projectId = null): array
    {
        try {
            return $this->query("
                SELECT fi.id,
                       fi.reference,
                       fi.dated_on,
                       fi.total_value,
                       COALESCE(fi.net_value, fi.total_value)  AS net_value,
                       COALESCE(fi.status_override, fi.status) AS eff_status,
                       CASE WHEN pil_here.id IS NOT NULL THEN 1 ELSE 0 END AS linked_here,
                       other_p.id                                          AS other_project_id,
                       other_p.name                                        AS other_project_name
                FROM freeagent_invoices fi
                LEFT JOIN project_invoice_links pil_here
                       ON pil_here.freeagent_invoice_id = fi.id
                      AND pil_here.project_id = ?
                LEFT JOIN project_invoice_links pil_other
                       ON pil_other.freeagent_invoice_id = fi.id
                      AND (? IS NULL OR pil_other.project_id != ?)
                LEFT JOIN projects other_p ON other_p.id = pil_other.project_id
                WHERE fi.client_id = ?
                GROUP BY fi.id
                ORDER BY fi.dated_on DESC, fi.id DESC
            ", [$projectId ?? 0, $projectId, $projectId, $clientId])->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Replace a project's invoice links and recompute income_invoiced from
     * them. Net (ex-VAT) values are used, matching every other revenue
     * aggregate in the app.
     *
     * @param int[] $invoiceIds
     */
    public function syncInvoiceLinks(int $projectId, array $invoiceIds): void
    {
        $invoiceIds = array_values(array_unique(array_filter(array_map('intval', $invoiceIds))));

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) $this->db->beginTransaction();

        try {
            $this->query("DELETE FROM project_invoice_links WHERE project_id = ?", [$projectId]);

            if ($invoiceIds) {
                // Only link invoices that actually belong to this project's client.
                $in   = implode(',', array_fill(0, count($invoiceIds), '?'));
                $stmt = $this->query("
                    INSERT OR IGNORE INTO project_invoice_links (project_id, freeagent_invoice_id)
                    SELECT ?, fi.id
                    FROM freeagent_invoices fi
                    JOIN projects p ON p.id = ?
                    WHERE fi.id IN ($in) AND fi.client_id = p.client_id
                ", [$projectId, $projectId, ...$invoiceIds]);
                unset($stmt);
            }

            $this->query("
                UPDATE projects
                SET income_invoiced = (
                    SELECT COALESCE(SUM(COALESCE(fi.net_value, fi.total_value)), 0)
                    FROM project_invoice_links pil
                    JOIN freeagent_invoices fi ON fi.id = pil.freeagent_invoice_id
                    WHERE pil.project_id = projects.id
                )
                WHERE id = ?
            ", [$projectId]);

            if ($ownTransaction) $this->db->commit();
        } catch (\Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /** Linked-invoice count per project id, for list views. */
    public function invoiceLinkCounts(array $projectIds = []): array
    {
        try {
            $sql = "SELECT project_id, COUNT(*) AS cnt FROM project_invoice_links";
            $params = [];
            if ($projectIds) {
                $in = implode(',', array_fill(0, count($projectIds), '?'));
                $sql .= " WHERE project_id IN ($in)";
                $params = array_map('intval', $projectIds);
            }
            $sql .= " GROUP BY project_id";

            $out = [];
            foreach ($this->query($sql, $params)->fetchAll() as $row) {
                $out[(int)$row['project_id']] = (int)$row['cnt'];
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    public static function incomeCategories(): array
    {
        return [
            'web_design'      => 'Web Design',
            'web_development' => 'Web Development',
            'consultancy'     => 'Consultancy',
            'hosting'         => 'Hosting',
            'email_hosting'   => 'Email Hosting',
            'domain'          => 'Domain',
        ];
    }

    public static function statuses(): array
    {
        return ['active' => 'Active', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
    }
}

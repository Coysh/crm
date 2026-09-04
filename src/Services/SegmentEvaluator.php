<?php

declare(strict_types=1);

namespace CoyshCRM\Services;

use PDO;
use RuntimeException;

final class SegmentEvaluator
{
    public function __construct(private PDO $db) {}

    public function contacts(int $segmentId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM marketing_segments WHERE id = ?');
        $stmt->execute([$segmentId]);
        $segment = $stmt->fetch();
        if (!$segment) throw new RuntimeException('Segment not found.');

        if ($segment['segment_type'] === 'manual') {
            $sql = "SELECT mc.* FROM marketing_contacts mc
                    JOIN marketing_segment_members msm ON msm.contact_id = mc.id
                    WHERE msm.segment_id = ? AND msm.action = 'include' AND mc.status = 'active'
                    ORDER BY lower(mc.email)";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$segmentId]);
            return $stmt->fetchAll();
        }

        $rules = json_decode((string)$segment['rules_json'], true);
        if (!is_array($rules)) $rules = [];
        $parts = [];
        $params = [];
        foreach ($rules as $rule) {
            if (!is_array($rule)) continue;
            [$sql, $ruleParams] = $this->ruleSql($rule);
            $parts[] = $sql;
            array_push($params, ...$ruleParams);
        }
        $joiner = $segment['match_type'] === 'any' ? ' OR ' : ' AND ';
        $where = $parts ? '(' . implode($joiner, $parts) . ')' : '1=1';

        $sql = "SELECT DISTINCT mc.* FROM marketing_contacts mc
                WHERE mc.status = 'active' AND $where
                  AND NOT EXISTS (
                    SELECT 1 FROM marketing_segment_members x
                    WHERE x.segment_id = ? AND x.contact_id = mc.id AND x.action = 'exclude'
                  )
                UNION
                SELECT mc.* FROM marketing_contacts mc
                JOIN marketing_segment_members x ON x.contact_id = mc.id
                WHERE x.segment_id = ? AND x.action = 'include' AND mc.status = 'active'
                ORDER BY email";
        $params[] = $segmentId;
        $params[] = $segmentId;
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function audience(int $segmentId): array
    {
        $included = [];
        $excluded = [];
        foreach ($this->contacts($segmentId) as $contact) {
            $reason = $this->exclusionReason($contact);
            if ($reason === null) $included[] = $contact;
            else $excluded[] = ['contact' => $contact, 'reason' => $reason];
        }
        return ['included' => $included, 'excluded' => $excluded];
    }

    private function exclusionReason(array $contact): ?string
    {
        if (!filter_var($contact['email'], FILTER_VALIDATE_EMAIL)) return 'Invalid email address';
        if (($contact['eligibility_basis'] ?? 'unknown') === 'unknown') return 'Eligibility not reviewed';
        if (!empty($contact['unsubscribed_at'])) return 'Unsubscribed';
        $stmt = $this->db->prepare('SELECT reason FROM marketing_suppressions WHERE email_norm = ? AND cleared_at IS NULL');
        $stmt->execute([$contact['email_norm']]);
        $reason = $stmt->fetchColumn();
        return $reason ? 'Suppressed: ' . str_replace('_', ' ', (string)$reason) : null;
    }

    private function ruleSql(array $rule): array
    {
        $field = (string)($rule['field'] ?? '');
        $operator = (string)($rule['operator'] ?? 'equals');
        $value = $rule['value'] ?? '';

        $direct = [
            'contact.name' => 'mc.name',
            'contact.email' => 'mc.email',
            'contact.company_name' => 'mc.company_name',
            'contact.status' => 'mc.status',
            'contact.eligibility_basis' => 'mc.eligibility_basis',
        ];
        if (isset($direct[$field])) return $this->comparison($direct[$field], $operator, $value);

        if (str_starts_with($field, 'client.')) {
            $columns = ['client.status' => 'c.status', 'client.client_type' => 'c.client_type', 'client.name' => 'c.name'];
            if (!isset($columns[$field])) throw new RuntimeException("Unsupported segment field: $field");
            [$cmp, $params] = $this->comparison($columns[$field], $operator, $value);
            return ["EXISTS (SELECT 1 FROM marketing_contact_clients mcc JOIN clients c ON c.id=mcc.client_id WHERE mcc.contact_id=mc.id AND $cmp)", $params];
        }

        if (str_starts_with($field, 'site.')) {
            $columns = [
                'site.status' => 'cs.status', 'site.website_stack' => 'cs.website_stack',
                'site.css_framework' => 'cs.css_framework', 'site.smtp_service' => 'cs.smtp_service',
                'site.server' => 's.name',
            ];
            if (!isset($columns[$field])) throw new RuntimeException("Unsupported segment field: $field");
            [$cmp, $params] = $this->comparison($columns[$field], $operator, $value);
            return ["EXISTS (SELECT 1 FROM marketing_contact_clients mcc JOIN client_sites cs ON cs.client_id=mcc.client_id LEFT JOIN servers s ON s.id=cs.server_id WHERE mcc.contact_id=mc.id AND $cmp)", $params];
        }

        if (str_starts_with($field, 'domain.')) {
            if ($field === 'domain.cloudflare') {
                $exists = "EXISTS (SELECT 1 FROM marketing_contact_clients mcc JOIN domains d ON d.client_id=mcc.client_id JOIN cloudflare_zones cz ON cz.domain_id=d.id WHERE mcc.contact_id=mc.id)";
                return [$this->booleanSql($exists, $operator, $value), []];
            }
            if ($field !== 'domain.registrar') throw new RuntimeException("Unsupported segment field: $field");
            [$cmp, $params] = $this->comparison('d.registrar', $operator, $value);
            return ["EXISTS (SELECT 1 FROM marketing_contact_clients mcc JOIN domains d ON d.client_id=mcc.client_id WHERE mcc.contact_id=mc.id AND $cmp)", $params];
        }

        if (str_starts_with($field, 'agreement.')) {
            $columns = [
                'agreement.type' => 'a.agreement_type', 'agreement.status' => 'a.status',
                'agreement.covers_hosting' => 'a.covers_hosting',
                'agreement.covers_support' => 'a.covers_support',
                'agreement.covers_maintenance' => 'a.covers_maintenance',
            ];
            if (!isset($columns[$field])) throw new RuntimeException("Unsupported segment field: $field");
            [$cmp, $params] = $this->comparison($columns[$field], $operator, $value);
            return ["EXISTS (SELECT 1 FROM marketing_contact_clients mcc JOIN agreements a ON a.client_id=mcc.client_id WHERE mcc.contact_id=mc.id AND $cmp)", $params];
        }

        throw new RuntimeException("Unsupported segment field: $field");
    }

    private function comparison(string $column, string $operator, mixed $value): array
    {
        return match ($operator) {
            'equals' => ["lower(COALESCE($column,'')) = lower(?)", [(string)$value]],
            'not_equals' => ["lower(COALESCE($column,'')) <> lower(?)", [(string)$value]],
            'contains' => ["lower(COALESCE($column,'')) LIKE lower(?) ESCAPE '\\'", ['%' . $this->escapeLike((string)$value) . '%']],
            'not_contains' => ["lower(COALESCE($column,'')) NOT LIKE lower(?) ESCAPE '\\'", ['%' . $this->escapeLike((string)$value) . '%']],
            'is_empty' => ["trim(COALESCE($column,'')) = ''", []],
            'is_not_empty' => ["trim(COALESCE($column,'')) <> ''", []],
            'is_true' => ["COALESCE($column,0) = 1", []],
            'is_false' => ["COALESCE($column,0) = 0", []],
            default => throw new RuntimeException("Unsupported segment operator: $operator"),
        };
    }

    private function booleanSql(string $exists, string $operator, mixed $value): string
    {
        $want = in_array($operator, ['is_true', 'equals'], true)
            && !in_array(strtolower((string)$value), ['0', 'false', 'no'], true);
        return $want ? $exists : "NOT $exists";
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}

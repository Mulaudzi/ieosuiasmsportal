<?php
/** Read-only calculations used only by the Campaign Comparison page. */
final class CampaignComparisonService
{
    public static function ids(string $raw): array
    {
        $ids = [];
        foreach (explode(',', $raw) as $part) {
            if (!preg_match('/^[1-9][0-9]*$/D', $part)) {
                throw new InvalidArgumentException('Invalid campaign selection');
            }
            $id = filter_var($part, FILTER_VALIDATE_INT);
            if ($id === false) {
                throw new InvalidArgumentException('Invalid campaign selection');
            }
            $ids[] = $id;
        }
        if (count($ids) < 2 || count($ids) > 5 || count(array_unique($ids)) !== count($ids)) {
            throw new InvalidArgumentException('Select 2 to 5 different campaigns');
        }
        return $ids;
    }

    public static function campaigns(PDO $pdo, int $userId, ?array $ids = null): array
    {
        $args = [$userId];
        $where = 'c.user_id = ?';
        if ($ids !== null) {
            $where .= ' AND c.id IN ('.implode(',', array_fill(0, count($ids), '?')).')';
            $args = array_merge($args, $ids);
        }
        // A later failed delivery is still a sent message. Unsent failures and
        // skipped/queued recipients must not inflate this comparison's denominator.
        $sent = "m.state <> 'skipped' AND (m.sent_at IS NOT NULL OR m.state IN ('sent','delivered'))";
        $sql = "SELECT c.id, c.name, 'sms' type, c.created_at,
            COUNT(CASE WHEN $sent THEN 1 END) sent_count,
            COUNT(CASE WHEN m.state = 'delivered' THEN 1 END) delivered_count,
            COUNT(CASE WHEN m.state = 'failed' AND m.sent_at IS NOT NULL THEN 1 END) failed_count,
            COALESCE(SUM(m.actual_charge), 0) actual_cost,
            AVG(CASE WHEN m.state = 'delivered' AND m.sent_at IS NOT NULL AND m.delivered_at >= m.sent_at
                THEN TIMESTAMPDIFF(SECOND, m.sent_at, m.delivered_at) END) avg_delivery_time_seconds
            FROM sms_campaigns c
            LEFT JOIN sms_messages m ON m.campaign_id = c.id AND m.user_id = c.user_id
            WHERE $where
            GROUP BY c.id, c.name, c.created_at
            ORDER BY c.created_at DESC, c.id DESC";
        $query = $pdo->prepare($sql);
        $query->execute($args);
        $campaigns = $query->fetchAll(PDO::FETCH_ASSOC);
        if ($ids !== null && count($campaigns) !== count($ids)) {
            throw new OutOfBoundsException('Could not find all selected campaigns');
        }
        foreach ($campaigns as &$campaign) {
            $campaign['id'] = (string)$campaign['id'];
            foreach (['sent_count', 'delivered_count', 'failed_count'] as $key) {
                $campaign[$key] = (int)$campaign[$key];
            }
            $campaign['pending_count'] = max(0, $campaign['sent_count'] - $campaign['delivered_count'] - $campaign['failed_count']);
            $campaign['actual_cost'] = (float)$campaign['actual_cost'];
            $campaign['delivery_rate'] = $campaign['sent_count'] > 0
                ? round(100 * $campaign['delivered_count'] / $campaign['sent_count'], 1) : null;
            $campaign['avg_delivery_time_seconds'] = $campaign['avg_delivery_time_seconds'] === null
                ? null : (float)$campaign['avg_delivery_time_seconds'];
            $campaign['cost_per_delivery'] = $campaign['delivered_count'] > 0
                ? $campaign['actual_cost'] / $campaign['delivered_count'] : null;
        }
        unset($campaign);
        if ($ids !== null) {
            $order = array_flip(array_map('strval', $ids));
            usort($campaigns, fn($a, $b) => $order[$a['id']] <=> $order[$b['id']]);
        }
        return $campaigns;
    }
}

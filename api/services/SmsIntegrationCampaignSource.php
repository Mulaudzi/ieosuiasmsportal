<?php
declare(strict_types=1);
require_once __DIR__.'/../domain/SmsIntegrationPresentation.php';

/** Owner-only portal attribution; no keys, product internals or provider data. */
final class SmsIntegrationCampaignSource
{
    public static function attach(PDO $pdo, int $user, array $campaigns): array
    {
        if (!$campaigns) return [];
        $available = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name IN ('sms_integration_requests','sms_integrations')")->fetchColumn()
            : (int)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('sms_integration_requests','sms_integrations')")->fetchColumn();
        if ($available !== 2) return $campaigns; // Compatible with pre-API schemas.
        $ids = array_column($campaigns, 'id');
        $query = $pdo->prepare('SELECT r.campaign_id,r.id,r.external_reference,a.name FROM sms_integration_requests r JOIN sms_integrations a ON a.id=r.integration_id AND a.user_id=r.user_id JOIN sms_campaigns c ON c.id=r.campaign_id AND c.user_id=r.user_id WHERE r.user_id=? AND r.campaign_id IN ('.implode(',', array_fill(0, count($ids), '?')).')');
        $query->execute([$user, ...$ids]); $sources = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) $sources[$row['campaign_id']] = ['type' => 'api', 'connection_name' => $row['name'], 'reference' => SmsIntegrationPresentation::reference($row['id']), 'external_reference' => $row['external_reference']];
        foreach ($campaigns as &$campaign) {
            unset($campaign['source']);
            if ((int)$campaign['user_id'] === $user && isset($sources[$campaign['id']])) $campaign['source'] = $sources[$campaign['id']];
        } unset($campaign);
        return $campaigns;
    }
}

<?php
/**
 * A/B Test Winner Selection Cron
 * 
 * Automatically selects winning variants for completed A/B test campaigns
 * based on delivery rates. Should be run every 30 minutes.
 * 
 * Usage: php api/cron/process_ab_test_winners.php
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/QueryBuilder.php';

echo "[" . date('Y-m-d H:i:s') . "] Starting A/B test winner selection...\n";

// Find completed A/B test campaigns without a winner
$campaigns = table('campaigns')
    ->where('is_ab_test', 1)
    ->where('status', 'Sent')
    ->whereNull('ab_winner_variant')
    ->get();

echo "Found " . count($campaigns) . " campaigns to process.\n";

foreach ($campaigns as $campaign) {
    $campaignId = $campaign['id'];
    echo "\nProcessing campaign ID: {$campaignId} ({$campaign['name']})...\n";
    
    // Get variants with their stats
    $variants = table('campaign_variants')
        ->where('campaign_id', $campaignId)
        ->get();
    
    if (count($variants) < 2) {
        echo "  Skipping - insufficient variants.\n";
        continue;
    }
    
    // Calculate delivery stats for each variant
    foreach ($variants as &$variant) {
        $variantName = $variant['variant_name'];
        
        $stats = table('messages')
            ->where('campaign_id', $campaignId)
            ->where('variant_name', $variantName)
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN status IN ("Sent", "Awaiting DLR", "Delivered") THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status = "Delivered" THEN 1 ELSE 0 END) as delivered,
                SUM(CASE WHEN status = "Failed" THEN 1 ELSE 0 END) as failed
            ')
            ->first();
        
        $total = (int) ($stats['total'] ?? 0);
        $delivered = (int) ($stats['delivered'] ?? 0);
        $failed = (int) ($stats['failed'] ?? 0);
        $sent = (int) ($stats['sent'] ?? 0);
        
        $deliveryRate = $sent > 0 ? round(($delivered / $sent) * 100, 2) : 0;
        
        // Update variant stats
        table('campaign_variants')
            ->where('id', $variant['id'])
            ->update([
                'recipient_count' => $total,
                'sent_count' => $sent,
                'delivered_count' => $delivered,
                'failed_count' => $failed,
                'delivery_rate' => $deliveryRate,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        
        $variant['delivery_rate'] = $deliveryRate;
        $variant['delivered_count'] = $delivered;
        $variant['sent_count'] = $sent;
        
        echo "  Variant {$variantName}: {$deliveryRate}% delivery rate ({$delivered}/{$sent} delivered)\n";
    }
    
    // Determine winner based on delivery rate
    usort($variants, fn($a, $b) => $b['delivery_rate'] <=> $a['delivery_rate']);
    $winner = $variants[0];
    $winnerName = $winner['variant_name'];
    
    // Only select winner if there's a meaningful difference (> 2%)
    $rateDiff = abs($variants[0]['delivery_rate'] - $variants[1]['delivery_rate']);
    
    if ($rateDiff < 2 && $variants[0]['sent_count'] < 50) {
        echo "  Skipping winner selection - difference too small ({$rateDiff}%) with low sample size.\n";
        continue;
    }
    
    // Update campaign with winner
    table('campaigns')
        ->where('id', $campaignId)
        ->update([
            'ab_winner_variant' => $winnerName,
            'ab_winner_selected_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    
    // Mark winner variant
    table('campaign_variants')
        ->where('campaign_id', $campaignId)
        ->where('variant_name', $winnerName)
        ->update([
            'is_winner' => 1,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    
    // Store winning message content for future use
    $winningVariant = table('campaign_variants')
        ->where('campaign_id', $campaignId)
        ->where('variant_name', $winnerName)
        ->first();
    
    if ($winningVariant) {
        table('campaigns')
            ->where('id', $campaignId)
            ->update([
                'message' => $winningVariant['message_content'],
                'subject' => $winningVariant['subject'] ?? $campaign['subject'],
            ]);
    }
    
    echo "  Winner: Variant {$winnerName} with {$winner['delivery_rate']}% delivery rate!\n";
    
    // Create notification for user
    table('notifications')->insert([
        'user_id' => $campaign['user_id'],
        'type' => 'ab_test_winner',
        'title' => 'A/B Test Winner Selected',
        'message' => "Variant {$winnerName} won the A/B test for campaign \"{$campaign['name']}\" with a {$winner['delivery_rate']}% delivery rate.",
        'data' => json_encode([
            'campaign_id' => $campaignId,
            'winner_variant' => $winnerName,
            'delivery_rate' => $winner['delivery_rate'],
        ]),
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}

echo "\n[" . date('Y-m-d H:i:s') . "] A/B test winner selection complete.\n";

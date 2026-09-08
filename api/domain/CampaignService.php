<?php

require_once __DIR__ . '/RecipientResolver.php';
require_once __DIR__ . '/SmsSegmentCalculator.php';
require_once __DIR__ . '/SmsPricing.php';
require_once __DIR__ . '/WalletService.php';
require_once __DIR__ . '/TemplatePersonalizer.php';

final class CampaignService
{
    public static function preview(int $userId, array $input): array
    {
        $content = trim((string) ($input['content'] ?? ''));
        if ($content === '') throw new InvalidArgumentException('Message content is required');
        TemplatePersonalizer::assertSupported($content);
        $analysis = SmsSegmentCalculator::analyze($content);
        $maxSegments = (int) env('SMS_MAX_SEGMENTS_PER_MESSAGE', 6);
        if ($analysis['segment_count'] < 1 || $analysis['segment_count'] > $maxSegments) {
            throw new InvalidArgumentException("Message must contain between 1 and {$maxSegments} SMS segments");
        }
        $resolved = RecipientResolver::resolve($userId, (array) ($input['recipients'] ?? []));
        $maxRecipients = (int) env('SMS_MAX_RECIPIENTS_PER_CAMPAIGN', 10000);
        if ($resolved['sendable_count'] > $maxRecipients) throw new InvalidArgumentException('Campaign recipient limit exceeded');
        $price = SmsPricing::pricePerSegment();
        $profiles=self::recipientProfiles($userId,$resolved['recipients']);$totalSegments=0;$hasUnicode=false;$maximumSegments=0;
        foreach($resolved['recipients'] as $destination){$rendered=TemplatePersonalizer::render($content,$profiles[$destination]??['phone'=>$destination]);$recipientAnalysis=SmsSegmentCalculator::analyze($rendered);if($recipientAnalysis['segment_count']>$maxSegments)throw new InvalidArgumentException("Personalized message for {$destination} exceeds {$maxSegments} SMS segments");$totalSegments+=(int)$recipientAnalysis['segment_count'];$maximumSegments=max($maximumSegments,(int)$recipientAnalysis['segment_count']);$hasUnicode=$hasUnicode||$recipientAnalysis['encoding']==='ucs2';}
        $estimated=round($totalSegments*SmsPricing::pricePerSegment(),4);
        $wallet = table('wallets')->where('user_id', $userId)->first();
        $balance = (float) ($wallet['balance'] ?? 0);
        $available = round($balance - (float) ($wallet['reserved'] ?? 0), 4);
        return array_merge($resolved, $analysis, [
            'encoding' => $hasUnicode?'ucs2':$analysis['encoding'],
            'segment_count' => max(1,$maximumSegments?:$analysis['segment_count']),
            'total_segments' => $totalSegments,
            'required_credits' => $totalSegments,
            'price_per_segment' => $price,
            'estimated_charge' => $estimated,
            'wallet_balance' => $balance,
            'available_balance' => $available,
            'available_credits' => max(0, (int) floor(($available + 0.000001) / $price)),
            'sufficient_balance' => $available >= $estimated,
        ]);
    }

    public static function create(int $userId, array $input): array
    {
        $idempotency = trim((string) ($input['idempotency_key'] ?? ''));
        if ($idempotency === '') throw new InvalidArgumentException('idempotency_key is required');
        if (strlen($idempotency)>80) throw new InvalidArgumentException('idempotency_key must not exceed 80 characters');
        $existing=table('sms_campaigns')->where('user_id',$userId)->where('idempotency_key',$idempotency)->first();
        if($existing)return $existing;

        $preview = self::preview($userId, $input);
        if ($preview['sendable_count'] < 1) throw new InvalidArgumentException('No sendable recipients');
        if (!$preview['sufficient_balance']) throw new DomainException('Insufficient available balance');
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || strlen($name) > 100) throw new InvalidArgumentException('Campaign name is required and must not exceed 100 characters');
        $schedule = null;
        if (!empty($input['scheduled_at'])) {
            $timestamp = strtotime((string) $input['scheduled_at']);
            if ($timestamp === false || $timestamp <= time()) throw new InvalidArgumentException('scheduled_at must be a valid future date');
            $schedule = date('Y-m-d H:i:s', $timestamp);
        }
        $sendNow=!empty($input['send_now']);
        $state = $sendNow ? 'queued' : ($schedule ? 'scheduled' : 'draft');
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO sms_campaigns (user_id,idempotency_key,name,content,sender_id,state,encoding,segments_per_message,recipient_count,queued_count,total_segments,price_per_segment,estimated_cost,scheduled_at,queued_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,IF(?=1,NOW(),NULL),NOW(),NOW())');
            $stmt->execute([$userId,$idempotency,$name,$input['content'],$input['sender_id'] ?? env('SMS_DEFAULT_SENDER','IEOSUIA'),$state,$preview['encoding'],$preview['segment_count'],$preview['sendable_count'],$sendNow?$preview['sendable_count']:0,$preview['total_segments'],$preview['price_per_segment'],$preview['estimated_charge'],$schedule,$sendNow?1:0]);
            $campaignId = (int) $pdo->lastInsertId();
            $message = $pdo->prepare('INSERT INTO sms_messages (campaign_id,user_id,destination,content,encoding,segment_count,estimated_charge,state,created_at,updated_at) VALUES (?,?,?,?,?,?,?,\'pending\',NOW(),NOW())');
            $profiles=self::recipientProfiles($userId,$preview['recipients']);
            foreach ($preview['recipients'] as $destination) {
                $personalized=TemplatePersonalizer::render((string)$input['content'],$profiles[$destination]??['phone'=>$destination]);$recipientAnalysis=SmsSegmentCalculator::analyze($personalized);$charge=round($recipientAnalysis['segment_count']*$preview['price_per_segment'],4);
                $message->execute([$campaignId,$userId,$destination,$personalized,$recipientAnalysis['encoding'],$recipientAnalysis['segment_count'],$charge]);
            }
            WalletService::reserve($pdo, $userId, $campaignId, $preview['estimated_charge']);
            $pdo->commit();
            return self::findOwned($userId, $campaignId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e instanceof PDOException && $e->getCode() === '23000') {
                $existing = table('sms_campaigns')->where('user_id',$userId)->where('idempotency_key',$idempotency)->first();
                if ($existing) return $existing;
            }
            throw $e;
        }
    }

    public static function queue(int $userId, int $campaignId): array
    {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM sms_campaigns WHERE id=? AND user_id=? FOR UPDATE');
            $stmt->execute([$campaignId,$userId]);
            $campaign = $stmt->fetch();
            if (!$campaign) throw new RuntimeException('Campaign not found');
            if ($campaign['state'] === 'queued' || $campaign['state'] === 'processing') { $pdo->commit(); return $campaign; }
            if ($campaign['state'] !== 'draft') throw new DomainException('Only draft campaigns can be queued immediately');
            $pdo->prepare("UPDATE sms_campaigns SET state='queued',queued_count=recipient_count,queued_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$campaignId]);
            $pdo->commit();
            return self::findOwned($userId, $campaignId);
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    public static function findOwned(int $userId, int $campaignId): array
    {
        $campaign = table('sms_campaigns')->where('id',$campaignId)->where('user_id',$userId)->first();
        if (!$campaign) throw new RuntimeException('Campaign not found');
        return $campaign;
    }

    private static function recipientProfiles(int $userId,array $destinations):array
    {
        if(!$destinations)return [];$marks=implode(',',array_fill(0,count($destinations),'?'));$stmt=db()->prepare("SELECT name,surname,email,phone_normalized FROM contacts WHERE user_id=? AND phone_normalized IN ({$marks})");$stmt->execute(array_merge([$userId],$destinations));$profiles=[];foreach($stmt->fetchAll() as $contact){$phone=(string)$contact['phone_normalized'];$contact['phone']=$phone;$profiles[$phone]=$contact;}return $profiles;
    }
}

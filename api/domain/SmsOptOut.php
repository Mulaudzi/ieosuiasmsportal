<?php
final class SmsOptOut
{
    public const DEFAULT_TEXT = 'Reply STOP to unsubscribe';
    public static function text(int $userId): string
    {
        $row=table('sms_preferences')->where('user_id',$userId)->first();
        return (string)($row['opt_out_text']??self::DEFAULT_TEXT);
    }
    public static function compose(string $content, bool $enabled, string $footer): string
    {
        $content=trim($content);$footer=trim($footer);
        if($content==='')throw new InvalidArgumentException('Message content is required');
        if(!$enabled)return $content;
        if($footer==='')throw new InvalidArgumentException('Save opt-out text in Settings before enabling it');
        if(str_ends_with($content,$footer))return $content;
        return $content."\n".$footer;
    }
}

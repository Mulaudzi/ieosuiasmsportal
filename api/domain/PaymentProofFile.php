<?php

final class PaymentProofFile
{
    public static function mime(string $bytes): ?string
    {
        if (str_starts_with($bytes,'%PDF-') && str_contains(substr($bytes,-2048),'%%EOF')) return 'application/pdf';
        if (!str_starts_with($bytes,"\x89PNG\r\n\x1a\n") && !str_starts_with($bytes,"\xff\xd8\xff")) return null;
        try { $image=@getimagesizefromstring($bytes); } catch (Throwable) { return null; }
        if (!$image) return null;
        return match($image[2]??null){IMAGETYPE_PNG=>'image/png',IMAGETYPE_JPEG=>'image/jpeg',default=>null};
    }
}

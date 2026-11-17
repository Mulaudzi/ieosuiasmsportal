<?php
require_once __DIR__.'/ContactPhoneReview.php';

/** Version 2 import policy. Legacy version 1 approval semantics stay unchanged. */
final class ContactImportReview
{
    public static function value(string $value): string
    {
        $value=preg_replace('/[\x{200B}-\x{200F}\x{2060}\x{FEFF}]/u','',$value);
        $value=preg_replace('/^\s+|\s+$/u','',$value);
        return preg_match('/^(?:n\/?a|n\.a\.|none|null|nil|unknown|not known|not available|not provided|no phone|no number|no email|tbc|tba|[-–—])$/iuD',$value)?'':$value;
    }

    private static function cell(string $value): string
    {
        // ECMAScript trim, matching the browser's source-cell identity exactly.
        return preg_replace('/^[\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+|[\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+$/u','',$value);
    }

    public static function emailValid(string $value): bool
    {
        if(strlen($value)>254||substr_count($value,'@')!==1)return false;
        [$local,$domain]=explode('@',$value);
        if($local===''||strlen($local)>64||str_starts_with($local,'.')||str_ends_with($local,'.')||str_contains($local,'..')
            ||!preg_match('/^[A-Za-z0-9.!#$%&\x27*+\/=?^_`{|}~-]+$/D',$local)||strlen($domain)>253)return false;
        $labels=explode('.',$domain);if(count($labels)<2)return false;
        foreach($labels as $label)if(strlen($label)>63||!preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?$/D',$label))return false;
        return true;
    }

    public static function qualify(string $input): array
    {
        $value=self::value($input);
        $failure=static fn(string $reason):array=>['normalized'=>null,'proposed'=>'','reason'=>$reason,'automatic'=>false];
        if(preg_match_all('/./us',$value)>200)return $failure('This cell is too long to be one phone number. Enter one complete mobile number.');
        $map=[];
        foreach(['０１２３４５６７８９','٠١٢٣٤٥٦٧٨٩','۰۱۲۳۴۵۶۷۸۹'] as $alphabet)foreach(preg_split('//u',$alphabet,-1,PREG_SPLIT_NO_EMPTY) as $index=>$digit)$map[$digit]=(string)$index;
        $value=strtr($value,$map+['＋'=>'+','‐'=>'-','‑'=>'-','‒'=>'-','–'=>'-','—'=>'-','−'=>'-']);
        $value=preg_replace('/^[\x27’]/u','',$value);
        $value=preg_replace('/^(?:tel(?:ephone)?|phone|mobile|cell(?:phone)?|contact|whatsapp)\s*:\s*/iu','',$value);
        if(preg_match('/^="([+0-9\s().-]+)"$/uD',$value,$literal))$value=$literal[1];
        if(str_starts_with($value,'-'))return $failure('Negative values are not phone numbers. Enter the verified full number.');
        if(preg_match('/^([0-9]+)\.([0-9]+)[eE]\+?([0-9]{1,2})$/D',$value,$scientific)){
            $digits=$scientific[1].$scientific[2];$places=(int)$scientific[3]-strlen($scientific[2]);
            if(strlen($digits)<9||$places<0||strlen($digits)+$places>15)return $failure('Scientific notation may have lost digits. Enter the verified full number.');
            $value=$digits.str_repeat('0',$places);
        }elseif(preg_match('/^[+0-9.]+[eE][+-]?[0-9]*$/D',$value))return $failure('Scientific notation may have lost digits. Enter the verified full number.');
        if(preg_match('/^\+?[0-9]{9,13}\.0+$/D',$value))$value=preg_replace('/\.0+$/D','',$value);
        $compact=preg_replace('/[\s().-]/u','',$value);
        if(preg_match('/^00270[678][0-9]{8}$/D',$compact))$value='0'.substr($compact,5);
        $analysis=ContactPhoneReview::analyze($value);
        if($analysis['normalized']!==null)return $analysis+['automatic'=>$value!==$input];
        if($analysis['proposed']!==''){
            $repaired=ContactPhoneReview::analyze($analysis['proposed'])['normalized'];
            if($repaired!==null){$analysis['normalized']=$repaired;return $analysis+['automatic'=>true];}
        }
        return $analysis+['automatic'=>false];
    }

    public static function review(array $contact,?array $decision=null): array
    {
        $original=$contact['phone']??'';$originalEmail=$contact['email']??'';
        $rawPhone=self::value($original);$rawEmail=self::value($originalEmail);
        if($rawEmail===''&&self::emailValid($rawPhone)){$rawEmail=$rawPhone;$rawPhone='';}
        if($rawPhone!==''&&!preg_match('/\p{Nd}/u',$rawPhone))$rawPhone='';
        $result=static fn(string $status,?string $phone,string $email,bool $automatic=false,string $reason=''):array=>['status'=>$status,'phone'=>$phone,'email'=>$email,'emailOnly'=>$status==='ready'&&$phone===null&&$email!=='','automatic'=>$automatic,'reason'=>$reason];
        if($rawPhone===''&&$rawEmail==='')return $result('skipped',null,'',false,'No phone number or email.');
        $analysis=self::qualify($original);$emailValid=$rawEmail===''||self::emailValid($rawEmail);
        if($emailValid&&($analysis['normalized']!==null||($rawPhone===''&&$rawEmail!=='')))return $result('ready',$analysis['normalized'],$rawEmail,$analysis['automatic']);
        $current=($decision['original']??null)===$original&&($decision['originalEmail']??null)===$originalEmail?$decision:null;
        if(($current['action']??null)==='reject')return $result('rejected',null,$rawEmail);
        if($current){
            $email=self::value($current['email']??$originalEmail);$phone=self::qualify($current['value']??$original);
            if($current['action']==='email_only'&&$email!==''&&self::emailValid($email))return $result('ready',null,$email);
            if($current['action']==='accept'&&($email===''||self::emailValid($email))&&($phone['normalized']!==null||(self::value($current['value']??$original)===''&&$email!=='')))return $result('ready',$phone['normalized'],$email);
        }
        $reasons=[];if(!$emailValid)$reasons[]='Check or remove the email address.';
        if($analysis['normalized']===null&&$rawPhone!=='')$reasons[]=$analysis['reason'];
        elseif($rawPhone===''&&!$emailValid)$reasons[]='Add a complete phone number or correct the email.';
        return $result('review',$analysis['normalized'],$rawEmail,false,implode(' ',$reasons));
    }

    /** Entire CSV is qualified before the controller can write a single contact. */
    public static function prepare(array $header,array $rows,array $mapping,mixed $decisions): array
    {
        if(count($rows)>10000||!array_is_list($rows))throw new InvalidArgumentException('Import at most 10,000 contact rows.');
        $columns=[];
        foreach(['phone','email'] as $field){
            $name=$mapping[$field]??'';if(!is_string($name))throw new InvalidArgumentException('Invalid contact column mapping.');
            $columns[$field]=$name!==''?array_search(strtolower(trim($name)),$header,true):false;
            if($name!==''&&$columns[$field]===false)throw new InvalidArgumentException('Mapped contact column was not found.');
        }
        if($columns['phone']===false&&$columns['email']===false)throw new InvalidArgumentException('Choose a phone or email column.');
        if($columns['phone']!==false&&$columns['phone']===$columns['email'])throw new InvalidArgumentException('Phone and email must use different columns.');
        if(!is_array($decisions)||!array_is_list($decisions)||count($decisions)>10000)throw new InvalidArgumentException('Invalid contact review decisions.');
        $byRow=[];
        foreach($decisions as $decision){
            if(!is_array($decision)||!is_int($decision['row']??null)||$decision['row']<2||$decision['row']>count($rows)+1||isset($byRow[$decision['row']])
                ||!in_array($decision['action']??null,['accept','reject','email_only'],true)||!is_string($decision['original']??null)||!is_string($decision['originalEmail']??null)
                ||(isset($decision['value'])&&!is_string($decision['value']))||(isset($decision['email'])&&!is_string($decision['email'])))throw new InvalidArgumentException('Invalid or repeated contact review decision.');
            $byRow[$decision['row']]=$decision;
        }
        $prepared=[];
        foreach($rows as $index=>$row){
            $number=$index+2;if(count($row)!==count($header))throw new InvalidArgumentException("Row $number does not match the column headings.");
            $contact=['phone'=>$columns['phone']===false?'':self::cell((string)$row[$columns['phone']]),'email'=>$columns['email']===false?'':self::cell((string)$row[$columns['email']])];
            $base=self::review($contact);$decision=$byRow[$number]??null;
            if($decision!==null&&($base['status']!=='review'||$decision['original']!==$contact['phone']||$decision['originalEmail']!==$contact['email']))throw new InvalidArgumentException("Row $number changed since its review. Review it again.");
            $review=self::review($contact,$decision);
            if($review['status']==='review')throw new InvalidArgumentException("Check the contact details on row $number before importing.");
            $prepared[$number]=$review+['original'=>$contact['phone'],'corrected'=>$review['automatic']||($decision!==null&&$review['status']==='ready')];
        }
        return $prepared;
    }
}

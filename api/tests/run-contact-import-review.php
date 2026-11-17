<?php
// Pure CLI fixture: no bootstrap, environment, database or production access.
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../domain/ContactImportReview.php';
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$output=[];
foreach($input as $request){
    try{
        $output[]=match($request['action']){
            'qualify'=>array_map([ContactImportReview::class,'qualify'],$request['values']),
            'review'=>array_map(static fn(array $row):array=>ContactImportReview::review($row['contact'],$row['decision']??null),$request['values']),
            'email'=>array_map([ContactImportReview::class,'emailValid'],$request['values']),
            'prepare'=>ContactImportReview::prepare($request['headers'],$request['rows'],$request['mapping'],$request['decisions']),
            default=>throw new InvalidArgumentException('Unknown test operation'),
        };
    }catch(InvalidArgumentException $error){$output[]=['error'=>$error->getMessage()];}
}
echo json_encode($output,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);

<?php
require_once __DIR__ . '/../domain/ContactPhoneReview.php';
if (PHP_SAPI !== 'cli') { exit(1); }
// Optional JSON input lets the frontend test compare the exact server rules.
if (($argv[1] ?? '') === '--analyze') {
    $inputs = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    echo json_encode(array_map([ContactPhoneReview::class, 'analyze'], $inputs), JSON_THROW_ON_ERROR); exit;
}
if (($argv[1] ?? '') === '--prepare') {
    $input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
    echo json_encode(ContactPhoneReview::prepare($input['headers'],$input['rows'],$input['phoneColumn'],$input['decisions']),JSON_THROW_ON_ERROR);exit;
}
$checks = 0;
function checkReview(bool $value, string $message): void {
    global $checks;
    if (!$value) throw new RuntimeException($message);
    $checks++; echo "PASS $message\n";
}
$rows = [['0821234567'], ['821234567'], ['invalid'], ['270821234568']];
$choices = [
    ['row'=>3,'original'=>'821234567','action'=>'accept','value'=>'0821234567'],
    ['row'=>4,'original'=>'invalid','action'=>'reject'],
    ['row'=>5,'original'=>'270821234568','action'=>'accept','value'=>'+27821234568'],
];
$result = ContactPhoneReview::prepare(['phone'], $rows, 'Phone', $choices);
checkReview($result[2]['phone'] === '+27821234567' && !$result[2]['corrected'], 'Valid local format is canonicalized');
checkReview($result[3]['phone'] === '+27821234567' && $result[3]['corrected'], 'Only an approved missing zero is corrected');
checkReview($result[4] === null, 'Rejected row is excluded');
checkReview($result[5]['phone'] === '+27821234568', 'User can approve a complete international format');
foreach ([[], array_slice($choices, 0, 2), [...$choices,$choices[0]], [['row'=>99,'original'=>'x','action'=>'reject']],
    [array_replace($choices[0],['value'=>'821234567']),$choices[1],$choices[2]],
    [array_replace($choices[0],['original'=>'different']),$choices[1],$choices[2]],
    [array_replace($choices[0],['action'=>'guess']),$choices[1],$choices[2]],
    [array_replace($choices[0],['row'=>'3']),$choices[1],$choices[2]],
    [...$choices,['row'=>2,'original'=>'0821234567','action'=>'reject']], null, ['not'=>'a list']
] as $invalid) {
    try { ContactPhoneReview::prepare(['phone'], $rows, 'phone', $invalid); throw new RuntimeException('Invalid choices were accepted'); }
    catch (InvalidArgumentException) { checkReview(true, 'Malformed, missing or stale choices fail closed'); }
}
checkReview(ContactPhoneReview::analyze('07812345')['proposed'] === '', 'Missing subscriber digits are never guessed');
checkReview(ContactPhoneReview::analyze('+447700900123')['proposed'] === '', 'Foreign country codes are never rewritten as South African');
foreach(['+27','27','0027','2771234567','+2771234567','002771234567'] as $incomplete) {
    $analysis=ContactPhoneReview::analyze($incomplete);
    checkReview($analysis['normalized']===null&&$analysis['proposed']===''&&str_contains($analysis['reason'],'digit(s) are missing'),'Incomplete country-code number needs verified missing digits, never padding');
}
try {ContactPhoneReview::prepare(['phone'],[['2771234567']],'phone',[]);throw new RuntimeException('Incomplete country code imported');}catch(InvalidArgumentException){checkReview(true,'Incomplete country-code row blocks import before writes');}
try {ContactPhoneReview::prepare(['phone'],[['2771234567']],'phone',[['row'=>2,'action'=>'accept','original'=>'2771234567','value'=>'2771234567']]);throw new RuntimeException('Incomplete correction imported');}catch(InvalidArgumentException){checkReview(true,'Accept cannot bypass complete-number validation');}
echo "$checks phone review checks passed. No database access.\n";

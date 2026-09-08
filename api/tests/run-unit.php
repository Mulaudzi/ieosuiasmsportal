<?php

putenv('APP_ENV=testing');
putenv('SMS_PRICE_PER_SEGMENT=0.35');
function env(string $key, mixed $default=null): mixed { $value=getenv($key); return $value===false?$default:$value; }
final class EmptyTestQuery {
    public function where(string $column, mixed $value): self { return $this; }
    public function whereIn(string $column, array $values): self { return $this; }
    public function get(): array { return []; }
    public function first(): ?array { return null; }
}
function table(string $name): EmptyTestQuery { return new EmptyTestQuery(); }
require_once __DIR__.'/../domain/PhoneNumber.php';
require_once __DIR__.'/../domain/SmsSegmentCalculator.php';
require_once __DIR__.'/../domain/SmsPricing.php';
require_once __DIR__.'/../domain/RecipientResolver.php';
require_once __DIR__.'/../domain/TemplatePersonalizer.php';
require_once __DIR__.'/../domain/PaymentState.php';
require_once __DIR__.'/../services/PayOSService.php';

$tests=[];
function test(string $name, callable $fn): void { global $tests; $tests[] = [$name,$fn]; }
function same(mixed $expected,mixed $actual):void{if($expected!==$actual)throw new RuntimeException('expected '.json_encode($expected).', got '.json_encode($actual));}

test('SA local normalizes',fn()=>same('+27821234567',PhoneNumber::normalize('082 123 4567')));
test('SA country code normalizes',fn()=>same('+27821234567',PhoneNumber::normalize('27821234567')));
test('international E164 accepted',fn()=>same('+447911123456',PhoneNumber::normalize('+44 7911 123456')));
test('ambiguous number rejected',function(){try{PhoneNumber::normalize('7911123456');throw new RuntimeException('not rejected');}catch(InvalidArgumentException){}});
foreach([[160,1],[161,2],[306,2],[307,3]] as [$length,$segments])test("GSM {$length}",fn()=>same($segments,SmsSegmentCalculator::analyze(str_repeat('a',$length))['segment_count']));
foreach([[70,1],[71,2],[134,2],[135,3]] as [$length,$segments])test("Unicode {$length}",fn()=>same($segments,SmsSegmentCalculator::analyze(str_repeat('漢',$length))['segment_count']));
test('GSM extension consumes two septets',function(){same(2,SmsSegmentCalculator::analyze('^')['unit_count']);same(2,SmsSegmentCalculator::analyze(str_repeat('^',81))['segment_count']);});
test('pricing uses segments',fn()=>same(2.10,SmsPricing::estimate(3,2)));
test('campaign exclusion does not alter source and removes normalized recipient',function(){
    $source=['manual'=>['079 928 2775','0821234567'],'excluded'=>['+27799282775']];
    $resolved=RecipientResolver::resolve(1,$source);
    same(1,$resolved['excluded_count']);
    same(['+27821234567'],$resolved['recipients']);
    same(['079 928 2775','0821234567'],$source['manual']);
});
test('template variables personalize per recipient',function(){same('Hi Lufuno Venda, call +27799282775 on '.date('Y-m-d'),TemplatePersonalizer::render('Hi {{name}}, call {{ phone }} on {{date}}',['name'=>'Lufuno','surname'=>'Venda','phone'=>'+27799282775']));});
test('missing contact name uses safe customer fallback',fn()=>same('Hi Customer',TemplatePersonalizer::render('Hi {{name}}',['phone'=>'+27820000000'])));
test('unsupported template variables fail before sending',function(){try{TemplatePersonalizer::assertSupported('Order {{order_id}}');throw new RuntimeException('not rejected');}catch(InvalidArgumentException){}});
test('completed payment cannot be downgraded by delayed callback',function(){
    same('completed',PaymentState::merge('completed','pending'));
    same('completed',PaymentState::merge('completed','failed'));
    same('refunded',PaymentState::merge('completed','refunded'));
});
test('PayOS callback signature fails closed and accepts exact HMAC',function(){
    putenv('PAYOS_CALLBACK_SECRET=test-callback-secret');
    $body='{"external_order_id":"WALLET-SMS-TEST","status":"completed","amount":10,"currency":"ZAR"}';
    $service=new PayOSService();
    same(false,$service->verifyCallbackSignature($body,''));
    same(false,$service->verifyCallbackSignature($body,str_repeat('0',64)));
    same(true,$service->verifyCallbackSignature($body,hash_hmac('sha256',$body,'test-callback-secret')));
});
test('PayOS status and response fields normalize',function(){
    $service=new PayOSService();
    same('completed',$service->mapStatus('SUCCESSFUL'));
    same('pending',$service->mapStatus('processing'));
    same('failed',$service->mapStatus('unexpected'));
    same('https://payos.example/checkout',$service->getCheckoutUrl(['data'=>['checkout_url'=>'https://payos.example/checkout']]));
    same('PAYOS-123',$service->getInternalReference(['data'=>['reference'=>'PAYOS-123']]));
});

$failed=0;
foreach($tests as [$name,$fn]){try{$fn();echo "PASS {$name}\n";}catch(Throwable $e){$failed++;echo "FAIL {$name}: {$e->getMessage()}\n";}}
echo sprintf("%d passed, %d failed\n",count($tests)-$failed,$failed);exit($failed?1:0);

<?php

final class TemplatePersonalizer
{
    private const SUPPORTED=['name','first_name','surname','last_name','phone','email','date'];

    public static function variables(string $template):array
    {
        preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',$template,$matches);
        return array_values(array_unique(array_map('strtolower',$matches[1]??[])));
    }

    public static function assertSupported(string $template):void
    {
        $unsupported=array_values(array_diff(self::variables($template),self::SUPPORTED));
        if($unsupported)throw new InvalidArgumentException('Unsupported template variable(s): '.implode(', ',$unsupported));
    }

    public static function render(string $template,array $recipient):string
    {
        self::assertSupported($template);
        $first=trim((string)($recipient['name']??''));$surname=trim((string)($recipient['surname']??''));
        $values=['name'=>trim($first.' '.$surname)?:'Customer','first_name'=>$first?:'Customer','surname'=>$surname,'last_name'=>$surname,'phone'=>(string)($recipient['phone']??''),'email'=>(string)($recipient['email']??''),'date'=>date('Y-m-d')];
        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',static fn($m)=>$values[strtolower($m[1])]??'', $template);
    }
}

<?php
require_once __DIR__.'/../domain/PortalSearch.php';
final class PortalSearchController
{
 public function index():void
 {
  RateLimiter::checkOrFail('portal_search:'.Auth::id(),90,1);
  $query=Request::query('q','');if(!is_string($query)){Response::error('Enter a search phrase.',422);return;}
  try{Response::success(['results'=>PortalSearch::find(db(),(int)Auth::id(),$query)]);}
  catch(InvalidArgumentException $e){Response::error($e->getMessage(),422);}
 }
}

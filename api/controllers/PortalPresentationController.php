<?php
require_once __DIR__.'/../services/PortalReadModel.php';
require_once __DIR__.'/../domain/SmsPricing.php';
class PortalPresentationController
{
    private function respond(callable $load): void
    {
        try { header('Cache-Control: no-store');Response::success($load()); }
        catch(InvalidArgumentException $e){Response::error($e->getMessage(),422);}
        catch(OutOfBoundsException $e){Response::error('Campaign not found',404);}
    }
    public function overview(): void { $this->respond(fn()=>PortalReadModel::overview(db(),(int)Auth::id())); }
    public function confirmation(): void { $this->respond(fn()=>PortalReadModel::confirmation(db(),(int)Auth::id(),(string)Request::query('range','7d'))); }
    public function activity(): void {
        $filters=[];foreach(['period','status','search','start','end','page','per_page'] as $key){$value=Request::query($key);if($value!==null)$filters[$key]=$value;}
        $this->respond(fn()=>PortalReadModel::activity(db(),(int)Auth::id(),$filters,SmsPricing::pricePerSegment()));
    }
    public function recipients(array $params): void {
        $this->respond(function()use($params){$r=PortalReadModel::recipients(db(),(int)Auth::id(),(int)$params['id'],(string)Request::query('state','all'),trim((string)Request::query('search','')));$page=max(1,(int)Request::query('page',1));$r['rows']=array_slice($r['rows'],($page-1)*50,50);return $r;});
    }
    public function export(array $params): void {
        try{
            $r=PortalReadModel::recipients(db(),(int)Auth::id(),(int)$params['id'],(string)Request::query('state','all'));
            if(!$r['total']){Response::error('No '.strtolower($r['choice']==='All recipients'?'matching':$r['choice']).' recipients',422);return;}
            require_once __DIR__.'/../services/CampaignRecipientExport.php';
            CampaignRecipientExport::download($r,(string)Request::query('format','csv'));
        }catch(InvalidArgumentException $e){Response::error($e->getMessage(),422);}
        catch(OutOfBoundsException $e){Response::error('Campaign not found',404);}
    }
}

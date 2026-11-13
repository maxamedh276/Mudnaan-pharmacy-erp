<?php
namespace App\Controllers\Web;
use App\Controllers\BaseController;
use App\Libraries\AuthContext;
use App\Services\WebReportService;
class ReportsWebController extends BaseController
{
    public function index(string $type='sales'): string|\CodeIgniter\HTTP\ResponseInterface
    {
        if($type==='financial')return redirect()->to(site_url('reports/income-statement'));
        $this->authorize($type);
        try{$report=(new WebReportService())->build($type,(int)AuthContext::$user->branch_id,$this->request->getGet());}
        catch(\InvalidArgumentException $e){return $this->response->setStatusCode(422)->setBody(esc($e->getMessage()));}
        return view('web/reports/report',['title'=>$report['title'],'report'=>$report]);
    }
    public function data(string $type): \CodeIgniter\HTTP\ResponseInterface
    {
        $this->authorize($type);
        try{return $this->response->setJSON(['success'=>true,'data'=>(new WebReportService())->build($type,(int)AuthContext::$user->branch_id,$this->request->getGet())]);}
        catch(\InvalidArgumentException $e){return $this->response->setStatusCode(422)->setJSON(['success'=>false,'message'=>$e->getMessage()]);}
    }
    private function authorize(string $type): void
    {
        if(!isset(WebReportService::TITLES[$type]))throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        if(!AuthContext::can('reports.advanced')||!AuthContext::isPremium())throw new \CodeIgniter\HTTP\Exceptions\HTTPException('You do not have access to this report.',403);
        $user=AuthContext::$user;$role=(int)($user->role_id??0);
        if($role!==1){$keys=(new \App\Services\MenuPermissionService())->getUserMenuKeys((int)$user->id,$role);$allowed=in_array('reports.'.$type,$keys,true)||(in_array($type,['income-statement','balance-sheet','cash-flow','trial-balance'],true)&&in_array('reports.financial',$keys,true));if(!$allowed)throw new \CodeIgniter\HTTP\Exceptions\HTTPException('You do not have access to this report.',403);}

    }
}

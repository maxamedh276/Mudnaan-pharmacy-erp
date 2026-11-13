<?php
namespace App\Controllers\Web;
use App\Controllers\BaseController;
use App\Libraries\AuthContext;
use App\Models\SettingModel;
use App\Services\AppearanceSettingsService;
class SettingsWebController extends BaseController
{
    private function authorize():void{if(!AuthContext::can('settings.manage'))throw new \CodeIgniter\HTTP\Exceptions\HTTPException('You do not have access to settings.',403);}
    public function index(): string
    {
        $this->authorize();
        $settings=(new SettingModel())->allAssoc();
        $branding=(new \App\Services\ReceiptBrandingService())->get();
        $settings['business_name']=$branding['store_name'];
        $settings+=['business_address'=>$branding['address'],'business_phone'=>$branding['phone']];
        return view('web/settings/appearance',['title'=>'Settings','settings'=>$settings,'printers'=>(new \App\Services\PrinterManagementService())->list()]);
    }
    public function operational(): string
    {
        if(!AuthContext::can('settings.operational'))throw new \CodeIgniter\HTTP\Exceptions\HTTPException('Operational Settings is available to Superadmins only.',403);
        return view('web/settings/index',['title'=>'Operational and printer settings','settings'=>(new SettingModel())->allAssoc(),'businessName'=>(new \App\Services\ReceiptBrandingService())->get()['store_name'],'branch'=>db_connect()->table('branches')->where('id',(int)AuthContext::$user->branch_id)->get()->getRowArray()??[],'auditLogs'=>[]]);
    }
    public function save(): \CodeIgniter\HTTP\ResponseInterface
    {
        $this->authorize();try{$body=$this->request->getJSON(true)??[];if(!is_array($body))throw new \InvalidArgumentException('Provide a settings object.');(new AppearanceSettingsService())->save($body);return $this->response->setJSON(['success'=>true]);}catch(\InvalidArgumentException $e){return $this->response->setStatusCode(422)->setJSON(['success'=>false,'message'=>$e->getMessage()]);}
    }
    public function printer(): \CodeIgniter\HTTP\ResponseInterface
    {
        $this->authorize();try{$body=$this->request->getJSON(true)??[];if(!is_array($body))throw new \InvalidArgumentException('Provide printer details.');return $this->response->setJSON(['success'=>true,'data'=>(new \App\Services\PrinterManagementService())->save($body)]);}catch(\InvalidArgumentException $e){return $this->response->setStatusCode(422)->setJSON(['success'=>false,'message'=>$e->getMessage()]);}
    }
    public function logo(): \CodeIgniter\HTTP\ResponseInterface
    {
        $this->authorize();try{$file=$this->request->getFile('logo');if(!$file)throw new \InvalidArgumentException('Select a logo image.');(new AppearanceSettingsService())->uploadLogo($file);return $this->response->setJSON(['success'=>true,'data'=>['url'=>AppearanceSettingsService::logoUrl()]]);}catch(\InvalidArgumentException $e){return $this->response->setStatusCode(422)->setJSON(['success'=>false,'message'=>$e->getMessage()]);}
    }
}

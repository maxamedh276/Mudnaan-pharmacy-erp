<?php
namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Libraries\AuthContext;
use App\Services\TaxRateService;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;

class TaxesWebController extends BaseController
{
    private function authorize(): void
    {
        if (!AuthContext::can('settings.manage')) throw new HTTPException('You do not have access to Tax Management.',403);
        $user = AuthContext::$user;
        if (!in_array('finance.taxes',(new \App\Services\MenuPermissionService())->getUserMenuKeys((int)$user->id,(int)$user->role_id),true)) throw new HTTPException('You do not have access to Tax Management.',403);
    }

    public function index(): string
    {
        $this->authorize();
        return view('web/finance/taxes',['title'=>'Tax Management','taxes'=>(new TaxRateService())->list()]);
    }

    public function save(): ResponseInterface
    {
        $this->authorize();
        try {
            $body = $this->request->getJSON(true);
            if (!is_array($body)) throw new InvalidArgumentException('Enter valid tax details.');
            return $this->response->setJSON(['success'=>true,'data'=>(new TaxRateService())->save($body)]);
        } catch (InvalidArgumentException $error) {
            return $this->response->setStatusCode(422)->setJSON(['success'=>false,'message'=>$error->getMessage()]);
        }
    }
}

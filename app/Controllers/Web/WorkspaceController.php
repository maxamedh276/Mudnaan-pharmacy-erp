<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Services\WorkspaceNavigationService;
use CodeIgniter\Exceptions\PageNotFoundException;

final class WorkspaceController extends BaseController
{
    public function index(string $module = 'home'): string
    {
        $modules = (new WorkspaceNavigationService())->modules();
        if ($module !== 'home' && !isset($modules[$module])) throw PageNotFoundException::forPageNotFound();
        $group = $this->request->getGet('group');
        $context = (new WorkspaceNavigationService())->context($modules, 'workspace/'.$module, is_string($group) ? $group : null);
        return view('web/workspace', ['title'=>($context['module']['label'] ?? 'Home'), 'workspaceModules'=>$modules, 'workspaceContext'=>$context]);
    }
}

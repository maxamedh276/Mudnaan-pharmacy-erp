<?php

namespace App\Database\Migrations;

use App\Services\AdministrativeRolesService;
use CodeIgniter\Database\Migration;

class AddAdministrativeRoleTiers extends Migration
{
    public function up()
    {
        (new AdministrativeRolesService())->upgrade();
    }

    public function down()
    {
        // Preserve assigned accounts and access rules; reverting requires an explicit role reassignment.
    }
}

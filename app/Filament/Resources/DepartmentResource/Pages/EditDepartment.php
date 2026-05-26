<?php

namespace App\Filament\Resources\DepartmentResource\Pages;

use App\Filament\Resources\DepartmentResource;
use Escalated\Filament\Resources\DepartmentResource\Pages\EditDepartment as BaseEditDepartment;

class EditDepartment extends BaseEditDepartment
{
    protected static string $resource = DepartmentResource::class;
}

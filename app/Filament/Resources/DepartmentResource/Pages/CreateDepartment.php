<?php

namespace App\Filament\Resources\DepartmentResource\Pages;

use App\Filament\Resources\DepartmentResource;
use Escalated\Filament\Resources\DepartmentResource\Pages\CreateDepartment as BaseCreateDepartment;

class CreateDepartment extends BaseCreateDepartment
{
    protected static string $resource = DepartmentResource::class;
}

<?php

namespace App\Filament\Resources\DepartmentResource\Pages;

use App\Filament\Resources\DepartmentResource;
use Escalated\Filament\Resources\DepartmentResource\Pages\ListDepartments as BaseListDepartments;

class ListDepartments extends BaseListDepartments
{
    protected static string $resource = DepartmentResource::class;
}

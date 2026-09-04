<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PropertyBill;
use App\Models\Section;
use App\Models\Task;
use Illuminate\Http\Request;

class SectionsController extends Controller
{
    public function index(Request $request)
    {
        if (! $request->user()->hasAnyPermission(['staff.view', 'rbac.edit_role', 'rbac.create_role'])) {
            return response()->json(['data' => [$request->user()->section?->toArray()]]);
        }

        return response()->json(['data' => Section::orderBy('name')->get()]);
    }
}
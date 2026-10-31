<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ManagementPerformanceService;
use Illuminate\Http\Request;

class ManagementPerformanceController extends Controller
{
    public function summary(Request $request, ManagementPerformanceService $performance)
    {
        return response()->json(['data' => $performance->summarize($request->user())]);
    }
}

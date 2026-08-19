<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function summary(Request $request, DashboardService $dashboard)
    {
        return response()->json([
            'data' => $dashboard->resumo($request->user()),
            'success' => true,
        ]);
    }
}

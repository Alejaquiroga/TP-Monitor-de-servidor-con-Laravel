<?php

namespace App\Http\Controllers;

use App\Services\ServerMetrics;
use Illuminate\View\View;

class ServerController extends Controller
{
    public function index(ServerMetrics $metrics): View
    {
        return view('servidor', [
            'data' => $metrics->snapshot(),
        ]);
    }

    public function api(ServerMetrics $metrics)
    {
        return response()->json($metrics->snapshot());
    }
}

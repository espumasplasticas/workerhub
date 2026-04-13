<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Contracts\View\View;

class WorkerOperationsDashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        return view('monitor.index', [
            'operatorToken' => (string) $request->query('token', ''),
        ]);
    }
}

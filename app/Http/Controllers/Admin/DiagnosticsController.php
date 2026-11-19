<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Diagnostics;
use Illuminate\View\View;

class DiagnosticsController extends Controller
{
    public function __invoke(Diagnostics $diagnostics): View
    {
        return view('admin.diagnostics', ['checks' => $diagnostics->run()]);
    }
}

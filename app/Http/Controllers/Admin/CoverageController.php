<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Coverage;
use Illuminate\View\View;

/** Health: the coverage score and its parts, each with a link to its fix. */
class CoverageController extends Controller
{
    public function __invoke(Coverage $coverage): View
    {
        return view('admin.health', ['coverage' => $coverage->report()]);
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Policy\FilteringMap;
use App\Services\Policy\ProtectedSites;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Filtering map: pick a network, see every filter on it, the policy behind each, and the lists that fill them. */
class FilteringMapController extends Controller
{
    public function show(Request $request, FilteringMap $map, ProtectedSites $protectedSites): View
    {
        $overview = $map->overview($request->boolean('refresh'));
        $networks = $overview['networks'];
        $selected = $networks->firstWhere('id', (string) $request->query('network'))
            ?? $networks->first(fn (array $network) => $network['policy'] !== null)
            ?? $networks->first();

        return view('admin.policy.map', [
            'error' => $overview['error'],
            'networks' => $networks,
            'selected' => $selected,
            'trace' => $selected ? $map->trace($selected) : null,
            'protectedCount' => count($protectedSites->all()),
        ]);
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function __invoke(Request $request): View
    {
        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:120'],
            'actor' => ['nullable', 'integer'],
        ]);

        return view('admin.audit', [
            'events' => AuditEvent::query()->with('actor:id,name,email')
                ->when($filters['action'] ?? null, fn ($query, $action) => $query->where('action', 'like', str_replace('*', '%', $action).'%'))
                ->when($filters['actor'] ?? null, fn ($query, $actor) => $query->where('actor_id', $actor))
                ->latest('id')->paginate(50)->withQueryString(),
            'actions' => AuditEvent::query()->distinct()->orderBy('action')->pluck('action'),
            'filters' => $filters,
        ]);
    }
}

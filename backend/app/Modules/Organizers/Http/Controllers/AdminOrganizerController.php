<?php

namespace App\Modules\Organizers\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Organizers\Http\Resources\OrganizerResource;
use App\Modules\Organizers\Models\Organizer;
use App\Modules\Organizers\Services\OrganizerService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class AdminOrganizerController extends Controller
{
    public function __construct(private readonly OrganizerService $organizers) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $v = $request->validate([
            'status' => ['nullable', Rule::in([Organizer::PENDING, Organizer::APPROVED, Organizer::REJECTED, Organizer::SUSPENDED])],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        return OrganizerResource::collection(Organizer::query()
            ->when($v['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($v['search'] ?? null, fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->latest('id')->paginate(25)->withQueryString());
    }

    public function updateStatus(Request $request, Organizer $organizer): OrganizerResource
    {
        $v = $request->validate([
            'status' => ['required', Rule::in([Organizer::APPROVED, Organizer::REJECTED, Organizer::SUSPENDED])],
            'reason' => ['required_unless:status,'.Organizer::APPROVED, 'nullable', 'string', 'max:500'],
        ]);

        return new OrganizerResource($this->organizers->decide($organizer, $v['status'], $v['reason'] ?? null, $request->user()));
    }
}

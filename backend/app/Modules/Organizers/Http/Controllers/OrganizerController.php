<?php

namespace App\Modules\Organizers\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Organizers\Http\Resources\OrganizerResource;
use App\Modules\Organizers\Models\Organizer;
use App\Modules\Organizers\Services\OrganizerContext;
use App\Modules\Organizers\Services\OrganizerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Organizer self-service: apply, profile, staff (C05). */
class OrganizerController extends Controller
{
    public function __construct(private readonly OrganizerService $organizers, private readonly OrganizerContext $context) {}

    public function apply(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'contact_email' => ['required', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'regex:/^\+[1-9]\d{7,14}$/'],
            'business_registration_no' => ['nullable', 'string', 'max:50'],
        ]);

        return (new OrganizerResource($this->organizers->apply($request->user(), $data)))->response()->setStatusCode(201);
    }

    public function show(Request $request): OrganizerResource
    {
        return new OrganizerResource($this->context->for($request->user())->load('members'));
    }

    public function addStaff(Request $request): JsonResponse
    {
        $organizer = $this->context->for($request->user(), [Organizer::ROLE_OWNER]);
        $data = $request->validate([
            'email' => ['required', 'email'],
            'role' => ['required', Rule::in([Organizer::ROLE_MANAGER, Organizer::ROLE_GATE_STAFF])],
        ]);
        $this->organizers->addStaff($organizer, $data['email'], $data['role']);

        return (new OrganizerResource($organizer->load('members')))->response()->setStatusCode(201);
    }

    public function removeStaff(Request $request, User $user): Response
    {
        $this->organizers->removeStaff($this->context->for($request->user(), [Organizer::ROLE_OWNER]), $user);

        return response()->noContent();
    }
}

<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Http\Requests\RegisterRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Services\AuthService;
use App\Modules\Identity\Services\VerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $this->auth->registerCustomer($request->validated());
        $code = app(VerificationService::class)->send($user, 'email');

        $response = $this->tokenResponse($request, $user, $request->validated('device_name'), 201);
        if ($code) { // local development only: lets the app be tested without a mail inbox
            $response->setData(array_merge_recursive($response->getData(true), ['data' => ['debug_code' => $code]]));
        }

        return $response;
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = $this->auth->attemptLogin($request->validated('email'), $request->validated('password'));

        return $this->tokenResponse($request, $user, $request->validated('device_name'));
    }

    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('roles'));
    }

    private function tokenResponse(Request $request, $user, string $deviceName, int $status = 200): JsonResponse
    {
        $request->setUserResolver(fn () => $user);

        return response()->json([
            'data' => $this->auth->issueToken($user, $deviceName) + [
                'user' => (new UserResource($user->load('roles')))->resolve($request),
            ],
        ], $status);
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\Api\BaseApiController;
use App\Core\Exceptions\NotFoundHttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\UserService;

final class UserController extends BaseApiController
{
    public function __construct(private readonly UserService $service)
    {
    }

    public function index(Request $request): Response
    {
        return $this->ok([
            'users' => $this->service->all(),
        ]);
    }

    public function show(Request $request): Response
    {
        $id = (int) $request->attribute('id', 0);
        $user = $this->service->find($id);

        if ($user === null) {
            throw new NotFoundHttpException('User not found');
        }

        return $this->ok(['user' => $user]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, [
            'name' => 'required',
            'email' => 'required|email',
        ]);

        $user = $this->service->create([
            'name' => (string) $data['name'],
            'email' => (string) $data['email'],
        ]);

        return $this->ok(['user' => $user], 201);
    }
}

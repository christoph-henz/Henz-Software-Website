<?php
declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\Api\BaseApiController;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Logging\Logger;

class FormStateController extends BaseApiController
{
    public function store(Request $request): Response
    {
        $request->session();

        $field = $request->input('field');
        $value = $request->input('value');

        $_SESSION['contact_form'][$field] = $value;

        return Response::json([
            'session_id' => session_id(),
            'session' => $_SESSION,
        ]);
    }

    public function clear(): Response
    {
        unset($_SESSION['contact_form']);

        return Response::json([
            'success' => true
        ]);
    }
}
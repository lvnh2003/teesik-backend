<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class UserController extends Controller
{
    public function me(Request $request)
    {
        return $this->successResponse([
            'user' => $request->user(),
        ]);
    }
}
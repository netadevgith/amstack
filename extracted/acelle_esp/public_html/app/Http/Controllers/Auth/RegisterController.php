<?php

namespace Acelle\Http\Controllers\Auth;
use Acelle\User;
use Validator;
use Acelle\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\ThrottlesLogins;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\Request;

class RegisterController extends Controller
{
    use AuthenticatesUsers;

    protected $redirectTo = '/campaigns';

    public function __construct()
    {
        $this->middleware('guest');
    }

    public function index(Request $request)
    {
      return "0";
    }

    protected function create(array $data)
    {
    }

    public function showRegistrationForm(Request $request) {
        // tris will be register form for user
        return;
}
}
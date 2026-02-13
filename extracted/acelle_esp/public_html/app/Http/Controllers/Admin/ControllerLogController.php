<?php

namespace Acelle\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Acelle\Http\Controllers\Controller;

class ControllerLogController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        parent::__construct();

        $this->middleware('auth');
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $items = \Acelle\Model\ControllerLog::getAll();

        return view('admin.controller_logs.index', [
            'items' => $items,
        ]);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function listing(Request $request)
    {

        $items = \Acelle\Model\ControllerLog::search($request)->paginate($request->per_page);

        return view('admin.controller_logs._list', [
            'items' => $items,
        ]);
    }
}

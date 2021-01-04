<?php

namespace App\Http\Controllers;

use Exception;
use DataTables;
use PDOException;
use App\Department;
use App\Constants\Permissions;
use App\Http\Requests\Department as DerpartmentRequest;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class DepartmentController extends Controller
{
    public function __construct()
    {
        $this->middleware(['permission:'.Permissions::Claims]);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return view('departments/list');
    }

    public function allDepartments(Request $request)
    {
        $departments = Department::all();

        return DataTables::of($departments)
            ->addColumn('action', function ($department) use ($request) {
                $data = array();
                if ($request->user()->hasAccountPermission(Permissions::Claims)) {
                    $data['id'] = $department->id;
                }
                return view('departments/actions', $data)->render();
            })
            ->make(true);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $department = new Department;

        return view('departments/create_edit', compact('department'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(DerpartmentRequest $request)
    {
        $account_id = $request->user()->account_id;
        $department = new Department([ 'account_id' => $account_id, 'name' => $request->name ]);
        $department->save();

        return redirect()->route('departments.index');
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        try {
            $department = Department::findOrFail($id);
        }catch (ModelNotFoundException $exception) {
            return back()->withError($exception->getMessage())->withInput();
        }

        return view('departments/create_edit', compact('department'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(DerpartmentRequest $request, $id)
    {
        try {
            $department = Department::findOrFail($id);
            $department->update([ 'name' => $request->name ]);
        }catch (ModelNotFoundException $exception) {
            return back()->withError($exception->getMessage())->withInput();
        }catch (Exception $exception) {
            return response()->json(['data' => $exception->getMessage()], 500);
        }

        return redirect()->route('departments.index');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        try {
            $department = Department::findOrFail($id);
            $department->delete();
        }catch (ModelNotFoundException $exception) {
            return back()->withError($exception->getMessage())->withInput();
        }catch (Exception $exception) {
            return back()->withError($exception->getMessage());
        }

        return redirect()->route('departments.index');
    }
}

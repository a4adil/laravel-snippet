<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use App\Constants\Permissions;
use App\Http\Resources\PermissionCollection;
use Illuminate\Database\Eloquent\Collection;

class PermissionController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        if($request->ajax()) {
            $allPermissions = Permission::all();
            return response()->json(['allPermissions' => $allPermissions]);
        }
        
        return view('permission_views/Permission_view');
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        if($request->has('name') && $request->name) {
            Permission::create(['name' => $request->name]);
            return response()->json(['message' => 'Permission '.$request->name.' created']);
        }

        return response()->json(['message' => 'Invalid string'], 300);
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
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }

    public function permissions(Request $request){
        $collection = new Collection();
        $roles = $request->user()->roles()->get();
        foreach($roles as $role){
            $collection = $collection->concat($role->permissions);
        }
        $permissions = $collection->concat($request->user()->permissions)->unique();
        return  new PermissionCollection($permissions->pluck("name"));
    }
}

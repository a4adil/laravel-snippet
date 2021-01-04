<?php

namespace App\Http\Controllers;

use App\User;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

/*
* This library for role and permissins is being used https://docs.spatie.be/laravel-permission/v2/installation-laravel/
*/

class UserRoleAndPermissionController extends Controller
{
    /*
    *   user's permission temp views
    */

    public function view_users(Request $request)
    {
        return view('permission_views/user_role_permissions_view');
    }
    
    /*
    * ajax call to get all roles and permission for user
    */
    public function get_roles(Request $request)
    {
        $allRoles = Role::all();
        $allPermissions = Permission::all();

        $user = User::find($request->id);
        $userPermissions = $user->permissions;
     
        $userRoles = $user->roles;
         
        return response()->json(compact('userPermissions', 'allRoles', 'allPermissions', 'userRoles'), 200);
    }
}

    // May be use able in future build.

        //$user = auth()->user();
        // echo $user->id;
        // $permissionName = 'manage claims';

        // $permissionScopes = $user->permissions()->where(['name' => $permissionName])->first();
        // //dd($permissionScopes->scopes->scopes());
       
        // $scopes = $permissionScopes->scopes->scopes()->get();
        // //dd($scopes);
        //  foreach ($scopes as $value) {
        //    echo $value->model_has_permissions_id;
        // }
       
        // exit;


        // $roleString = 'super-admin';
        // $user = $user->roles->where('name', $roleString)->first();

       
        // $roleScope = $user->scopes->scopes()->where('scope_type','location')
        //                             ->pluck('scope_id')->toArray();
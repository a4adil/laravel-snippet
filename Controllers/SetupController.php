<?php

namespace App\Http\Controllers;

use App\Constants\Permissions;
use App\Constants\Roles;
use App\Http\Requests\SiteSetupRequest;
use App\Account;
use App\PermissionScope;
use App\RoleScope;
use App\TimeZone;
use Illuminate\Support\Str;

class SetupController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        if (Account::unsafe()->count()) {
            return redirect('/');
        }

        $timezones = TimeZone::pluck('id', 'name')->flip()->toArray();
        return view('setup/index', ['setupModel' => [], 'timezones'=>$timezones]);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function store(SiteSetupRequest $request)
    {
        if (Account::unsafe()->count()) {
            return redirect('/');
        }

        $accountData = [
            'name' => $request->input('account_name'),
            'address' => $request->input('account_address'),
            'city' => $request->input('account_city'),
            'state' => $request->input('account_state'),
            'zipcode' => $request->input('account_zipcode'),
            'account_level_id' => 1,
            'parent_account_id' => null,
            'time_zone_id' => $request->input('setting_time_zone')
        ];

        // The main admin user for the account
        $mainAdminData = [
            'email' => $request->input('admin_email'),
            'password' => $request->input('admin_password'),
            'remember_token' => Str::random(60),
            'first_name' => $request->input('admin_first_name'),
            'last_name' => $request->input('admin_last_name'),
            'time_zone_id' => $request->input('setting_time_zone')
        ];

        $ctrl = new AccountController();

        $createdData = $ctrl->createNewAccount($accountData, $mainAdminData);

        $createdData['user']->giveRoleTo(Roles::SiteAdmin, new RoleScope([
            'scope_type' => 'account',
            'scope_id' => $account->id
        ]));
        return redirect('/')->withSuccess('Your site has been set up - please login to continue');
    }
}

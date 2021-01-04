<?php

namespace App\Http\Controllers;

use App\Account;
use App\Constants\AccountLevel;
use App\Constants\ClaimFields;
use App\Constants\Departments;
use App\Constants\Permissions;
use App\Constants\Roles;
use App\Forum;
use App\Http\Requests\CreateAccount;
use App\Http\Requests\UpdateAccount;
use App\Location;
use App\Mail\NewuserLoginLink;
use App\PermissionScope;
use App\RoleScope;
use App\TimeZone;
use App\User;
use Auth;
use DataTables;
use Exception;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Illuminate\Support\Facades\Mail;

class AccountController extends Controller
{
    use AdminControllerTrait;

    public function __construct()
    {
        $this->middleware(['permission:'.Permissions::ManageAccounts])->only(['index', 'allAccounts', 'create', 'destroy']);
        $this->middleware(['permission:'.Permissions::ManageAccountAdmins])->only(['store']);
        $this->middleware(['permission:'.Permissions::ManageOwnAccount.'|'.Permissions::ManageAccounts])->only(['edit', 'update']);
    }

    public function index()
    {
        return view('accounts/list');
    }

    public function create()
    {
        $levels = AccountLevel::getLevels(Auth::user()->account->account_level_id)->flip()->toArray();
        $time_zones = TimeZone::all()->pluck('id', 'name')->flip()->toArray();

        return view('accounts/create', compact('levels', 'time_zones'));
    }

    /**
     * Store account and its primary user.
     * @param Request $request
     * @return Redirect account.index
     */
    public function store(CreateAccount $request)
    {
        $accountData = [
            'primary_user_id' => null,
            'name' => $request->account_name,
            'address' => $request->account_address,
            'city' => $request->account_city,
            'state' => $request->account_state,
            'zipcode' => $request->account_zipcode,
            'account_level_id' => 0,
            'parent_account_id' => $request->user()->account_id,
            'time_zone_id' => $request->timezone,
        ];

        $userData = [
            'email' => $request->email,
            'password' => $request->password,
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'title' => $request->title,
            'phone' => $request->phone,
            'fax' => $request->fax,
            'address' => $request->address,
            'city' => $request->city,
            'state' => $request->state,
            'country' => $request->country,
            'zipcode' => $request->zipcode,
            'time_zone_id' => $request->timezone,
        ];
        $account_data = $this->createNewAccount($accountData, $userData);
        $user = $account_data['user'];

        if($user):
            try{
                Mail::to($user->email)->send(new NewuserLoginLink($user));
            }catch(Exception $e){
                Log::error($e);
            }

        endif;

        return redirect()->route('account.index');
    }

    /**
     * Get All accounts.
     * @return DataTable Array
     */
    public function allAccounts()
    {
        $accounts = Account::all();
        return DataTables::of($accounts)
            ->addColumn('action', function ($account) {
                $data['account'] = $account;
                return view('accounts/partials/actions', $data)->render();
            })
            ->setRowClass(function($account){
                if (Auth::user()->account_id == $account->id) {
                    return 'alert-warning';
                }
            })
            ->make(true);
    }

    public function show($account_id)
    {
        try {
            $account = Account::findOrFail($account_id);
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError('Something went wrong with account id. No related record found.')->withInput();
        }

        return view('accounts/details', compact('account'));
    }

    /**
     * Edit Account detail (primary user is not included)
     * @param int $account_id
     * @return view accounts/update
     */
    public function edit($account_id)
    {
        if(!Auth::user()->hasAccountPermission(Permissions::ManageOwnAccount, $account_id) &&
            !CheckAccountScope(Auth::user(), Permissions::ManageAccounts, (int)$account_id, true)) {
            throw new UnauthorizedException(403, 'User does not have the right permissions.');
        }

        try {
            $account = Account::findOrFail($account_id);
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError('No related record found, Try later!')->withInput();
        }
        
        $levels = AccountLevel::getLevels(Auth::user()->account->account_level_id);

        return view('accounts/details', compact('account', 'levels'));
    }

    /**
     * Update Account detail (primary user is not included)
     * @param Request $request
     * @param int $account_id
     * @return Redirect account.index
     */
    public function update(UpdateAccount $request, $account_id)
    {
        if(!Auth::user()->hasAccountPermission(Permissions::ManageOwnAccount, $account_id) &&
            !CheckAccountScope(Auth::user(), Permissions::ManageAccounts, (int)$account_id, true)) {
            throw new UnauthorizedException(403, 'User does not have the right permissions.');
        }

        try {
            $account = Account::findOrFail($account_id);
            $account->update([
                'name' =>  $request->name,
                'address' => $request->address,
                'city' => $request->city,
                'state' => $request->state,
                'zipcode' => $request->zipcode,
            ]);
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError('Something went wrong with account updating. Try later.')->withInput();
        }

        if(Auth::user()->account_id == $account_id) {
            return redirect()->route('account.edit', $account_id)->withSuccess("Account updated successfully");
        } else {
            return redirect()->route('account.index');
        }
    }

    /**
     * Delete/Destroy Account.
     * @param int $account_id
     * @return Redirect account.index
     */
    public function destroy($account_id)
    {
        try {
            $account = Account::findOrFail($account_id);
            $account->delete();
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError('Something went wrong with account deletion. Try later.')->withInput();
        }
        
        return redirect()->route('account.index');
    }

    public function createNewAccount($accountData, $mainAdminInfo)
    {
        try {
            $newAccount = Account::create($accountData);
        } catch (Exception $exception) {
            Log::error($exception);
            return back()->withError('Something went wrong with account creation. Try later.')->withInput();
        }

        $locationData = [
            'account_id' => $newAccount->id,
            'name' => $accountData['name'],
            'address' => $newAccount->address,
            'city' => $newAccount->account_city,
            'state' => $newAccount->account_state,
            'zip' => $newAccount->account_zipcode
        ];

        try {
            $newLocation = $newAccount->locations()->create($locationData);
        } catch (Exception $exception) {
            Log::error($exception);
            return back()->withError('Something went wrong with location creation. Try later.')->withInput();
        }

        $userData = [
            'account_id' => $newAccount->id,
            'email' => $mainAdminInfo['email'],
            'password' => bcrypt($mainAdminInfo['password']),
            'remember_token' => str_random(60),
        ];

        try {
            $newUser = $newAccount->users()->create($userData);
            $newAccount->primary_user()->associate($newUser)->save();
        } catch (Exception $exception) {
            Log::error($exception);
            return back()->withError('Something went wrong with user creation. Try later.')->withInput();
        }

        $mainAdminInfo['user_id'] = $newUser->id;

        try {
            $newUserInfo = $newUser->userInfo()->create($mainAdminInfo);
        } catch (Exception $exception) {
            Log::error($exception);
            return back()->withError('Something went wrong with user extra information creation. Try later.')->withInput();
        }

        $newUser->giveRoleTo(Roles::MainAccountAdmin, new RoleScope([
            'scope_type' => 'account',
            'scope_id' => $newAccount->id
        ]));

        $newUser->giveRoleTo(Roles::AccountAdmin, new RoleScope([
            'scope_type' => 'account',
            'scope_id' => $newAccount->id
        ]));

        if ($newAccount->account_level_id > 0) {
            $newUser->givePermissionTo(Permissions::ManageAccounts, new PermissionScope([
                'scope_type' => 'account',
                'scope_id' => $newAccount->id
            ]));
        }

        //Set up account defaults
        try {
            $all_departments = Departments::departments();
            foreach ($all_departments as $department) {
                $newAccount->departments()->create(['name' => $department]);
            }

            $areasOfLoss = ClaimFields::areaOfLoss();
            foreach ($areasOfLoss as $areaOfLoss) {
                $newAccount->claimAreaOfLoss()->create(['name' => $areaOfLoss]);
            }
        } catch (Exception $exception) {
            Log::error($exception);
            return back()->withError('Something went wrong with department/area of loss creation. Try later.')->withInput();
        }

        //Set up account general forum
        try {
            Forum::create([
                'account_id' => $newAccount->id,
                'name' => 'General',
                'description' => 'A forum for general conversation',
                'status' => '',
                'topic_approve' => false,
                'post_approve' => false
            ]);
        } catch (Exception $exception) {
            Log::error($exception);
            return back()->withError("There's an error in creating Forum, try later!")->withInput();
        }

        return ['account' => $newAccount, 'user' => $newUser, 'location' => $newLocation];
    }
}

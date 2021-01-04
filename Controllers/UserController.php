<?php

namespace App\Http\Controllers;

use Auth;
use Artisan;
use App\User;
use Exception;
use DataTables;
use App\Account;
use App\Location;
use App\TimeZone;
use App\UserInfo;
use Illuminate\Support\Facades\Log;
use PDOException;
use App\RoleScope;
use Carbon\Carbon;
use App\UserPreference;
use App\Constants\Roles;
use App\Constants\Permissions;
use App\Forum;
use App\Http\Requests\UpdateAccountUser;
use App\Http\Requests\CreateAccountUser;
use App\PermissionScope;
use Illuminate\Http\Request;
use App\Mail\NewuserLoginLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Mail;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Http\Resources\UserPreference as UserPreferenceResource;
use Illuminate\Database\Eloquent\Collection;

class UserController extends Controller
{
    use AdminControllerTrait;

    public function __construct()
    {
        $this->middleware(['permission:'.Permissions::ManageUsers])->only(['create', 'store', 'destroy']);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $account = $this->getAccountForRequest(Permissions::ManageUsers, $request);
        if($account == null) {
            return back()->withError('You do not have permission to access that resource');
        }

        return view('users/list', compact('account'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create(Request $request)
    {
        $account = $this->getAccountForRequest(Permissions::ManageUsers, $request);
        if($account == null) {
            return back()->withError('You do not have permission to access that resource');
        }


        $user = new User;
        $formName = 'Add';
        $locations = Location::where('account_id', $account->id)->get();
        $timezones = TimeZone::pluck('id', 'name')->flip()->toArray();

        return view('users/create_edit', compact('user', 'formName', 'locations', 'timezones', 'account'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(CreateAccountUser $request)
    {
        $account = $this->getAccountForRequest(Permissions::ManageUsers, $request);
        if($account == null) {
            return back()->withError('You do not have permission to access that resource');
        }

        $newUser = new User();
        $newUser->account_id = $account->id;
        $newUser->email = $request->get('email', $newUser->email);
        $newUser->password = bcrypt($request->get('password', 'secret'));
        $newUser->remember_token = str_random(60);
        $newUser->save();

        if ($request->filled('temp_file.name'))
        {
            $image = $this->profileMoveToPermanent($request['temp_file.name'],$account->id);
        }
        $newUser->UserInfo()->create([
            'first_name' =>  $request->get('first_name'),
            'last_name' => $request->get('last_name'),
            'title' => $request->get('title'),
            'image' => !empty($image) ? $image : null,
            'phone' => $request->get('phone'),
            'fax' => $request->get('fax'),
            'address' => $request->get('address'),
            'city' => $request->get('city'),
            'state' => $request->get('state'),
            'country' => $request->get('country'),
            'zipcode' => $request->get('zipcode'),
            'time_zone_id' => $request->get('timezone'),
        ]);

        $this->saveRoleAndPermissions($request, $newUser);

        if($newUser) {
            try {

                Mail::to($newUser->email)->send(new NewuserLoginLink($newUser));
            } catch (Exception $e) {
                Log::error($e);
            }
        }

        return redirect()->route('user.index', ['accountId'=>$account->id]);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        $formName = 'Edit';
        try {
            $user = User::withTrashed()->findOrFail($id);
        }catch (ModelNotFoundException $exception) {
            return back()->withError('Data not found');
        }

        $account = $this->getAccountForRequest(Permissions::ManageUsers, null, $user->account_id);
        if($account == null) {
            return back()-withError('You do not have permission to access that resource');
        }

        $locations = Location::where('account_id', $user->account_id)->get();
        $timezones = TimeZone::pluck('id', 'name')->flip()->toArray();

        return view('users/create_edit', compact('user', 'formName', 'locations', 'timezones', 'account'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(UpdateAccountUser $request, User $user)
    {
        $account = $this->getAccountForRequest(Permissions::ManageUsers, null, $user->account_id);
        if($account == null) {
            return back()->withError('You do not have permission to access that resource');
        }

        if ($request->filled('temp_file.name'))
        {
            $image = $this->profileMoveToPermanent($request['temp_file.name'],Auth::user()->account_id);
        }
        try {
            $user->UserInfo()->update([
                'first_name' =>  $request->get('first_name', $user->first_name),
                'last_name' => $request->get('last_name', $user->last_name),
                'title' => $request->get('title', $user->title),
                'image' => !empty($image) ? $image : $user->UserInfo['image'],
                'phone' => $request->get('phone', $user->phone),
                'fax' => $request->get('fax', $user->fax),
                'address' => $request->get('address', $user->address),
                'city' => $request->get('city', $user->city),
                'state' => $request->get('state', $user->state),
                'country' => $request->get('country', $user->country),
                'zipcode' => $request->get('zipcode', $user->zipcode),
                'time_zone_id' => $request->get('timezone', $user->time_zone_id),
            ]);
            
            $user->update(['email' => $request->get('email', $user->email)]);

            if($request->filled('password') && ($request->password === $request->confirm_password)) {
                $user->update(['password' => bcrypt($request->get('password'))]);
            }
        }catch (QueryException $exception) {
            return back()->withInput()->withErrors(['errors' => $exception->getMessage()]);
        }catch (PDOException $exception) {
            return back()->withInput();
        }catch (Exception $exception) {
            return back()->withInput();
        }

        if($user->id != Auth::user()->id) {
            if(!$user->hasRole(Roles::MainAccountAdmin)) {
                $this->saveRoleAndPermissions($request, $user);
            }
        } else if($request->filled('password')) {
            Auth::logout();
            return redirect('/login');
        }

        return redirect()->route('user.index', ['accountId' => $account->id]);
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

    public function usersList(Request $request, int $accountId)
    {
        if (!$accountId) {
            $accountId = $request->user()->account_id;
        }
        $userList = User::withTrashed()->where('account_id', $accountId);
        if (!Auth::user()->hasAccountPermission(Permissions::ManageAccountAdmins, $accountId, true)) {
            $userList = User::excludeAdmins($userList);
        }
        if(!Auth::user()->hasAccountPermission(Permissions::ManageUsers, $accountId, true)) {
            $userList = User::withLocationPermission($userList, Auth::user()->allLocation(Permissions::ManageUsers));
        }

        return DataTables::of($userList->get())
            ->addColumn('first_name', function ($user) {
                return $user->userInfo->first_name;
            })
            ->addColumn('last_name', function ($user) {
                return $user->userInfo->last_name;
            })
            ->addColumn('title', function ($user) {
                return $user->userInfo->title;
            })
            ->addColumn('phone', function ($user) {
                return $user->userInfo->phone;
            })
            ->addColumn('status', function ($user) {
                if ($user->deleted_at == null) {
                    return 'Active';
                } else {
                    return 'Inactive';
                }
            })
            ->editColumn('role', function ($user) {
                $roles = [];
                if ($user->hasRole(Roles::MainAccountAdmin)) {
                    $roles[] = "Main Account Admin";
                } elseif ($user->hasRole(Roles::AccountAdmin)) {
                    $roles[] = "Account Admin";
                } elseif ($user->hasRole(Roles::LocationAdmin)) {
                    $roles[] = "Location Admin";
                } else {
                    $roles[] = "User";
                }
                if ($user->hasRole(Roles::SiteAdmin)) {
                    $roles[] = "Site Admin";
                }
                return join(", ", $roles);
            })
            ->editColumn('roleValue', function ($user) {
                if ($user->hasRole(Roles::MainAccountAdmin)) {
                    return 4;
                }
                if ($user->hasRole(Roles::SiteAdmin)) {
                    return 3;
                }
                if ($user->hasRole(Roles::AccountAdmin)) {
                    return 2;
                }
                if ($user->hasRole(Roles::LocationAdmin)) {
                    return 1;
                }
                return 0;
            })
            ->addColumn('action', function ($user) {
                return view('users/actions', compact('user'))->render();
            })
            ->setRowClass(function($user){
                if ($user->deleted_at) {
                    return 'alert-secondary';
                }
                if($user->hasRole(Roles::MainAccountAdmin)) {
                    return 'alert-warning';
                }
                if($user->hasRole(Roles::AccountAdmin)) {
                    return 'alert-info';
                }
            })
            ->make(true);
    }

    /**
     * Save roles and permissions of user
     * @param $request
     * @param $user
     * @return 
     */
    protected function saveRoleAndPermissions($request, $user)
    {
        $activeUser = Auth::user();

        //Clear permissions that we have the ability to add, so we can re-add them
        $editUserPermissions = $user->permissions()->get();
        foreach ($editUserPermissions as $permission) {
            $preservePermission = false;
            foreach ($permission->scopes->permissionScopes()->get() as $scopedPermission) {
                if ($scopedPermission->scope_type == "account") {
                    $hasPermission = $activeUser->hasAccountPermission($permission->name,
                        $scopedPermission->scope_id, true);
                } else {
                    $hasPermission = $activeUser->hasLocationPermission($permission->name,
                        $scopedPermission->scope_id);
                }
                if ($hasPermission) {
                    $scopedPermission->delete();
                } else {
                    $preservePermission = true;
                }
            }
            if (!$preservePermission) {
                $user->permissions()->detach($permission);
            }
        }

        //Clear Roles that we have access to
        $editUserRoles = $user->roles()->get();
        foreach ($editUserRoles as $role) {
            $preserveRole = false;
            foreach ($role->scopedRole->roleScopes()->get() as $scopedRole) {
                if ($scopedRole->scope_type == "account") {
                    $hasRole = $activeUser->hasAccountRole($role->name, $scopedRole->scope_id);
                } else {
                    $hasRole = $activeUser->hasLocationRole($role->name, $scopedRole->scope_id);
                }
                if ($hasRole) {
                    $scopedRole->delete();
                } else {
                    $preserveRole = true;
                }
            }
            if (!$preserveRole) {
                $user->roles()->detach($role);
            }
        }

        $editUserRole = $request->get('accountrole', null);
        if ($editUserRole === Roles::AccountAdmin) {
            $user->giveRoleTo(Roles::AccountAdmin, new RoleScope([
                'scope_type' => 'account',
                'scope_id' => $user->account_id
            ]));
        }

        if ($request->has('account_permission')) {
            $permissions = $request->get('account_permission');
            foreach ($permissions as $permission) {
                $user->givePermissionTo($permission, new PermissionScope([
                    'scope_type' => 'account',
                    'scope_id' => $user->account_id
                ]));
            }
        }

        if ($request->has('location_role') && $editUserRole !== Roles::AccountAdmin) {
            foreach ($request->get('location_role') as $location => $roles) {
                foreach ($roles as $roleName) {
                    if ($activeUser->hasLocationRole($roleName, $location)) {
                        $user->giveRoleTo($roleName, new RoleScope([
                            'scope_type' => 'location',
                            'scope_id' => $location
                        ]));
                    }
                }
            }
        }

        if ($request->has('location_permission') && $editUserRole !== Roles::AccountAdmin) {
            foreach ($request->get('location_permission') as $location => $permissions) {
                foreach ($permissions as $permissionName) {
                    if ($activeUser->hasLocationPermission($permissionName, $location)) {
                        $user->givePermissionTo($permissionName, new PermissionScope([
                            'scope_type' => 'location',
                            'scope_id' => $location
                        ]));
                    }
                }
            }
        }
    }

    public function activateDeactivateUser(int $user_id)
    {        
        $activate_deactivate = false;

        try {
            $user = User::withTrashed()->findOrFail($user_id);
            $account = $this->getAccountForRequest(Permissions::ManageUsers, null, $user->account_id);
            if($account == null || !($user->exists() && $account->exists())) {
                return back()->withError('You do not have permission to access that resource');
            }

            if($user->hasAnyRole(Roles::AccountAdmin, Roles::MainAccountAdmin, Roles::SiteAdmin)) {
                if(Auth::user()->hasPermissionTo(Permissions::ManageAccountAdmins)) {
                    $activate_deactivate = true;
                }
            }else {
                $activate_deactivate = true;
            }

            if($activate_deactivate) {
                if($user->deleted_at == null) {
                    $user->update([ 'deleted_at' => now() ]);
                }else {
                    $user->update([ 'deleted_at' => null ]);
                }
            }else {
                return back()->withError('You are unauthorized to perform the operation');
            }
        }catch (Exception $exception) {
            return back()->withError('You are unauthorized to perform the operation');
        }

        return redirect()->route('user.index', ['accountId'=>$account->id]);
    }

    public function getScheduleTestView()
    {
        return view('test_schedule');
    }

    public function sendScheduleTestRequest(Request $request)
    {
        $id = $request->id;

        if($id == 1)
            Artisan::call('contract:transition');
        else if($id == 2)
            Artisan::call('expiredContract:transition');
        else if($id == 3)
            Artisan::call('contractExpiry:reminder');
        else if($id == 4)
            Artisan::call('contractMilestone:reminder');
        else if($id == 5)
            Artisan::call('vendorCertificate:request');
        else if($id == 6)
            Artisan::call('certificate:expiry');
        else if($id == 7)
            Artisan::call('business_entities:milestone_reminder');
        else if($id == 8)
            Artisan::call('send:chartEmail');
       

        $response['message'] = 'Process Completed';

        return response()->json($response, 200);
    }

    public function addProfileImage(Request $request)
    {
        parse_str($request['data'], $data);

        $fileOriginalName = $_FILES['file']['name'];
        $fileSize = $_FILES['file']['size'];
        $file = $request->file('file');


        $folder_name = 'public/'.App::environment().'/tmp/';
        //get folder Name if exist
        if($request->filled('folderName')) {
            $folder_name = $request['folderName'];
        }

        if($request->hasFile('file')) {
            //define storage path(e.g. local)
            $storagePath = $folder_name;
            $fileExtension = $file->extension();
            if($fileExtension == "jpeg") {
                $fileExtension = "jpg";
            }
            //generate random name for physical storage
            $physicalFileName = str_random(8) . '-' . time() . '.' . $fileExtension;

            if($filePath = $file->storeAs($storagePath, $physicalFileName,['disk'=>'local'])) {
                $this->cropProfile(Storage::disk('local')->getDriver()->getAdapter()->getPathPrefix().'/'.$filePath);
                return array(
                    'original_name'=>$fileOriginalName,
                    'physical_name'=>$storagePath.$physicalFileName,
                    'display_link'=>Storage::disk('local')->url($storagePath.$physicalFileName),
                    'extension'=>$fileExtension,
                    'size' => $fileSize
                );

            }else "Fail to move file into temrary Folder!";
        }else {
            return 'No file chosen!';
        }
    }

    public function profileMoveToPermanent($image_path,$account_id)
    {
        $new_base_path = 'public/' . App::environment() . '/profile_image/account-' . $account_id;
        //In case directory not exists then creates new one
        if (!Storage::exists($new_base_path)) {
            Storage::makeDirectory($new_base_path, 0777, true, true);
        }
        $splitPath = explode('/', $image_path);
        $fileName = end($splitPath);
        $new_path = $new_base_path . '/' . $fileName;
        Storage::disk('local')->move($image_path, $new_path);
        return $new_path;
    }


    public function removeProfileImage(Request $request)
    {
        if(Storage::delete($request->data['physical_name']))
        {
            $response['data'] = '';
            $response['message'] = 'Profile reverted';
            return response()->json($response, 200);
        }
    }

    public function deleteProfileImage(Request $request)
    {
        $data = UserInfo::where('user_id',$request['id'])->first();
        $data->image = null;
        if(Storage::delete($data['image']))
        {
            $response['data'] = '';
            $response['message'] = 'Profile reverted';
            return response()->json($response, 200);
        }
        $data->save();
    }

    public function userPrefs(Request $request){

        if($request->isMethod('post')){
            $request->user()->userPreference()->update([
                $request->get("key") => $request->get("value", 30),
            ]);
        }
        $pref = $request->user()->userPreference;
        if($pref == null) {
            $request->user()->userPreference()->create([]);
            $pref = $request->user()->userPreference()->first();
        }
        return new UserPreferenceResource($pref);
    }

    private function cropProfile($imagePath) {
        $image = imagecreatefromjpeg($imagePath);

        $thumb_width = 225;
        $thumb_height = 225;

        $width = imagesx($image);
        $height = imagesy($image);

        $original_aspect = $width / $height;
        $thumb_aspect = $thumb_width / $thumb_height;

        if ( $original_aspect >= $thumb_aspect )
        {
            // If image is wider than thumbnail (in aspect ratio sense)
            $new_height = $thumb_height;
            $new_width = $width / ($height / $thumb_height);
        }
        else
        {
            // If the thumbnail is wider than the image
            $new_width = $thumb_width;
            $new_height = $height / ($width / $thumb_width);
        }

        $thumb = imagecreatetruecolor( $thumb_width, $thumb_height );

// Resize and crop
        imagecopyresampled($thumb,
            $image,
            0 - ($new_width - $thumb_width) / 2, // Center the image horizontally
            0 - ($new_height - $thumb_height) / 2, // Center the image vertically
            0, 0,
            $new_width, $new_height,
            $width, $height);
        imagejpeg($thumb, $imagePath, 80);
    }

    //impersonate
    public function impersonate($id)
    {
        $user = User::find($id);

        // Guard against administrator impersonate
        if(!$user->hasRole(Roles::SiteAdmin))
        {
            if ($user->hasRole(Roles::AccountAdmin) || $user->hasRole(Roles::MainAccountAdmin))
            {
                if(Auth::user()->can(Permissions::ImpersonateAdmin))
                {
                    Auth::user()->setImpersonating($user->id);
                }
                else
                {
                    return back()->withError("User don't have necessary permissions!");
                }
            }
            else
            {
                if(Auth::user()->can(Permissions::ImpersonateUser) || Auth::user()->can(Permissions::ImpersonateAdmin))
                {
                    Auth::user()->setImpersonating($user->id);
                }
                else
                {
                    return back()->withError("User don't have necessary permissions!");
                }
            }

        }
        else
        {
            return back()->withError('Impersonate disabled for this user!');
        }

        return redirect()->back();
    }

    public function stop_impersonate()
    {
        Auth::user()->stopImpersonating();

        return redirect()->back('success','Welcome back!');
    }
    
}

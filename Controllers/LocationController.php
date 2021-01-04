<?php

namespace App\Http\Controllers;

use App\Account;
use App\Constants\Permissions;
use App\Http\Requests\CreateLocation;
use App\Http\Resources\LocationCollection;
use App\Location;
use App\User;
use Auth;
use DataTables;
use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LocationController extends Controller
{
    use AdminControllerTrait;

    public function index(Request $request)
    {
        $account = $this->getAccountForRequest(Permissions::LocationEdit, $request);
        if ($account == null) {
            abort(403, 'This action is unauthorized.');
        }

        return view('location/list', compact('account'));
    }

    public function create($account_id)
    {
        $account = $this->getAccountForRequest(Permissions::LocationCreate, null, $account_id);

        if (!$account) {
            abort(403, 'This action is unauthorized.');
        }

        $location = new Location;

        return view('location/create_edit', compact('location', 'account'));
    }

    public function store(CreateLocation $request, $account_id)
    {
        $account = $this->getAccountForRequest(Permissions::LocationCreate, null, $account_id);

        if (!$account) {
            abort(403, 'This action is unauthorized.');
        }

        $locationData = $this->storeUpdateCommon($request, 0, $account->id);

        try {
            $location = Location::create($locationData);
        } catch (QueryException $exception) {
            return back()->withError($exception->getMessage())->withInput();
        } catch (Exception $exception) {
            return back()->withError($exception->getMessage())->withInput();
        }

        return redirect()->route('location.index', ['accountId'=>$account->id]);
    }

    public function edit($location_id)
    {
        $location = Location::withTrashed()->findOrFail($location_id);
        $account = $this->getAccountForRequest(Permissions::LocationEdit, null, $location->account_id);

        if (!$account && !Auth::user()->hasLocationPermission(Permissions::LocationEdit, $location_id)) {
            abort(403, 'This action is unauthorized.');
        }

        return view('location/create_edit', compact('location', 'account'));
    }

    public function update(CreateLocation $request, $location_id)
    {
        $location = Location::withTrashed()->findOrFail($location_id);
        $account = $this->getAccountForRequest(Permissions::LocationEdit, null, $location->account_id);

        if (!$account && !Auth::user()->hasLocationPermission(Permissions::LocationEdit, $location_id)) {
            abort(403, 'This action is unauthorized.');
        }

        $locationData = $this->storeUpdateCommon($request, $location_id, $location->account_id);

        $location->update($locationData);

        return redirect()->route('location.index', ['accountId'=>$location->account_id]);
    }

    public function getLocationUsers(Request $request)
    {
        $locations_array = collect($request->locations_array);
        $permission = $request->permission;

        if ($locations_array->isEmpty()) {
            $data = 'empty';
        } else {
            $users = collect(usersWithPermission($permission, null, $locations_array));
            $data = $users;
        }

        $response['data'] = $data;
        $response['message'] = 'Process Completed';

        return response()->json($response, 200);
    }

    public function getLocationDetails(int $id)
    {
        try {
            $location = Location::findOrFail($id);
        } catch (ModelNotFoundException $exception) {
            return back()->withError($exception->getMessage());
        } catch (Exception $exception) {
            return back()->withError($exception->getMessage());
        }

        return $location;
    }

    public function activateDeactivateLocation(int $location_id)
    {
        try {
            $location = Location::withTrashed()->findOrFail($location_id);
            if(!Auth::user()->hasAccountPermission(Permissions::LocationCreate, $location->account_id, true)){
                abort(403, 'This action is unauthorized.');
            }

            if ($location->deleted_at == null) {
                $location->update(['deleted_at' => now()]);
            } else {
                $location->update(['deleted_at' => null]);
            }
        } catch (Exception $exception) {
            Log::error($exception);
            return back()->withError('An error occurred');
        }

        return redirect()->route('location.index', ['accountId'=>$location->account_id]);
    }

    private function storeUpdateCommon($request, $id, $account_id)
    {
        $locationData = [
            'name' => $request->name,
            'deleted_at' => $request->active == '1' ? null : now(),
            'address' => $request->address,
            'city' => $request->city,
            'state' => $request->state,
            'zip' => $request->zip,
            'phone' => $request->phone,
            'fax' => $request->fax
        ];

        if (!$id) {
            $locationData1 = ['account_id' => $account_id];
            $locationData = array_merge($locationData1, $locationData);
        }

        return $locationData;
    }

    public function allLocations(Request $request)
    {
        $account = $this->getAccountForRequest(Permissions::LocationEdit, $request);
        if ($account == null) {
            abort(403, 'This action is unauthorized.');
        }
        $canSeeTrashed = Auth::user()->hasAccountPermission(Permissions::LocationCreate, $account->id, true);
        $locations = $canSeeTrashed ? Location::withTrashed() : Location::query();
        $locations = $locations->where('account_id', $account->id);
        if (!Auth::user()->hasAccountPermission(Permissions::LocationEdit, $account->id, true)) {
            $locationIds = locationScopeResolver(scopeResolver(Auth::user(), Permissions::LocationEdit));
            $locations = $locations->whereIn('id', $locationIds);
        }

        return DataTables::of($locations)
            ->addColumn('users', function ($location) {
                $users = $this->usersForLocation($location["id"]);
                return view("location/users", ["users" => $users])->render();
            })
            ->addColumn('action', function ($location) use($canSeeTrashed) {
                return view("location/actions", ["location" => $location, "canDelete" => $canSeeTrashed])->render();
            })
            ->rawColumns(['users', 'action'])
            ->setRowClass(function ($location) {
                if ($location->deleted_at) {
                    return 'alert-secondary';
                }
            })
            ->make(true);
    }

    protected function usersForLocation($locationId)
    {
        $data = [];

        $userQuery = User::withLocationOnlyPermission($locationId);
        $users = $userQuery->with('userInfo')->get();
        foreach ($users as $user) {
            $data[] = [
                "user_id" => $user->userInfo["user_id"],
                "full_name" => $user->userInfo["first_name"]." ".$user->userInfo["last_name"],
            ];
        }
        return $data;
    }

    public function locationResourcCollection(Request $request)
    {

        $locations = new Collection();
        $permissions = new Collection();
        $roles = $request->user()->roles()->get();
        foreach ($roles as $role) {
            $permissions = $permissions->concat($role->permissions);
        }
        $permissions = $permissions->concat($request->user()->permissions);
        $locations = $permissions->pluck("name")->map(function ($name, $key) {
            return Auth::user()->allLocation($name);
        });

        if ($locations->count()) {
            return new LocationCollection($locations->flatten()->unique());
        }

        return response()->json(["data" => []], 200);

    }
}

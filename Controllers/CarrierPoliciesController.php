<?php

namespace App\Http\Controllers;

use Auth;
use Exception;
use DataTables;
use Illuminate\Support\Facades\Log;
use PDOException;
use App\Location;
use App\CarrierPolicy;
use App\CarrierPolicyHistory;
use App\CarrierPolicyCoverage;
use App\Constants\Permissions;
use App\Constants\CarrierPolicies;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class CarrierPoliciesController extends Controller
{
    public function __construct()
    {
        $this->middleware(['permission:'.Permissions::Claims])->only(['index', 'allPolicies', 'create', 'store', 'edit', 'update', 
        'destroy', 'hideCarrirerPolicies', 'renewCarrirerPolicies', 'storeRenewCarrirerPolicy']);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return view('carrier_policies/list');
    }

    public function allCarrirerPolicies(Request $request)
    {
        $customFilters = $request->custom;
        $show_hidden = $customFilters['show_hidden'];
        $filter_date_from = $customFilters['filter_date_from'];
        $filter_date_to = $customFilters['filter_date_to'];

        $policies = CarrierPolicy::where(['hidden'=> $show_hidden]);

        if($filter_date_from != null && $filter_date_to != null) {
            $from_date = Carbon::parse($filter_date_from)->toDateTimeString();
            $to_date = Carbon::parse($filter_date_to)->toDateTimeString();

            $policies = $policies->where('effective_date', '<=', $to_date)->where('expiration_date', '>=', $from_date);
        }

        return DataTables::of($policies->get())
            ->addColumn('location', function ($policies) {
                if($policies->has_all_locations == true) {
                    return 'All locations';
                }else {
                    return implode(', ', $policies->locations ? $policies->locations->pluck('name')->toArray() : array());
                }
            })
            ->addColumn('coverages_list', function ($policies) {
                $list = collect($policies->coverage->pluck('name'));
                if($policies->coverages_other != Null)
                $list->push('Other('.$policies->coverages_other.')');
                return implode(', ', $list->toArray());
            })
            ->addColumn('effective_date', function ($policies) {
                return Carbon::parse($policies->effective_date)->format('m/d/Y').' - '.Carbon::parse($policies->expiration_date)->format('m/d/Y');
            })
            ->addColumn('action', function ($policies) {
                $data = $policies;
                $data['edit'] = route('carrier_policies.edit',$policies->id);
                $data['_csrf_token'] = csrf_token();
                return view('carrier_policies/actions', $data)->render();
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
        $locations = Location::locationsByPermission(Auth::user(), Permissions::Claims);
        $carrier_policy = new CarrierPolicy;
        $coverages = CarrierPolicies::getCoverages();
        $covereges_list = array();
        $carrier_locations = array();
        $carrier_locations_with_permission = array();
        $type = 'Create';

        return view('carrier_policies/create_edit', compact('locations', 'carrier_policy', 'coverages', 'covereges_list', 
        'carrier_locations', 'carrier_locations_with_permission', 'type'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $account_id = $request->user()->account_id;
        $newCarrierPolicyId = $this->CreatePolicyCommon($request, $account_id);
        CarrierPolicyHistory::new_carrier_policy_entry(Auth::id(), $newCarrierPolicyId);

        return redirect()->route('carrier_policies.index');
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
    public function edit(int $id)
    {
        $locations = Location::locationsByPermission(Auth::user(), Permissions::Claims);

        try {
            $carrier_policy = CarrierPolicy::findOrFail($id);
            $coverages = CarrierPolicies::getCoverages();
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError('No related carrier policy found. Try later.')->withInput();
        }

        $covereges_list = $carrier_policy->coverage->pluck('name')->toArray(); 
        $carrier_locations = $carrier_policy->locations ? $carrier_policy->locations->pluck('id')->toArray() : array();
        $carrier_locations_with_permission = $carrier_policy->locations ? $carrier_policy->locations->where('pivot.location_can_edit', 1)
        ->pluck('id')->toArray() : array();
        $type = 'Update';

        return view('carrier_policies/create_edit', compact('locations', 'carrier_policy', 'coverages', 'covereges_list', 
        'carrier_locations', 'carrier_locations_with_permission', 'type'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, int $id)
    {
        $old_carrier_policy = null;
        try {
            $old_carrier_policy = CarrierPolicy::with(['locations'])->findOrFail($id);
            if(!$old_carrier_policy) {
                Log::error('Carrier policy not found for ID:'.$id);
                return back()->withError('Carrier policy not found for ID:'.$id)->withInput();
            }
            $policy = CarrierPolicy::findOrFail($id);
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError('No related carrier policy found!')->withInput();
        }

        $policy->update([
            'name' => $request->name,
            'contact_name' => $request->contact_name,
            'email' => isset($request->email_check) ? '' : $request->email,
            'phone' => $request->phone,
            'fax' => $request->fax,
            'policy_number' => $request->policy_number,
            'address' => $request->address,
            'coverages_list' => $request->coverages ? implode(',', $request->coverages) : null,
            'coverages_other' => $request->coverages_other == '1' ? $request->coverages_other_inp : null,
            'effective_date' => $request->effective_date,
            'expiration_date' => $request->expiration_date,
            'has_all_locations' => $request->all_loc_check == '1' ? 1 : 0,
        ]);

        try {
            CarrierPolicyCoverage::syncCarrierPoliciesCovarages($policy, $request->coverages, 'update');
            CarrierPolicy::syncCarrierPoliciesLocations($policy, $request->location_check, $request->get('loc_perm_check', null), 'update');
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError('Something went wrong with carrier policy updating. Try later.')->withInput();
        }

        CarrierPolicyHistory::carrier_policy_updated_entry($request->user(), $policy, $old_carrier_policy);

        return redirect()->route('carrier_policies.index');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy(int $id)
    {
        try {
            $policy = CarrierPolicy::findOrFail($id);
            $policy->delete();
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError('Something went wrong with carrier policy deletion. Try later.')->withInput();
        }

        CarrierPolicyHistory::deleted_update(Auth::id(), $policy->id);

        return redirect()->route('carrier_policies.index');
    }

    public function hideCarrirerPolicies(int $id)
    {
        try {
            $policy = CarrierPolicy::findOrFail($id);
            $policy->update([ 'hidden' => !$policy->hidden ]);
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError('Something went wrong with hidden/unhidden carrier policy. Try later.')->withInput();
        }

        CarrierPolicyHistory::hidden_changed(Auth::id(), $policy->id, $policy->hidden);

        return redirect()->route('carrier_policies.index');
    }

    public function renewCarrirerPolicies(int $id)
    {
        $locations = Location::locationsByPermission(Auth::user(), Permissions::Claims);

        try {
            $carrier_policy = CarrierPolicy::findOrFail($id);
            $coverages = CarrierPolicies::getCoverages();
        }catch (ModelNotFoundException $exception) {
            Log::error($exception->getMessage());
            return back()->withError('No releted record found, Try later')->withInput();
        }

        $covereges_list = explode(',', $carrier_policy->coverages_list);
        $carrier_locations = $carrier_policy->locations ? $carrier_policy->locations->pluck('id')->toArray() : array();
        $carrier_locations_with_permission = $carrier_policy->locations ? $carrier_policy->locations->where('pivot.location_can_edit', 1)->pluck('id')->toArray() : array();
        $type = 'Renew';

        return view('carrier_policies/create_edit', compact('locations', 'carrier_policy', 'coverages', 'covereges_list', 
        'carrier_locations', 'carrier_locations_with_permission', 'type'));
    }

    public function storeRenewCarrirerPolicy(Request $request, int $id)
    {
        try {
            $carrier_policy = CarrierPolicy::findOrFail($id);
            $carrier_policy->update([ 'hidden' => $request->hide_old == '1' ? 1 : 0 ]);
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError('Error in renew policy, Try later')->withInput();
        }

        $newCarrierPolicyId = $this->CreatePolicyCommon($request, $carrier_policy->account_id);
        CarrierPolicyHistory::renew_carrier_policy_entry(Auth::id(), $newCarrierPolicyId);

        return redirect()->route('carrier_policies.index');
    }

    private function CreatePolicyCommon($request, $account_id)
    {
        $carrierPolicyData = [
            'account_id' => $account_id,
            'name' => $request->name,
            'contact_name' => $request->contact_name,
            'email' => isset($request->email_check) ? '' : $request->email,
            'phone' => $request->phone,
            'fax' => $request->fax,
            'policy_number' => $request->policy_number,
            'address' => $request->address,
            'coverages_list' => $request->coverages ? implode(',', $request->coverages) : null,
            'coverages_other' => $request->coverages_other == '1' ? $request->coverages_other_inp : null,
            'effective_date' => $request->effective_date,
            'expiration_date' => $request->expiration_date,
            'has_all_locations' => $request->all_loc_check == '1' ? 1 : 0,
        ];

        try {
            $newCarrierPolicy = CarrierPolicy::create($carrierPolicyData);
            CarrierPolicyCoverage::syncCarrierPoliciesCovarages($newCarrierPolicy, $request->coverages, 'create');
            CarrierPolicy::syncCarrierPoliciesLocations($newCarrierPolicy, $request->location_check, $request->get('loc_perm_check', null), 'create');
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError('Something went wrong with carrier policy creation. Try later.')->withInput();
        }

        return $newCarrierPolicy->id;
    }

    public function carrierPolicyHistory(int $carrier_policy_id)
    {
        $data = '';
        $carrier_policy_history = CarrierPolicyHistory::where('carrier_policy_id', $carrier_policy_id)->get();

        foreach($carrier_policy_history as $history) {
            $data .= '<b>('.localizeDateFormat($history->created_at).') - </b>'.$history->description.'<br>';
        }
        
        $response['data'] = $data;
        $response['message'] = 'Process Completed';

        return response()->json($response, 200);
    }
}

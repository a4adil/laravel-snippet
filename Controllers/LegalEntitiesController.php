<?php

namespace App\Http\Controllers;

use App\LegalEntityHasLocation;
use Auth;
use Exception;
use DataTables;
use Illuminate\Support\Facades\Log;
use PDOException;
use App\Location;
use App\LegalEntityName;
use App\Constants\Permissions;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class LegalEntitiesController extends Controller
{
    public function __construct()
    {
        $this->middleware(['permission:'.Permissions::Certificates]);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return view('legal_entities/list');
    }

    public function allLegalEntities(Request $request)
    {
        $customFilters = $request->custom;
        $show_hidden = $customFilters['show_hidden'];
        $legal_entities = LegalEntityName::where(['hidden' => $show_hidden]);

        return DataTables::of($legal_entities->get())
            ->addColumn('location', function ($legal_entities) {
                if ($legal_entities->has_all_locations) {
                    return 'All locations';
                }
                else{
                    $names = $legal_entities->locations()->pluck('name')->toArray();
                    return implode(', ', $names);
                }
            })
            ->addColumn('action', function ($legal_entities) {
                $data = $legal_entities;
                $data['_csrf_token'] = csrf_token();
                $data['edit'] = route('legal_entities.edit',$legal_entities->id);
                return view('legal_entities/actions', $data)->render();
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
        $legal_entity = new LegalEntityName;
        $all_locations = null;
        $selected_locations = [];
        return view('legal_entities/create_edit', compact('legal_entity', 'selected_locations','all_locations'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $validationResponse = $this->validate($request, [
            'locations' => 'required',
            'name' => 'required|max:255',
        ]);
        $model = new LegalEntityName;
        $locationIds = $request->locations;
        if($locationIds[0] == 'all'){
            $model->has_all_locations = true;
        }
        $model->account_id = $request->user()->account_id;
        $model->hidden = false;
        $model->name = $request->name;

        try {
            $model->save();
            if($locationIds[0] != 'all'){
                foreach ($locationIds as $location_id)
                {$location_ids[]['location_id'] = $location_id;}
                $model->Legal_entity_has_location()->createMany($location_ids);
            }
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError("There's an error in creating legal entity, try later!")->withInput();
        }
        return redirect()->route('legal_entities.index');
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
            $legal_entity = LegalEntityName::findOrFail($id);
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError("No related_record found, try later")->withInput();
        }
        $all_locations = $legal_entity->has_all_locations;
        if($legal_entity->all_locations) {
            $selected_locations = [];
        }else {
            $selected_locations = $legal_entity->Legal_entity_has_location ? $legal_entity->Legal_entity_has_location->pluck('location_id')->toArray() : array();
        }

        return view('legal_entities/create_edit', compact('legal_entity', 'selected_locations','all_locations'));
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
        $validationResponse = $this->validate($request, [
            'locations' => 'required',
            'name' => 'required|max:255',
        ]);
        $model = LegalEntityName::findOrFail($id);
        $locationIds = $request->locations;
        //remove old entries
        $oldEntries = LegalEntityHasLocation::where('legal_entity_name_id', $id);
        $oldEntries->delete();
        $model->has_all_locations = false;

        if($locationIds[0] == 'all'){
            $model->has_all_locations = true;
        }
        $model->name = $request->name;

        try {
            $model->save();
            if($locationIds[0] != 'all'){
                foreach ($locationIds as $location_id)
                {$location_ids[]['location_id'] = $location_id;}
                $model->Legal_entity_has_location()->createMany($location_ids);
            }

        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError("There's an error in updating legal entity, try later!");
        }

        return redirect()->route('legal_entities.index');
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
            $entity = LegalEntityName::findOrFail($id);
            $entity->certificate()->detach();
            $entity->delete();
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError("There's an error in deleting legal entity, try later!");
        }

        return redirect()->route('legal_entities.index');
    }

    public function hideLegalEntities(int $id)
    {
        try {
            $legal_entity = LegalEntityName::findOrFail($id);
            $legal_entity->update([ 'hidden' => !$legal_entity->hidden ]);
        }catch (Exception $exception) {
            Log::error($exception);
            return redirect()->route('legal_entities.index');
        }

        return redirect()->route('legal_entities.index');
    }

    public function ajax_add_legal_entity(Request $request)
    {
        parse_str($request['data'], $data);
        $model = new LegalEntityName;
        $locationIds = $data['locations'];
        if($locationIds[0] == 'all'){
            $model->has_all_locations = true;
        }
        $model->account_id = Auth::user()->account_id;
        $model->hidden = false;
        $model->name = $data['name'];

        try {
            $model->save();
            if ($locationIds[0] != 'all') {
                foreach ($locationIds as $location_id) {
                    $location_ids[]['location_id'] = $location_id;
                }
                $model->Legal_entity_has_location()->createMany($location_ids);
            }
            $response['id'] = $model->id;
            $response['message'] = 'Added successfully!';

            return response()->json($response, 200);
        }
        catch (Exception $exception)
        {
            Log::error($exception);
            $response['message'] = "Fail to add enity!";
            $response['result'] = false;
            return response()->json($response, 200);
        }
    }
}

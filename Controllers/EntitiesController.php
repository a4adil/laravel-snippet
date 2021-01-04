<?php

namespace App\Http\Controllers;

use Auth;
use Exception;
use App\Entity;
use DataTables;
use Illuminate\Support\Facades\Log;
use PDOException;
use App\EntityHistory;
use Illuminate\Http\Request;
use App\Constants\Permissions;
use Illuminate\Support\Collection;
use Illuminate\Database\QueryException;
use App\Http\Resources\EntityCollection;
use App\Http\Requests\CreateEditEntities;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class EntitiesController extends Controller
{
    public function __construct()
    {
        $this->middleware(['permission:'.Permissions::Entities]);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return view('entities/list');
    }

    public function allEntities(Request $request)
    {
        $customFilters = $request->custom;
        $show_hidden = $customFilters['show_hidden'];

        $entities = Entity::with([ 'items' => function($query) { 
            $query->with(['milestones']); 
        }])->where(['hidden'=> $show_hidden]);

        return DataTables::of($entities->get())
            ->addColumn('location', function ($entity) {
                if($entity->has_all_locations == 1) {
                    return 'All locations';
                }else {
                    return implode(', ', $entity->locations ? $entity->locations->pluck('name')->toArray() : array());
                }
            })
            ->addColumn('milestone', function ($entity) {
                $all_milestones = array();
                $items = $entity->items;
                
                foreach($items as $item) {
                    $milestone = $item->milestones->isNotEmpty() ? $item->milestones->sortBy('milestone_date')->first()->toArray() : array();
                    $milestone ? array_push($all_milestones, $milestone) : '';
                }

                $new_collection = collect($all_milestones)->map(function($row) {
                    return collect($row);
                });

                $closest_milestone = $new_collection->sortBy('milestone_date')->first();
                return $closest_milestone ? $closest_milestone['description'].' on '.localizeDateFormat($closest_milestone['milestone_date'], 'Y-m-d') : '';
            })
            ->addColumn('action', function ($entity) use ($request) {
                $data['id'] = null;
                $data['hiddenText'] = 'Hide'; 
                if($request->user()->hasAccountPermission(Permissions::Entities)) {
                    $data['id'] = $entity->id;
                }

                if($entity->hidden) {
                    $data['hiddenText'] = 'Un-hide';
                }
                
                return view('entities/partials/actions', $data)->render();
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
        $entity = new Entity;
        $locations_array = array();

        return view('entities/create_edit', compact('entity', 'locations_array'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(CreateEditEntities $request)
    {
        $entityData = $this->storeUpdateCommon($request, 0);

        try {
            $newEntity = Entity::create($entityData);
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError("There's an error in creating entry, try later!")->withInput();
        }
        
        $this->syncLocations($request, $newEntity);

        EntityHistory::new_entity_entry(Auth::id(), $newEntity->id);

        return redirect()->route('entity.edit',$newEntity->id);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        try {
            $entity = Entity::findOrFail($id);
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError("No related record found, try later!")->withInput();
        }

        return view('entities/details', compact('entity'));
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
            $entity = Entity::findOrFail($id);
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError("There's an error in retrieving entity, try later! ")->withInput();
        }

        $locations_array = $entity->locations ? $entity->locations->pluck('id')->toArray() : array();
    
        return view('entities/create_edit', compact('entity', 'locations_array'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(CreateEditEntities $request, $id)
    {
        $old_entity = null;

        try {
            $old_entity = Entity::with(['locations'])->where('id', $id)->first();

            if(!$old_entity)
            {
                Log::error('Entity Not found for ID:'.$id);
                return back()->withError('Entity Not found for ID:'.$id)->withInput();
            }

            $entity = Entity::findOrFail($id);
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError("There's somthing went wrong in updation, try later!")->withInput();
        }

        $entityData = $this->storeUpdateCommon($request, $id);

        $entity->update($entityData);

        $this->syncLocations($request, $entity);
        
        EntityHistory::entity_updated_entry($request->user(), $entity, $old_entity);

        return redirect()->route('entity.index');
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
            Entity::findOrFail($id)->delete();
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError("There's an error in deletion, try later!");
        }

        return redirect()->route('entity.index');
    }

    public function hideEntity(int $id)
    {
        try {
            $entity = Entity::findOrFail($id);
            $entity->update([ 'hidden' => !$entity->hidden ]);
        }catch (Exception $exception) {
            Log::error($exception);
            return redirect()->route('entity.index');
        }

        EntityHistory::hidden_changed(Auth::id(), $entity->id, $entity->hidden);

        return redirect()->route('entity.index');
    }

    public function entityHistory(int $entitytId)
    {
        $data = '';
        $entityHistory = EntityHistory::where('entity_id', $entitytId)->get();

        foreach($entityHistory as $history) {
            $data .= '<b>('.localizeDateFormat($history->created_at).') - </b>'.$history->description.'<br>';
        }

        $response['data'] = $data;
        $response['message'] = 'Process Completed';

        return response()->json($response, 200);
    }

    private function storeUpdateCommon($request, $id)
    {
        $entityData = [
            'has_all_locations' => isset($request->locations) ? in_array('all', $request->locations) ? 1 : 0 : 0,
            'name' => $request->name,
        ];

        if(!$id) {
            $account_id = $request->user()->account_id;
            $entityData1 = [ 'account_id' => $account_id ];
            $entityData = array_merge($entityData1, $entityData);
        }

        return $entityData;
    }

    private function syncLocations($request, $entity)
    {
        if($request->has('locations')) {
            if(!$entity->has_all_locations) {
                $entity->locations()->sync($request->locations);
            }else {
                $entity->locations()->detach();
            }
        }
    }

    public function entitieResourcCollection(Request $request){
        
        $entities = Entity::query()->where("hidden", 0);
        $entities->with(["items"=>function($query){
            $query->with("milestones");
        }]);
        return  new EntityCollection($entities->get());
    }
}

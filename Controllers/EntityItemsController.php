<?php

namespace App\Http\Controllers;

use App\Entity;
use Auth;
use App\Note;
use Exception;
use DataTables;
use Illuminate\Support\Facades\Log;
use PDOException;
use App\EntityItem;
use App\FileRelationType;
use App\EntityItemHistory;
use Illuminate\Support\Str;
use App\EntityItemMilestone;
use Illuminate\Http\Request;
use App\Constants\Permissions;
use App\Traits\FileHandlingTrait;
use Illuminate\Database\QueryException;
use App\Http\Requests\CreateEditEntities;
use App\Http\Requests\CreateItemEntities;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class EntityItemsController extends Controller
{
    use FileHandlingTrait;
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        //
    }

    public function allEntityItems(Request $request, $id)
    {
        $items = EntityItem::where('entity_id', $id)->get();

        return DataTables::of($items)
            ->addColumn('milestone', function ($item) {
                $milestone = $item->milestones ? $item->milestones->sortBy('milestone_date')->first() : '';
                return $milestone ? $milestone->description.' on '.localizeDateFormat($milestone->milestone_date, 'Y-m-d') : '';
            })
            ->addColumn('action', function ($item) use ($request, $id) {
                $data['id'] = null;
                $data['entity_id'] = $id;
                if($request->user()->hasAccountPermission(Permissions::Entities)) {
                    $data['id'] = $item->id;
                }
                
                return view('entities/partials/entityItems_actions', $data)->render();
            })
            ->make(true);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create($entity_id)
    {
        $item = new EntityItem;

        $entity = Entity::findOrFail($entity_id);

        $milestones = array();

        $milestoneCount = 0;

        return view('entities_items/create_edit', compact('entity', 'item', 'milestones', 'milestoneCount'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(CreateItemEntities $request, $entity_id)
    {
        $entityItemData = $this->storeUpdateCommon($request, 0, $entity_id);

        try {
            $newEntityItem = EntityItem::create($entityItemData);
            //save file against Entity Item id
            if($request->filled('temp_file')) {
                $this->move_file_to_directory($request->temp_file, 'entityitems', $request->user()->account_id, $newEntityItem->id);
            }
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError("There's an error in creating enitity item, try later!")->withInput();
        }

        //notes section
        if($request->filled('note.new')) {
            $newEntityItem->notes()->createMany($request->note['new']);
        }
        $this->storeUpdateMilestoneCommon($request, $newEntityItem, null);

        return redirect()->route('entity.edit', $entity_id);
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
    public function edit($entity_id, $item_id)
    {
        try {
            $item = EntityItem::findOrFail($item_id);
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError("No related record found, try later!")->withInput();
        }

        $entity = $item->entity;

        $milestones = $item->milestones;

        $users = collect(usersWithPermission('location.entities', null, null));

        $milestoneCount = 0;
        //get related_notes
        $notes = $item->notes;

        return view('entities_items/create_edit', compact('entity', 'item', 'milestones', 'users', 'milestoneCount','notes'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(CreateItemEntities $request, $entity_id, $item_id)
    {
        $old_entity_item = null;

        try {
            $old_entity_item = EntityItem::with([
                'milestones' => function($query) { $query->with(['recipients', 'remindDays', 'remindUsers']); }
            ])->where('id', $item_id)->first();

            if(!$old_entity_item)
            {
                Log::error('Entity Not found for ID:'.$item_id);
                return back()->withError('Entity Not found for ID:'.$item_id)->withInput();
            }

            $entityItem = EntityItem::findOrFail($item_id);
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError("There's an error in updating item, try later!")->withInput();
        }

        $entityItemData = $this->storeUpdateCommon($request, $item_id, $entity_id);

        //Update file against Entity Item id
        if($request->filled('temp_file')) {
            $this->move_file_to_directory($request->temp_file, 'entityitems', $request->user()->account_id, $old_entity_item->id);
        }

        $entityItem->update($entityItemData);

        $this->storeUpdateMilestoneCommon($request, $entityItem, $old_entity_item);

        //notes section
        //removing notes from claims_notes table
        if($request->filled('note.deleted')) {
            foreach($request->note["deleted"] as $note){
                $note = Note::findOrFail($note);
                $note->delete();
            }
        }

        //insert records for notes
        if($request->filled('note.new')) {
            $entityItem->notes()->createMany($request->note['new']);
        }

        //update notes from claims_notes table
        if($request->filled('note.updated')) {
            foreach($request->note['updated'] as $key => $value) {
                Note::where('id', $key)->update(['subject' => $value['subject'], 'description' => $value['description']]);
            }
        }

        return redirect()->route('entity.edit', $entity_id);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request)
    {
        try {
            $item = EntityItem::findOrFail($request->id);
            $item->history()->delete();
            $milestones = $item->milestones;
            foreach($milestones as $milestone) {
                $delete_milestone = EntityItemMilestone::findORFail($milestone->id);
                $delete_milestone->recipients()->delete();
                $delete_milestone->remindDays()->delete();
                $delete_milestone->remindUsers()->detach();
                $delete_milestone->delete();
            }
            $item->delete();
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError("There's an error in deletion, try later!");
        }

        $response['message'] = 'Deleted successfully!';
        return response()->json($response, 200);
    }

    public function entityItemHistory(int $itemId)
    {
        $data = '';
        $entityItemHistory = EntityItemHistory::where('entity_item_id', $itemId)->get();

        foreach($entityItemHistory as $history) {
            $data .= '<b>('.localizeDateFormat($history->created_at).') - </b>'.$history->description.'<br>';
        }

        $response['data'] = $data;
        $response['message'] = 'Process Completed';

        return response()->json($response, 200);
    }

    private function storeUpdateCommon($request, $id, $entity_id)
    {
        $entityItemData = [
            'name' => $request->name,
            'details' => $request->details,
            'effective_date' => $request->effective_date,
        ];

        if(!$id) {
            $entityItemData1 = [ 'entity_id' => $entity_id ];
            $entityItemData = array_merge($entityItemData1, $entityItemData);
        }

        return $entityItemData;
    }

    public function load_milestone_view(Request $request)
    {
        $milestoneId = $request->count;
        $data = null;
        $remindDays = null;
        $users = collect(usersWithPermission('location.entities', null, null));
        $remindUsers = null;
        $additionalEmails = array();

        $view = view('entities_items.partials.milestone', compact('milestoneId', 'data', 'remindDays', 'users', 'remindUsers', 
        'additionalEmails'))->render();

        $response['data'] = $view;
        $response['message'] = 'Process Completed';

        return response()->json($response, 200);
    }

    public function load_email_view(Request $request)
    {
        $milestoneId = $request->milestoneId;
        $email_count = $request->count;
        $email = null;

        $view = view('entities_items.partials.email', compact('milestoneId', 'email_count', 'email'))->render();

        $response['data'] = $view;
        $response['message'] = 'Process Completed';

        return response()->json($response, 200);
    }

    public function storeUpdateMilestoneCommon($request, EntityItem $entityItem, ? EntityItem $old_entity_item)
    {
        $milestones_tobe_deleted = EntityItemMilestone::where('entity_item_id', $entityItem->id)
            ->whereNotIn('id', $request->input('milestone.*.hidden_id', [0]))
            ->get();

        try {
            foreach($milestones_tobe_deleted as $delete_milestone) {
                $delete = EntityItemMilestone::findORFail($delete_milestone->id);
                $delete->recipients()->delete();
                $delete->remindDays()->delete();
                $delete->remindUsers()->detach();
                EntityItemHistory::create_entity_item_milestone_delete_entry(Auth::id(), $delete->entity_item_id, 'Milestone Deleted');
                $delete->delete();                
            }
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withErrors("There's an error in updating milestone, try later!");
        }

        if($request->has('milestone')) {
            $milestones = $request->get('milestone');
            foreach($milestones as $milestone_data) {
                try {
                    if(isset($milestone_data['hidden_id'])) {
                        $milestone = EntityItemMilestone::findORFail((int) $milestone_data['hidden_id']);
                        $milestone->update([
                            'description' => $milestone_data['description'],
                            'milestone_date' => $milestone_data['date']
                        ]);
                    }else {
                        $milestone = new EntityItemMilestone;
                        $milestone->description = $milestone_data['description'];
                        $milestone->milestone_date = $milestone_data['date'];
                        $milestone->entityItem()->associate($entityItem);
                        $milestone->save();

                        EntityItemHistory::create_entity_item_milestone_add_entry(Auth::id(), $entityItem->id, 'New Milestone created');
                    }
                }catch (Exception $exception) {
                    Log::error($exception);
                    return back()->withErrors("There's an error in entity item, try later!");
                }

                if(!empty($milestone_data['remind_days'])) {
                    $milestone->remindDays()->delete();
                    foreach($milestone_data['remind_days'] as $day) {
                        $milestone->remindDays()->create(['days' => $day]);
                    }
                }

                if(!empty($milestone_data['users'])) {
                    $milestone->remindUsers()->sync($milestone_data['users']);
                }

                if(!empty($milestone_data['additional_recipient'])) {
                    $milestone->recipients()->delete();
                    foreach($milestone_data['additional_recipient'] as $additional_recipient) {
                        $milestone->recipients()->create(['email' => $additional_recipient]);
                    }
                }
            }
        }

        if(!$old_entity_item) {
            EntityItemHistory::new_entity_item_entry(Auth::id(), $entityItem->id);
        }else {
            EntityItemHistory::entity_item_updated_entry($request->user(), $entityItem, $old_entity_item);
        }
    }
    public function load_notes_view(Request $request)
    {
        $elementCountNum = Str::random(16);
        $sort = $request->sort;

        $view = view('partial_views.notes', compact('elementCountNum', 'sort'))->render();

        $response['data'] = $view;
        $response['message'] = 'Process Completed';

        return response()->json($response, 200);
    }

    public function view_note($id)
    {
        try {
            //to validate scoperResolver find claim
            $item = EntityItem::whereHas('notes', function ($query) use ($id) {
                $query->where('id', $id);
            })->first();
            if (empty($item)) {
                Log::error("There is an error in finding note of item id".$id);
                return back()->withError("There is an error in finding note of item id".$id);
            }
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError("There's an error in note view, try later!")->withInput();
        }

        $response['data'] = Note::findOrFail($id);
        $response['message'] = 'Process Completed';

        return response()->json($response, 200);
    }

    //validate files datatable permission
    public function data_table(Request $request)
    {
        if($request->id){
            //to validate scoperResolver find Certificate
            $item = EntityItem::find($request->id);
            if(empty($item)){
                Log::error("There is an error in finding files of item id".$request->id);
                return back()->withError("There is an error in finding files of item id".$request->id);
            }
        }
        return $this->generic_data_table($request);
    }

    public function delete_files($id)
    {
        if($id){
            //to validate scoperResolver find Certificate
            $file = FileRelationType::where('file_id',$id)->first();
            $model_accessed = EntityItem::findOrFail($file->model_id);
            if(empty($model_accessed)){
                Log::error("There is an error in deleting files, try later!");
                return back()->withError("There is an error in deleting files, try later!");
            }
            return $this->generic_delete_files($id);
        }
    }
}

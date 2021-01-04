<?php

namespace App\Http\Controllers;

use Auth;
use Exception;
use DataTables;
use App\Location;
use App\Recipients;
use App\RecipientHistory;
use App\RecipientLocationNotifications;
use App\Constants\Permissions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Http\Request;


class RecipientsController extends Controller
{
    private $_model = 'App\Recipient';
    private $_moduleTitle = 'Recipients';
    private $_moduleName = 'recipients';

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $data = array();
        $data['_moduleName'] = $this->_moduleName;
        $data['title'] = $this->_moduleTitle;


        $locationsArray = array();
        $locations = Location::locationsByPermission(Auth::user(), Permissions::Claims);
        $locationsArray[''] = 'All Locations';
        foreach($locations as $location) {
            $locationsArray[$location['id']] = $location['name'];
        }
        $data['locations'] = $locationsArray;

        return view($this->_moduleName.'/list')->with('data', $data);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $data = array();
        $data['_moduleName'] = $this->_moduleName;
        $data['title'] = $this->_moduleTitle;

        $data['current_page_header'] = 'Create'.' '.Str::singular($this->_moduleTitle);
        $data['locations'] = Location::locationsByPermission(Auth::user(), Permissions::Claims);

        return view($this->_moduleName.'/create')->with('data', $data);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        // dd($request->all());
        $validationResponse = $this->validate($request, [
            'name' => 'required|max:255',
            'contact_name' => 'max:255',
            'email' => 'required|unique:recipients,email,NULL,id,deleted_at,NULL|max:255',
            'location_check' => ['required', function ($attribute, $value, $fail){
                if (empty($value)){
                    $fail('At least one location/permission is required.');
                }
            }]
        ], ['location_check.required' => 'At least one location/permission is required.']);

        //initializing model of the module
        $user = Auth::User();
        $model = new $this->_model;

        $model->account_id = $user['account_id'];
        $model->name = $request->name;
        $model->contact_name = $request->contact_name;
        $model->email = $request->email;

        if($request->has('location_check'))
        {
            foreach ($request->location_check as $location)
            {
                $tempArr['location_id'] = $location;
                $recipientLocNoti[$location] = $tempArr;
            }
        }else{

        }

        //location selection option is selected
        if($request->has('all_loc_check'))
        {
            $model->has_all_locations = true;
        }
        //notify update option is selected
        if($request->has('update_all_loc_check'))
        {
            $model->notify_all_updates = true;
        }
        if ($request->filled('update_loc_check'))
        {
            //if selected notify updates is checked
            //location_check
            foreach ($request->update_loc_check as $updateLoc)
            {
                $recipientLocNoti[$updateLoc]['notify_update_location'] = true;
            }

        }

        //notify save option is selected
        if($request->has('save_all_loc_check'))
        {
            $model->notify_all_saves = true;
        }
        if($request->filled('save_loc_check'))
        {
            //if selected notify save is checked
            foreach ($request->save_loc_check as $updateLoc)
            {
                $recipientLocNoti[$updateLoc]['notify_save_location'] = true;
            }

        }
        //notify submission option is selected
        if($request->has('submission_all_loc_check'))
        {
            $model->notify_all_submissions = true;
        }
        if($request->filled('submission_loc_check'))
        {
            //if selected notify submission is checked
            foreach ($request->submission_loc_check as $updateLoc)
            {
                $recipientLocNoti[$updateLoc]['notify_submission_location'] = true;
            }
        }
        if($request->has('edit_loc_check'))
        {
            foreach ($request->edit_loc_check as $updateLoc)
            {
                $recipientLocNoti[$updateLoc]['edit_location'] = true;
            }
        }

        try {
            $model->save();
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError("There's an error in creating recipient, try later")->withInput();
        }

        //saving data in relational table
        if($request->has('location_check'))
        {
            $model->RecipientLocationNotifications()->createMany($recipientLocNoti);
        }

        $changedVal['description'] = '<b>Recipient created!</b>';
        $changedVal['recipient_id'] = $model->id;
        $changedVal['user_id'] = Auth::id();
        RecipientHistory::create($changedVal);

        return redirect()->route($this->_moduleName.'.index')->withSuccess($this->_moduleTitle.' '.'created successfully!');
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Recipients  $recipients
     * @return \Illuminate\Http\Response
     */
    public function show(Recipients $recipients)
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
            $data =  $this->_model::findOrFail($id);
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError("No related Record found, try later")->withInput();
        }

        $relational_data = $data->RecipientLocationNotifications;

        $data['_moduleName'] = $this->_moduleName;
        $data['title'] = $this->_moduleTitle;
        $data['current_page_header'] = 'Update'.' '.Str::singular($this->_moduleTitle);

        $data['locations'] = Location::locationsByPermission(Auth::user(), Permissions::Claims);

        $location_ids = array();
        $location_data = array();

        foreach ($relational_data as $location) {
            array_push($location_ids, $location->location_id);
            array_push($location_data, $location);
        }

        $data['relation'] = array_combine($location_ids, $location_data);

        return view($this->_moduleName.'/create')->with('data', $data);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Recipients  $recipients
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $validationResponse = $this->validate($request, [
            'name' => 'required|max:255',
            'contact_name' => 'max:255',
            'email' => 'required|max:255'
        ]);

        try {
            $oldData = $model = $this->_model::findOrFail($id);
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError("No related record found, try later!")->withInput();
        }
        $old_relation = $oldData->RecipientLocationNotifications;

        $model->name = $request->name;
        $model->contact_name = $request->contact_name;
        $model->email = $request->email;

        //Find and delete old entries
        $recipientNotification = RecipientLocationNotifications::where('recipient_id',$id);
        $recipientNotification->delete();

        //location checks
        if($request->has('location_check')) {
            foreach ($request->location_check as $location) {
                $tempArr['location_id'] = $location;
                $recipientLocNoti[$location] = $tempArr;
            }
        }

        //location selection option is selected
        if($request->has('all_loc_check'))
        {
            $model->has_all_locations = true;
        }
        else
        {
            $model->has_all_locations = false;
        }

        //notify update option is selected
        if($request->has('update_all_loc_check'))
        {
            $model->notify_all_updates = true;
        }
        if ($request->filled('update_loc_check'))
        {
            //if selected notify updates is checked
            //location_check
            foreach ($request->update_loc_check as $updateLoc)
            {
                $recipientLocNoti[$updateLoc]['notify_update_location'] = true;
            }

        }
        if (!$request->has('update_all_loc_check'))
        {
            $model->notify_all_updates = false;
        }
        //notify save option is selected
        if($request->has('save_all_loc_check'))
        {
            $model->notify_all_saves = true;
        }
        if($request->filled('save_loc_check'))
        {
            //if selected notify save is checked
            foreach ($request->save_loc_check as $updateLoc)
            {
                $samp['notify_save_location'] = $updateLoc;
                $recipientLocNoti[$updateLoc]['notify_save_location'] = true;
            }

        }
        if (!$request->has('save_all_loc_check'))
        {
            $model->notify_all_saves = false;
        }

        //notify submission option is selected
        if($request->has('submission_all_loc_check'))
        {
            $model->notify_all_submissions = true;
        }
        if($request->filled('submission_loc_check'))
        {
            //if selected notify submission is checked
            foreach ($request->submission_loc_check as $updateLoc)
            {
                $recipientLocNoti[$updateLoc]['notify_submission_location'] = true;
            }
        }
        if (!$request->has('submission_all_loc_check'))
        {
            $model->notify_all_submissions = false;
        }
        if($request->has('edit_loc_check'))
        {
            foreach ($request->edit_loc_check as $updateLoc)
            {
                $recipientLocNoti[$updateLoc]['edit_location'] = true;
            }
        }

        //Record history Section Begin
        $history = $this->save_history($oldData);
        //record History Section end

        try{
            //saving Data
            $model->save();
            //saving data in relational table
            if($request->has('location_check')) {
                $model->RecipientLocationNotifications()->createMany($recipientLocNoti);
            }
        }catch (Exception $exception)
        {
            Log::error($exception);
            return back()->withError("There's an error in updating record, try later!")->withInput();
        }

        return redirect()->route($this->_moduleName.'.index')->withSuccess($this->_moduleTitle.' '.'updated successfully!');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Recipients  $recipients
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request)
    {
        try {
            $model = $this->_model::findOrFail($request->id);
            $model->delete();
        }catch (Exception $exception) {
            Log::error($exception);
            $response['message'] = 'There is an error in recipient deletion!';
            return response()->json($response, 200);
        }

        $response['message'] = 'Recipient deleted successfully!';
        return response()->json($response, 200);
    }

    // data table
    public function data_table(Request $request)
    {
        $account_id = auth()->user()->account_id;
        $customFilters = $request->custom;
        $status = $customFilters['show_hidden'];
        $whereArr = array('hidden' => $status, 'account_id' => $account_id);

        $tableData = $this->_model::where($whereArr)->get();

        //apply filter in case of location
        if($customFilters['location'] && $customFilters['location'] !== '') {
            $locationId = $customFilters['location'];
            $tableData = $this->_model::whereHas('RecipientLocationNotifications', function ($query) use ($locationId) {
                $query->where('location_id', '=', $locationId);
            })->where($whereArr)->get();
        }

        return DataTables::of($tableData)
            ->addColumn('action', function ($tableData) {
                $optionData = $tableData;
                $optionData['edit'] = route($this->_moduleName.'.edit', $tableData['id']);
                $optionData['delete'] = route($this->_moduleName.'.destroy', $tableData['id']);
                return view($this->_moduleName.'.actions',$optionData)->render();
            })
            ->make(true);
    }

    // Show/Hide Record
    public function hide_show_record(Request $request)
    {
        $id = $request['id'];
        try {
            $recipient = $this->_model::findOrFail($id);
            $recipient->update([ 'hidden' => !$recipient->hidden ]);
        }catch (Exception $exception) {
            Log::error($exception);
            return redirect()->route('legal_entities.index');
        }

        $changedVal['description'] = '<b>Recipient ' . ($recipient->hidden ? 'Hidden' : 'Unhidden') . '</b>';
        $changedVal['recipient_id'] = $id;
        $changedVal['user_id'] = Auth::id();
        RecipientHistory::create($changedVal);
        //save history Ends

        if($recipient) {
            $response['message'] = 'Updated Successfully!';
            return response()->json($response, 200);
        }

        $response['message'] = 'Fail to update!';
        return response()->json($response, 200);
    }

    private function save_history($data)
    {
        $originalData = $data->getOriginal();
        $modifiedData = $data->getAttributes();
        $keys = array_keys($originalData);
        $changedVal = array();

        foreach($keys as $key) {
            if($originalData[$key] != $modifiedData[$key]) {
                $description = ucfirst($key).' <b>Changed From:</b> '.$originalData[$key].' <b>to:</b> '.$modifiedData[$key];

                if($originalData[$key] == 1) {
                    $originalData[$key] = true;
                }else {
                    $originalData[$key] = false;
                }

                //location history
                if($key == 'has_all_locations') {
                    $description = 'All Location turned off!';
                    if($data->has_all_locations == true) {
                        $description = 'All Location turned on!';
                    }
                }

                //All Updates check history
                if($key == 'notify_all_updates') {
                    $description = 'Recipient will not be copied on all claims';
                    if($data->notify_all_updates == true) {
                        $description = 'Recipient will be copied on all claims';
                    }
                }

                //All submission history
                if($key == 'notify_all_saves') {
                    $description = 'Recipient will be copied on all claims will not be copied on all initial save';
                    if($data->notify_all_saves == true) {
                        $description = 'Recipient will not be copied on all claims will be copied on all initial save';
                    }
                }

                //All submission history
                if($key == 'notify_all_submissions') {
                    $description = 'Recipient will be copied on all claims will not be copied on all submission';
                    if($data->notify_all_submissions == true) {
                        $description = 'Recipient will not be copied on all claims will be copied on all submission';
                    }
                }

                $changedVal['description'] = $description;
                $changedVal['recipient_id'] = $originalData['id'];
                $changedVal['user_id'] = Auth::id();
                RecipientHistory::create($changedVal);
            }
        }
    }
    
    public function view_history(Request $request)
    {
        $data = RecipientHistory::where('recipient_id', $request->id)->get();
        
        $response['data'] = $data;
        $response['message'] = 'Process Completed';

        return response()->json($response, 200);
    }

}

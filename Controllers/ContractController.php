<?php

namespace App\Http\Controllers;

use Auth;
use Illuminate\Support\Facades\Log;
use Storage;
use App\File;
use Exception;
use DataTables;
use App\Contract;
use App\ContractsHistory;
use App\FileRelationType;
use App\SentEmailHistory;
use App\ContractMilestone;
use App\Constants\Contracts;
use Illuminate\Http\Request;
use App\Constants\Permissions;
use Illuminate\Support\Carbon;
use App\ContractExpiryRemindUser;
use App\Traits\FileHandlingTrait; 
use App\ContractMilestoneRemindDay;
use App\Traits\GenerateExcelReport;
use App\ContractMilestoneRemindUser;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use App\ContractExpiryAdditionalRecipient;
use App\Http\Resources\ContractCollection;
use App\Http\Requests\ContractStoreRequest;
use App\ContractMilestoneAdditionalRecipient;

class ContractController extends Controller
{
    use GenerateExcelReport;
    
    public function __construct()
    {
        $this->middleware(['permission:'.Permissions::Contracts]);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    use FileHandlingTrait;
    public function index()
    {
        return view('contracts/list');
    }

    public function allContracts(Request $request)
    {
        $customFilters = $request->custom;
        $show_hidden = $customFilters['show_hidden'];
        $filter_date_from = $customFilters['filter_date_from'];
        $filter_date_to = $customFilters['filter_date_to'];

        $contracts = Contract::where(['hidden'=> $show_hidden]);

        if($filter_date_from != null && $filter_date_to != null) {
            $from_date = Carbon::parse($filter_date_from)->toDateTimeString();
            $to_date = Carbon::parse($filter_date_to)->toDateTimeString();

            $contracts = $contracts->whereBetween('expiration_date', [$from_date, $to_date]);
        }

        return DataTables::of($contracts->get())
            ->addColumn('location', function ($contract) {;
                if($contract->has_all_locations == 1) {
                    return 'All locations';
                }else {
                    return implode(', ', $contract->locations ? $contract->locations->pluck('name')->toArray() : array());
                }
            })
            ->addColumn('active_contract_file', function ($contract) {
                $files = $this->get_related_files($contract->id,'contracts',true);
                if(sizeof($files)> 0)
                {
                    return [
                        "name" => $files[0]->name,
                        "url" => route('contract.file.download', ['id' => $files[0]->id, 'view' => 'true'])
                    ];
                }
                return null;
            })
            ->addColumn('action', function ($contract) use ($request) {
                $data['hidden'] = $contract->hidden;
                if($contract->location_can_edit || $request->user()->hasAccountPermission(Permissions::Contracts)) {
                    $data['id'] = $contract->id;
                }
                return view('contracts/actions', $data)->render();
            })
            ->setRowAttr([
                'class' => function($contract) {
                    $files = $this->get_related_files($contract->id,'contracts',true);
                    if($contract->expiration_date < date_format(now(),'Y-m-d') || sizeof($files)== 0)
                    {
                        return "alert-warning";
                    }
                    return '';
                },
            ])
            ->make(true);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $contract = new Contract;
        $locations_array = array();
        $status = Contracts::getContractStatus();
        $transition_status = Contracts::getContractTransitionStatus();
        $expiry_notify_led_days = Contracts::getExpirationNotifyLedDays();

        return view('contracts/create_edit', compact('contract', 'status', 'transition_status', 'locations_array',
        'expiry_notify_led_days'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(ContractStoreRequest $request)
    {
        $contractData = $this->storeUpdateCommon($request, 0);

        try {
            $newContract = Contract::create($contractData);
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError("Something went wrong with contract creation. Try later.")->withInput();
        }

        $this->storeUpdateRelatedCommons($request, $newContract, null);
        
        return redirect()->route('contract.index');
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
            $contract = Contract::findOrFail($id);
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError("No related record found, Try later!")->withInput();
        }

        $locations_array = array();
        if($contract->has_all_locations == 1) {
            array_push($locations_array, 'All locations');
        }else {
            foreach($contract->locations as $location) {
                array_push($locations_array, $location->name);
            }
        }

        return view('contracts/details', compact('contract', 'locations_array'));
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
            $contract = Contract::findOrFail($id);
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError("No related record found, try later!")->withInput();
        }

        $locations_array = $contract->locations ? $contract->locations->pluck('id')->toArray() : array();
    
        $status = Contracts::getContractStatus();
        $transition_status = Contracts::getContractTransitionStatus();
        $expiry_notify_led_days = Contracts::getExpirationNotifyLedDays();

        return view('contracts/create_edit', compact('contract', 'status', 'transition_status', 'locations_array', 
        'expiry_notify_led_days'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(ContractStoreRequest $request, $id)
    {
        $old_contract = null;

        try {
            $old_contract = Contract::with([
                'locations', 
                'milestone' => function($query) { $query->with(['recipient', 'remindDay', 'remindUser']); },
                'expiryAdditionalRecipient',
                'expiryRemindUsers',
            ])->where('id', $id)->first();

            if(!$old_contract) {
                Log::error('Contract Not found for ID:' . $id);
                return back()->withError('Contract Not found for ID:' . $id);
            }

            $contract = Contract::findOrFail($id);
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError("Unable to find related record. Try later.")->withInput();
        }

        $contractData = $this->storeUpdateCommon($request, $contract);

        $contract->update($contractData);

        $this->storeUpdateRelatedCommons($request, $contract, $old_contract);

        return redirect()->route('contract.index');
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
            Contract::findOrFail($id)->delete();
        }catch (Exception $exception) {
            Log::error($exception);
            return response()->json(['data'=> $exception->getMessage()], 500);
        }

        return response()->json(['data'=> 'Deleted'], 200);
    }

    public function hideContract(int $id)
    {
        try {
            $contract = Contract::findOrFail($id);
            $contract->update([ 'hidden' => !$contract->hidden ]);
            ContractsHistory::hidden_changed(Auth::id(), $id, $contract->hidden);
        }catch (Exception $exception) {
            Log::error($exception);
            return redirect()->route('contract.index');
        }

        return redirect()->route('contract.index');
    }

    public function getExpiryRecipient(Request $request)
    {
        try {
            $data = Contract::with('expiryAdditionalRecipient')->findOrFail($request->get('id', null));
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError("No related record found, Try later!");
        }

        $response['data'] = $data;
        $response['message'] = 'Process Completed';

        return response()->json($response, 200);
    }

    public function getExpiryUsers(Request $request)
    {
        $data = ContractExpiryRemindUser::where('contract_id', $request->get('id', null))->get();

        $response['data'] = $data;
        $response['message'] = 'Process Completed';

        return response()->json($response, 200);
    }

    public function getContractMilestones(Request $request)
    {
        try {
            $data = Contract::with(['milestone' => function ($query) {
                $query->with('recipient');
                $query->with('remindDay');
                $query->with('remindUser');
            }])->findOrFail($request->get('id', null));
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError('No related record found, Try later!');
        }

        $response['data'] = $data;
        $response['message'] = 'Process Completed';

        return response()->json($response, 200);
    }

    public function getMilestoneEmailUsers(Request $request)
    {
        return ContractMilestoneRemindUser::where('contract_milestone_id', $request->get('id', null))->get();
    }

    public function contractHistory(int $contractId)
    {
        $data = '';
        $contractHistory = ContractsHistory::where('contract_id', $contractId)->get();

        foreach($contractHistory as $history) {
            $data .= '<b>('.localizeDateFormat($history->created_at).') - </b>'.$history->description.'<br>';
        }

        $response['data'] = $data;
        $response['message'] = 'Process Completed';

        return response()->json($response, 200);
    }

    public function contractEmailHistory(int $contractId)
    {
        $contractEmailHistory = SentEmailHistory::where('contract_id', $contractId)->get();
        $html = '<table class="table table-bordered table-sm"> <thead class="thead-dark"><tr>';
        $html .= '<th>To</th><th>Subject</th><th>Date</th><th>Status</th><th>Attachments</th><th>Action</th>';
        $html .= '</tr><thead><tbody>';

        foreach($contractEmailHistory as $history) {
            $html .= '<tr>';
            $html .= '<td>'.$history->email.'</td>';
            $html .= '<td>'.$history->description.'</td>';
            $html .= '<td>'.localizeDateFormat($history->date).'</td>';
            $html .= '<td>'.$history->status.'</td>';
            $html .= '<td>'.$history->file_name.'</td>';
            $html .= '<td>'."<button class=' btn btn-link j-email-view' data-id='".$history->id."'>View Email</button>".'</td>';
            $html .= '</tr>';

        }
        $html .= '</tbody><table>';

        $response['data'] = $html;
        $response['message'] = 'Process Completed';

        return response()->json($response, 200);
    }

    public function contractEmailHtml(int $emailhistoryId)
    {
        try {
            $contractEmailHistory = SentEmailHistory::findOrFail($emailhistoryId);
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError("No email history found for related record, try later!");
        }

        $response['data'] = $contractEmailHistory->html;
        $response['message'] = 'Process Completed';

        return response()->json($response, 200);
    }

    public function generateContractReport()
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $this->generateCliamsReportSheet($spreadsheet, 'Contracts', 'H');

        // Adding header data
        $sheet->setCellValue('A1', 'Location');
        $sheet->setCellValue('B1', 'Contracted Party');
        $sheet->setCellValue('C1', 'Contact Name');
        $sheet->setCellValue('D1', 'Effective Date');
        $sheet->setCellValue('E1', 'Expiration Date');
        $sheet->setCellValue('F1', 'Description');
        $sheet->setCellValue('G1', 'Contract Type');
        $sheet->setCellValue('H1', 'History');

        // Getting contract data
        $row_counter = 2;
        $contracts = Contract::all();

        // Setting data
        foreach($contracts as $contract) {
            $history_array = array();
            $history = ContractsHistory::where('contract_id', $contract->id)->get();
            foreach($history as $hist) {
                array_push($history_array, localizeDateFormat($hist->created_at, 'm-d-Y').': '.strip_tags($hist->description));
            }

            if($contract->has_all_locations) {
                $locations_array = ['All locations'];
            }else {
                $locations_array = $contract->locations ? $contract->locations->pluck('name')->toArray() : array();
            }

            $sheet->setCellValue('A'.$row_counter, implode("\n", $locations_array));
            $sheet->setCellValue('B'.$row_counter, $contract->contracted_party_name);
            $sheet->setCellValue('C'.$row_counter, $contract->contact_name);
            $sheet->setCellValue('D'.$row_counter, localizeDateFormat($contract->effective_date, 'm-d-Y'));
            $sheet->setCellValue('E'.$row_counter, localizeDateFormat($contract->expiration_date, 'm-d-Y'));
            $sheet->setCellValue('F'.$row_counter, $contract->description);
            $sheet->setCellValue('G'.$row_counter, $contract->status);
            $sheet->setCellValue('H'.$row_counter, implode("\n", $history_array));
            $row_counter++;
        }

        $this->downloadCliamsReportSheet($spreadsheet, $sheet);
    }

    private function storeUpdateRelatedCommons($request, Contract $contract, ? Contract $old_contract)
    {
        if($request->has('locations')) {
            if(!$contract->has_all_locations) {
                $contract->locations()->sync($request->locations);
            }else {
                $contract->locations()->detach();
            }
        }

        if($request->has('expiryemailtouser')) {
            $contract->expiryRemindUsers()->sync($request->expiryemailtouser);
        }
        
        if($request->has('expiry_additional_recipient')) {
            $contract->expiryAdditionalRecipient()->delete();
            $emails = [];
            foreach($request->expiry_additional_recipient as $email) {
                $emails[]['email'] = $email;
            }
            $contract->expiryAdditionalRecipient()->createMany($emails);
        }

        ContractMilestone::where('contract_id', $contract->id)
            ->whereNotIn('id', $request->input('milestone.*.hidden_id', [0]))
            ->delete();

            
        if($request->has('milestone')) {
            $numberOfMilStones = count($request->get('milestone'));
            for($index = 1; $index < $numberOfMilStones+1; $index++) {
                try {
                    if($request->filled('milestone.'.$index.'.hidden_id')) {
                        $milestone = ContractMilestone::findORFail((int) $request->input('milestone.'.$index.'.hidden_id'));
                        $milestone->update([
                            'description' => $request->input('milestone.'.$index.'.description'),
                            'milestone_date' => $request->input('milestone.'.$index.'.date')
                        ]);
                    }else {
                        $milestone = new ContractMilestone;
                        $milestone->description = $request->input('milestone.'.$index.'.description');
                        $milestone->milestone_date = $request->input('milestone.'.$index.'.date');
                        $milestone->contract()->associate($contract);
                        $milestone->save();
                    }
                }catch (Exception $exception) {
                    Log::error($exception);
                    return back()->withErrors("No related milestone found, try later!");
                }

                if($request->has('milestone.'.$index.'.remind_days')) {
                    $days = $this->storeUpdateCommons_itrator($request->input('milestone.'.$index.'.remind_days'), 'days');
                    $milestone->remindDay()->delete();
                    $milestone->remindDay()->createMany($days);
                }

                if($request->has('milestone.'.$index.'.users')) {
                    $milestone->remindUser()->sync($request->input('milestone.'.$index.'.users'));
                }

                if($request->has('milestone.'.$index.'.additional_recipient')) {
                    $additional_recipient = $this->storeUpdateCommons_itrator($request->input('milestone.'.$index.'.additional_recipient'), 'email');
                    $milestone->recipient()->delete();
                    $milestone->recipient()->createMany($additional_recipient);
                }
            }
        }

        //check if file are attached with contract form
        if($temp_path = $request->get('hidden_path') && $old_contract) {
            $new_base_path = 'public/contracts/account' . $contract->account_id . '/';
            $files = Storage::files($temp_path);
            foreach($files as $file_old_path) {
                $file_display_name = basename($file_old_path);
                $file_physical_name = time() . $file_display_name;
                $file_new_path = $new_base_path . $file_physical_name;
                $file_size = Storage::size($file_old_path);
                $file_type = Storage::mimeType($file_old_path);

                //move file from temporary storage to acctual storage
                Storage::move($file_old_path, $file_new_path);

                //save file data to database
                $fileData = [
                    'contract_id' => $contract->id,
                    'name' => $file_display_name,
                    'physical_name' => $file_physical_name,
                    'file_link' => $file_new_path,
                    'size' => $file_size,
                    'type' => $file_type,
                    'related_to' => 'contracts'
                ];

                try{
                    File::create($fileData);
                }catch (Exception $e) {
                    Log::error($e);
                    return back()->withErrors("No related file found, try later!");
                }
            }

            //delete temporary folder
            Storage::deleteDirectory('public/temp/contracts/user' . $request->user()->id);
        }

        if($request->filled('temp_file')) {
            $this->move_file_to_directory($request->temp_file, 'contracts', $contract->account_id, $contract->id);
        }

        if(!$old_contract) {
            ContractsHistory::new_contract_entry(Auth::id(), $contract->id);
        }else {
            ContractsHistory::contract_updated_entry($request->user(), $contract, $old_contract);
        }
    }

     /**
     * Input itrator for Mileston childern.
     * @param Array $data
     * @param String $index
     * @return Array $array
     */
    private function storeUpdateCommons_itrator($data, $index)
    {
        $array = [];
        
        foreach($data as $adr) {
            $array[][$index] = $adr;
        }

        return $array;
    }

    private function storeUpdateCommon($request, $contract)
    {
        $contractData = [
            'has_all_locations' => isset($request->locations) ? in_array('all', $request->locations) ? 1 : 0 : 0,
            'contracted_party_name' => $request->get("contracted_party_name", $contract ? $contract->contracted_party_name : '' ),
            'contact_name' => $request->get("contact_name", $contract ? $contract->contact_name : 0),
            'contact_phone' => $request->get("contact_phone", $contract ? $contract->contact_phone : ''),
            'contact_email' => $request->get("contact_email", $contract ? $contract->contact_email : ''),
            'effective_date' => $request->get("effective_date", $contract ? $contract->effective_date : ''),
            'expiration_date' => $request->get("expiration_date", $contract ? $contract->expiration_date : ''),
            'description' => $request->get("description", $contract ? $contract->description : ''),
            'additional_details' => $request->get("additional_details", $contract ? $contract->additional_details : ''),
            'status' => $request->get("status", $contract ? $contract->status : ''),
            'transition_status_to' => $request->get("transition_status_to", $contract ? $contract->transition_status_to : ''),
            'expiration_notify_lead_days' => $request->get("expiration_notify_lead_days", $contract ? $contract->expiration_notify_lead_days : '0'),
            'expired_notification' => $request->expired_notification_check == '1' ? 1 : 0,
            'hidden' => $request->hidden_check == '1' ? 1 : 0,
            'location_can_view' => $request->location_can_view_check == '1' ? 1 : 0,
            'location_can_edit' => $request->location_can_edit_check == '1' ? 1 : 0,
        ];

        if(!$contract) {
            $account_id = $request->user()->account_id;
            $contractData1 = [ 'account_id' => $account_id ];
            $contractData = array_merge($contractData1, $contractData);
        }

        return $contractData;
    }

    //validate files datatable permissiond
    public function data_table(Request $request)
    {
        if($request->id){
            //to validate scoperResolver find Contract
            $contract = Contract::find($request->id);
            if(empty($contract)){
                Log::error('Nothing found!');
                throw new Exception("Not found");
            }
        }
        return $this->generic_data_table($request);
    }
    public function delete_files($id)
    {
        if($id){
            //to validate scoperResolver find Contract
            $file = FileRelationType::where('file_id',$id)->first();
            $model_accessed = Contract::findOrFail($file->model_id);
            if(empty($model_accessed)){
                Log::error('Nothing found!');
                throw new Exception("Not found");
            }
            return $this->generic_delete_files($id);
        }
    }

    public function contractResourcCollection(Request $request){
        
        
        $contract = Contract::query()->where("hidden", 0);
        $contract = $contract->with("locations");
        $contract = $contract->with("milestone");
        return  new ContractCollection($contract->get());
    }

    public function download(Request $request)
    {
        try {
            $file = File::findOrFail($request['id']);
        }catch (Exception $exception) {
            Log::error("An exception occurred downloading a contract file", [$exception]);
            return back()->withError($exception->getMessage());
        }

        $relation = $file->FileRelationType()->first();
        $cont = Contract::find($relation->model_id);
        if(!$cont) {
            return back()->withError("You do not have permission to view this");
        }
        $view = $request->has("view") && $request->input("view") == 'true';

        return $this->unsafeDownload($file, $view);
    }
}

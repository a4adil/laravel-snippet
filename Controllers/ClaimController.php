<?php

namespace App\Http\Controllers;

use App;
use Auth;
use App\File;
use App\Note;
use stdClass;
use App\Claim;
use Exception;
use App\Entity;
use App\Folder;
use DataTables;
use SoapClient;
use App\Contract;
use App\Location;
use PDOException;
use Carbon\Carbon;
use App\Certificate;
use App\ClaimHistory;
use App\CarrierPolicy;
use App\ClaimAreaOfLoss;
use App\ClaimCarriers;
use App\FileRelationType;
use App\SentEmailHistory;
use App\Mail\ClaimCarrier;
use Illuminate\Support\Str;
use App\Mail\ClaimRecipient;
use App\Mail\DeliveryStatus;
use Illuminate\Http\Request;
use App\Constants\ClaimFields;
use App\Constants\Permissions;
use App\Traits\OshaReportTrait;
use App\Traits\FileHandlingTrait;
use App\Http\Requests\ClaimRequest;
use App\Traits\GenerateExcelReport;
use Illuminate\Support\Facades\Mail;
use App\CustomClasses\Osha_variables;
use App\Department;
use App\Http\Resources\ClaimCollection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ClaimController extends Osha_variables
{
    use GenerateExcelReport,FileHandlingTrait, OshaReportTrait;

    protected $postponetime = '2001-12-31T00:00:00-00:00';
    protected $retries = '1';

    protected $csid = 'INTERFAX';

    protected $pageheader = 'To: {To} From: {From} Pages: {TotalPages}';
    protected $subject = 'Claim Submission';
    protected $replyemail = "dummy@curiousdog.com";
    protected $page_size = 'A4';
    protected $page_orientation = 'Portrait';
    protected $high_resolution = false;
    protected $fine_rendering = true;

    /**************** Settings end ****************/
    protected $filetypes = 'html;';
    protected $data = "<!DOCTYPE html><html><head><title>Page Title</title></head><body><h1>This is a Heading</h1>"
    ."<p>This is a paragraph.</p></body></html>";

    public function __construct()
    {
        $this->middleware(['permission:' . Permissions::Claims]);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $claimsList = Claim::all();
        $chart_locations = Location::locationsByPermission(Auth::user(), Permissions::Claims)->pluck('id', 'name')->prepend(0, 'All Locations');
        $chart_locations = $chart_locations->flip()->toArray();
        $folders = Folder::where('user_id', Auth::id())->where('library_type', 'personal')->pluck('id', 'name')->prepend(0, 'Root');
        $folders = $folders->flip()->toArray();
        $claim_fields = ClaimFields::chartsClaimStatus();

        return view('claims/list', compact('claimsList', 'chart_locations', 'folders', 'claim_fields'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create(Request $request)
    {

        if($request->filled('claim-location')) {
            $location = Location::findOrFail($request->input('claim-location'));
            $claimTypes = ClaimFields::getClaimTypes();
            $claimStatus = ClaimFields::getClaimStatus();
            $departments = Department::select('name as lable', 'name as value')->orderBy('lable', 'Asc')->pluck('value', 'lable')->toArray();
            $eventType = ClaimFields::eventTypes();
            $generalLIbailities = ClaimAreaOfLoss::select('name as lable', 'name as value')->orderBy('lable', 'Asc')->pluck('value', 'lable')->toArray();
            $typeOfLoss = ClaimFields::typeOfLoss();
            $natureOfClaims = ClaimFields::natureOfClaims();
            $yourPropertyTypeOfLoss = ClaimFields::yourPropertyTypeOfLoss();
            $classifiedCase = ClaimFields::classifiedCase();
            $injuryType = ClaimFields::injuryType();
            $bodyParts = ClaimFields::bodyParts();
            $illnessType = ClaimFields::illnessType();
            $claim = new Claim;
            $emailFaxHistory = array();

            $carrierOptions = CarrierPolicy::getCarrierPolicyList($location->id);

            //Email Submission Notice section
            $location_id = $location->id;
            //subscribe recipients
            $recipient = App\Recipient::whereHas('RecipientLocationNotifications', function ($query) use ($location_id) {
                $query->where('location_id',$location_id)->where('notify_save_location',1)->orWhere('notify_update_location',1);
            })->get();

            //get All location recipient
            $recipientAll = App\Recipient::where('account_id',Auth::user()->account_id)->pluck('id','name')->flip()->toArray();
            $recipientAll[''] = 'Please Select...';

            //default email
            $email_templates = App\EmailTemplate::where('template_type', 'claim')->where('claim_id', null)
                ->where(function($q) use ($location_id) {
                    $q->where('location_id', null)->orWhere('location_id', $location_id);
                })->get();

            $default_template_id = '';
            foreach($email_templates as &$template) {
                if($template->default == 1) {
                    $default_template_id = $template->id;
                }
                if($template->name == 'No Name') {
                    $template->name = '(Custom Template)';
                }
            }
            if($default_template_id == '') {
                $def_certificate = App\EmailTemplate::where('template_type', 'claim')
                    ->where('account_id', null)->where('location_id', null)->first();
                if($def_certificate)
                {
                    $default_template_id = $def_certificate->id;
                }
            }
            $email_templates = $email_templates->pluck('id', 'name')->flip()->toArray();
            $user_datails = Auth::user()->userInfo;

            return view('claims/create', compact('claim', 'location', 'claimTypes', 'claimStatus', 'departments', 'eventType',
                'generalLIbailities', 'typeOfLoss', 'natureOfClaims', 'yourPropertyTypeOfLoss', 'classifiedCase', 'injuryType', 'bodyParts',
                'illnessType', 'emailFaxHistory', 'carrierOptions','recipient','recipientAll','email_templates','default_template_id','user_datails'));
        }

        return redirect()->to('claim');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(ClaimRequest $request) //ClaimRequest
    {

        $claim = $this->storeUpdateCommons($request, 0);
        $this->carrier_email_fax_notifcation($claim, $request);
        $this->recipient_email_fax_notifcation($claim, $request);
        $this->setUnSetSession();
        return redirect()->to('claim');
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
        $claim = Claim::with(['location', 'claimWorkerComp', 'claimGenrelLiab', 'claimAuto', 'claimProperty', 'claimLabour',
            'claimLabourInvolvedParty', 'claimOther', 'claimCarriers'])->where('id', $id)->first();

        $notes = $claim->notes;
        if(!$claim) {
            return redirect()->to('claim');
        }

        $this->setUnSetSession();
        $location = $claim->location;
        $claim->claims_type = array_search($claim->claims_type, ClaimFields::getClaimTypes());
        $claim->claims_status = array_search($claim->claims_status, ClaimFields::getClaimStatus());
        $claim->claims_datetime = date('Y-m-d', strtotime($claim->claims_datetime));

        if($claim->claims_datetime_submitted) {
            $claim->claims_datetime_submitted = date('Y-m-d', strtotime($claim->claims_datetime_submitted));
        }

        if($claim->claims_datetime_notified) {
            $claim->claims_datetime_notified = date('Y-m-d', strtotime($claim->claims_datetime_notified));
        }

        if($claim->claimWorkerComp) {
            $this->setUnSetSession($claim, true);
        }elseif ($claim->claimGenrelLiab) {
            $this->setUnSetSession($claim, true);
        }elseif ($claim->claimAuto) {
            $this->setUnSetSession($claim, true);
        }elseif ($claim->claimProperty) {
            $this->setUnSetSession($claim, true);
        }elseif ($claim->claimLabour) {
            $this->setUnSetSession($claim, true);
        }elseif ($claim->claimOther) {
            $this->setUnSetSession($claim, true);
        }

        $claimTypes = ClaimFields::getClaimTypes();
        $claimStatus = ClaimFields::getClaimStatus();
        $departments = Department::select('name as lable', 'name as value')->orderBy('lable', 'Asc')->pluck('value', 'lable')->toArray();
        $eventType = ClaimFields::eventTypes();
        $generalLIbailities = ClaimAreaOfLoss::select('name as lable', 'name as value')->orderBy('lable', 'Asc')->pluck('value', 'lable')->toArray();
        $typeOfLoss = ClaimFields::typeOfLoss();
        $natureOfClaims = ClaimFields::natureOfClaims();
        $yourPropertyTypeOfLoss = ClaimFields::yourPropertyTypeOfLoss();
        $classifiedCase = ClaimFields::classifiedCase();
        $injuryType = ClaimFields::injuryType();
        $bodyParts = ClaimFields::bodyParts();
        $illnessType = ClaimFields::illnessType();
        $emailFaxHistory = SentEmailHistory::where('claim_id', $id)->get();

        //load carrier policies
        $selected_carrier_policy = $claim->claimCarrierPolicy;
        $carrierOptions = CarrierPolicy::getCarrierPolicyList($location->id);

        //Email Submission Notice section
        $location_id = $location->id;
        //subscribe recipients
        $recipient = App\Recipient::whereHas('RecipientLocationNotifications', function ($query) use ($location_id) {
            $query->where('location_id',$location_id)->where('notify_save_location',1)->orWhere('notify_update_location',1);
        })->get();

        //get All location recipient
        $recipientAll = App\Recipient::where('account_id',Auth::user()->account_id)->pluck('id','name')->flip()->toArray();

        //get email templates
        $email_templates = App\EmailTemplate::where('template_type', 'claim')
            ->where(function($q) use ($claim, $location_id) {
                $q->where('location_id', null)->orWhere('location_id', $location_id)->where('claim_id', null)
                    ->orWhere('certificate_id', $claim->id);
            })->get();

        $default_template_id = '';
        foreach($email_templates as &$template) {
            if($template->default == 1) {
                $default_template_id = $template->id;
            }

            if($template->name == 'No Name') {
                $template->name = '(Custom Template)';
            }
        }

        if($default_template_id == '') {
            $def_certificate = App\EmailTemplate::where('template_type', 'claim')
                ->where('account_id', null)->where('location_id', null)->first();

            if($def_certificate)
                $default_template_id = $def_certificate->id;
        }

        $email_templates = $email_templates->pluck('id', 'name')->flip()->toArray();
        $user_datails = Auth::user()->userInfo;

        return view('claims/create', compact('claim', 'location', 'claimTypes', 'claimStatus', 'departments', 'eventType',
            'generalLIbailities', 'typeOfLoss', 'natureOfClaims', 'yourPropertyTypeOfLoss', 'classifiedCase', 'injuryType', 'bodyParts',
            'illnessType', 'emailFaxHistory', 'carrierOptions', 'selected_carrier_policy', 'notes','recipient','recipientAll','default_template_id','email_templates','user_datails'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(ClaimRequest $request, $id)
    {
        
        $claim = $this->storeUpdateCommons($request, $id);
        $this->carrier_email_fax_notifcation($claim, $request);
        $this->recipient_email_fax_notifcation($claim, $request);
        $this->setUnSetSession();
        return redirect()->to('claim');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $claim = Claim::findOrFail($id);

        if($claim->claimWorkerComp){
            $claim->claimWorkerComp()->delete();
        }elseif($claim->claimGenrelLiab){
            $claim->claimGenrelLiab()->delete();
        }elseif($claim->claimAuto){
            $claim->claimAuto()->delete();
        }elseif($claim->claimProperty){
            $claim->claimProperty()->delete();
        }elseif($claim->claimLabour){
            $claim->claimLabour()->delete();
        }elseif($claim->claimOther){
            $claim->claimOther()->delete();
        }

        ClaimHistory::claim_delete($claim->id, Auth::id());
        $claim->delete();
    }

    public function allClaims(Request $request)
    {
        $claims = Claim::query();

        if($request->filled('custom.current')) {
            $claims->where('claims_type', ClaimFields::getClaimTypes($request->input('custom.current')));
        }

        if($request->filled('custom.filterLocations.*')) {
            $locations = $request->input('custom.filterLocations');
            $claims->whereHas('location', function ($query) use ($locations) {
                $query->whereIn('id', $locations);
            });
        }

        if($request->filled('custom.show_hidden')) {
            $claims->where('claims_hidden', $request->input('custom.show_hidden'));
        }

        if($request->filled('custom.filter_date_from') && $request->filled('custom.filter_date_to')) {
            $from_date = Carbon::parse($request->input('custom.filter_date_from'))->toDateTimeString();
            $to_date = Carbon::parse($request->input('custom.filter_date_to'))->toDateTimeString();

            $claims = $claims->whereBetween('claims_datetime', [$from_date, $to_date]);
        }

        $claims = $claims->with(['location' => function ($query) {
            $query->select('id', 'name');
        }]);

        return DataTables::of($claims->get())
            ->addColumn('location', function ($claim) {
                return $claim->location ? $claim->location->name : '';
            })
            ->addColumn('claimant', function ($claim) use($request) {
                $claimnent = "-";
                $type = $request->input('custom.current');

                if($type == "claims-worker-comp"){
                    $claimnent = optional($claim->claimWorkerComp)->claim_workers_comp_employee_name;
                }elseif($type == "claims-general-liability"){
                    $claimnent = optional($claim->claimGenrelLiab)->claim_general_liab_claimant;
                }elseif($type == "claims-auto-claims"){
                    $claimnent = optional($claim->claimAuto)->claim_auto_claimant;
                }

                return $claimnent;
            })
            ->addColumn('incurred', function ($claim) {
                return '$';
            })
            ->addColumn('carrier', function ($claim) {
                $data = $claim->claimCarrierPolicy;
                $name = array();
                foreach ($data as $obj)
                {$name[] = $obj->name;}
                return implode(',', $name);
            })
            ->addColumn('action', function ($claims) use ($request) {
                if ($request->user()->hasAccountPermission(Permissions::Claims)) {
                    return $claims->id;
                }
                return null;
            })
            ->make(true);
    }

    public function generateWorkersCompCliamsReport()
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $this->generateCliamsReportSheet($spreadsheet, 'Workers Comp Claim', 'N');

        // Adding header data
        $sheet->setCellValue('A1', 'Location');
        $sheet->setCellValue('B1', 'DOI');
        $sheet->setCellValue('C1', 'Claimant');
        $sheet->setCellValue('D1', 'Injury Type');
        $sheet->setCellValue('E1', 'Body Part');
        $sheet->setCellValue('F1', 'Dept.');
        $sheet->setCellValue('G1', 'Status');
        $sheet->setCellValue('H1', 'Date Submitted');
        $sheet->setCellValue('I1', 'Date Closed');
        $sheet->setCellValue('J1', 'Amount Paid');
        $sheet->setCellValue('K1', 'Amount Reversed');
        $sheet->setCellValue('L1', 'Total Incurred');
        $sheet->setCellValue('M1', 'Notes');
        $sheet->setCellValue('N1', 'History');

        // Getting contract data
        $row_counter = 2;
        $claims = Claim::where('claims_type', 'Workers Comp')->get();

        // Setting data
        foreach($claims as $claim) {
            $location = $claim->location ? $claim->location->name : null;
            $total_Incurred = $claim->claims_amount_paid + $claim->claims_amount_reserved;
            $history_array = $this->getClaimHistory($claim->id);

            $sheet->setCellValue('A'.$row_counter, $location);
            $sheet->setCellValue('B'.$row_counter, localizeDateFormat($claim->claims_datetime, 'm-d-Y'));
            $sheet->setCellValue('C'.$row_counter, $claim->claimWorkerComp ? $claim->claimWorkerComp->claim_workers_comp_employee_name : '');
            $sheet->setCellValue('D'.$row_counter, $claim->claimWorkerComp ? $claim->claimWorkerComp->claim_workers_comp_injury_type : '');
            $sheet->setCellValue('E'.$row_counter, $claim->claimWorkerComp ? $claim->claimWorkerComp->claim_workers_comp_body_parts : '');
            $sheet->setCellValue('F'.$row_counter, $claim->claimWorkerComp ? $claim->claimWorkerComp->claim_workers_comp_event_department : '');
            $sheet->setCellValue('G'.$row_counter, $claim->claims_status);
            $sheet->setCellValue('H'.$row_counter, $claim->claims_datetime_submitted ? localizeDateFormat($claim->claims_datetime_submitted, 'm-d-Y') : null);
            $sheet->setCellValue('I'.$row_counter, $claim->claims_datetime_closed ? localizeDateFormat($claim->claims_datetime_closed, 'm-d-Y') : null);
            $sheet->setCellValue('J'.$row_counter, $claim->claims_amount_paid);
            $sheet->setCellValue('K'.$row_counter, $claim->claims_amount_reserved);
            $sheet->setCellValue('L'.$row_counter, $total_Incurred);
            $sheet->setCellValue('M'.$row_counter, $claim->notes ? implode(', ', $claim->notes->pluck('description')->toArray()) : '');
            $sheet->setCellValue('N'.$row_counter, implode("\n", $history_array->getData()->data));
            $row_counter++;
        }

        $this->downloadCliamsReportSheet($spreadsheet, $sheet);
    }

    public function generateGeneralLiabilityCliamsReport()
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $this->generateCliamsReportSheet($spreadsheet, 'General Liability Claim', 'M');

        // Adding header data
        $sheet->setCellValue('A1', 'Location');
        $sheet->setCellValue('B1', 'DOI');
        $sheet->setCellValue('C1', 'Claimant');
        $sheet->setCellValue('D1', 'Area of Loss');
        $sheet->setCellValue('E1', 'Type of Loss');
        $sheet->setCellValue('F1', 'Status');
        $sheet->setCellValue('G1', 'Date Submitted');
        $sheet->setCellValue('H1', 'Date Closed');
        $sheet->setCellValue('I1', 'Amount Paid');
        $sheet->setCellValue('J1', 'Amount Reversed');
        $sheet->setCellValue('K1', 'Total Incurred');
        $sheet->setCellValue('L1', 'Notes');
        $sheet->setCellValue('M1', 'History');

        // Getting contract data
        $row_counter = 2;
        $claims = Claim::where('claims_type', 'General Liability / Property of Others')->get();

        // Setting data
        foreach($claims as $claim) {
            $location = $claim->location ? $claim->location->name : null;
            $total_Incurred = $claim->claims_amount_paid + $claim->claims_amount_reserved;
            $history_array = $this->getClaimHistory($claim->id);

            $sheet->setCellValue('A'.$row_counter, $location);
            $sheet->setCellValue('B'.$row_counter, localizeDateFormat($claim->claims_datetime, 'm-d-Y'));
            $sheet->setCellValue('C'.$row_counter, $claim->claimGenrelLiab ? $claim->claimGenrelLiab->claim_general_liab_claimant : '');
            $sheet->setCellValue('D'.$row_counter, $claim->claimGenrelLiab ? $claim->claimGenrelLiab->claim_general_liab_loss_area : '');
            $sheet->setCellValue('E'.$row_counter, $claim->claimGenrelLiab ? $claim->claimGenrelLiab->claim_general_liab_loss_type : '');
            $sheet->setCellValue('F'.$row_counter, $claim->claims_status);
            $sheet->setCellValue('G'.$row_counter, $claim->claims_datetime_submitted ? localizeDateFormat($claim->claims_datetime_submitted, 'm-d-Y') : null);
            $sheet->setCellValue('H'.$row_counter, $claim->claims_datetime_closed ? localizeDateFormat($claim->claims_datetime_closed, 'm-d-Y') : null);
            $sheet->setCellValue('I'.$row_counter, $claim->claims_amount_paid);
            $sheet->setCellValue('J'.$row_counter, $claim->claims_amount_reserved);
            $sheet->setCellValue('K'.$row_counter, $total_Incurred);
            $sheet->setCellValue('L'.$row_counter, $claim->notes ? implode(', ', $claim->notes->pluck('description')->toArray()) : '');
            $sheet->setCellValue('M'.$row_counter, implode("\n", $history_array->getData()->data));
            $row_counter++;
        }

        $this->downloadCliamsReportSheet($spreadsheet, $sheet);
    }

    public function generateAutoCliamsReport()
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $this->generateCliamsReportSheet($spreadsheet, 'Auto Claim', 'L');

        // Adding header data
        $sheet->setCellValue('A1', 'Location');
        $sheet->setCellValue('B1', 'DOI');
        $sheet->setCellValue('C1', 'Claimant');
        $sheet->setCellValue('D1', 'Nature of Claim');
        $sheet->setCellValue('E1', 'Status');
        $sheet->setCellValue('F1', 'Date Submitted');
        $sheet->setCellValue('G1', 'Date Closed');
        $sheet->setCellValue('H1', 'Amount Paid');
        $sheet->setCellValue('I1', 'Amount Reversed');
        $sheet->setCellValue('J1', 'Total Incurred');
        $sheet->setCellValue('K1', 'Notes');
        $sheet->setCellValue('L1', 'History');

        // Getting contract data
        $row_counter = 2;
        $claims = Claim::where('claims_type', 'Auto')->get();

        // Setting data
        foreach($claims as $claim) {
            $location = $claim->location ? $claim->location->name : null;
            $total_Incurred = $claim->claims_amount_paid + $claim->claims_amount_reserved;
            $history_array = $this->getClaimHistory($claim->id);

            $sheet->setCellValue('A'.$row_counter, $location);
            $sheet->setCellValue('B'.$row_counter, localizeDateFormat($claim->claims_datetime, 'm-d-Y'));
            $sheet->setCellValue('C'.$row_counter, $claim->claimAuto ? $claim->claimAuto->claim_auto_claimant : '');
            $sheet->setCellValue('D'.$row_counter, $claim->claimAuto ? $claim->claimAuto->claim_auto_nature : '');
            $sheet->setCellValue('E'.$row_counter, $claim->claims_status);
            $sheet->setCellValue('F'.$row_counter, $claim->claims_datetime_submitted ? localizeDateFormat($claim->claims_datetime_submitted, 'm-d-Y') : null);
            $sheet->setCellValue('G'.$row_counter, $claim->claims_datetime_closed ? localizeDateFormat($claim->claims_datetime_closed, 'm-d-Y') : null);
            $sheet->setCellValue('H'.$row_counter, $claim->claims_amount_paid);
            $sheet->setCellValue('I'.$row_counter, $claim->claims_amount_reserved);
            $sheet->setCellValue('J'.$row_counter, $total_Incurred);
            $sheet->setCellValue('K'.$row_counter, $claim->notes ? implode(', ', $claim->notes->pluck('description')->toArray()) : '');
            $sheet->setCellValue('L'.$row_counter, implode("\n", $history_array->getData()->data));
            $row_counter++;
        }

        $this->downloadCliamsReportSheet($spreadsheet, $sheet);
    }

    public function generatePropertyCliamsReport()
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $this->generateCliamsReportSheet($spreadsheet, 'Property Claim', 'K');

        // Adding header data
        $sheet->setCellValue('A1', 'Location');
        $sheet->setCellValue('B1', 'DOI');
        $sheet->setCellValue('C1', 'Type of Loss');
        $sheet->setCellValue('D1', 'Status');
        $sheet->setCellValue('E1', 'Date Submitted');
        $sheet->setCellValue('F1', 'Date Closed');
        $sheet->setCellValue('G1', 'Amount Paid');
        $sheet->setCellValue('H1', 'Amount Reversed');
        $sheet->setCellValue('I1', 'Total Incurred');
        $sheet->setCellValue('J1', 'Notes');
        $sheet->setCellValue('K1', 'History');

        // Getting contract data
        $row_counter = 2;
        $claims = Claim::where('claims_type', 'Your Property')->get();

        // Setting data
        foreach($claims as $claim) {
            $location = $claim->location ? $claim->location->name : null;
            $total_Incurred = $claim->claims_amount_paid + $claim->claims_amount_reserved;
            $history_array = $this->getClaimHistory($claim->id);

            $sheet->setCellValue('A'.$row_counter, $location);
            $sheet->setCellValue('B'.$row_counter, localizeDateFormat($claim->claims_datetime, 'm-d-Y'));
            $sheet->setCellValue('C'.$row_counter, $claim->claimProperty ? $claim->claimProperty->claim_property_loss_type : '');
            $sheet->setCellValue('D'.$row_counter, $claim->claims_status);
            $sheet->setCellValue('E'.$row_counter, $claim->claims_datetime_submitted ? localizeDateFormat($claim->claims_datetime_submitted, 'm-d-Y') : null);
            $sheet->setCellValue('F'.$row_counter, $claim->claims_datetime_closed ? localizeDateFormat($claim->claims_datetime_closed, 'm-d-Y') : null);
            $sheet->setCellValue('G'.$row_counter, $claim->claims_amount_paid);
            $sheet->setCellValue('H'.$row_counter, $claim->claims_amount_reserved);
            $sheet->setCellValue('I'.$row_counter, $total_Incurred);
            $sheet->setCellValue('J'.$row_counter, $claim->notes ? implode(', ', $claim->notes->pluck('description')->toArray()) : '');
            $sheet->setCellValue('K'.$row_counter, implode("\n", $history_array->getData()->data));
            $row_counter++;
        }

        $this->downloadCliamsReportSheet($spreadsheet, $sheet);
    }

    public function generateLaborCliamsReport()
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $this->generateCliamsReportSheet($spreadsheet, 'Labor Claim', 'L');

        // Adding header data
        $sheet->setCellValue('A1', 'Location');
        $sheet->setCellValue('B1', 'DOI');
        $sheet->setCellValue('C1', 'Claimant');
        $sheet->setCellValue('D1', 'Incident Location');
        $sheet->setCellValue('E1', 'Status');
        $sheet->setCellValue('F1', 'Date Submitted');
        $sheet->setCellValue('G1', 'Date Closed');
        $sheet->setCellValue('H1', 'Amount Paid');
        $sheet->setCellValue('I1', 'Amount Reversed');
        $sheet->setCellValue('J1', 'Total Incurred');
        $sheet->setCellValue('K1', 'Notes');
        $sheet->setCellValue('L1', 'History');

        // Getting contract data
        $row_counter = 2;
        $claims = Claim::where('claims_type', 'Labor Law')->get();

        // Setting data
        foreach($claims as $claim) {
            $location = $claim->location ? $claim->location->name : null;
            $total_Incurred = $claim->claims_amount_paid + $claim->claims_amount_reserved;
            $history_array = $this->getClaimHistory($claim->id);

            $sheet->setCellValue('A'.$row_counter, $location);
            $sheet->setCellValue('B'.$row_counter, localizeDateFormat($claim->claims_datetime, 'm-d-Y'));
            $sheet->setCellValue('C'.$row_counter, $claim->claimLabour ? $claim->claimLabour->claim_employment_claimant : '');
            $sheet->setCellValue('D'.$row_counter, $claim->claimLabour ? $claim->claimLabour->claim_employment_incident_location : '');
            $sheet->setCellValue('E'.$row_counter, $claim->claims_status);
            $sheet->setCellValue('F'.$row_counter, $claim->claims_datetime_submitted ? localizeDateFormat($claim->claims_datetime_submitted, 'm-d-Y') : null);
            $sheet->setCellValue('G'.$row_counter, $claim->claims_datetime_closed ? localizeDateFormat($claim->claims_datetime_closed, 'm-d-Y') : null);
            $sheet->setCellValue('H'.$row_counter, $claim->claims_amount_paid);
            $sheet->setCellValue('I'.$row_counter, $claim->claims_amount_reserved);
            $sheet->setCellValue('J'.$row_counter, $total_Incurred);
            $sheet->setCellValue('K'.$row_counter, $claim->notes ? implode(', ', $claim->notes->pluck('description')->toArray()) : '');
            $sheet->setCellValue('L'.$row_counter, implode("\n", $history_array->getData()->data));
            $row_counter++;
        }

        $this->downloadCliamsReportSheet($spreadsheet, $sheet);
    }

    public function generateOtherCliamsReport()
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $this->generateCliamsReportSheet($spreadsheet, 'Other Claim', 'L');

        // Adding header data
        $sheet->setCellValue('A1', 'Location');
        $sheet->setCellValue('B1', 'DOI');
        $sheet->setCellValue('C1', 'Claimant');
        $sheet->setCellValue('D1', 'Area of Loss');
        $sheet->setCellValue('E1', 'Status');
        $sheet->setCellValue('F1', 'Date Submitted');
        $sheet->setCellValue('G1', 'Date Closed');
        $sheet->setCellValue('H1', 'Amount Paid');
        $sheet->setCellValue('I1', 'Amount Reversed');
        $sheet->setCellValue('J1', 'Total Incurred');
        $sheet->setCellValue('K1', 'Notes');
        $sheet->setCellValue('L1', 'History');

        // Getting contract data
        $row_counter = 2;
        $claims = Claim::where('claims_type', 'Other')->get();

        // Setting data
        foreach($claims as $claim) {
            $location = $claim->location ? $claim->location->name : null;
            $total_Incurred = $claim->claims_amount_paid + $claim->claims_amount_reserved;
            $history_array = $this->getClaimHistory($claim->id);

            $sheet->setCellValue('A'.$row_counter, $location);
            $sheet->setCellValue('B'.$row_counter, localizeDateFormat($claim->claims_datetime, 'm-d-Y'));
            $sheet->setCellValue('C'.$row_counter, $claim->claimOther ? $claim->claimOther->claim_other_claimant : '');
            $sheet->setCellValue('D'.$row_counter, $claim->claimOther ? $claim->claimOther->claim_other_loss_area : '');
            $sheet->setCellValue('E'.$row_counter, $claim->claims_status);
            $sheet->setCellValue('F'.$row_counter, $claim->claims_datetime_submitted ? localizeDateFormat($claim->claims_datetime_submitted, 'm-d-Y') : null);
            $sheet->setCellValue('G'.$row_counter, $claim->claims_datetime_closed ? localizeDateFormat($claim->claims_datetime_closed, 'm-d-Y') : null);
            $sheet->setCellValue('H'.$row_counter, $claim->claims_amount_paid);
            $sheet->setCellValue('I'.$row_counter, $claim->claims_amount_reserved);
            $sheet->setCellValue('J'.$row_counter, $total_Incurred);
            $sheet->setCellValue('K'.$row_counter, $claim->notes ? implode(', ', $claim->notes->pluck('description')->toArray()) : '');
            $sheet->setCellValue('L'.$row_counter, implode("\n", $history_array->getData()->data));
            $row_counter++;
        }

        $this->downloadCliamsReportSheet($spreadsheet, $sheet);
    }

    protected function getClaimHistory($claim_id)
    {
        $history_array = array();
        $history = ClaimHistory::where('claim_id', $claim_id)->get();
        foreach($history as $hist) {
            array_push($history_array, localizeDateFormat($hist->created_at, 'm-d-Y').': '.strip_tags($hist->description));
        }

        $response['data'] = $history_array;
        $response['message'] = 'Process Completed';

        return response()->json($response, 200);
    }

    public function hideClaim($claim_id)
    {
        try {
            $claim = Claim::findOrFail($claim_id);
            $claim->update([ 'claims_hidden' => !$claim->claims_hidden ]);
        }catch (ModelNotFoundException $exception) {
            return response()->json(['data'=> $exception->getMessage()], 500);
        }catch (Exception $exception) {
            return response()->json(['data'=> $exception->getMessage()], 500);
        }

        $response['message'] = 'Process Completed';

        return response()->json($response, 200);
    }

    public function getEmailsFaxHistory()
    {
        return view('claims/email_fax_history');
    }

    public function allEmailFaxHistory(Request $request)
    {
        $customFilters = $request->custom;
        $filter_date_from = $customFilters["filter_date_from"];
        $filter_date_to = $customFilters["filter_date_to"];

        $accountId = Auth::user()->account_id;
        $history = SentEmailHistory::where('claim_id', '!=', null)->where('account_id', $accountId);

        if ($filter_date_from != null && $filter_date_to != null) {

            $from_date = Carbon::parse($filter_date_from)->toDateTimeString();
            $to_date = Carbon::parse($filter_date_to)->toDateTimeString();

            $history = $history->whereBetween('date', [$from_date, $to_date]);
        }

        return DataTables::of($history->get())
            ->addColumn('location', function ($history) {
                return $history->claim->location ? $history->claim->location->name : null;
            })
            ->addColumn('claim_type', function ($history) {
                return $history->claim->claims_type;
            })
            ->addColumn('to', function ($history) {
                return $history->email;
            })
            ->addColumn('action', function ($history) use ($request) {
                $data = $history;
                if($request->user()->hasAccountPermission(Permissions::Claims)) {
                    $data['view_claim_id'] = $history->claim_id;
                    $data['view_claim'] = route('claim.edit',$history->claim_id);
                    $data['view_history_id'] = $history->id;
                }
                return view('claims/partials/email_fax_history_action', $data)->render();
            })
            ->make(true);
    }

    public function getEmailsFaxHistoryDetails(int $emailhistoryId)
    {
        try {
            $claimEmailHistory = SentEmailHistory::findOrFail($emailhistoryId);
        }catch (ModelNotFoundException $exception) {
            return back()->withError($exception->getMessage())->withInput();
        }catch (Exception $exception) {
            return back()->withError($exception->getMessage())->withInput();
        }

        $response['data'] = $claimEmailHistory->html;
        $response['message'] = 'Process Completed';

        return response()->json($response, 200);
    }

    public function claimEmailFaxHistoryModal(int $claim_id)
    {
        $History = SentEmailHistory::where('claim_id', $claim_id)->get();
        $html = '<table class="table table-bordered table-sm"> <thead class="thead-dark"><tr>';
        $html .= '<th>To</th><th>Subject</th><th>Date</th><th>Status</th><th>Attachments</th><th>Action</th>';
        $html .= '</tr><thead><tbody>';

        foreach($History as $history) {
            $html .= '<tr>';
            $html .= '<td>'.$history->email.'</td>';
            $html .= '<td>'.$history->description.'</td>';
            $html .= '<td>'.localizeDateFormat($history->date).'</td>';
            $html .= '<td>'.$history->status.'</td>';
            $html .= '<td>'.$history->file_name.'</td>';
            $html .= '<td>'."<a onClick='showEmailHtml(".$history->id.")' href='javascript:void(0)'>View Email/Fax</a>".'</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody><table>';

        $response['data'] = $html;
        $response['message'] = 'Process Completed';

        return response()->json($response, 200);
    }

    protected function setUnSetSession($claim=null, $set=false)
    {
        if(!$set) {
            session([
                '_old_input.claim_workers_comp_employee_name' => null,
                '_old_input.claim_workers_comp_job_title' => null,
                '_old_input.claim_workers_comp_employee_department' => null,
                '_old_input.claim_workers_comp_event_department' => null,
                '_old_input.claim_workers_comp_event_type' => null,
                '_old_input.claim_workers_comp_illness_type' => null,
                '_old_input.claim_workers_comp_injury_type' => null,
                '_old_input.claim_workers_comp_injury_type_Other' => null,
                '_old_input.claim_workers_comp_body_parts' => null,
                '_old_input.claim_workers_comp_illness_type_Other' => null,
                '_old_input.claim_workers_comp_osha_include' => null,
                '_old_input.claim_workers_comp_osha_privacy' => null,
                '_old_input.claim_workers_comp_case_number' => null,
                '_old_input.claim_workers_comp_classification' => null,
                '_old_input.claim_workers_comp_datetime_away_start' => null,
                '_old_input.claim_workers_comp_datetime_away_end' => null,
                '_old_input.claim_workers_comp_datetime_restricted_start' => null,
                '_old_input.claim_workers_comp_datetime_restricted_end' => null,
                '_old_input.claim_workers_comp_total_away' => 0,
                '_old_input.claim_workers_comp_total_restricted' => 0,
                '_old_input.claim_workers_comp_body_parts_Other' => null,

                '_old_input.claim_general_liab_loss_area' => null,
                '_old_input.claim_general_liab_loss_type' => null,
                '_old_input.claim_general_liab_claimant' => null,
                '_old_input.claim_general_liab_loss_area_Other' => null,
                '_old_input.claim_general_liab_loss_type_Other' => null,

                '_old_input.claim_auto_claimant' => null,
                '_old_input.claim_auto_nature' => null,
                '_old_input.claim_auto_nature_Other' => null,

                '_old_input.claim_property_loss_type' =>null,
                '_old_input.claim_property_loss_type_Other' =>null,

                '_old_input.claim_employment_claimant' => null,
                '_old_input.claim_employment_job_title' => null,
                '_old_input.claim_employment_department' => null,
                '_old_input.claim_employment_incident_location' => null,
                '_old_input.claim_employment_involved_parties_name' => null,

                '_old_input.claim_other_claimant' => null,
                '_old_input.claim_other_loss_area_Other' => null,
                '_old_input.claim_other_loss_area' => null,

            ]);
            return true;
        }

        if($claim->claims_type == 'claims-worker-comp') {
            session([
                '_old_input.claim_workers_comp_employee_name' => $claim->claimWorkerComp->claim_workers_comp_employee_name,
                '_old_input.claim_workers_comp_job_title' => $claim->claimWorkerComp->claim_workers_comp_job_title,
                '_old_input.claim_workers_comp_date_of_birth' => $claim->claimWorkerComp->claim_workers_comp_date_of_birth,
                '_old_input.claim_workers_comp_employee_department' => $claim->claimWorkerComp->claim_workers_comp_employee_department,
                '_old_input.claim_workers_comp_event_department' => $claim->claimWorkerComp->claim_workers_comp_event_department,
                '_old_input.claim_workers_comp_event_type' => $claim->claimWorkerComp->claim_workers_comp_event_type,
                '_old_input.claim_workers_comp_illness_type' => $claim->claimWorkerComp->claim_workers_comp_illness_type,
                '_old_input.claim_workers_comp_injury_type' => $claim->claimWorkerComp->claim_workers_comp_injury_type,
                '_old_input.claim_workers_comp_injury_type_Other' => $claim->claimWorkerComp->claim_workers_comp_injury_type_other,
                '_old_input.claim_workers_comp_body_parts' => explode(",", $claim->claimWorkerComp->claim_workers_comp_body_parts),
                '_old_input.claim_workers_comp_illness_type_Other' => $claim->claimWorkerComp->claim_workers_comp_illness_type_other,
                '_old_input.claim_workers_comp_osha_include' => $claim->claimWorkerComp->claim_workers_comp_osha_include,
                '_old_input.claim_workers_comp_osha_privacy' => $claim->claimWorkerComp->claim_workers_comp_osha_privacy,
                '_old_input.claim_workers_comp_case_number' => $claim->claimWorkerComp->claim_workers_comp_case_number,
                '_old_input.claim_workers_comp_classification' => "work_comp_class_".$claim->claimWorkerComp->claim_workers_comp_classification,
                '_old_input.claim_workers_comp_datetime_away_start' => $claim->claimWorkerComp->claim_workers_comp_datetime_away_start?date("Y-m-d", strtotime($claim->claimWorkerComp->claim_workers_comp_datetime_away_start)):null,
                '_old_input.claim_workers_comp_datetime_away_end' => $claim->claimWorkerComp->claim_workers_comp_datetime_away_end?date("Y-m-d", strtotime($claim->claimWorkerComp->claim_workers_comp_datetime_away_end)):null,
                '_old_input.claim_workers_comp_datetime_restricted_start' => $claim->claimWorkerComp->claim_workers_comp_datetime_restricted_start?date("Y-m-d", strtotime($claim->claimWorkerComp->claim_workers_comp_datetime_restricted_start)):null,
                '_old_input.claim_workers_comp_datetime_restricted_end' => $claim->claimWorkerComp->claim_workers_comp_datetime_restricted_end?date("Y-m-d", strtotime($claim->claimWorkerComp->claim_workers_comp_datetime_restricted_end)):null,
                '_old_input.claim_workers_comp_total_away' => $this->getDateDifference(true, null, null, null,$claim->claimWorkerComp->claim_workers_comp_datetime_away_end, $claim->claimWorkerComp->claim_workers_comp_datetime_away_start),
                '_old_input.claim_workers_comp_total_restricted' => $this->getDateDifference(true, null, null, null,$claim->claimWorkerComp->claim_workers_comp_datetime_restricted_end, $claim->claimWorkerComp->claim_workers_comp_datetime_restricted_start),
                '_old_input.claim_workers_comp_body_parts_Other' => $claim->claimWorkerComp->claim_workers_comp_body_parts_other,
            ]);
        }elseif($claim->claims_type == 'claims-general-liability') {
            session([
                '_old_input.claim_general_liab_loss_area' => explode(",", $claim->claimGenrelLiab->claim_general_liab_loss_area),
                '_old_input.claim_general_liab_loss_type' => $claim->claimGenrelLiab->claim_general_liab_loss_type,
                '_old_input.claim_general_liab_claimant' => $claim->claimGenrelLiab->claim_general_liab_claimant,
                '_old_input.claim_general_liab_loss_area_Other' => $claim->claimGenrelLiab->claim_general_liab_loss_area_other,
                '_old_input.claim_general_liab_loss_type_Other' => $claim->claimGenrelLiab->claim_general_liab_loss_type_other,

            ]);
        }elseif($claim->claims_type == 'claims-auto-claims') {
            session([
                '_old_input.claim_auto_claimant' => $claim->claimAuto->claim_auto_claimant,
                '_old_input.claim_auto_nature' => $claim->claimAuto->claim_auto_nature,
                '_old_input.claim_auto_nature_Other' => $claim->claimAuto->claim_auto_nature_other,

            ]);
        }elseif($claim->claims_type == 'claims-property-claims') {
            session([
                '_old_input.claim_property_loss_type' => explode(",", $claim->claimProperty->claim_property_loss_type),
                '_old_input.claim_property_loss_type_Other' => $claim->claimProperty->claim_property_loss_type_other,
            ]);
        }elseif($claim->claims_type == 'claims-labor-claims') {
            session([
                '_old_input.claim_employment_claimant' => $claim->claimLabour->claim_employment_claimant,
                '_old_input.claim_employment_job_title' => $claim->claimLabour->claim_employment_job_title,
                '_old_input.claim_employment_department' => $claim->claimLabour->claim_employment_department,
                '_old_input.claim_employment_incident_location' => $claim->claimLabour->claim_employment_incident_location,

                '_old_input.claim_employment_involved_parties_name_list' => $claim->claimLabourInvolvedParty?$claim->claimLabourInvolvedParty->implode("claim_employment_involved_parties_name",","):null,
            ]);
        }elseif($claim->claims_type == 'claims-other') {
            session([
                '_old_input.claim_other_claimant' => $claim->claimOther->claim_other_claimant,
                '_old_input.claim_other_loss_area_Other' => $claim->claimOther->claim_other_loss_area_other,
                '_old_input.claim_other_loss_area' => explode(",", $claim->claimOther->claim_other_loss_area),
            ]);
        }
    }

    /**
     * Get Date Difference in Days, Minusts, Seconds or years.
     * @param $days Boolean
     * @param $minits Boolean
     * @param $seconds Boolean
     * @param $years Boolean
     * @param $endDate
     * @param $startDate
     * @return int
     *
     */
    protected function getDateDifference($days = true, $minits = null, $seconds = null, $years = null, $endDate, $startDate)
    {
        if($startDate && $endDate) {
            $days = 0;
            $days = strtotime($endDate) - strtotime($startDate);

            if($days) {
                return round($days/(60 * 60 * 24));
            }
        }

        return 0;
    }

    public function load_claim_carrier_view(Request $request)
    {
        $elementCountNum = Str::random(16);
        $sort = $request->sort;
        $claimStatus = ClaimFields::getClaimStatus();
        $carrierOptions = CarrierPolicy::getCarrierPolicyList($request->location_id);
        return view('partial_views.claim_carriers',compact('elementCountNum','sort','claimStatus','carrierOptions'));
    }

    public function saveChartToFile(Request $request)
    {
        $folder = null;
        $folder_id = (int) $request->folder_id;
        $dataURL = $request->dataURL;
        $name = $request->name;
        $fileExtension = 'jpeg';
        $display_name = $name.'.'.$fileExtension;
        $fileSize = (int) (strlen(rtrim($dataURL, '=')) * 3 / 4);
        $folder_name = 'public/'.App::environment().'/';

        if($folder_id > 0) {
            try {
                $folder = Folder::findOrFail($folder_id);
                if($folder)
                    $folder_name = 'public/'.App::environment().'/folder-' . $folder->id . '/';
            }catch (ModelNotFoundException $exception) {
                return back()->withError($exception->getMessage());
            }catch (Exception $exception) {
                return back()->withError($exception->getMessage());
            }
        }else {
            $folder_id = null;
        }

        $physicalFileName = str_random(8) . '-' . time() . '.' . $fileExtension;
        $storagePath = $folder_name.$physicalFileName;

        if(preg_match('/^data:image\/(\w+);base64,/', $dataURL)) {
            $image = str_replace('data:image/jpeg;base64,', '', $dataURL);
            $image = str_replace(' ', '+', $image);
            $data = base64_decode($image);

            try {
                Storage::put($storagePath, $data);

                $fileDataArray = array(
                    'name' => $display_name,
                    'physical_name' => $physicalFileName,
                    'extension' => $fileExtension,
                    'storage_location' => $folder_name . $physicalFileName,
                    'size' => $fileSize
                );

                $myFilesDataArray = array(
                    'folder_id' => $folder_id,
                    'library_type' => 'personal',
                    'user_id' => Auth::id(),
                );

                $files = File::create($fileDataArray);
                $myFiles = $files->libraryFile()->create($myFilesDataArray);
            }catch (Exception $exception) {
                return back()->withError($exception->getMessage());
            }
        }

        $response['message'] = 'File Saved Successfully';

        return response()->json($response, 200);
    }

    protected function storeUpdateCommons($request, $id)
    {
        $validated = $request->validated();
        $validated['account_id'] = $request->user()->account_id;
        $validated['claims_type'] = ClaimFields::getClaimTypes($validated['claims_type']);
        $validated['claims_status_codes_id'] = $validated['claims_status'];
        $validated['claims_status'] = ClaimFields::getClaimStatus($validated['claims_status']);
        $validated['claims_submit_type'] = 'fax';
        $validated['claims_email_subject'] = $request->claims_email_subject;
        $validated['claims_email_templates_id'] = $request->claims_email_templates_id;

        if(!$id) {
            try {
                $claim = Claim::create($validated);
                //save file against claim id
                if ($request->filled('temp_file')) {
                    $this->move_file_to_directory($request->temp_file, 'claims', $request->user()->account_id, $claim->id);
                }
            }catch (PDOException $exception) {
                return back()->withError($exception->getMessage())->withInput();
            }catch (QueryException $exception) {
                return back()->withError($exception->getMessage())->withInput();
            }catch (Exception $exception) {
                return back()->withError($exception->getMessage())->withInput();
            }
        }else{
            try {
                $claim = Claim::findOrFail($id);
            }catch (ModelNotFoundException $exception) {
                return back()->withError($exception->getMessage());
            }catch (Exception $exception) {
                return back()->withError($exception->getMessage());
            }

            $oldClaim = Claim::where('id', $id);
        }

        if($request['claims_type'] == 'claims-worker-comp') {
            if($id) {
                $oldClaim = $oldClaim->with('claimWorkerComp')->first();
            }

            $validated['claim_workers_comp_event_department'] = ClaimFields::wokderCompEmployeeDepartment($validated['claim_workers_comp_event_department'], null);
            $validated['claim_workers_comp_event_type'] = ClaimFields::eventTypes($validated['claim_workers_comp_event_type'], null);

            if($request->filled('claim_workers_comp_illness_type')) {
                $validated['claim_workers_comp_illness_type'] = ClaimFields::illnessType($validated['claim_workers_comp_illness_type'], null);

                if($request->filled('claim_workers_comp_illness_type_Other')) {
                    $validated['claim_workers_comp_illness_type_other'] = $validated['claim_workers_comp_illness_type_Other'];
                }

                $validated['claim_workers_comp_injury_type'] = null;
                $validated['claim_workers_comp_body_parts'] = null;
                $validated['claim_workers_comp_injury_type_other'] = null;
                $validated['claim_workers_comp_body_parts_other'] = null;
            }else{
                $validated['claim_workers_comp_injury_type'] = ClaimFields::injuryType($validated['claim_workers_comp_injury_type'], null);
                $validated['claim_workers_comp_body_parts'] = implode(",", $validated['claim_workers_comp_body_parts']);

                if($request->filled('claim_workers_comp_injury_type_Other')) {
                    $validated['claim_workers_comp_injury_type_other'] = $validated['claim_workers_comp_injury_type_Other'];
                }

                if($request->filled('claim_workers_comp_body_parts_Other')) {
                    $validated['claim_workers_comp_body_parts_other'] = $validated['claim_workers_comp_body_parts_Other'];
                }

                $validated['claim_workers_comp_illness_type'] = null;
                $validated['claim_workers_comp_illness_type_other'] = null;
            }

            $validated['claim_workers_comp_classification'] = ltrim(explode("-", ClaimFields::classifiedCase($validated['claim_workers_comp_classification'], null))[0]);
            $validated['claim_workers_comp_classification_'.trim($validated['claim_workers_comp_classification'])] = true;

            if($claim->claimWorkerComp && $id) {
                $claim->claimWorkerComp->fill($validated)->save();
            }

            if(!$claim->claimWorkerComp && !$id) {
                $claim->claimWorkerComp()->create($validated);
            }
        }elseif($request['claims_type'] == 'claims-general-liability') {
            if($id) {
                $oldClaim = $oldClaim->with('claimGenrelLiab')->first();
            }

            $validated['claim_general_liab_loss_area'] = implode(",", $validated['claim_general_liab_loss_area']);

            if($request->filled('claim_general_liab_loss_area_Other')) {
                $validated['claim_general_liab_loss_area_other'] = $validated['claim_general_liab_loss_area_Other'];
            }

            if($request->filled('claim_general_liab_loss_type_Other')) {
                $validated['claim_general_liab_loss_type_other'] = $validated['claim_general_liab_loss_type_Other'];
            }

            if($claim->claimGenrelLiab) {
                $claim->claimGenrelLiab->fill($validated)->save();
            }

            if(!$claim->claimGenrelLiab && !$id) {
                $claim->claimGenrelLiab()->create($validated);
            }

        }elseif($request['claims_type'] == 'claims-auto-claims') {
            if($id) {
                $oldClaim = $oldClaim->with('claimAuto')->first();
            }

            if($request->filled('claim_auto_nature_Other')) {
                $validated['claim_auto_nature_other'] = $validated['claim_auto_nature_Other'];
            }

            if(!$claim->claimAuto && !$id) {
                $claim->claimAuto()->create($validated);
            }else {
                $claim->claimAuto->fill($validated)->save();
            }

        }elseif($request['claims_type'] == 'claims-property-claims') {
            if($id) {
                $oldClaim = $oldClaim->with('claimProperty')->first();
            }

            $validated['claim_property_loss_type'] = implode(",", $validated['claim_property_loss_type']);

            if($request->filled('claim_property_loss_type_Other')) {
                $validated['claim_property_loss_type_other'] = $validated['claim_property_loss_type_Other'];
            }

            if($claim->claimProperty) {
                $claim->claimProperty->fill($validated)->save();
            }

            if(!$claim->claimProperty && !$id) {
                $claim->claimProperty()->create($validated);
            }

        }elseif($request['claims_type'] == 'claims-labor-claims') {
            if($id) {
                $oldClaim = $oldClaim->with('claimLabour')->first();
            }

            if($claim->claimLabour) {
                $claim->claimLabour->fill($validated)->save();
            }

            if(!$claim->claimLabour && !$id) {
                $claim->claimLabour()->create($validated);
            }

            if($request->filled('claim_employment_involved_parties_name')) {
                $parties = [];
                foreach($validated['claim_employment_involved_parties_name'] as $claim_employment_involved_parties_name) {
                    if(!empty($claim_employment_involved_parties_name)) {
                        $parties[] = ['claim_employment_involved_parties_name' => $claim_employment_involved_parties_name];
                    }
                }

                $claim->claimLabourInvolvedParty()->delete();
                $claim->claimLabourInvolvedParty()->createMany($parties);
            }

        }elseif($request['claims_type'] == 'claims-other') {
            if($id) {
                $oldClaim = $oldClaim->with('claimOther')->first();
            }

            $validated['claim_other_loss_area'] = implode(",", $validated['claim_other_loss_area']);

            if($request->filled('claim_other_loss_area_Other')) {
                $validated['claim_other_loss_area_other'] = $validated['claim_other_loss_area_Other'];
            }

            if($claim->claimOther) {
                $claim->claimOther->fill($validated)->save();
            }

            if(!$claim->claimOther && !$id) {
                $claim->claimOther()->create($validated);
            }
        }


        //notes section
        if($request->filled('note.new')) {
            $notes = [];
            foreach($request->note['new'] as $note) {
                if(isset($note['subject']) && $note['subject'] || isset($note['description']) && $note['description']) {
                    $notes[] = $note;
                }
            }
            if(count($notes)) {
                $claim->notes()->createMany($notes);
                foreach($notes as $note){
                    ClaimHistory::note_added_entry($request->user()->id, $claim->id, $note["subject"]);
                }
            }
        }
        if($id) {
            //notes section
            //removing notes from claims_notes table
            if($request->filled('note.deleted')) {
                foreach($request->note["deleted"] as $note){
                    if($note > 0){
                        $note = Note::findOrFail($note);
                        ClaimHistory::note_deleted_entry($request->user()->id, $claim->id, $note->subject);
                        $note->delete();
                    }
                }
            }

            //update notes from claims_notes table
            if($request->filled('note.updated')) {
                foreach($request->note['updated'] as $key => $value) {
                    Note::where('id', $key)->update(['subject' => $value['subject'], 'description' => $value['description']]);
                    ClaimHistory::note_updated_entry($request->user()->id, $claim->id, $value["subject"]);
                }
            }
        }

        //removing entries from Claim carrier table
        if($request->filled('carrier.delete')){
            foreach($request->carrier['delete'] as $cch){
                if($cch > 0){
                    $claimPolicy = ClaimCarriers::findOrFail($cch);
                    ClaimHistory::carriers_deleted_entry($request->user()->id, $claim->id, $claimPolicy->carrier_policy_id);
                    $claimPolicy->delete();
                }
            }
        }
        //add new entries for carrier
        if($request->filled('carrier.new'))
        {
            $carriers = [];
            foreach($request->carrier['new'] as $carrier) {
                if(isset($carrier['carrier_policy_id']) && $carrier['carrier_policy_id']) {
                    $carriers[] = $carrier;
                }
            }
            if(count($carriers)) {
                $claim->claimCarriers()->createMany($carriers);
                foreach ($carriers as $cch) {
                    ClaimHistory::carriers_added_entry($request->user()->id, $claim->id,
                        $cch['carrier_policy_id']);
                }
            }
        }
        //update carrier from claims_carrier table
        if($request->filled('carrier.updated'))
        {
            foreach ($request->carrier['updated'] as $key=>$value)
            {
                $claimPolicy = ClaimCarriers::find('$key');
                ClaimCarriers::where('id',$key)->update(
                    [
                        'adjusters_name'=>$value['adjusters_name'],
                        'adjusters_email'=>$value['adjusters_email'],
                        'adjusters_phone'=>$value['adjusters_phone'],
                        'amount_paid'=>$value['amount_paid'],
                        'amount_reserved'=>$value['amount_reserved'],
                        'total_incurred'=>$value['total_incurred'],
                        'claim_number'=>$value['claim_number'],
                        'status'=>$value['status'],
                        'date_submitted'=>$value['date_submitted'],
                        'date_closed'=>$value['date_closed'],
                    ]
                );
                ClaimHistory::carriers_updated_entry($request->user()->id, $claim->id, $claimPolicy->carrier_policy_id);
            }
        }

        if($id) {
            $claim->fill($validated)->save();
            //save file against claim id
            if ($request->filled('temp_file')) {
                $this->move_file_to_directory($request->temp_file, 'claims', $request->user()->account_id, $claim->id);
            }

            ClaimHistory::claim_updated_entry(Auth::id(), $claim, $oldClaim);
        }else {
            ClaimHistory::new_claim_entry(Auth::id(), $claim->id);
        }

        return $claim;
    }

    public function claimHistory(int $claim_id)
    {
        $data = '';
        $claimHistory = ClaimHistory::where('claim_id', $claim_id)->get();
        foreach($claimHistory as $history) {
            $data .= '<b>('.localizeDateFormat($history->created_at).') - </b>'.$history->description.'<br>';
        }

        return response()->json(compact('data'));
    }

    public function view_note($id)
    {
        try {
            //to validate scoperResolver find claim
            $claim = Claim::whereHas('notes', function ($query) use ($id) {
                $query->where('id',$id);
            })->first();
            if(empty($claim)){
                throw new Exception("Not found");
            }
        }catch (ModelNotFoundException $exception) {
            return back()->withError($exception->getMessage())->withInput();
        }catch (Exception $exception) {
            return back()->withError($exception->getMessage())->withInput();
        }

        $response['data'] = Note::findOrFail($id);
        $response['message'] = 'Process Completed';

        return response()->json($response, 200);
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

    public function data_table(Request $request)
    {
        if($request->id){
            //to validate scoperResolver find claim
            $claim = Claim::find($request->id);
            if(empty($claim)){
                throw new Exception("Not found");
            }
        }
        return $this->generic_data_table($request);
    }

    public function delete_files($id)
    {
        if($id){
            //to validate scoperResolver find claim
            $file = FileRelationType::where('file_id',$id)->first();
            $model_accessed = Claim::findOrFail($file->model_id);
            if(empty($model_accessed)){
                throw new Exception("Not found");
            }
            return $this->generic_delete_files($id);
        }
    }

    public function recipient_email_fax_notifcation($claim, $request){

        $location_id = $claim->location->id;

        $recipient = App\Recipient::whereHas('RecipientLocationNotifications', function ($query) use ($location_id) {
            $query->where('location_id',$location_id)->where('notify_save_location',1)->orWhere('notify_update_location',1);
        })->select("id", "email", "name")->get();

        $subject = "An incident has been submitted to a carrier in " . config('app.name');
        $html = null;

        $files = [];
        if($request->filled('claim_send_files_to_recipients') && $request->filled('file_ids')){
            $files = File::whereIn('id', explode(',', $request->get('file_ids', [])))->pluck('storage_location')->toArray();
        }

        foreach($recipient as $rcpt){

            $html = (new ClaimRecipient($claim, $rcpt))->render();
            $emailHistory = $this->claimEmailFaxHistoryCreate($claim, $rcpt, $subject, $html);
            Mail::to($rcpt)->send(new ClaimRecipient($claim, $rcpt, $subject, $emailHistory->id, $files));

        }
    }

    public function carrier_email_fax_notifcation($claim, $request){

        $carriers = $request->input("notice_carrier", null);
        if(!$carriers){
            return true;
        }
        $subject = "An incident has been submitted to a carrier in " . config('app.name');
        $html = null;

        $files = [];
        if($request->filled('claim_send_files_to_recipients') && $request->filled('file_ids')){
            $files = File::whereIn('id', explode(',', $request->get('file_ids', [])))->pluck('storage_location')->toArray();
        }

        foreach($carriers as $cr)
        {
            $cr = explode("-", $cr[0]);
            $carrier = CarrierPolicy::findOrFail($cr[0]);
            $html = (new ClaimCarrier($claim, $carrier))->render();
            $emailHistory = $this->claimEmailFaxHistoryCreate($claim, $carrier, $subject, $html);

            if($cr[1] == 'email'){
                Mail::to($carrier)->send(new ClaimCarrier($claim, $carrier, $subject, $emailHistory->id, $files));

            }elseif($cr[1] == 'fax'){

                $emailHistory->communication_type = "fax";
                $emailHistory->email = $carrier->fax;
                $submissionId = $this->claimSubmitViaFax($claim, $carrier, $subject, $html);
                $emailHistory->submission_id = $submissionId;
                if($submissionId > 0){
                    $emailHistory->status = "Success";
                    $emailHistory->status_reason = "Fax delivered";
                    $emailHistory->save();
                }else{
                    $emailHistory->status = "Fail";
                    $emailHistory->status_reason = "FAX number not found";
                    $emailHistory->save();
                    $subject = "FAX delivery fail for Claim.";
                    $html = new DeliveryStatus($emailHistory, $subject);
                    Mail::to($request->user())->send($html);
                }

            }
        }

    }

    protected function claimEmailFaxHistoryCreate($claim, $etc, $subject, $html, $communication_type='Email'){
        $sent_email_data = [
            'account_id' => $claim->account_id,
            'location_id' => $claim->location->id,
            'claim_id' => $claim->id,
            'contract_id' => null,
            'business_item_id' => null,
            'certificate_id' => null,
            'user_id' => Auth::id(),
            'email' => $etc->email,
            'date' => now(),
            'description' => $subject,
            'html' => $html,
            'file_name' => null,
            'communication_type' => $communication_type,
            'status' => 'Pending',
            'status_reason' => 'Email is sent and waiting to be delivered',
            'submission_id' => null,
        ];

        return SentEmailHistory::create($sent_email_data);
    }

    protected function claimSubmitViaFax($claim, $carrier, $subject, $html){

        $faxNumber = $carrier->fax;
        $faxNumber = str_replace(' ', '',
            str_replace('-', '', str_replace('+', '', str_replace('(', '', str_replace(')', '', $faxNumber)))));

        if (!is_numeric($faxNumber)) {
            $faxResult = array(
                'success' => false,
                'notice' => 'The Carrier\'s fax is invalid.'
            );
        } else {
            if (strlen($faxNumber == 10)) {
                $faxNumber = '+1' . $faxNumber;
            }
        }

        $params = new stdClass;
        $params->Username = env('CLAIM_FAX_USER', null);
        $params->Password = env('CLAIM_FAX_PASSWORD', null);
        $params->FaxNumbers = $faxNumber;
        $params->Contacts = $carrier->contact_name;
        $params->FilesData = $html;
        $params->FileTypes = trim($this->filetypes, ';');
        $params->FileSizes = strlen($params->FilesData);
        $params->Postpone = $this->postponetime;
        $params->RetriesToPerform = $this->retries;
        $params->CSID = $this->csid;
        $params->PageHeader = $this->pageheader;
        $params->JobID = '';
        $params->Subject = $subject;
        $params->ReplyAddress = $this->replyemail;
        $params->PageSize = $this->page_size;
        $params->PageOrientation = $this->page_orientation;
        $params->IsHighResolution = $this->high_resolution;
        $params->IsFineRendering = $this->fine_rendering;

        $client = new SoapClient("http://ws.interfax.net/dfs.asmx?wsdl");
        $result = $client->SendfaxEx_2($params);
        return $result->SendfaxEx_2Result;
    }

    public function claimResourcCollection(Request $request){

        $startDate = Carbon::now()->subYear()->toDateTimeString();
        $endDate = Carbon::now()->toDateTimeString();
        $claims = Claim::query()->where("claims_hidden", 0);
        $claims  = $claims->whereBetween("claims_datetime", [$startDate,$endDate]);
        $claims = $claims->with([
            'claimWorkerComp' => function ($query){$query->addSelect('id', 'claim_id', 'claim_workers_comp_employee_name');},
            'claimGenrelLiab' => function ($query){$query->addSelect('id', 'claim_id', 'claim_general_liab_claimant');},
            'claimAuto' => function ($query){$query->addSelect('id', 'claim_id', 'claim_auto_claimant');},
            'claimProperty' => function ($query){$query->addSelect('id', 'claim_id', 'claim_property_loss_type');},
            'claimLabour' => function ($query){$query->addSelect('id', 'claim_id', 'claim_employment_claimant');},
            'claimOther' => function ($query){$query->addSelect('id', 'claim_id', 'claim_other_claimant');},
        ]);
        return  new ClaimCollection($claims->get());
    }
}

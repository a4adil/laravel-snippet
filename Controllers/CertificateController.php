<?php

namespace App\Http\Controllers;

use Auth;
use Illuminate\Support\Facades\Log;
use Mail;
use App\File;
use Exception;
use App\Vendor;
use DataTables;
use App\Account;
use App\Location;
use PDOException;
use App\Certificate;
use App\EmailTemplate;
use App\LegalEntityName;
use App\FileRelationType;
use App\SentEmailHistory;
use App\CertificateHistory;
use Illuminate\Http\Request;
use App\Constants\Permissions;
use Illuminate\Support\Carbon;
use App\Constants\Certificates;
use App\Traits\FileHandlingTrait;
use Illuminate\Database\QueryException;
use App\Http\Resources\CertificateCollection;
use App\Traits\CertificateVendorRequestTrait;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class CertificateController extends Controller
{
    use FileHandlingTrait, CertificateVendorRequestTrait;

    public function __construct()
    {
        \App::Make('bootstrap_form');
        $this->middleware([ 'permission:'.Permissions::Certificates ])->only([ 'index', 'allCertificates', 'create', 'createCertificate',
            'store', 'edit', 'update', 'destroy', 'hideCertificate' ]);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return view('certificates/list');
    }

    public function allCertificates(Request $request)
    {
        $customFilters = $request->custom;
        $show_hidden = $customFilters['show_hidden'];
        $filter_date_from = $customFilters['filter_date_from'];
        $filter_date_to = $customFilters['filter_date_to'];

        $certificates = Certificate::where(['hidden' => $show_hidden]);

        if($filter_date_from != null && $filter_date_to != null) {
            $from_date = Carbon::parse($filter_date_from)->toDateTimeString();
            $to_date = Carbon::parse($filter_date_to)->toDateTimeString();

            $certificates = $certificates->whereBetween('expiry_date', [$from_date, $to_date]);
        }

        return DataTables::of($certificates->get())
            ->addColumn('location', function ($certificate) {
                return $certificate->location ? $certificate->location->name : null;
            })
            ->addColumn('vendor', function ($certificate) {
                if($certificate->vendor) {
                    $data = array();
                    $data['id'] = $certificate->vendor->id;
                    $data['name'] = $certificate->vendor->name;
                    $data['route'] = route('vendors.edit', ['vendor'=> $data['id']]);
                    return $data;
                }else {
                    return null;
                }
            })
            ->addColumn('active_certificate_file', function ($certificate) {
                $files = $this->get_related_files($certificate->id,'certificates',true);
                if(sizeof($files) > 0) {
                    return [
                        "name" => $files[0]->name,
                        "url" => route('certificate.file.download', ['id' => $files[0]->id, 'view' => 'true'])
                    ];
                }
                return null;
            })
            ->addColumn('action', function ($certificate) use ($request) {
                $data['id'] = $certificate->id;
                $data['hiddenText'] = 'Hide';
                if(!$certificate->location_can_edit && !$request->user()->hasAccountPermission(Permissions::Certificates)) {
                    return null;
                }

                if($certificate->hidden) {
                    $data['hiddenText'] = 'Un-hide';
                }

                return view('certificates/partials/actions', $data)->render();
            })
            ->setRowAttr([
                'class' => function($certificate) {
                    $files = $this->get_related_files($certificate->id,'certificates',true);
                    if($certificate->expiry_date < date_format(now(),'Y-m-d') || sizeof($files)==0)
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
        $locations = Location::locationsByPermission(Auth::user(), Permissions::Certificates)->pluck('id', 'name')->flip()->toArray();

        return view('certificates/certificate_location', compact('locations'));
    }

    public function createCertificate(Request $request)
    {
        $certificate = new Certificate();
        $certificate_entites = '';
        $remind_before_users = array();
        $remind_after_users = array();
        $location_id = $request->get('location', null);
        $account_id = Auth::user()->account_id;

        try {
            $location = Location::findOrFail($location_id);
            $account = Account::findOrFail($account_id);
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError('No related location/Account found. Try later.')->withInput();
        }

        $vendors = Vendor::where('account_id', $account->id)->where('has_all_locations', true)
            ->orWhereHas('locations', function ($query) use($location_id) {
                $query->where('location_id', $location_id);
            })->pluck('id', 'name')->flip()->toArray();

        $before_expiry_days = Certificates::getBeforeExpiryDays();
        $after_expiry_weekly_select_options = Certificates::getAfterExpiryWeeklySelectOptions();

        $entities = LegalEntityName::whereHas('legal_entity_has_location', function ($query) use($location_id) {
            $query->where('location_id', '=', $location_id);
        })->orWhere('has_all_locations', true)->get();;
        $account_users = $account->users;

        $email_templates = EmailTemplate::where('template_type', 'certificate')->where('certificate_id', null)
            ->where(function($q) use ($location_id) {
                $q->where('location_id', null)->orWhere('location_id', $location_id);
            })->get();

        $dafault_template_id = '';
        foreach($email_templates as &$template) {
            if($template->default == 1) {
                $dafault_template_id = $template->id;
            }

            if($template->name == 'No Name') {
                $template->name = '(Custom Template)';
            }
        }

        if($dafault_template_id == '') {
            $def_certificate = EmailTemplate::where('template_type', 'certificate')
                ->where('account_id', null)->where('location_id', null)->first();

            if($def_certificate)
                $dafault_template_id = $def_certificate->id;
        }

        $email_templates = $email_templates->pluck('id', 'name')->flip()->toArray();

        return view('certificates/create_edit', compact('location', 'certificate', 'vendors', 'before_expiry_days',
            'entities', 'certificate_entites', 'account_users', 'email_templates', 'remind_before_users', 'remind_after_users',
            'location_id', 'after_expiry_weekly_select_options', 'dafault_template_id'));
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
        $email_template_id = $request->conf_vendor_cert_email_template;

        //in case of Customise email template
        if($request->has('emailTemplateEdit')) {
            $emailTemplate = new EmailTemplate;
            $emailTemplate->account_id = $account_id;
            $emailTemplate->location_id = $request->hidden_location_id;
            $emailTemplate->html = $request->email_template;
            $emailTemplate->template_type = 'certificate';
            //saving Email Template Data
            $emailTemplate->save();
            $email_template_id = $emailTemplate->id;
        }

        $certificateData = $this->storeUpdateCommon($request, 0, $email_template_id);

        try {
            $newCertificate = Certificate::create($certificateData);

            //save file against certificate id
            if($request->filled('temp_file')) {
                $this->move_file_to_directory($request->temp_file, 'certificates', $account_id, $newCertificate->id);
                $desc = 'New certificate file added: '.$request->temp_file['original_name'];
                CertificateHistory::certificate_file_history($newCertificate->id,$desc);            }

            //add certificated id to email template
            if($request->has('emailTemplateEdit')) {
                $emailTemplate->certificate_id = $newCertificate->id;
                $emailTemplate->save();
            }
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError('Something went wrong with certificate creation. Try later.')->withInput();
        }

        Certificate::certificateRelatedSubTablesData('create', $newCertificate, $request, $account_id);

        $send_req_now = $request->vendor_cert_req_now == '1' ? 1 : 0;
        if($send_req_now) {
            //send certificate request email right now
            $this->EmailCertificateRequestNow($newCertificate->id);
        }

        CertificateHistory::new_certificate_entry(Auth::id(), $newCertificate->id);

        return redirect()->route('certificate.index');
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show(int $id)
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
        $certificate_entites = '';
        try {
            $certificate = Certificate::findOrFail($id);
            $location_id = $certificate->location_id;
            $location = Location::findOrFail($location_id);
            $account = Account::findOrFail(Auth::user()->account_id);
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError('No related record found. Try later.')->withInput();
        }

        $vendors = Vendor::where('account_id', $account->id)->where('has_all_locations', true)
            ->orWhereHas('locations', function ($query) use($location_id) {
                $query->where('location_id', $location_id);
            })->pluck('id', 'name')->flip()->toArray();

        $before_expiry_days = Certificates::getBeforeExpiryDays();
        $after_expiry_weekly_select_options = Certificates::getAfterExpiryWeeklySelectOptions();

        $entities = $account->entity;
        $account_users = $account->users;

        //get email templates
        $email_templates = EmailTemplate::where('template_type', 'certificate')
            ->where(function($q) use ($certificate, $location_id) {
                $q->where('location_id', null)->orWhere('location_id', $location_id)->where('certificate_id', null)
                    ->orWhere('certificate_id', $certificate->id);
            })->get();

        $dafault_template_id = '';
        foreach($email_templates as &$template) {
            if($template->default == 1) {
                $dafault_template_id = $template->id;
            }

            if($template->name == 'No Name') {
                $template->name = '(Custom Template)';
            }
        }

        if($dafault_template_id == '') {
            $def_certificate = EmailTemplate::where('template_type', 'certificate')
                ->where('account_id', null)->where('location_id', null)->first();

            if($def_certificate)
                $dafault_template_id = $def_certificate->id;
        }

        $email_templates = $email_templates->pluck('id', 'name')->flip()->toArray();

        $remind_before_users = $certificate->user->where('pivot.before_expiry', 1);
        $remind_after_users = $certificate->user->where('pivot.after_expiry', 1);

        //email History for show edit
        $certificateEmailHistory = $certificate->sentEmailHistory;

        return view('certificates/create_edit', compact('location', 'certificate', 'vendors', 'before_expiry_days',
            'entities', 'certificate_entites', 'account_users', 'email_templates', 'remind_before_users', 'remind_after_users', 'location_id',
            'certificateEmailHistory', 'after_expiry_weekly_select_options', 'dafault_template_id'));
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
        try {
            $old_certificate = Certificate::with(['coverage', 'emailTemplate', 'entity', 'user'])->where('id', $id)->first();
            if(!$old_certificate) {
                Log::error('Certificate Not found for ID:'.$id);
                return back()->withError('Certificate Not found for ID:'.$id)->withInput();
            }

            $certificate = Certificate::findOrFail($id);
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError('No related record found. Try later.')->withInput();
        }

        //if default is email template is attached and the same being used
        $email_template_id = $request->conf_vendor_cert_email_template;

        //check where customised template available
        $customised_email_template = EmailTemplate::where('id', $certificate->email_template_id)->where('certificate_id', $id)->first();
        if(!empty($customised_email_template)) {
            $customised_email_template->html = $request->email_template;
            $customised_email_template->save();
            $email_template_id = $customised_email_template->id;
        }

        //if previous template is default but now new Customide added
        if($request->has('emailTemplateEdit') && empty($customised_email_template)) {
            //saving Email Template Data
            $emailTemplate = new EmailTemplate;
            $emailTemplate->account_id = $certificate->account_id;
            $emailTemplate->location_id = $certificate->location_id;
            $emailTemplate->html = $request->email_template;
            $emailTemplate->template_type = 'certificate';
            $emailTemplate->save();
            $email_template_id = $emailTemplate->id;
        }

        $certificateData = $this->storeUpdateCommon($request, $id, $email_template_id);

        $certificate->update($certificateData);

        if($request->filled('temp_file')) {
            $this->move_file_to_directory($request->temp_file, 'certificates', $certificate->account_id, $certificate->id);
            $desc = 'New certificate file added: '.$request->temp_file['original_name'];
            CertificateHistory::certificate_file_history($certificate->id,$desc);
        }

        Certificate::certificateRelatedSubTablesData('update', $certificate, $request, $certificate->account_id);

        $send_req_now = $request->vendor_cert_req_now == '1' ? 1 : 0;
        if($send_req_now) {
            //send certificate request email right now
            $this->EmailCertificateRequestNow($certificate->id);
        }

        CertificateHistory::certificate_updated_entry($request->user(), $certificate, $old_certificate);

        return redirect()->route('certificate.index');
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
            $certificate = Certificate::findOrFail($id);
            $certificate->coverage()->delete();
            $certificate->emailTemplate()->delete();
            $certificate->certificateHistory()->delete();
            $certificate->sentEmailHistory()->detach();
            $certificate->CertificateExpiryAdditionalEmail()->delete();
            $certificate->entity()->detach();
            $certificate->user()->detach();
            $certificate->delete();
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError('Something went wrong with certificate deletion. Try later.')->withInput();
        }

        return redirect()->route('certificate.index');
    }

    public function getCertificateEntities(Request $request)
    {
        $certificate_entities = "";
        $certificate = "";
        $certificate_id = $request->get('id', null);
        $location_id = $request->get('location_id', null);
        $entities = LegalEntityName::whereHas('Legal_entity_has_location', function ($query) use($location_id) {
            $query->where('location_id', '=', $location_id);
        })->orWhere('has_all_locations', true)->get();
        if($certificate_id != null) {
            try {
                $certificate = Certificate::findOrFail($certificate_id);
            }catch (Exception $exception) {
                Log::error($exception);
                return back()->withError('Error in finding certificate enity. Try later.')->withInput();
            }
        }

        if($certificate != "")
            $certificate_entities = $certificate->entity;


        $response['entities'] = $entities;
        $response['certificate_entities'] = $certificate_entities;
        $response['message'] = 'Process Completed';

        return response()->json($response, 200);
    }

    public function hideCertificate(int $id)
    {
        try {
            $certificate = Certificate::findOrFail($id);
            $certificate->update([ 'hidden' => !$certificate->hidden ]);
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError('Error in hide/un-hide certificate. Try later.')->withInput();
        }

        CertificateHistory::hidden_changed(Auth::id(), $certificate->id, $certificate->hidden);

        return redirect()->route('certificate.index');
    }

    public function certificateHistory(int $certificateId)
    {
        $data = '';
        $certificateHistory = CertificateHistory::where('certificate_id', $certificateId)->get();
        foreach($certificateHistory as $history) {
            $data .= '<b>('.localizeDateFormat($history->created_at).') - </b>'.$history->description.'<br>';
        }

        $response['data'] = $data;
        $response['message'] = 'Process Completed';

        return response()->json($response, 200);
    }

    public function certificateEmailHistory(int $certificateId)
    {
        try {
            $certificate = Certificate::findOrFail($certificateId);
            $certificateEmailHistory = $certificate->sentEmailHistory;
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError('Error in finding certificate email history. Try later.')->withInput();
        }

        $html = '<table class="table table-bordered table-sm"> <thead class="thead-dark"><tr>';
        $html .= '<th>To</th><th>Subject</th><th>Date</th><th>Status</th><th>Attachments</th><th>Action</th>';
        $html .= '</tr><thead><tbody>';

        foreach($certificateEmailHistory as $history) {
            $html .= '<tr>';
            $html .= '<td>'.$history->email.'</td>';
            $html .= '<td>'.$history->description.'</td>';
            $html .= '<td>'.localizeDateFormat($history->date).'</td>';
            $html .= '<td>'.$history->status.'</td>';
            $html .= '<td>'.$history->file_name.'</td>';
            $html .= '<td><a href="'.route('certificate.email', $history->id).'" class="j-show-email" >View Email</a></td>';
            $html .= '</tr>';
        }

        $html .= '</tbody><table>';

        $response['html'] = $html;
        $response['message'] = 'Process Completed';

        return response()->json($response, 200);
    }

    public function certificateEmailHtml(int $emailhistoryId)
    {
        try {
            $certificateEmailHistory = SentEmailHistory::findOrFail($emailhistoryId);
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError('Error in finding certificate sent email history. Try later.');
        }

        $response['data'] = $certificateEmailHistory->html;
        $response['message'] = 'Process Completed';

        return response()->json($response, 200);
    }

    private function EmailCertificateRequestNow(int $certificate_id)
    {
        try {
            $certificate = Certificate::unsafe()->findOrFail($certificate_id);
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError('Error in emailing certificate request. Try later.')->withInput();
        }

        $additional_insured = [];
        $coverages = [];

        if($certificate->additional_insured_request == 1) {
            $certificate_legal_names = LegalEntityName::get_legal_entity_names_for_certificate($certificate_id);
            foreach ($certificate_legal_names as $certificate_legal_name) {
                $additional_insured[] = $certificate_legal_name;
            }
        }

        $certificates_coverages = Certificate::get_coverages_by_certificates_id($certificate_id);
        foreach($certificates_coverages as $certificate_coverage) {
            if($certificate_coverage->name == "Worker's Compensation (W/C)") {
                $amount = array(
                    'amount1' => $certificate_coverage->amount1,
                    'amount2' => $certificate_coverage->amount2,
                    'amount3' => $certificate_coverage->amount3,
                );
            }else {
                $amount = $certificate_coverage->amount1;
            }
            $coverages[$certificate_coverage->name] = $amount;
        }

        $certRequestModel = array(
            'vendors_name' => $certificate->vendor->name,
            'vendors_street' => $certificate->vendor->street,
            'loc_name' => $certificate->location ? $certificate->location->name : '',
            'certificates_coverages' => $coverages,
            'certificates_coverage_other' => '',
            'members_fax' => $certificate->location ? $certificate->location->fax : '',
            'members_billing_addr1' => $certificate->location ? $certificate->location->address : '',
            'members_billing_addr2' => '',
            'members_billing_city' => $certificate->location ? $certificate->location->city : '',
            'members_billing_state' => $certificate->location ? $certificate->location->state : '',
            'members_billing_zip' => $certificate->location ? $certificate->location->zip : '',
            'certificates_request_cert_to_email' => $certificate->cert_req_email,
            'certificates_request_cert_to_fax' => $certificate->cert_req_via_fax_check,
            'certificates_request_cert_to_address' => $certificate->cert_req_via_postal_check,
            'certificates_include_additional_insured_request' => $certificate->additional_insured_request,
            'certificates_default_location_name' => '',
            'certificate_additional_insured' => $additional_insured,
            'send_cert_to' => '',
        );

        $this->sendEmailAndLogHistory($certificate, $certRequestModel);
    }

    private function sendEmailAndLogHistory($certificate, $certRequestModel)
    {
        $message = null;
        $template = EmailTemplate::get_email_template($certificate->email_template_id);
        $html = stripslashes($template->html);
        $newcertRequestModel = $this->generate_html($certRequestModel);
        $new_converted_html = $this->replace_text($html, $newcertRequestModel);
        $current_date = Date('Y-m-d');

        $sent_email_data = [
            'account_id' => $certificate->account->id,
            'location_id' => $certificate->location_id,
            'claim_id' => null,
            'contract_id' => null,
            'business_item_id' => null,
            'user_id' => null,
            'email' => $certificate->vendor->email,
            'date' => now(),
            'description' => $certificate->cert_req_email_subject,
            'html' => $new_converted_html,
            'file_name' => null,
            'communication_type' => 'Email',
            'status' => 'Pending',
            'status_reason' => 'Email is sent and waiting to be delivered',
            'submission_id' => null,
        ];

        try {
            $emailHistory = SentEmailHistory::create($sent_email_data);
            $certificate->sentEmailHistory()->attach($emailHistory->id);

            if($emailHistory) {
                $history_id = $emailHistory->id;
                $email = $certificate->vendor->email;
                $subject = $certificate->cert_req_email_subject;

                $additional_header_data = json_encode(array(
                    'event_id' => 'cert-request',
                    'vendors_id' => $certificate->vendor_id,
                    'vendors_name' => $certificate->vendor->name,
                    'certificates_id' => $certificate->id,
                    'email_history_id' => $history_id
                ));

                Mail::send(array(), array(), function ($m) use ($email, $subject, $additional_header_data, $new_converted_html) {
                    $m->to($email)->subject($subject);
                    $m->getHeaders()->addTextHeader('X-Mailgun-Variables', $additional_header_data);
                    $m->setBody($new_converted_html, 'text/html');
                });
            }
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError('Something went wrong with Email history creation. Try later.')->withInput();
        }
    }

    public function getCertificateExpiryAdditionalEmail(Request $request)
    {
        try {
            $certificate = Certificate::findOrFail($request->get('id', null));
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError($exception->getMessage())->withInput();
        }

        $before_expiry_emails = $certificate->expiryBeforeEmail ? $certificate->expiryBeforeEmail->pluck('email')->toArray() : array();
        $after_expiry_emails = $certificate->expiryAfterEmail ? $certificate->expiryAfterEmail->pluck('email')->toArray() : array();

        $response['before_expiry_emails'] = $before_expiry_emails;
        $response['after_expiry_emails'] = $after_expiry_emails;
        $response['message'] = 'Process Completed';

        return response()->json($response, 200);
    }

    private function storeUpdateCommon($request, $id, $email_template_id)
    {
        $certificateData = [
            'vendor_id' => $request->vendor,
            'email_template_id' => $email_template_id,
            'location_can_view' => $request->non_ac_admin_can_view_cert == '1' ? 1 : 0,
            'location_can_edit' => $request->non_ac_admin_can_edit_del_cert == '1' ? 1 : 0,
            'project_name' => $request->project_name,
            'expiry_date' => $request->cert_expiry,
            'coverages' => $request->coverages != null ? implode(', ', $request->coverages) : null,
            'coverages_other' => $request->coverage_other == '1' ? $request->certificates_coverage_other : null,
            'coverages_other_amount' => $request->coverage_other == '1' ? $request->coverage_other_amount : null,
            'additional_insured_request' => $request->additional_insured_check == '1' ? 1 : 0,
            'remind_bfr_exp_days' => $request->before_expiry_days,
            'remind_vendor' => $request->vendor_cert_req_bfr_exp == '1' ? 1 : 0,
            'aftr_exp_remind_till_update' => $request->aftr_exp_weekly_update,
            'remind_vendor_expired' => $request->has('vendor_cert_req_aftr_exp') ? $request->vendor_cert_req_aftr_exp == '1' ? 1 : 0 : 0,
            'cert_req_email_subject' => $request->conf_vendor_cert_req_email_subject,
            'cert_req_to_email_check' => $request->conf_vendor_cert_sent_to_email_check == '1' ? 1 : 0,
            'cert_req_email' => $request->conf_vendor_cert_sent_to_email_check == '1' ? $request->conf_vendor_cert_sent_to_email_inp : null,
            'cert_req_via_fax_check' => $request->conf_vendor_cert_fax_check == '1' ? 1 : 0,
            'cert_req_via_postal_check' => $request->conf_vendor_cert_post_mail_check == '1' ? 1 : 0,
        ];

        if(!$id) {
            $account_id = $request->user()->account_id;
            $certificateData1 = [ 'account_id' => $account_id, 'location_id' => $request->hidden_location_id ];
            $certificateData = array_merge($certificateData1, $certificateData);
        }

        return $certificateData;
    }

    //validate files datatable permission
    public function data_table(Request $request)
    {
        if($request->id){
            //to validate scoperResolver find Certificate
            $certificate = Certificate::find($request->id);
            if(empty($certificate)){
                Log::error('Files Not Found for id:'.$request->id.'. Try later.');
                return back()->withError('Files Not Found. Try later.')->withInput();
            }
        }
        return $this->generic_data_table($request);
    }

    public function delete_files($id)
    {
        if($id){
            //to validate scoperResolver find Certificate
            $file = FileRelationType::where('file_id',$id)->first();
            $model_accessed = Certificate::findOrFail($file->model_id);
            if(empty($model_accessed)){
                Log::error('Error in file deletion for certificate id:'.$id.'. Try later.');
                return back()->withError('Error in file deletion. Try later.')->withInput();
            }
            $desc = 'Certificate File Deleted: '.File::find($id)->name;
            CertificateHistory::certificate_file_history($model_accessed->id,$desc);
            return $this->generic_delete_files($id);
        }
    }

    public function certificateResourcCollection(Request $request){


        $certificate = Certificate::query()->where("hidden", 0);
        $certificate = $certificate->with([
            'vendor'=>function($query){
            $query->addSelect('id', 'name', 'phone');
        },
        'location' => function($query){
            $query->addSelect('id', 'name');
        }]);
        return  new CertificateCollection($certificate->get());
    }

    public function download(Request $request)
    {
        try {
            $file = File::findOrFail($request['id']);
        }catch (Exception $exception) {
            Log::error("An exception occurred downloading a certificate file", [$exception]);
            return back()->withError($exception->getMessage());
        }

        $relation = $file->FileRelationType()->first();
        $cert = Certificate::find($relation->model_id);
        if(!$cert) {
            return back()->withError("You do not have permission to view this");
        }
        $view = $request->has("view") && $request->input("view") == 'true';

        return $this->unsafeDownload($file, $view);
    }
}

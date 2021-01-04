<?php

namespace App\Http\Controllers;

use Auth;
use Carbon\Carbon;
use Exception;
use DataTables;
use Illuminate\Support\Facades\Log;
use PDOException;
use App\Location;
use App\EmailTemplate;
use App\Constants\Permissions;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class EmailTemplatesController extends Controller
{
    private $_model = 'App\EmailTemplate';
    private $_moduleTitle = 'Email Templates';
    private $_moduleName = 'email_templates';

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index($type)
    {
        $data = array();
        $data['_moduleName'] = $this->_moduleName;

        $data['title'] = $this->_moduleTitle;
        $data['type'] = $type;

        return view($this->_moduleName.'/list')->with('data', $data);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create($type)
    {
        $data = array();
        $data['_moduleName'] = $this->_moduleName;

        try {
            //get location of login user
            $all_locations = Location::locationsByPermission(Auth::user(), Permissions::Certificates)->pluck('id', 'name')
                ->prepend(0, 'All Locations');
            $data['locations'] = $all_locations->flip()->toArray();
            $data['location_id'] = null;
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError('there is an error in fingind location. Try later.')->withInput();
        }


        //load Default email template content from DB
        $defaultTemplate = EmailTemplate::where('template_type', $type)->where('account_id', null)->first();
        $data['html'] = $defaultTemplate['html'];

        // Breadcrumbs Begins
        $data['title'] = $this->_moduleTitle;
        $data['current_page_title'] = 'Create'.' '.Str::singular($this->_moduleTitle);
        $data['type'] = $type;

        return view($this->_moduleName.'/create')->with('data', $data);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request, $type)
    {
        $validationResponse = $this->validate($request, [
            'name' => 'required|max:100',
            'html' => 'required'
            ]);

        $emailTemplateData = $this->storeUpdateCommon($request, 0, $type);

        try {
            $emailTemplate = $this->_model::create($emailTemplateData);
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError('There is an error in creating/updating email template. Try later.')->withInput();
        }

        return redirect()->route($this->_moduleName, $type)->withSuccess('Template created successfully!');
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
            $data = EmailTemplate::findOrFail($id);
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError('No related email template found, Try later!')->withInput();
        }

        $emailContent =  $data['html'];
        $sampleUserCont = array(
            '{:vendors_name}' => 'Sample Vendor',
            '{:certificates_coverages}' => 'In addition, please include the following',
            '{:additional_insured}' => 'names as Additional Insureds:',
            '{:send_certificate_to}' => '',
            '{:location_name}' => 'Sample Location',
            '{:vendors_street}' => '',
            '{:location_fax}' => '',
            '{:location_billing_address}' => '',
        );
        //sample data in the case of claim
        if($data->template_type == 'claim')
        {
            $sampleUserCont = array(
                '{:todays_date}'=>Carbon::parse(now())->format('m/d/Y g:i A'),
                '{:claim_location}'=>'Sample Location',
                '{:carriers_name}'=>'Sample Carrier',
                '{:policy_number}'=>'s-o-m-e_n-u-m-b-e-r',
                '{:claimant}'=>'Sample Claimant',
                '{:date_of_incident}'=>Carbon::parse(now())->format('m/d/Y g:i A'),
                '{:date_notified}'=>Carbon::parse(now())->format('m/d/Y g:i A'),
                '{:brief_description}'=>'Sample Description',
                '{:members_firstname}'=>'Sample First Name',
                '{:members_lastname}'=>'Sample Last Name',
            );
        }

        foreach($sampleUserCont as $key => $value) {
            $emailContent = str_replace($key, $value, $emailContent);
        }

        $response['data'] = $emailContent;
        $response['message'] = 'Process Completed';

        return response()->json($response, 200);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($type, $id)
    {
        try {
            $data = $this->_model::findOrFail($id);
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError('No related email template found, Try later!')->withInput();
        }

        if($data['location_id'] == null) {
            $data['location_id'] = 0;
        }

        $data['_moduleName'] = $this->_moduleName;
        //get location of login user
        $all_locations = Location::locationsByPermission(Auth::user(), Permissions::Certificates)->pluck('id', 'name')
        ->prepend(0, 'All Locations');
        $data['locations'] = $all_locations->flip()->toArray();

        $data['title'] = $this->_moduleTitle;
        $data['current_page_title'] = 'Update'.' '.Str::singular($this->_moduleTitle);
        $data['type'] = $type;

        return view($this->_moduleName.'/create')->with('data', $data);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $type, $id)
    {
        try {
            $emailTemplate = $this->_model::findOrFail($id);
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError('Something went wrong with finding email template for updation, try later!')->withInput();
        }

        $validationResponse = $this->validate($request, [
            'name' => 'required|max:100',
            'html' => 'required'
            ]);

        $emailTemplateData = $this->storeUpdateCommon($request, 0, $type);

        try{
            $emailTemplate->update($emailTemplateData);
        }catch (Exception $exception)
        {
            Log::error($exception);
            return back()->withError('Something went wrong with updating email template, try later!')->withInput();
        }

        return redirect()->route($this->_moduleName, $type)->withSuccess('Email template Updated successfully!');
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
            $result = $this->_model::findOrFail($request->id);
            $result->delete();
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError('Something went wrong with email template deletion, try later!')->withInput();
        }
        
        $response['message'] = 'Email Template deleted successfully!';
        return response()->json($response, 200);
    }

    // data table
    public function data_table($template_type)
    {
        $account_id = auth()->user()->account_id;
       
        $tableData = $this->_model::where('template_type', $template_type)->where('account_id', $account_id)->get();

        return DataTables::of($tableData)
            ->addColumn('location', function ($tableData) {
                $data = 'All Locations';
                if($tableData->location_id !== null) {
                    $data = $tableData->location ? $tableData->location->name : null;
                }
                return $data;
            })
            ->addColumn('templateName', function ($tableData) {
                $data = $tableData->name;
                if($tableData->default == true)
                {
                    $data = $tableData->name . ' (default)';
                }
                return $data;
            })
            ->addColumn('action', function ($tableData) use($template_type) {
                $optionData = $tableData;
                $optionData['edit'] = route($this->_moduleName.'.edit', [$template_type, $tableData['id']]);
                return view($this->_moduleName.'.actions',$optionData)->render();
            })
            ->make(true);
    }

    //get email template
    public function get_email_template(Request $request)
    {
        try {
            $data = EmailTemplate::findOrFail($request->id);
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError('No related email template found!')->withInput();
        }

        $emailBody =  $data['html'];
        if($request->includeSampleContent == 'true') {
            //sample data for certificate
            $sampleUserContent = array(
                '{:vendors_name}' => 'Sample Vendor',
                '{:certificates_coverages}' => 'In addition, please include the following',
                '{:additional_insured}' => 'names as Additional Insureds:',
                '{:send_certificate_to}' => 'Sample User',
                '{:location_name}' => 'Sample Location',
                '{:vendors_street}' => '123 Main Street City of Light, USA',
                '{:location_fax}' => '1-800-555-5555',
                '{:location_billing_address}' => '11 Down Street main Hall California, USA',
            );
            //sample data in the case of claim
            if($data->template_type == 'claim')
            {
                $sampleUserContent = array(
                    '{:todays_date}'=>Carbon::parse(now())->format('m/d/Y g:i A'),
                    '{:claim_location}'=>'Sample Location',
                    '{:carriers_name}'=>'Sample Carrier',
                    '{:policy_number}'=>'s-o-m-e_n-u-m-b-e-r',
                    '{:claimant}'=>'Sample Claimant',
                    '{:date_of_incident}'=>Carbon::parse(now())->format('m/d/Y g:i A'),
                    '{:date_notified}'=>Carbon::parse(now())->format('m/d/Y g:i A'),
                    '{:brief_description}'=>'Sample Description',
                    '{:members_firstname}'=>'Sample First Name',
                    '{:members_lastname}'=>'Sample Last Name',
                );
            }

            foreach($sampleUserContent as $key => $value) {
                $emailBody = str_replace($key, $value, $emailBody);
            }
        }

        $response['data'] = $emailBody;
        $response['message'] = 'Process Completed';

        return response()->json($response, 200);
    }

    // Make/Set Active File
    public function set_default_template(Request $request)
    {
        //Remove all Default
        $this->_model::where('template_type', $request->template_type)->where('account_id', auth()->user()->account_id)
        ->where('location_id', null)->update(['default' => false]);
        //find and set default
        $data = $this->_model::where('id', $request->id)->first();
        $data->default = true;

        try{
            $data->save();
            return array('message' => 'Template set to default!');
        } catch (Exception $exception){
            Log::error($exception);
            return array('message' => 'Error in setting default template!');
        }

    }

    private function storeUpdateCommon($request, $id, $template_type)
    {
        if($request->location == 0) {
            $request->location = null;
        }
        
        $emailTemplateData = [
            'location_id' => $request->location,
            'name' => $request->name,
            'html' => $request->html,
            'template_type' => $template_type
        ];

        if(!$id) {
            $account_id = $request->user()->account_id;
            $emailTemplateData1 = [ 'account_id' => $account_id ];
            $emailTemplateData = array_merge($emailTemplateData1, $emailTemplateData);
        }

        return $emailTemplateData;
    }
}

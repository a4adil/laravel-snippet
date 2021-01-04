<?php

namespace App\Http\Controllers;

use Auth;
use Exception;
use DataTables;
use App\Vendor;
use App\Location;
use App\VendorHistory;
use App\VendorHasLocation;
use App\Constants\Permissions;
use App\Traits\GenerateExcelReport;
use App\Http\Requests\CreateVendor;
use App\Http\Requests\UpdateVendor;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

class VendorsController extends Controller
{
    use GenerateExcelReport;

    private $_moduleTitle = 'Vendors';
    private $_moduleName = 'vendors';

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

        //get location of login user
        $locationsArray = array();
        $locations = Location::getAccountLocations();
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
        $data['has_all_locations'] = null;
        $data['vendor_locations'] = [];
        $data['location_can_view'] = null;
        $data['location_can_edit'] = null;

        return view($this->_moduleName.'/create', compact('data'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(CreateVendor $request)
    {
        //dd($request);
        $user = Auth::User();
        $vendor = new Vendor;

        $vendor->has_all_locations = 1;
        if(!in_array('all',$request['locations']))
        {
            $vendor->has_all_locations = 0;
        }
        $vendor->location_can_view = 0;
        if($request->has('location_can_view'))
        {
            $vendor->location_can_view = 1;
        }
        $vendor->location_can_edit = 0;
        if($request->has('location_can_edit'))
        {
            $vendor->location_can_edit = 1;
        }

        $vendor->account_id = $user['account_id'];
        $vendor->name = $request->name;
        $vendor->contact_name = $request->contact_name;
        $vendor->email = $request->email;
        $vendor->phone = $request->phone;
        $vendor->street = $request->street;
        $vendor->services = $request->services;

        try {
            $vendor->save();
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError("There's an error in creating vendor, try later!")->withInput();
        }

        //vendor location if other than all
        if(!in_array('all',$request['locations']))
        {
            $vendorLocations = array();
            foreach($request['locations'] as $location)
            {
                $tempData['vendor_id'] = $vendor->id;
                $tempData['location_id'] = $location;
                array_push($vendorLocations,$tempData);
            }
            VendorHasLocation::insert($vendorLocations);
        }
        return redirect()->route($this->_moduleName.'.index')->withSuccess('Vendor created successfully!');
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
        $data = array();
        try {
            $data = Vendor::findOrFail($id);
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError("No related record found, try later!")->withInput();
        }

        $data['_moduleName'] = $this->_moduleName;
        $data['title'] = $this->_moduleTitle;
        $data['current_page_header'] = 'Edit'.' '.Str::plural($this->_moduleTitle, 2);
        //multiple location case
        if($data['has_all_locations'] == 0) {
            $data['vendor_locations'] = $data->vendorHasLocation ? $data->vendorHasLocation->pluck('location_id')->toArray() : array();
        }else {
            $data['vendor_locations'] = [];
        }

        return view($this->_moduleName.'/create', compact('data'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(UpdateVendor $request, $id)
    {
        try {
            $oldData = $vendor = Vendor::findOrFail($id);
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError("No related record found, try later!")->withInput();
        }

        $vendor->has_all_locations = 1;
        if(!in_array('all',$request['locations']))
        {
            $vendor->has_all_locations = 0;
        }
        $vendor->location_can_view = 0;
        if($request->has('location_can_view'))
        {
            $vendor->location_can_view = 1;
        }
        $vendor->location_can_edit = 0;
        if($request->has('location_can_edit'))
        {
            $vendor->location_can_edit = 1;
        }


        $vendor->name = $request->name;
        $vendor->contact_name = $request->contact_name;
        $vendor->email = $request->email;
        $vendor->phone = $request->phone;
        $vendor->street = $request->street;
        $vendor->services = $request->services;

        //Record history Section Begin
        if (sizeof($oldData->locations)>0)
        {
            $locationArr = array();
            foreach($vendor->locations as $location)
            {
                $locationArr[] = $location['id'];
            }
            $oldData['locations'] = $locationArr;
        }
        $history = $this->save_history($oldData);
        unset($vendor->locations);
        //record History Section end

        try {
            //saving vendor Data
            $vendor->save();
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError("There's an error in updating vendor, try later!")->withInput();
        }

        //removing old entries from vendorHaslocation table
        $oldEntries = VendorHasLocation::where('vendor_id',$id);
        $oldEntries->delete();

        //vendor location if other than all
        if(!in_array('all',$request['locations']))
        {
            $vendorLocations = array();
            foreach($request['locations'] as $location)
            {
                $tempData['vendor_id'] = $vendor->id;
                $tempData['location_id'] = $location;
                array_push($vendorLocations,$tempData);
            }
            VendorHasLocation::insert($vendorLocations);
        }

        return redirect()->route($this->_moduleName.'.index')->withSuccess('Vendor updated successfully!');
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
            $vendor = Vendor::findOrFail($request->id);
            $vendor->delete();
        }catch (Exception $exception) {
            Log::error($exception);
            $response['message'] = 'Vendor deletion error occur, try later!';
            return response()->json($response, 200);
        }

        $response['message'] = 'Vendor deleted successfully!';
        return response()->json($response, 200);
    }

    // data table
    public function data_table(Request $request)
    {
        $customFilters = $request->custom;
        $vendors_status = $customFilters['show_hidden'];
        $tableData = Vendor::where('hidden', $vendors_status);

        if($customFilters['location'] && $customFilters['location'] !== '') {
            $locationId = $customFilters['location'];
            $tableData = $tableData->where(function($builder) use($locationId) {
                $builder->whereHas('vendorHasLocation', function ($query) use($locationId) {
                    return $query->where('location_id', $locationId);
                })->orWhere('has_all_locations', 1);
            });
        }

        $tableData = $tableData->get();
        return DataTables::of($tableData)
            ->addColumn('locations', function ($vendor) {
                if($vendor->has_all_locations == 1) {
                    return 'All Locations';
                }
                $locationNames = $vendor->locations->pluck('name')->toArray();
                return implode(', ', $locationNames);
            })
            ->addColumn('num_of_cert', function ($vendor) {
                return $vendor->certificate()->count();
            })
            ->addColumn('action', function ($vendor) use ($request) {
                $data['id'] = $vendor->id;
                $data['hiddenText'] = 'Hide';
                if(!$vendor->location_can_edit && !$request->user()->hasAccountPermission(Permissions::Certificates)) {
                    return null;
                }

                if($vendor->hidden) {
                    $data['hiddenText'] = 'Un-hide';
                }

                return view('vendors/actions', $data)->render();
            })
            ->make(true);
    }

    public function hide($id)
    {
        try {
            $vendor = Vendor::findOrFail($id);
            $vendor->update([ 'hidden' => !$vendor->hidden ]);
        }catch (Exception $exception) {
            Log::error($exception);
            $response['message'] = 'No related record found!';
            return response()->json($response, 200);
        }

        $changedVal['description'] = '<b>Vendor ' . ($vendor->hidden ? 'Hidden' : 'Unhidden') . '</b>';
        $changedVal['vendor_id'] = $id;
        $changedVal['user_id'] = Auth::id();
        VendorHistory::create($changedVal);
        //save history Ends

        if($vendor) {
            $response['message'] = 'Updated Successfully!';
            return response()->json($response, 200);
        }

        $response['message'] = 'Fail to update!';
        return response()->json($response, 404);
    }

    public function vendors_excel_export()
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $this->generateCliamsReportSheet($spreadsheet, 'Vendors Export', 'G');

        // Adding header data
        $sheet->setCellValue('A1', 'Vendor Name');
        $sheet->setCellValue('B1', 'Contact Name');
        $sheet->setCellValue('C1', 'Email');
        $sheet->setCellValue('D1', 'Phone Number');
        $sheet->setCellValue('E1', 'Address');
        $sheet->setCellValue('F1', 'Services');
        $sheet->setCellValue('G1', 'Location');

        // Getting contract data
        $row_counter = 2;
        $account_id = auth()->user()->account_id;
        $vendors = Vendor::where('account_id', $account_id)->get();

        // Setting data
        foreach($vendors as $vendor) {
            $locationNames = $vendor->locations->pluck('name')->toArray();
            $locations = implode(', ', $locationNames);
            if($vendor->has_all_locations == 1) {
                $locations = 'All Locations';
            }
            $sheet->setCellValue('A'.$row_counter, $vendor->name);
            $sheet->setCellValue('B'.$row_counter, $vendor->contact_name);
            $sheet->setCellValue('C'.$row_counter, $vendor->email);
            $sheet->setCellValue('D'.$row_counter, $vendor->phone);
            $sheet->setCellValue('E'.$row_counter, $vendor->street);
            $sheet->setCellValue('F'.$row_counter, $vendor->services);
            $sheet->setCellValue('G'.$row_counter, $locations);
            $row_counter++;
        }
        $this->downloadCliamsReportSheet($spreadsheet, $sheet);
    }

    private function save_history($data)
    {
        $originalData = $data->getOriginal();
        $modifiedData = $data->getAttributes();
        $keys = array_keys($originalData);
        $changedVal = array();

        foreach($keys as $key) {
            if($originalData[$key] != $modifiedData[$key] ) {
                if($originalData[$key] === 1) {
                    $originalData[$key] = 'View';
                }
                elseif ($originalData[$key] === 0)
                {
                    $originalData[$key] = 'Hide';
                }

                if($modifiedData[$key] === 0) {
                    $modifiedData[$key] = 'Hide';
                }
                elseif ($modifiedData[$key] === 1)
                {
                    $modifiedData[$key] = 'View';
                }

                //location history
                if($key == 'has_all_locations') {
                    $key = 'Location';
                    $modifiedData[$key] = 'Some';
                    $originalData[$key] = 'All Locations';
                    if($data->has_all_locations) {
                        $originalData[$key] = 'Some';
                        $modifiedData[$key] = 'All Locations';
                    }
                }

                $changedVal['description'] = ucfirst($key).' <b>Changed From:</b> '.$originalData[$key].' <b>to:</b> '.$modifiedData[$key];
                $changedVal['vendor_id'] = $originalData['id'];
                $changedVal['user_id'] = Auth::id();
                VendorHistory::create($changedVal);
            }
        }
    }

    public function view_history($id)
    {
        $data = VendorHistory::where('vendor_id', $id)->get();

        $response['data'] = $data;
        $response['message'] = 'Process Completed';

        return response()->json($response, 200);
    }

    public function get_vendor_details($id)
    {
        try {
            $data = Vendor::findOrFail($id);
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError("There's an error in getting vendor details, try later")->withInput();
        }

        return response()->json($data, 200);
    }


}

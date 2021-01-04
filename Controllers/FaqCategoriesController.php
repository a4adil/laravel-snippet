<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use DataTables;
use App\Constants\Permissions;
class FaqCategoriesController extends Controller
{
    private $_model = "App\FaqCategory";
    private $_moduleTitle = "FAQ Categories";
    private $_moduleName = "faq_categories";

    public function __construct()
    {
        $this->middleware(['permission:'.Permissions::ManageFaqs])->only(['create', 'store', 'edit', 'update']);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        //
        $data = array();
        $data['_moduleName'] = $this->_moduleName;
        $data['title'] = $this->_moduleTitle;

        return view($this->_moduleName.'/list')->with('data', $data);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
        $data = array();
        $data['_moduleName'] = $this->_moduleName;
        $data['title'] = $this->_moduleTitle;
        $data['current_page_header'] = 'Create'.' '.Str::singular($this->_moduleTitle);

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
        //
        $validationResponse = $this->validate($request, [
            'name' => 'required'
        ]);
        $user = Auth::user();
        //initializing model of the module
        $model = new $this->_model;
        $model->name = $request->name;
        $model->hidden = isset($request->hidden) && $request->hidden == 1 ? 1 : 0;
        $model->user_id = $user->id;

        try {
            //saving Data
            $model->save();
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError("There's an error in creation faq category, try later!")->withInput();
        }


        return redirect()->route($this->_moduleName.'.index')->withSuccess($this->_moduleTitle.' '.'created successfully!');
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Category  $categories
     * @return \Illuminate\Http\Response
     */
    public function show(Request $request)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Category  $categories
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        //

        try {
            $data =  $this->_model::findOrFail($id);
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError("No related record found, try later!")->withInput();
        }
        $data['_moduleName'] = $this->_moduleName;
        $data['title'] = $this->_moduleTitle;
        $data['current_page_header'] = 'Edit'.' '.Str::singular($this->_moduleTitle);

        return view($this->_moduleName.'/create')->with('data', $data);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Category  $categories
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        //
        $validationResponse = $this->validate($request, [
            'name' => 'required'
        ]);
        $model = $this->_model::find($id);
        $model->name = $request->name;
        $model->hidden = isset($request->hidden) && $request->hidden == 1 ? 1 : 0;

        try {
            //saving Data
            $model->save();
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError("There's an error in updating faq category, try later!")->withInput();
        }

        return redirect()->route($this->_moduleName.'.index')->withSuccess($this->_moduleTitle.' '.'Updated successfully!');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Category  $categories
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request)
    {
        //
    }

    // data table
    public function data_table(Request $request)
    {
        $tableData = $this->_model::all();

        return DataTables::of($tableData)
            ->addColumn('action', function ($tableData) use ($request){
                $optionData = $tableData;
                if($request->user()->can(Permissions::ManageFaqs)) {
                    $optionData['edit'] = route($this->_moduleName.'.edit', $tableData['id']);
                }
                return view('faq_categories.actions',$optionData)->render();
            })
            ->make(true);
    }
}

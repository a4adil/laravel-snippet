<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use DataTables;
use Illuminate\Support\Facades\Auth;
use App\Constants\Permissions;

class TutorialController extends Controller
{
    private $_model = "App\Tutorial";
    private $_moduleTitle = "Tutorials";
    private $_moduleName = "tutorials";

    public function __construct()
    {
        $this->middleware(['permission:'.Permissions::ManageTutorials])->only(['create', 'store', 'edit', 'update']);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        //
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
            'tutorial_title' => 'required|max:255',
            'link' => 'required',
        ]);
        $user = Auth::user();
        //initializing model of the module
        $model = new $this->_model;
        $model->user_id = $user->id;
        $model->tutorial_title = $request->tutorial_title;
        $model->link = $request->link;
        $model->hidden = isset($request->hidden) && $request->hidden == 1 ? 1 : 0;
        try {
            //saving Data
            $model->save();
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError("There's an error in creating Tutorial, try later!")->withInput();
        }

        return redirect()->route($this->_moduleName.'.index')->withSuccess($this->_moduleTitle.' '.'created successfully!');
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\FrequentlyAskQuestion  $frequentlyAskQuestions
     * @return \Illuminate\Http\Response
     */
    public function show(Request $request)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\FrequentlyAskQuestion  $frequentlyAskQuestions
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        //
        $data =  $this->_model::findOrFail($id);
        $data['_moduleName'] = $this->_moduleName;
        $data['title'] = $this->_moduleTitle;
        $data['current_page_header'] = 'Edit'.' '.Str::singular($this->_moduleTitle);
        return view($this->_moduleName.'/create')->with('data', $data);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\FrequentlyAskQuestion  $frequentlyAskQuestions
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        //
        $validationResponse = $this->validate($request, [
            'tutorial_title' => 'required|max:255',
            'link' => 'required'
        ]);

        $model = $this->_model::find($id);
        $model->tutorial_title = $request->tutorial_title;
        $model->link = $request->link;
        $model->hidden = isset($request->hidden) && $request->hidden == 1 ? 1 : 0;
        try {
            //saving Data
            $model->save();
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError("There's an error in updating Tutorial, try later!")->withInput();
        }
        return redirect()->route($this->_moduleName.'.index')->withSuccess($this->_moduleTitle.' '.'Updated successfully!');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\FrequentlyAskQuestion  $frequentlyAskQuestions
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request)
    {
        //
        $model = $this->_model::findOrFail($request->id);
        try {
            $model->delete();
            $response['message'] = "Tutorial deleted successfully!";
            $response['result'] = true;
            return response()->json($response, 200);
        }catch (Exception $exception) {
            Log::error($exception);
            $response['message'] = "Fail to delete Tutorial!";
            $response['result'] = false;
            return response()->json($response, 200);
        }
    }

    // data table
    public function data_table(Request $request)
    {
        $whereCondition = array();
        if(!$request->user()->can(Permissions::ManageTutorials)) {
            $whereCondition = array('hidden'=>0);
        }
        $tableData = $this->_model::where($whereCondition);

        return DataTables::of($tableData)
            ->addColumn('action', function ($tableData) use ($request){
                $optionData = $tableData;
                if($request->user()->can(Permissions::ManageTutorials)) {
                    $optionData['edit'] = route($this->_moduleName.'.edit', $tableData['id']);
                }
                return view($this->_moduleName.'.actions',$optionData)->render();
            })
            ->rawColumns(['tutorial_title','link','hidden','action'])
            ->setRowAttr([
                'class' => function($tableData) {
                    if($tableData->hidden)
                    {
                        return "alert-warning";
                    }
                    return '';
                },
            ])
            ->make(true);
    }

    // Show/Hide Record
    public function hide_show_record(Request $request)
    {
        $id = $request['id'];
        try {
            $data = $this->_model::findOrFail($id);
            $data->update([ 'hidden' => !$data->hidden ]);
        }catch (Exception $exception) {
            Log::error($exception);
            return redirect()->route($this->_moduleName.'.index');
        }
        if($data) {
            $response['message'] = 'Updated Successfully!';
            return response()->json($response, 200);
        }

        $response['message'] = 'Fail to update!';
        return response()->json($response, 200);
    }
}

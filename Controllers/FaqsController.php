<?php

namespace App\Http\Controllers;

use App\FaqCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use DataTables;
use Illuminate\Support\Facades\Auth;
use App\Constants\Permissions;

class FaqsController extends Controller
{
    private $_model = "App\Faqs";
    private $_moduleTitle = "FAQs";
    private $_moduleName = "faqs";

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

        try {
            $data['categories'] = FaqCategory::where('hidden',0)->pluck('name','id')->toArray();
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError("There's an error in finding faq category, try later!")->withInput();
        }
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
            'question' => 'required',
            'answer' => 'required',
            'category_id' => 'required'
        ]);
        $user = Auth::user();
        //initializing model of the module
        $model = new $this->_model;
        $model->user_id = $user->id;
        $model->question = $request->question;
        $model->answer = $request->answer;
        $model->key_words = $request->key_words;
        $model->category_id = $request->category_id;
        $model->hidden = isset($request->hidden) && $request->hidden == 1 ? 1 : 0;
        try {
            //saving Data
            $model->save();
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError("There's an error in creating FAQ, try later!")->withInput();
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

        try {
            $data['categories'] = FaqCategory::where('hidden',0)->pluck('name','id')->toArray();
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError("There's an error in finding faq category, try later!")->withInput();
        }
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
            'question' => 'required',
            'answer' => 'required',
            'category_id' => 'required'
        ]);

        $model = $this->_model::find($id);
        $model->question = $request->question;
        $model->answer = $request->answer;
        $model->key_words = $request->key_words;
        $model->category_id = $request->category_id;
        $model->hidden = isset($request->hidden) && $request->hidden == 1 ? 1 : 0;
        try {
            //saving Data
            $model->save();
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError("There's an error in updating FAQ, try later!")->withInput();
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
            $response['message'] = "Question deleted successfully!";
            $response['result'] = true;
            return response()->json($response, 200);
        }catch (Exception $exception) {
            Log::error($exception);
            $response['message'] = "Fail to delete Question!";
            $response['result'] = false;
            return response()->json($response, 200);
        }
    }

    // data table
    public function data_table(Request $request)
    {
        $tableData = $this->_model::all();

        return DataTables::of($tableData)
            ->addColumn('hidden', function ($tableData) {
                return $tableData->hidden == 1 ? '<span class="badge badge-secondary">Hidden</span>' : '<span class="badge badge-primary">Show</span>';
            })
            ->addColumn('category', function ($tableData) {
                return $tableData->category['name'];
            })
            ->addColumn('action', function ($tableData) use ($request){
                $optionData = $tableData;
                if($request->user()->can(Permissions::ManageFaqs)) {
                    $optionData['edit'] = route($this->_moduleName.'.edit', $tableData['id']);
                }
                return view('faqs.actions',$optionData)->render();
            })
            ->rawColumns(['question','answer','hidden','action'])
            ->make(true);
    }
}

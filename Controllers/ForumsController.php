<?php

namespace App\Http\Controllers;


use App\User;
use App\Forum;
use Exception;
use DataTables;
use App\UserInfo;
use App\ForumTopic;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Constants\Permissions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Collection;

class ForumsController extends Controller
{
    private $_model = "App\Forum";
    private $_moduleTitle = "Forums";
    private $_moduleName = "forums";

    public function __construct()
    {
        $this->middleware(['permission:'.Permissions::ForumAdmin])->only(['create', 'store', 'edit', 'update', 'destroy', 'hide_show_forum']);
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

        //Breadcrumb data
        $breadcrumb_data = array( route($this->_moduleName.'.index') => $this->_moduleTitle);
        //$data['breadcrumb'] = breadcrumb($breadcrumb_data);
        // End Breadcrumbs

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

        // Breadcrumbs Begins
        $breadcrumb_data = array(
            route($this->_moduleName.'.index') => $this->_moduleTitle." ". "List",
            'current_page' => $data['current_page_header']
        );
        //$data['breadcrumb'] = breadcrumb($breadcrumb_data);
        // End Breadcrumbs

        $data['statuses'] = array('active'=>'Active','locked'=>'Locked');
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
        $validationResponse = $this->validate($request, [
            'name' => 'required|max:255',
            'is_private' => 'sometimes|boolean']);

        $user = Auth::user();
        //initializing model of the module
        $model = new $this->_model;
        $model->account_id = $user->account_id;
        $model->name = $request->name;
        $model->description = $request->description;
        $model->status = $request->status;
        $model->topic_approve = isset($request->topic_approve) && $request->topic_approve == 1 ? 1 : 0;
        $model->post_approve = isset($request->post_approve) && $request->post_approve == 1 ? 1 : 0;
        $model->is_private = $request->get('is_private', 0);
        try {
            //saving Data
            $model->save();
            if($model->is_private && !empty($request->get('forum_users', []))):
                $model->privateUsers()->attach($request->get('forum_users'));
            endif;
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError("There's an error in creating Forum, try later!")->withInput();
        }
        //if user subscribe to topic
        if ($request->has('subscribe'))
        {
            $model->NotificationSubscriber()->create(['user_id'=>$user->id]);
        }

        return redirect()->route($this->_moduleName.'.index')->withSuccess($this->_moduleTitle.' '.'created successfully!');
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Forum  $forum
     * @return \Illuminate\Http\Response
     */
    public function show(Forum $forum)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Forum  $forum
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        //
        $data =  $this->_model::findOrFail($id);

        $data['_moduleName'] = $this->_moduleName;
        $data['title'] = $this->_moduleTitle;
        $data['current_page_header'] = 'Edit'.' '.Str::singular($this->_moduleTitle);

        // Breadcrumbs Begins
        $breadcrumb_data = array(
            route('home') => 'Dashboard',
            route($this->_moduleName.'.index') => $this->_moduleTitle." ". "List",
            'current_page' => $data['current_page_header']
        );
        //$data['breadcrumb'] = breadcrumb($breadcrumb_data);
        // End Breadcrumbs
        //notification subscriber
        $data['subscribe'] = $this->_model::get_subscriber($id);
        $data['statuses'] = array('active'=>'Active','locked'=>'Locked');

        return view($this->_moduleName.'/create')->with('data', $data);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Forum  $forum
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        //
        $validationResponse = $this->validate($request, [
            'name' => 'required|max:255',
            'is_private' => 'sometimes|boolean']);

        $model = $this->_model::findorFail($id);

        $model->name = $request->name;
        $model->description = $request->description;
        $model->status = $request->status;
        $model->topic_approve = isset($request->topic_approve) && $request->topic_approve == 1 ? 1 : 0;
        $model->post_approve = isset($request->post_approve) && $request->post_approve == 1 ? 1 : 0;
        $model->is_private = $request->get('is_private', 0);

        //notification subscriber
        $subscribe = $this->_model::get_subscriber($id);
        if(!$subscribe){
            //if user subscribe to topic
            if ($request->has('subscribe'))
            {
                $model->NotificationSubscriber()->create(['user_id'=>Auth::id()]);
            }
        }
        else{
            if (!$request->has('subscribe'))
            {
                $this->_model::un_subscribe_to_forum_topic($id);
            }
        }
        try {
            //saving Data
            $model->save();
            
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError("There's an error in updating Forum, try later!")->withInput();
        }

        $model->privateUsers()->detach($model->privateUsers);

        if($model->is_private && !empty($request->get('forum_users', []))):
            $model->privateUsers()->attach($request->get('forum_users'));
        endif;

        return redirect()->route($this->_moduleName.'.index')->withSuccess($this->_moduleTitle.' '.'Updated successfully!');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Forum  $forum
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request)
    {
        //
        $model = $this->_model::findOrFail($request->id);

        try{
            $model->delete();
            $response['message'] = "Forum deleted successfully!";
            $response['result'] = true;
            return response()->json($response, 200);
        }catch (Exception $exception)
        {
            Log::error($exception);
            $response['message'] = "Fail to delete forum!";
            $response['result'] = false;
            return response()->json($response, 200);
        }

    }

    // data table
    public function data_table(Request $request)
    {
        $whereCondition = array();
        if(!$request->user()->can(Permissions::ForumAdmin)) {
            $whereCondition = array('hidden'=>0);
        }

        $tableData = $this->_model::where($whereCondition);

        if(!$request->user()->can(Permissions::ForumAdmin)) {

            $tableData = $tableData->where(function($query) use($request) {

                        $query->whereHas('privateUsers', function($query) use($request){
                            $query->where('user_id', $request->user()->id);
                            $query->where('is_private', 1);
                        });

                        $query->orwhere('is_private', 0);

            });
        }

        return DataTables::of($tableData)
            ->addColumn('name', function ($tableData) {
                $hidden = '';
                if ($tableData->hidden)
                {
                    $hidden = "(Hidden)";
                }
            return '<a href="'.route('forum_topics', $tableData['id']).'">'.$tableData->name.'</a>'.' '.$hidden;
            })
            ->addColumn('created_on', function ($tableData) {
            return localizeDate($tableData['created_at']);
            })
            ->addColumn('topics', function ($tableData) {
            $num_forum_topics = ForumTopic::where('forum_id',$tableData->id)->count();
            return $num_forum_topics;
            })
            ->addColumn('subscribe', function ($tableData) {
            return $this->_model::get_subscriber($tableData['id']);
            })
            ->addColumn('action', function ($tableData) use ($request){
                $optionData = $tableData;
                $optionData['edit'] = route($this->_moduleName.'.edit', $tableData['id']);
                $optionData['topics'] = route('forum_topics', $tableData['id']);
                if($request->user()->can(Permissions::ForumAdmin)) {
                    $optionData['hide_show'] = true;
                }
                return view($this->_moduleName.'.actions',$optionData)->render();
            })
            ->setRowAttr([
                'class' => function($tableData) {
                    if($tableData->hidden)
                    {
                        return "alert-warning";
                    }
                    return '';
                },
            ])
            ->rawColumns(['name','action'])
            ->make(true);
    }

    //hide show Forum
    public function hide_show_forum(Request $request)
    {
        try {
            $forum = $this->_model::findOrFail($request['id']);
            $forum->update([ 'hidden' => !$forum->hidden ]);
        }catch (Exception $exception) {
            Log::error($exception);
            return redirect()->route($this->_moduleName.'.index');
        }
        if($forum) {
            $response['message'] = 'Updated Successfully!';
            return response()->json($response, 200);
        }

        $response['message'] = 'Fail to update!';
        return response()->json($response, 200);
    }

    public function subscribe_to_forum(Request $request)
    {
        $data = $this->_model::findOrFail($request['id']);
        //if user subscribe to topic
        if ($request['checkbox_event']) {
            $data->NotificationSubscriber()->create(['user_id'=>Auth::id()]);
            $message = "Subscribe to notification successfully";
        }
        else {
            $this->_model::un_subscribe_to_forum_topic($request['id']);
            $message = "Un-Subscribe to notication succesfully";
        }
        $response['message'] = $message;
        return response()->json($response, 200);
    }

    public function getPrivateUsers(Request $request, $id = 0){

        $users = User::where('account_id', $request->user()->account_id)->get();

        $selected_user_list = new Collection;

        if($id){
            $forum = Forum::findOrFail($id);
            $selected_user_list = $forum->privateUsers;
        }

        $data = new Collection;
        $counter = 0;
            foreach($users as $user){
                if($user->can(Permissions::Forum) && !$user->can(Permissions::ForumAdmin)):
                if($selected_user_list->where('id', $user->id)->count()):
                    $selected = 'SELECT 1';
                else:
                $selected = 'SELECT 0';
                endif;
                $counter++;
                $data = $data->concat($user->userInfo->where('user_id', $user->id)->select('user_id as id', DB::raw('CONCAT(first_name, " ", last_name) as text'))
                ->selectSub($selected, 'selected')->get());
                endif;
            }
        
       return response()->json(['data' => $d['results'] = $data, 'total'=>$counter], 200);

    }

    public function getSelectedPrivateUsers($id){

        $forum = Forum::findOrFail($id);
        $user_list = $forum->privateUsers->pluck('id')->toArray();
        $users = UserInfo::whereIn('user_id', $user_list);

        if($users->count()){
           
            $users = $users->select('user_id as id', DB::raw('CONCAT(first_name, " ", last_name) as text'))->get();

        }

       return response()->json(['results' => $users], 200);

    }
}

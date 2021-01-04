<?php

namespace App\Http\Controllers;

use App\Forum;
use App\ForumTopic;
use App\ForumTopicPost;
use App\ForumTopics;
use App\User;
use Illuminate\Http\Request;
use DataTables;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use App\Traits\FileHandlingTrait;
use Exception;

class ForumTopicsController extends Controller
{
    use FileHandlingTrait;
    private $_model = "App\ForumTopic";
    private $_moduleTitle = "Forum Topic";
    private $_moduleName = "forum_topics";
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index($forum_id)
    {
        //
        $data = array();
        $data['_moduleName'] = $this->_moduleName;
        $forum_data = Forum::findOrFail($forum_id);
        $data['title'] = $forum_data['name'];
        $data['current_page_header'] = $forum_data['name'].' '.'list';
        //Breadcrumb data
        $breadcrumb_data = array(
            route('forums.index') => 'Forums',
            'current_page' => $data['current_page_header']);

        //$data['breadcrumb'] = breadcrumb($breadcrumb_data);
        // End Breadcrumbs

        $data['forum_id'] = $forum_id;
        return view($this->_moduleName.'/list')->with('data', $data);

    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create($forum_id)
    {
        //
        $data = array();
        $data['_moduleName'] = $this->_moduleName;
        $data['title'] = $this->_moduleTitle;
        $data['current_page_header'] = 'Create'.' '.Str::singular($this->_moduleTitle);

        $data['forum_id'] = $forum_id;
        return view($this->_moduleName.'/create')->with('data', $data);

    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request, $forum_id)
    {
        $validationResponse = $this->validate($request, [
            'topic_title' => 'required|max:255',
            'post.post_body' => 'required',
        ]);

        //validating forum id
        if ($request->forum_id != $forum_id) {
            Session::flash('error', 'Error in creating new topic!');
            return redirect()->back()->withInput();
        }
        $user = Auth::user();
        //initializing model of the module
        $model = new $this->_model;
        $model->forum_id = $forum_id;
        $model->user_id = $user->id;
        $model->title = $request->topic_title;
        $model->status = !isset($request->status) ? 'active' : $request->status;
        $model->stickied = isset($request->stickied) && $request->stickied == 1 ? 1 : 0;;
        $model->approved = isset($request->approved) && $request->approved == 1 ? 1 : 0;
        try {
            //saving Data
            $model->save();
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError("There's an error in creating Forum topic, try later!")->withInput();
        }
        //if user subscribe to topic
        if ($request->has('subscribe'))
        {
            $model->NotificationSubscriber()->create(['user_id'=>$user->id]);
        }
        //saving post data
        $topic_post = $request->post;
        $topic_post['user_id'] = $user->id;
        $topic_post['forum_topic_id'] = $model->id;
        try {
            //saving Data
            $post = ForumTopicPost::create($topic_post);
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError("There's an error in creating first forum topic post, try later!")->withInput();
        }

        //save file against Topic post id
        if($request->filled('temp_file')) {
            $this->move_file_to_directory($request->temp_file, 'post', $user->account_id, $post->id);
        }

        Forum::send_mail_to_subscribers($forum_id);
        return redirect()->route($this->_moduleName,$forum_id)->withSuccess($this->_moduleTitle.' '.'created successfully!');
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\ForumTopics  $forumTopics
     * @return \Illuminate\Http\Response
     */
    public function show($forum_id,$id)
    {
        //
        $data = $this->_model::with('forum_topic_post')->where('id',$id)->first();
        $data['topic_title'] = $data['title'];
        $data['_moduleName'] = $this->_moduleName;
        $data['title'] = $this->_moduleTitle;
        $data['current_page_header'] = 'Topic title:'.' '.$data['topic_title'];
        $data['show_posts'] = true;
        //notification subscriber
        $data['subscribe'] = $this->_model::get_subscriber($id);

        $data['forum_topic_post'] = ForumTopicPost::where('forum_topic_id',$id)->paginate(5);
        return view($this->_moduleName.'/create')->with('data', $data);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\ForumTopics  $forumTopics
     * @return \Illuminate\Http\Response
     */
    public function edit($forum_id, $id)
    {
        //
        $data = $this->_model::with('forum_topic_post')->where('id',$id)->first();
        $data['topic_title'] = $data['title'];
        $data['_moduleName'] = $this->_moduleName;
        $data['title'] = $this->_moduleTitle;
        $data['current_page_header'] = 'Edit'.' '.Str::singular($this->_moduleTitle);
        //notification subscriber
        $data['subscribe'] = $this->_model::get_subscriber($id);


        return view($this->_moduleName.'/create')->with('data', $data);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\ForumTopics  $forumTopics
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $forum_id, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\ForumTopics  $forumTopics
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request)
    {
        //
    }

    // data table
    public function data_table($id)
    {
        $tableData = $this->_model::where('forum_id',$id);
        return DataTables::of($tableData)
            ->addColumn('title', function ($tableData)
            {
                $user = $tableData->user->userInfo;
                $created_by = 'By'.' '.$user['first_name'].' '.$user['last_name'].':'. Carbon::parse($tableData['created_at'])->format('m/d/Y');
                return '<a href="'.route($this->_moduleName.'.show', [$tableData['forum_id'],$tableData['id']]).'">'.$tableData->title.'</a>'.'  '.'<small>'.$created_by.'</small>';
            })
            ->addColumn('forum_post_data', function ($tableData) {
                $post_data = array();
                $topic_posts = $tableData->forum_topic_post;
                $post_data['posts_replies'] = sizeof($topic_posts)-1 > 0 ? sizeof($topic_posts)-1 : 0;
                $last_post = $topic_posts->last();
                $post_data['last_post'] = '';
                if ($last_post !=null)
                {
                    $last_post_user = User::find($last_post['user_id'])->userInfo;
                    $post_data['last_post'] = 'By'.' '.$last_post_user['first_name'].' '.$last_post_user['last_name'].' :'.Carbon::parse($last_post['post_date_time'])->format('m/d/Y g:i A');
                }
                return $post_data;
            })
            ->addColumn('action', function ($tableData) {
                $optionData = $tableData;
                $optionData['show'] = route($this->_moduleName.'.show', [$tableData['forum_id'],$tableData['id']]);
                $optionData['edit'] = route($this->_moduleName.'.edit', [$tableData['forum_id'],$tableData['id']]);
                return view($this->_moduleName.'.actions',$optionData)->render();
            })
            ->rawColumns(['title'])
            ->make(true);
    }

    public function subscribe_to_forum_topic(Request $request)
    {
        $data = ForumTopic::findOrFail($request['topic_id']);
        //if user subscribe to topic
        if ($request['checkbox_event']) {
            $data->NotificationSubscriber()->create(['user_id'=>Auth::id()]);
            $message = "Subscribe to notification successfully";
        }
        else {
            $this->_model::un_subscribe_to_forum_topic($request['topic_id']);
            $message = "Un-Subscribe to notication succesfully";
        }
        $response['message'] = $message;
        return response()->json($response, 200);
    }

}

<?php

namespace App\Http\Controllers;

use App\ForumTopic;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Traits\FileHandlingTrait;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\FileRelationType;
use Exception;

class ForumTopicPostsController extends Controller
{
    use FileHandlingTrait;
    private $_model = "App\ForumTopicPost";
    private $_moduleTitle = "Forum Topic Post";
    private $_moduleName = "forum_topic_posts";
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
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
        parse_str($request['data'], $data);
        $user = Auth::user();
        //initializing model of the module
        $model = new $this->_model;
        $model['forum_topic_id'] = $data['forum_topic_id'];
        $model['post_body'] = $data['add_post_body'];
        $model['user_id'] = $user->id;
        try {
            //saving Data
            $model->save();
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError("There's an error in creating topic post, try later!")->withInput();
        }
        //save file against Topic post id
        if(isset($data['temp_file']) && !empty($data['temp_file'])) {
            $this->move_file_to_directory($data['temp_file'], 'post', $user->account_id, $model->id);
        }
        //sending mail on new post
        ForumTopic::send_mail_to_subscribers($data['forum_topic_id']);

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
        $data = $this->_model::findOrFail($id);
        $data['userInfo'] = $data->user->userInfo;
        return $data;
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        //
        $data = $this->_model::findOrFail($id);
        $data['userInfo'] = $data->user->userInfo;
        $data['topic_title'] = $data['title'];
        $data['_moduleName'] = $this->_moduleName;
        $data['title'] = $this->_moduleTitle;
        $data['current_page_header'] = 'Edit'.' '.Str::singular($this->_moduleTitle);

        // Breadcrumbs Begins
        $breadcrumb_data = array(
            route('forum_topics.show',[$data->forum_topic['forum_id'],$data['forum_topic_id']]) => $this->_moduleTitle." ". "List",
            'current_page' => $data['current_page_header']
        );
        $data['breadcrumb'] = breadcrumb($breadcrumb_data);
        // End Breadcrumbs

        return view($this->_moduleName.'/create')->with('data', $data);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request,$id)
    {
        //
        $validationResponse = $this->validate($request, [
            'post_body' => 'required',
            'modified_reason' => 'required'
        ]);
        $user = Auth::user();
        $model = $this->_model::find($id);
        $model->post_body = $request['post_body'];
        $model->modified_reason = $request['modified_reason'];
        $model->modifier_user_id = $user->id;

        //save file against Topic post id
        if(isset($request['temp_file']) && !empty($request['temp_file'])) {
            $this->move_file_to_directory($request['temp_file'], 'post', $user->account_id, $id);
        }

        try {
            //saving Data
            $model->save();
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError("There's an error in updating topic post, try later!")->withInput();
        }
        return redirect()->route('forum_topics.show',[$model->forum_topic['forum_id'],$model['forum_topic_id']])->withSuccess($this->_moduleTitle.' '.'Updated successfully!');

    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request)
    {
        //
        $model = $this->_model::findOrFail($request->id);
        try {
            $model->delete();
            $response['msg'] = "Post deleted successfully!";
            $response['result'] = true;
            return $response;
        }catch (Exception $exception) {
            Log::error($exception);
            $response['msg'] = "Fail to delete post!";
            $response['result'] = false;
            return $response;
        }
    }

    public function hide_show_post(Request $request)
    {
        try {
            $forum_post = $this->_model::findOrFail($request['id']);
            $forum_post->update([ 'hidden' => !$forum_post->hidden ]);
        }catch (Exception $exception) {
            Log::error($exception);
            return redirect()->route($this->_moduleName.'.index');
        }
        if($forum_post) {
            $response['message'] = 'Updated Successfully!';
            return response()->json($response, 200);
        }

        $response['message'] = 'Fail to update!';
        return response()->json($response, 200);
    }

    //validate files datatable permission
    public function data_table(Request $request)
    {
        if($request->id){
            //to validate scoperResolver find Certificate
            $certificate = $this->_model::find($request->id);
            if(empty($certificate)){
                Log::error("Don't have the permission to delete files");
                throw new Exception("Not found");
            }
        }
        return $this->generic_data_table($request);
    }

    public function delete_files($id)
    {
        if($id){
            //to validate scoperResolver find Certificate
            $file = FileRelationType::where('file_id',$id)->first();
            $model_accessed = $this->_model::findOrFail($file->model_id);
            if(empty($model_accessed)){
                Log::error("Don't have the permission to delete files");
                throw new Exception("Not found");
            }
            return $this->generic_delete_files($id);
        }
    }
}

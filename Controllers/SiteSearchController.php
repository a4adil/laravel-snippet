<?php

namespace App\Http\Controllers;

use App\Faqs;
use App\File;
use App\Forum;
use App\ForumTopic;
use App\ForumTopicPost;
use App\SiteSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SiteSearchController extends Controller
{
    private $_moduleTitle = "Search";
    private $_moduleName = "site_search";
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $this->validate($request, [
            'q' => 'required|min:1'
        ]);
        //
        $data['word'] = $q = $request->get('q');
        $data['_moduleName'] = $this->_moduleName;
        $data['title'] = $this->_moduleTitle;

        //Breadcrumb data
        //$breadcrumb_data = array( route($this->_moduleName.'.index') => $this->_moduleTitle);
        //$data['breadcrumb'] = breadcrumb($breadcrumb_data);
        // End Breadcrumbs

        //Document Library Files
        $document_library = File::whereHas('libraryFile', function ($query) {
                                $query->where('library_type', 'site');
                            })->where('name','LIKE','%'.$q.'%')->get();
        $data['document_library'] = $this->get_minified_files_data($document_library);

        //My Files
        $my_files = File::whereHas('libraryFile', function ($query) {
                                $query->where(['library_type'=> 'personal','user_id'=> Auth::id()]);
                            })->where('name','LIKE','%'.$q.'%')->get();
        $data['my_files'] = $this->get_minified_files_data($my_files);

        //Forums
        //$data['forums'] = Forum::where('name','LIKE','%'.$q.'%')->orWhere('description','LIKE','%'.$q.'%')->get();

        //Forum Topics
        $forum_topics = ForumTopic::with('forum_topic_post')->where('title','LIKE','%'.$q.'%')->get();
        $topic_array = array();
        foreach ($forum_topics as $forum_topic)
        {
            $topic_array['topic-'.$forum_topic->id]['link'] = route('forum_topics.show',[$forum_topic['forum_id'],$forum_topic['id']]);
            $topic_array['topic-'.$forum_topic->id]['title'] = $forum_topic['title'];
            $topic_array['topic-'.$forum_topic->id]['post'] = $forum_topic->forum_topic_post[0]['post_body'];
        }

        //Topic posts
        $topic_posts = ForumTopicPost::with('forum_topic')->where('post_body','LIKE','%'.$q.'%')->get();
        $post_array = array();
        foreach ($topic_posts as $topic_post)
        {
            $post_array['post-'.$topic_post->id]['link'] = route('forum_topics.show',[$topic_post->forum_topic['id'],$topic_post['forum_topic_id']]);
            $post_array['post-'.$topic_post->id]['title'] = $topic_post->forum_topic['title'];
            $post_array['post-'.$topic_post->id]['post'] = $topic_post['post_body'];
        }
        $data['forums_data'] = array_merge($topic_array,$post_array);

        //FAQ's
        $data['faqs'] = Faqs::where('question','LIKE','%'.$q.'%')
            ->orWhere('answer','LIKE','%'.$q.'%')
            ->orWhere('key_words','LIKE','%'.$q.'%')->get();


        return view($this->_moduleName.'/list')->with('data', $data);
    }

    private function get_minified_files_data($query_result)
    {
        $data = array();
        foreach ($query_result as $file)
        {
            $data[$file->id]['icon'] = $this->extension_icon($file->extension);;
            $data[$file->id]['name'] = $file->name;
            $data[$file->id]['download_link'] = route('documentLibrary.download',$file->id);
            if($this->canView($file->extension))
            {
                $data[$file->id]['view_link'] = route('documentLibrary.download', [$file->id, 'view' => 'true']);
            }
        }
        return $data;
    }

    //validate is file have the extension of view
    protected function canView(?string $fileExtension) {
        return $fileExtension && array_search(strtolower($fileExtension), ['jpg', 'jpeg', 'gif', 'png', 'pdf']) !== FALSE;
    }
    //extension icon
    private function extension_icon($extension)
    {
        switch ($extension){
            case 'pdf':
                $file_icon = '<i class="fa fa-file-pdf-o"></i> ';
                break;
            case 'xls':
            case 'xlsx':
                $file_icon = '<i class="fa fa-file-excel-o"></i> ';
                break;
            case 'doc':
            case 'docx':
                $file_icon = '<i class="fa fa-file-word-o"></i> ';
                break;
            case 'jpg':
            case 'jpeg':
            case 'gif':
            case 'png':
            case 'tif':
            case 'tiff':
                $file_icon = '<i class="fa fa-file-image-o"></i> ';
                break;
            default:
                $file_icon = '<i class="fa fa-file-o"></i> ';
        }
        return $file_icon;
    }
}

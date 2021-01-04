<?php

namespace App\Http\Controllers;

use Auth;
use Exception;
use DataTables;
use App\File;
use App\Folder;
use App\Location;
use App\LibraryFile;
use App\Constants\Permissions;
use App\Traits\FilesFoldersTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class DocumentLibraryController extends Controller
{
    use FilesFoldersTrait;

    private $_moduleTitle = 'Document Library';
    private $_moduleName = 'documentLibrary';
    private $fileSize = '5MB';
    private $_library_type = 'site';
    private $arrayForBreadcrumb = array();

    public function __construct()
    {
        $this->middleware(['role_or_permission:'.Permissions::ManageDocumentLibrary])->only(['create', 'edit', 'store', 'update',
        'destroy']);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        //Breadcrumb data
        $data['title'] = $this->_moduleTitle;
        $breadcrumb_data['current_page_title'] = '';
        $breadcrumb_data['breadcrumb_array'] = array(array(route('documentLibrary.index') => $this->_moduleTitle));
        $data['breadcrumb_data'] = $breadcrumb_data;

        $data['fileSize'] = $this->fileSize;
        $data['folders'] = Folder::where('user_id', Auth::id())
                            ->where('library_type', 'personal')
                            ->get();

        //get location of login user
        $locations = Location::getAccountLocations();
        foreach($locations as $location) {
            $locationsArray[$location['id']] = $location['name'];
        }

        $data['locations'] = $locationsArray;
        return view('document_library/list')->with('data', $data);
    }

    /**
     * Show the form for creating a new Folder.
     *
     * @return \Illuminate\Http\Response
     */
    public function create($parentId = NULL)
    {
        // Breadcrumbs Begins
        $data['title'] = $this->_moduleTitle;
        $breadcrumb_data['breadcrumb_array'] = array(route($this->_moduleName.'.index') => $this->_moduleTitle);
        $current_page_title = 'Create folder';
        // End Breadcrumbs

        if($parentId !== NULL) {
            $data['parentId'] = $parentId;
            $parentFolder = Folder::find($parentId)['folder_name'];

            $breadcrumb_data['breadcrumb_array'] = array( route($this->_moduleName.'.index') => $this->_moduleTitle, 
            'javascript:history.go(-1)' => $parentFolder);
            $current_page_title = "Create sub-folder";
        }

        $breadcrumb_data['current_page_title'] = $current_page_title;
        $data['breadcrumb_data'] = $breadcrumb_data;

        return view('document_library/create_folder')->with('data', $data);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        return $this->store_folder_trait($request);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $myfiles = array();
        $folders = Folder::where('parent_folder_id', $id)->where('library_type', $this->_library_type)->get();

        $data['parentId'] = $id;

        $parentFolder = Folder::find($id)['name'];

        //Breadcrumb data
        $data['title'] = $this->_moduleTitle;
        $breadcrumb_data['current_page_title'] = $parentFolder;

        $recursiveBreadcrumb = $this->recursive_breadcrumb($id);
        if(count($recursiveBreadcrumb) > 3) {
            $recursiveBreadcrumb = array_slice($recursiveBreadcrumb, 0, 3);
            $blankLink = array(route('documentLibrary.index') => '...');
            array_push($recursiveBreadcrumb, $blankLink);
        }
        $mainLink = array(route('documentLibrary.index') => $this->_moduleTitle);
        array_push($recursiveBreadcrumb, $mainLink);
        $recursiveBreadcrumb = array_reverse($recursiveBreadcrumb);
        unset($recursiveBreadcrumb[count($recursiveBreadcrumb) - 1]);
        $breadcrumb_data['breadcrumb_array'] = $recursiveBreadcrumb;

        $data['breadcrumb_data'] = $breadcrumb_data;
        //End Breadcrumb

        $data['fileSize'] = $this->fileSize;

        $data['folders'] = Folder::where('user_id', Auth::id())
        ->where('library_type','personal')
        ->get();

        $locations = Location::getAccountLocations();

        foreach($locations as $location) {
            $locationsArray[$location['id']] = $location['name'];
        }

        $data['locations'] = $locationsArray;

        return view('document_library/list')->with('data', $data);
    }

    /**
     * Show the form for editing the specified File.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        $data = $this->edit_file_trait($id);
        // Breadcrumbs Begins
        $data['title'] = $this->_moduleTitle;

        $breadcrumb_data['breadcrumb_array'] = array(route('documentLibrary.index') => $this->_moduleTitle);

        if($data['folder_id'] != null) {
            $folderData = Folder::find($data['folder_id']);
            $breadcrumb_data['breadcrumb_array'] = array(
                route('documentLibrary.index') => $this->_moduleTitle,
                route('documentLibrary.show', $folderData['id']) => $folderData['name']
            );
            $data['folder_id'] = $folderData['id'];
        }

        $breadcrumb_data['current_page_title'] = $data['name'];
        $data['breadcrumb_data'] = $breadcrumb_data;
        // End Breadcrumbs
        return view('document_library/edit_file')->with('data', $data);
    }

    /**
     * Update the specified resource in File.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        try {
            $files = File::findOrFail($id);
        }catch (ModelNotFoundException $exception) {
            return back()->withError($exception->getMessage());
        }catch (Exception $exception) {
            return back()->withError($exception->getMessage());
        }
        
        $myFiles = $files->libraryFile->first();
        $redirectPathId = $myFiles->folder_id != null ? $myFiles->folder_id : '';
        $files->name = $request['name'];

        if($request->filled('physical_name')) {
            $link = str_replace(array('http://','https://'), '', $request['physical_name']);
            $files->physical_name = $link;
        }

        if($files->save()) {
            return redirect()->route('documentLibrary.show', $redirectPathId)->withSuccess('File updated successfully!');
        }

        return back()->withError("There's an error in updating!");
    }

    /**
     * Remove the specified File from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        try {
            $file= File::findOrFail($id);
        }catch (ModelNotFoundException $exception) {
            return back()->withError($exception->getMessage());
        }catch (Exception $exception) {
            return back()->withError($exception->getMessage());
        }
        
        if($file) {
            $unlinkingResponse = true;

            //Removing record from DB if un-linking successful
            if($unlinkingResponse) {
                try {
                    $dbResponse = $file->libraryFile()->delete();
                    File::destroy($file['id']);
                }catch (Exception $exception) {
                    return back()->withError($exception->getMessage());
                }
                
                if($dbResponse) {
                    $response['msg'] = 'File deleted successfully!';
                    $response['result'] = true;
                    return $response;
                }

                $response['msg'] = 'Fail to delete record from DB!';
                $response['result'] = false;
                return $response;
            }

            //if file don't exist or move to other folder
            $response['msg'] = 'Fail to find the file!';
            $response['result'] = false;
            return $response;
        }

        $response['msg'] = "Invalid id or don't exist in DB!";
        $response['result'] = false;
        return $response;
    }

    // data table
    public function data_table(Request $request)
    {
        $canEdit = Auth::user()->hasPermissionTo(Permissions::ManageDocumentLibrary);

        $id = $request->input('custom.id', null);

        $sql = 'SELECT -1';
        $files = File::whereHas('libraryFile', function ($query) use ($id) {
            $query->where(['folder_id'=> $id,'library_type'=>$this->_library_type]);
        })->with(['libraryFile' => function ($query) {
            $query->where(['library_type' => 'account', 'account_id'=> null]);
            $query->select('id', 'library_type', 'user_id', 'account_id', 'file_id');
            }
        ])
        ->select('id', 'name', 'extension', 'physical_name', 'storage_location')
        ->selectSub($sql, 'parent_folder_id')
        ->get();

        $whereArray = array(
            'parent_folder_id' => $id,
            'library_type' => $this->_library_type,
            'is_active' => 1
        );

        $folders = Folder::where($whereArray)->select('id', 'name', 'parent_folder_id')->selectSub($sql, 'extension')->get();
        $files = $files->concat($folders);

        return DataTables::of($files)
            ->addColumn('action', function ($file) use ($canEdit) {
                $view = get_class($file) == Folder::class ? 'folder' : 'file';

                if($file['storage_location'] !== null) {
                    $file['canView'] = $this->canView($file->extension);
                }
                $file['canEdit'] = $canEdit;

                return view("document_library/actions-$view", $file)->render();
            })
            ->addColumn('link', function ($file){
                if(get_class($file) == Folder::class) {
                    return route('documentLibrary.show', $file->id);
                }
                return route('documentLibrary.download', [$file->id, 'view' => 'true']);
            })
            ->make(true);
    }

    public function download(Request $request) {
        try {
            $file = File::findOrFail($request['id']);
        }catch (Exception $exception) {
            Log::error($exception);
            return back()->withError("There's an error in downloading/view file, Try later!");
        }

        $view = !empty($request['view']) && $request['view'] == 'true';

        return $this->unsafeDownload($file, $view);
    }
}

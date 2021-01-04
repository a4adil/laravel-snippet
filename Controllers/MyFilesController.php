<?php
namespace App\Http\Controllers;

use Auth;
use Storage;
use Exception;
use DataTables;
use App\File;
use App\Folder;
use App\Location;
use App\LibraryFile;
use App\Constants\Permissions;
use App\Traits\FilesFoldersTrait;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class MyFilesController extends Controller
{
    use FilesFoldersTrait;

    private $_moduleTitle = 'My Files';
    private $_moduleName = 'myFiles';
    private $_library_type = 'personal'; //it is used to seprate modules own folders
    private $fileSize = '5MB';
    private $arrayForBreadcrumb = array();
    public $_shared_folders = array();

    public function __construct()
    {
//        $this->middleware(['role_or_permission:'.Permissions::ShareDocuments])->only(['create', 'edit', 'store', 'update',
//        'destroy']);
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
        $data['fileSize'] = $this->fileSize;
        $data['folderId'] = null;

        //get location of login user
        $locationsArray = array();
        $locations = Location::getAccountLocations();
        $locationsArray['all_locations'] = 'All Locations';
        foreach($locations as $location) {
            $locationsArray[$location['id']] = $location['name'];
        }

        $data['locations'] = $locationsArray;

        return view('my_files/list')->with('data', $data);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create($parentId = null)
    {
        // Breadcrumbs Begins
        $data['title'] = $this->_moduleTitle;
        $breadcrumb_data['breadcrumb_array'] = array( route($this->_moduleName.'.index') => $this->_moduleTitle);
        $current_page_title = 'Create folder';
        // End Breadcrumbs

        if($parentId !== null) {
            $data['parentId'] = $parentId;
            $parentFolder = Folder::find($parentId)['name'];

            $breadcrumb_data['breadcrumb_array'] = array(
                route($this->_moduleName.'.index') => $this->_moduleTitle,
                //route('filesFolders.index') => "...",
                "javascript:history.go(-1)" => $parentFolder,
            );
            $current_page_title = "Create sub-folder";
        }

        $breadcrumb_data['current_page_title'] = $current_page_title;
        $data['breadcrumb_data'] = $breadcrumb_data;

       return view('my_files/create_folder')->with('data', $data);
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
        $folder = Folder::where('id', $id)
            ->where('library_type', $this->_library_type)
            ->where('user_id', Auth::id())
            ->first();

        if(!$folder) {
            return back()->withError('Folder not found');
        }

        $data['folder'] = $folder;
        $data['folderId'] = $id;
        $parentFolder = $folder['name'];

        $parentFolders = $this->parentFolders($folder, []);
        $data['parents'] = $parentFolders;
        //Breadcrumb data
        $data['title'] = $this->_moduleTitle;
        $breadcrumb_data['current_page_title'] = $parentFolder;

        $recursiveBreadcrumb = $this->recursive_breadcrumb($id);
        if(count($recursiveBreadcrumb) > 3) {
            $recursiveBreadcrumb = array_slice($recursiveBreadcrumb, 0, 3);
            $blankLink = array(route('myFiles.index') => '...');
            array_push($recursiveBreadcrumb, $blankLink);
        }

        $mainLink = array(route('myFiles.index') => $this->_moduleTitle);
       // array_push($recursiveBreadcrumb, $mainLink);
        $recursiveBreadcrumb = array_reverse($recursiveBreadcrumb);
        //unset($recursiveBreadcrumb[count($recursiveBreadcrumb) - 1]);
        $breadcrumb_data['breadcrumb_array'] = $recursiveBreadcrumb;

        $data['breadcrumb_data'] = $breadcrumb_data;
        //End Breadcrumb

        $data['fileSize'] = $this->fileSize;
        //get location of login user
        $locations = Location::getAccountLocations();
        $locationsArray['all_locations'] = 'All Locations';

        foreach($locations as $location) {
            $locationsArray[$location['id']] = $location['name'];
        }

        $data['locations'] = $locationsArray;
 
        return view('my_files/list')->with('data', $data);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        $data = $this->edit_file_trait($id);
        // Breadcrumbs Begins
        $data['title'] = $this->_moduleTitle;

        $breadcrumb_data['breadcrumb_array'] = array( route('myFiles.index') => $this->_moduleTitle, );

        if($data['folder_id'] != null) {
            $folderData = Folder::find($data['folder_id']);
            $breadcrumb_data['breadcrumb_array'] = array(
                route('myFiles.index') => $this->_moduleTitle,
                route('myFiles.show', $folderData['id']) => $folderData['name']
            );
            $data['folder_id'] = $folderData['id'];
        }

        $breadcrumb_data['current_page_title'] = $data['name'];
        $data['breadcrumb_data'] = $breadcrumb_data;
        // End Breadcrumbs

        return view('my_files/edit_file')->with('data', $data);
    }

    /**
     * Update the specified resource in storage.
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
            $files->physical_name = $request['physical_name'];
        }

        if($files->save()) {
            return redirect()->route('myFiles.show', $redirectPathId)->withSuccess('File updated successfully!');
        }

        return back()->withError("There's an error in updating!");
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $isSoftDelete = true;

        try {
            $searchResponse = File::findOrFail($id);
        }catch (ModelNotFoundException $exception) {
            return back()->withError($exception->getMessage());
        }catch (Exception $exception) {
            return back()->withError($exception->getMessage());
        }
        
        $folderId = $searchResponse['folder_id'] != null ? '-' . $searchResponse['folder_id'] : '';
        if($searchResponse) {
            $unlinkingResponse = true;

            //Removing record from DB if un-linking successful
            if($unlinkingResponse) {
                try {
                    $dbResponse = $searchResponse->libraryFile()->delete();
                    File::destroy($searchResponse['id']);
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

    // File Folder data table
    public function data_table(Request $request)
    {
        $id = $request->input('custom.id', null);
        $sql = 'SELECT -1';

        $files = File::whereHas('libraryFile', function($query) use ($id) {
            $query->where(['folder_id'=>$id,'user_id' => Auth::id(),'library_type'=>$this->_library_type]);
            $query->orwhere(function ($q) use ($id)
            {
                $q->where(['library_type' => 'account', 'folder_id' => $id, 'user_id' => Auth::id(), 'account_id' => null]);
            });
        })->with(['libraryFile' => function($query) use ($id) {
            $query->where(function ($query) use($id) {
                $query->where(['library_type' => $this->_library_type, 'folder_id' => $id, 'user_id' => Auth::id()])
                ->orwhere(['library_type' => 'account', 'folder_id' => $id, 'user_id' => Auth::id(), 'account_id' => !null]);
            })->with(['locations' => function($query) {
                $query->select('locations.id', 'locations.name');
            }])
            ->select('id', 'library_type', 'user_id', 'account_id', 'file_id','folder_id');
        }])
        ->select('id', 'name', 'extension', 'physical_name', 'storage_location')
        ->selectSub($sql, 'parent_folder_id')
        ->get();
       
        $whereArray = array(
            'parent_folder_id' => $id,
            'library_type' => $this->_library_type,
            'user_id' => Auth::id(),
            'is_active' => 1
        );

        $folders = Folder::where($whereArray)->select('id', 'name', 'parent_folder_id')->selectSub($sql, 'extension')->get();

        $files = $files->concat($folders);

        return DataTables::of($files)
            //location name Coloumn
            ->addColumn('location', function ($file) {
                $data = "";
                if($file['extension'] != -1) {
                    //get shared files
                    $sharedFilesLoc = $file->libraryFile->where('library_type', 'account')
                        ->where('file_id',$file->id)
                        ->first();
                    if ($sharedFilesLoc)
                    {
                        //in case for file share to locations/location
                        $locations = $sharedFilesLoc->locations;//->implode('name', ',');

                        if(!$locations->count() && $sharedFilesLoc->account_id){
                            //in case of file share to all location
                            $data = 'All Locations';
                        }else {
                            $data = $locations->implode('name', ',');
                        }
                    }

                }
                return $data;
            })
            // ->addColumn('action', function ($file) {
            //     $data = array();
            //     if($file->libraryFile) {
            //         $sharedFileData = $file->libraryFile
            //         ->where('user_id', Auth::id())
            //         ->where('library_type', 'account')
            //         ->where('account_id', null)
            //         ->sortByDesc('id')
            //         ->first();

            //         $lib_type = !empty($sharedFileData) ? $sharedFileData['library_type'] : '';
            //         $editIdForDt = LibraryFile::where('file_id', $file['id'])->first();

            //         if($file['storage_location'] !== null) {
            //             if($this->canView($file->extension)) {
            //                 $data['view_link'] = route('myFiles.download', [$file['id'], 'view' => 'true']);
            //             }
            //             $data['download_link'] = route('myFiles.download', $file['id']);
            //         }

            //         $data['lib_type'] = $lib_type;
            //         $data['folder_id'] = $editIdForDt['folder_id'];
            //         $data['my_file_id'] = $sharedFileData['id'];
            //         $data['edit'] = $editIdForDt != null ? route('myFiles.edit', $editIdForDt['id']) : '';
            //     }

            //     if($file['extension'] == -1) {
            //         $data['view'] = route('myFiles.show', $file['id']);
            //     }
            //     return $data;
            // })
            ->addColumn('action', function ($file) {
                $view = get_class($file) == Folder::class ? 'folder' : 'file';

                $isShared = $file['extension'] != -1 && $file->libraryFile->where('library_type', 'account')
                    ->where('file_id',$file->id)
                    ->first();

                if($file['storage_location'] !== null) {
                    $file['canView'] = $this->canView($file->extension);
                }
                if($file->libraryFile){
                    $file['folder_id'] = $file->libraryFile->first();
                }
                return view("my_files/actions-$view", ['file'=>$file, 'isShared'=>$isShared])->render();
            })
            ->addColumn('link', function ($file){
                if(get_class($file) == Folder::class) {
                    return route('myFiles.show', $file->id);
                }
                return route('myFiles.download', [$file->id, 'view' => 'true']);
            })
            ->make(true);
    }

    // get Selected locatons of shared file
    /**
     * @param Request fileId
     */
    public function getSelectedLocations(Request $request)
    {
        $user = Auth::user();
        $id = $request['id'];
        $account_id = $user->account_id;
        $file = LibraryFile::where('file_id', $id)
            ->where('library_type', 'account')
            ->where('user_id', $user->id)
            ->where('account_id', $account_id)
            ->first();
        $locationArr = array('all_locations' => 'All locations');

        if($request['type'] == "share_folder")
        {
            $file = Folder::with('locations')->where('id',$id)->first();
            if(sizeof($file->locations)==0)
            {
                $locationArr = array();
            }
        }
        if(!empty($file)) {
            if(sizeof($file->locations) > 0) {
                $locationArr = array();
                foreach($file->locations as $location) {
                    $locationArr[$location->id] = $location->name;
                }
            }
        }elseif (empty($file)) {
            $locationArr = array();
        }

        $response['data'] = $locationArr;
        $response['message'] = 'Process Completed';

        return response()->json($response, 200);
    }

    public function download(Request $request)
    {
        try {
            $file = File::findOrFail($request['id']);
        }catch (ModelNotFoundException $exception) {
            return back()->withError($exception->getMessage());
        }catch (Exception $exception) {
            return back()->withError($exception->getMessage());
        }

        $view = $request->has("view") && $request->input("view") == 'true';

        return $this->unsafeDownload($file, $view);
    }
}

<?php

namespace App\Http\Controllers;

use Auth;
use DataTables;
use App\User;
use App\File;
use App\Folder;
use App\Location;
use App\LibraryFile;
use App\Traits\FilesFoldersTrait;
use Illuminate\Http\Request;

class SharedDocumentController extends Controller
{
    use FilesFoldersTrait;

    private $_moduleTitle = 'Shared Documents';
    private $_moduleName = 'sharedDocuments';
    private $_library_type = 'account';
    private $fileSize = '5MB';
    private $arrayForBreadcrumb = array();

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
        $breadcrumb_data['breadcrumb_array'] = array(array(route('sharedDocuments.index') => $this->_moduleTitle));
        $data['breadcrumb_data'] = $breadcrumb_data;

        return view('shared_documents/list')->with('data', $data);
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
        $breadcrumb_data['current_page_title'] = '';
        $breadcrumb_data['breadcrumb_array'] = array(array(route('sharedDocuments.index') => $this->_moduleTitle));
        $breadcrumb_data['current_page_title'] = $parentFolder;
        $data['breadcrumb_data'] = $breadcrumb_data;
        $data['fileSize'] = $this->fileSize;

        $data['folders'] = Folder::where('user_id', Auth::id())
        ->where('library_type','personal')
        ->get();

        $locations = Location::getAccountLocations();

        foreach($locations as $location) {
            $locationsArray[$location['id']] = $location['name'];
        }

        $data['locations'] = $locationsArray;

        return view('shared_documents/list')->with('data', $data);
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
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }
    

    // data table
    public function data_table(Request $request)
    {
        $id = !empty($request->custom['id']) ? $request->custom['id'] : null;
        $account_id = Auth::user()['account_id'];

        $locations = Location::getAccountLocations()->pluck('id')->toArray();
        $allLlocationsData = Folder::with('sharedFolder')->where(function ($builder) use($id) {
            $builder->whereHas('sharedFolder', function ($query){
                return $query->where('has_all_locations',true);
            });
        })->where(['account_id'=>$account_id,'parent_folder_id'=>$id])->get()->toArray();

        $selectedLocationsData = Folder::with('sharedFolder')->where(function ($builder) use($locations,$id) {
            $builder->whereHas('folderHasLocation', function ($query) use($locations) {
                return $query->whereIn('location_id',$locations);
            });
        })->where(['account_id'=>$account_id,'parent_folder_id'=>$id])->get()->toArray();
        $sharedFolderData = array_merge($allLlocationsData,$selectedLocationsData);

        $whereArr = array(
            'account_id'=>$account_id,
            'library_type'=>$this->_library_type,
            'folder_id'=> $id
        );

        $allFiles = LibraryFile::with('file')->where($whereArr)->get();

        $myfiles = array();
        foreach($allFiles as $file) {
            $file->file['user_id'] =$file['user_id'];
            $myfiles[] = $file->file;
        }

        if(sizeof($sharedFolderData) > 0) {
            $foldersData = array();
            foreach($sharedFolderData as $folder) {
                $foldersData['id'] = $folder['id'];
                $foldersData['name'] = $folder['name'];
                $foldersData['storage_location'] = $folder['parent_folder_id'];
                $foldersData['extension'] = 'folder';
                $foldersData['user_id'] = $folder['user_id'];
                array_push($myfiles, $foldersData);
            }
        }

        return DataTables::of($myfiles)
            ->addColumn('sharedBy', function ($myfiles) {
                $user = User::find($myfiles['user_id']);
                return $user->userInfo->first_name.' '.$user->userInfo->last_name;
            })
            ->addColumn('action', function ($myfiles) {
                $editId = LibraryFile::where('file_id', $myfiles['id'])->first();
                $data = $myfiles;
                $data['view'] = '';
                $data['file_download_link'] = '';
                if($myfiles['storage_location'] !== null) {
                    $data['view'] = $myfiles['storage_location'];
                    $data['file_download_link'] = route('temporary_file_download_url_id', $myfiles['id']);
                }
                //$data['edit'] = route($this->_moduleName.'.edit', $editId['id']);
                if ($myfiles['extension'] == 'folder') {
                    $data['view'] = route($this->_moduleName.'.show', $myfiles['id']);
                }

                return view('shared_documents.actions',$data)->render();
                //return $data;
            })
            ->addColumn('link', function ($file){
                if($file['extension'] == 'folder') {
                    return route('sharedDocuments.show', $file['id']);
                }
                return route('sharedDocuments.download', [$file['id'], 'view' => 'true']);
            })
            ->make(true);
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

<?php

namespace App\Http\Controllers;

use App\TimeZone;
use Exception;
use App\SiteSetting;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Constants\Permissions;
use Illuminate\Support\Facades\App;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;

class SiteSettingController extends Controller
{
    public function __construct()
    {
        $this->middleware(['permission:' . Permissions::ManageAccounts]);
    }
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        
        $siteSettings = SiteSetting::all();
        $timezones = TimeZone::pluck('id', 'name')->flip()->toArray();
        return view("site-settings", compact("siteSettings", "timezones"));
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
        if($request->hasFile("logo") || $request->hasFile("loginbg")){
            if($request->hasFile("logo")){
                $file = $request->file('logo');
                $storeName = "logo.".$file->extension();
                $field = SiteSetting::where("key", "LOGO");
                if(!$field->exists()){
                    $field = SiteSetting::create(["key"=> "LOGO", "value"=>"//"]);
                }
            }else{
                $file = $request->file('loginbg');
                $storeName = "loginbg.".$file->extension();
                $field = SiteSetting::where("key", "LOGINBG");
                if(!$field->exists()){
                    $field = SiteSetting::create(["key"=> "LOGINBG", "value"=>"//"]);
                }
            }
            
            $storagePath = 'public/'.App::environment().'/'.'logo';
            $path = Storage::disk("local")->putFileAs($storagePath,$file,$storeName);
            if($field->exists()){
                $field->update(["value"=>$path]);
            }
            return response()->json(["message"=> "Image updated"], 200);
        }

        parse_str($request['form'], $data);
        if(empty($data["key"]) || empty($data["value"])){
            return response()->json(["message"=> "Error : ".$data["key"]." - Invalid/Missing data."], 500);
        }

        try{
            $data["key"] = strtoupper(Str::snake($data["key"]));
            SiteSetting::create($data);
        }catch(QueryException $exception)
        {
            return response()->json(["message"=> $data["key"] . " key already exists."], 500 );
        }
        catch(Exception $exception){
            return response()->json(["message"=> "Error : #1 - Invalid/Missing data."], 500);
        }
        
        return response()->json(["message"=> "Recored inserted."], 200);
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\SiteSetting  $siteSetting
     * @return \Illuminate\Http\Response
     */
    public function show(SiteSetting $siteSetting)
    {
        if (Request::ajax())
        {
            //
        }
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\SiteSetting  $siteSetting
     * @return \Illuminate\Http\Response
     */
    public function edit(SiteSetting $siteSetting)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\SiteSetting  $siteSetting
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $message = '';
        if(is_numeric($id)) {
            $siteSetting = SiteSetting::findOrFail($id);
            $active = !$siteSetting->active;
            $siteSetting->update(["active" => $active]);
            return response()->json(["success" => true, "message" => $message], 200);
        }
        
        $data = $request->all();
        foreach($data as $key=>$value){
            if(strtolower($key)  == "logo" || strtolower($key)  == "loginbg"){
                continue;
             }
            $field = SiteSetting::where("key", $key)->first();
            if($field){
                $activeInput = $request->input($key.'_active');
                $active = $activeInput == null ? 1: $activeInput;
                if($field->value != $value || $field->active != $active) {
                    if($field->key == 'COLOR_SCHEME') {
                        if ($active) {
                            $message = $this->setThemeColor($value);
                        } else {
                            //get default theme color
                            $color = env('THEME_COLOR');
                            if (!$this->isValidColor($color)) {
                                $color = '#4A93CF';
                            }
                            $message = $this->setThemeColor($color);
                        }
                        if($value == null) {
                            $value = '';
                        }
                    }
                    $field->update(["value"=>$value, "active"=>$active]);
                }
            }
        }

        return redirect()->to("setting")->withSuccess($message);
    }

    private function setThemeColor($color) {
        if(!$this->isValidColor($color)) {
            if(!$color) {
                return 'Be sure to enter a should be a hex color value like #4A93CF and Save to see your changes';
            }
            return 'Theme color is not valid - should be a hex color value like #4A93CF';
        } else {
            $themePath = base_path('resources/assets/sass/_theme.scss');
            $themeFile = fopen($themePath, "w");
            if ($themeFile) {
                fwrite($themeFile, "\$themeColor: $color;");
                fclose($themeFile);
                exec('npm run theme');
                return 'The site theme has been updated.';
            }
        }
    }

    private function isValidColor($color) {
        return preg_match('/^#[0-9a-f]{6}$/i', $color);
    }
    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\SiteSetting  $siteSetting
     * @return \Illuminate\Http\Response
     */
    public function destroy(SiteSetting $siteSetting)
    {
        //
    }

    protected function upload_logo($request){

        if($request->hasFile("logo") || $request->hasFile("loginbg")){
            if($request->hasFile("logo")){
                $file = $request->file('logo');
                $storeName = "logo.".$file->extension();
                $field = SiteSetting::where("key", "LOGO");
                if(!$field->exists()){
                    $field = SiteSetting::create(["key"=> "LOGO", "value"=>"//"]);
                }
            }else{
                $file = $request->file('loginbg');
                $storeName = "loginbg.".$file->extension();
                $field = SiteSetting::where("key", "LOGINBG");
                if(!$field->exists()){
                    $field = SiteSetting::create(["key"=> "LOGINBG", "value"=>"//"]);
                }
            }
            
            $storagePath = 'public/'.App::environment().'/'.'logo';
            return Storage::disk("local")->putFileAs($storagePath,$file,$storeName);
           
        }
    }

    protected function str_replace_once($str_pattern, $str_replacement, $string){

        if (strpos($string, $str_pattern) !== false){
            $occurrence = strpos($string, $str_pattern);
            return substr_replace($string, $str_replacement, strpos($string, $str_pattern), strlen($str_pattern));
        }
    
        return $string;
    }
}

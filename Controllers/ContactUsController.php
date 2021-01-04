<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class ContactUsController extends Controller
{
    private $_moduleTitle = "Contact Us";
    private $_moduleName = "contact_us";
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
        return view($this->_moduleName.'/create')->with('data', $data);
    }

    /**
     * sent email
     */
    public function send_email(Request $request)
    {
        //
        $this->validate($request, [
            'question' => 'required'
        ]);

        $user = $request->user();
        $data['name'] = $user_name = $user->userInfo['first_name'].' '.$user->userInfo['first_name'];
        $data['from_email'] = $from = $user->email;
        $data['user_account'] = $user->account['name'];
        $data['body'] = $request->question;
        $subject = config('APP_NAME')." Contact form submission";
        $to = config('contact_us_email');

        Mail::send($this->_moduleName.'.contact_us_mail_view',$data,
            function ($message) use ($from,$user_name,$to,$subject){
                $message->from($from, $user_name);
                $message->to($to)->subject($subject);
            });

        $data['success_msg'] = "We've been contacted and will be in touch shortly";
        return view($this->_moduleName.'/confirmation_page')->with('data', $data);
    }

}

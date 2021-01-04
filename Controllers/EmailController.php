<?php

namespace App\Http\Controllers;

use App\User;
use App\SentEmailHistory;
use App\Mail\DeliveryStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class EmailController extends Controller
{
    public function getEmailWebhookData(Request $request)
    {
        $status_reason = '';
        $status = '';
        $data = $request->get('event-data', null);
        $event = $data['event'];
        $history_id = $data['user-variables']['email_history_id'];

        if($data) {
            if($event) {
                if($event == 'delivered') {
                    $status_reason = 'Email delivered';
                    $status = 'Success';
                }
                else if($event == 'complained') {
                    $status_reason = 'Email is complained for spam';
                    $status = 'Fail';
                }
                else if($event == 'bounced') {
                    $status_reason = 'Email not found';
                    $status = 'Fail';
                }
                else if($event == 'dropped') {
                    $status_reason = 'Email not delivered';
                    $status = 'Fail';
                }
            }

            // return response()->json(["data"=> $status], 200);
            $emailHistory = SentEmailHistory::findOrFail($history_id);
            $emailHistory->update([
                'status' => $status,
                'status_reason' => $status_reason
            ]);

            $this->failureNotification($emailHistory);
        }
    }

    public function failureNotification($emailHistory)
    {
        $deliverFailMessage = "Message delivery failure for ";

        if($emailHistory->status == "Success" ){
            return true;
        }

        $user = null;
        if($emailHistory->user_id){
            $user = User::findOrFail($emailHistory->user_id);

        }else{
            $user = $emailHistory->account->users->where("id", $emailHistory->account->primary_user_id)->first();
        }

        if($emailHistory->claim_id){
            $subject = $deliverFailMessage. "claim.";


        }elseif($emailHistory->contract_id){
            $subject = $deliverFailMessage. "contract.";

        }elseif($emailHistory->business_item_id){
            $subject = $deliverFailMessage. "project/activity.";

        }else{
            $subject = $deliverFailMessage. "certificate.";
        }
        
        $html = new DeliveryStatus($emailHistory, $subject);
        Mail::to($user)->send($html);
    }
}

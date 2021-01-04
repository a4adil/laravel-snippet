<?php

namespace App\Http\Controllers;

use Auth;
use App\Claim;
use Carbon\Carbon;
use App\Charts\UserChart;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Spatie\Browsershot\Browsershot;

class UserChartController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $pref = Auth::user()->userPreference;
        $random = Str::random(10);
        
        $types  = $this->getClaims()->pluck('claims_type')->unique()->toArray();
        $path = storage_path('/'.$random.'.png');
        $usersChart = new UserChart;
        $usersChart->labels($types);

        $eachCount = [];
        foreach($types as $t){
            $eachCount[] = $this->getClaims()->where('claims_type', $t)->count();
        }

        $usersChart->dataset('By expiration date', 'bar', $eachCount);
        $view =  view('users-charts', [ 'usersChart' => $usersChart ] );
        Browsershot::html($view->render())->delay(5000)->save($path);
        return $view;
    }

    protected function getClaims(){
        $startDate = Carbon::now()->subYear()->toDateTimeString();
        $endDate = Carbon::now()->toDateTimeString();
        $claims = Claim::query()->where("claims_hidden", 0);
        return $claims->whereBetween("claims_datetime", [$startDate,$endDate]);
    }
}

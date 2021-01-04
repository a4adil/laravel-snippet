<?php

namespace App\Http\Controllers;

use Auth;
use App\File;
use App\User;
use stdClass;
use App\Claim;
use SoapClient;
use App\Contract;
use Carbon\Carbon;
use App\Mail\ClaimChart;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\ChartDirector\lib\XYChart;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Testing\FileFactory;
use Illuminate\Support\Facades\Response;
use Illuminate\Database\Eloquent\Builder;
use App\Http\Resources\ContractCollection;

class TestController extends Controller
{

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {

        $users = User::whereHas('userPreference', function (Builder $query) {
            $query->where('dashboard_chart_email_filter', 1);
        })->get();

        # Create a XYChart object of size 250 x 250 pixels
        $c = new XYChart(500, 700);

        # Set the plotarea at (30, 20) and of size 200 x 200 pixels
        $c->setPlotArea(30, 20, 450, 450);

        $c->xAxis->setLabelStyle("arial.ttf", 12, 'FFFF0002', 70);
        # Add a bar chart layer using the given data
        foreach ($users as $user) {
            $expiringMoment = Carbon::now()->addDays($user->userPreference->contract_expire_lead_days)->toDateString();
            $eachCount = [];

            if ($this->contractResourcCollection($user)->count()) {

                $eachCount[] = $this->contractResourcCollection($user)
                    ->where('status', 'Active')
                    ->whereDate('expiration_date', '>', $expiringMoment)
                    ->orWhereNull('expiration_date')
                    ->count();

                $eachCount[] = $this->contractResourcCollection($user)
                    ->where('status', 'Active')
                    ->whereNotNull('expiration_date')
                    ->whereDate('expiration_date', '<=', $expiringMoment)->count();

                $eachCount[] = $this->contractResourcCollection($user)
                    ->where('status', 'Expired')->count();
            }

            $data = $eachCount;
            # The labels for the bar chart
            $labels = ['More than ' . $user->userPreference->contract_expire_lead_days . ' days', $user->userPreference->contract_expire_lead_days . ' days or less', 'Expired'];

            $c->addBarLayer($data);

            # Set the labels on the x axis.
            $c->xAxis->setLabels($labels);

            Mail::to($user)->send(new ClaimChart($c->makeChart2(PNG)));
        }
    }

    public function contractResourcCollection($user)
    {

        return Contract::where("hidden", 0)->OfUser($user);
    }
}

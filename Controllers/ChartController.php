<?php

namespace App\Http\Controllers;

use App\Claim;
use App\Location;
use App\ClaimAuto;
use App\ClaimOther;
use App\ClaimProperty;
use App\ClaimWorkersComp;
use App\ClaimGeneralLiab;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ChartController extends Controller
{
    public function getAllChartData(Request $request)
    {
        $labels = $data = '';

        $claims = Claim::groupBy('claims_type')->selectRaw('count(*) as total, claims_type');
        $claims = $this->applyChartFilters($claims, $request, null);
        $labels = $claims->pluck('claims_type')->toArray();
        $data = $claims->pluck('total')->toArray();

        $response['labels'] = $labels;
        $response['data'] = $data;
        $response['message'] = 'Process Completed';

        return response()->json($response, 200);
    }

    public function getWorkerLocationCountChartData(Request $request)
    {
        $labels = $data = '';

        $locations_ids = null;
        $claims = Claim::groupBy('location_id')->selectRaw('count(*) as total, location_id');
        $claims = $this->applyChartFilters($claims, $request, 'Workers Comp');
        $locations_ids = $claims->pluck('location_id')->toArray();

        if($locations_ids) {
            $labels = Location::whereIn('id', $locations_ids)->pluck('name')->toArray();
            $data = $claims->pluck('total')->toArray();
        }

        $response['labels'] = $labels;
        $response['data'] = $data;
        $response['message'] = 'Process Completed';

        return response()->json($response, 200);
    }

    public function getWorkerClaimTypeChartData(Request $request)
    {
        $claim_ids = null;
        $injury_labels = $illness_labels = $injury_data = $ilness_data = [];

        $claims = Claim::query();
        $claims = $this->applyChartFilters($claims, $request, 'Workers Comp');
        $claim_ids = $claims->pluck('id')->toArray();

        if($claim_ids) {
            $injury_type_other_count = 0;

            $claimWorkersCompInjury = ClaimWorkersComp::whereIn('claim_id', $claim_ids)
            ->where('claim_workers_comp_event_type', 'Injury')
            ->groupBy('claim_workers_comp_injury_type')
            ->selectRaw('count(*) as total, claim_workers_comp_injury_type');

            if($claimWorkersCompInjury) {
                $injury_labels = $claimWorkersCompInjury->pluck('claim_workers_comp_injury_type');
                if($injury_labels) {
                    $injury_labels = $injury_labels->map(function ($item, $key) {
                        return 'Injury:'.$item;
                    });
    
                    $injury_labels = $injury_labels->toArray();
                    $injury_data = $claimWorkersCompInjury->pluck('total')->toArray();
                }
                
                $injury_type_other_count = ClaimWorkersComp::whereIn('claim_id', $claim_ids)
                ->where('claim_workers_comp_event_type', 'Injury')
                ->where('claim_workers_comp_injury_type_other', '!=', null)
                ->count();

                if($injury_type_other_count) {
                    array_push($injury_labels, 'Injury Other');
                    array_push($injury_data, $injury_type_other_count);
                }
            }
            
            $claimWorkersCompIllness = ClaimWorkersComp::whereIn('claim_id', $claim_ids)
            ->where('claim_workers_comp_event_type', 'Illness')
            ->groupBy('claim_workers_comp_illness_type')
            ->selectRaw('count(*) as total, claim_workers_comp_illness_type');

            if($claimWorkersCompIllness) {
                $illness_labels = $claimWorkersCompIllness->pluck('claim_workers_comp_illness_type');
                if($illness_labels) {
                    $illness_labels = $illness_labels->map(function ($item, $key) {
                        return 'Injury:'.$item;
                    });
    
                    $illness_labels = $illness_labels->toArray();
                    $ilness_data = $claimWorkersCompIllness->pluck('total')->toArray();
                }

                $injury_type_other_count = ClaimWorkersComp::whereIn('claim_id', $claim_ids)
                ->where('claim_workers_comp_event_type', 'Illness')
                ->where('claim_workers_comp_illness_type_other', '!=', null)
                ->count();

                if($injury_type_other_count) {
                    array_push($illness_labels, 'Illness Other');
                    array_push($ilness_data, $injury_type_other_count);
                }
            }

            $labels = array_merge($injury_labels, $illness_labels);
            $data = array_merge($injury_data, $ilness_data);
        }

        $response['labels'] = $labels;
        $response['data'] = $data;
        $response['message'] = 'Process Completed';

        return response()->json($response, 200);
    }

    public function getWorkerBodyPartChartData(Request $request)
    {
        $labels = $data = '';

        $claim_ids = null;
        $claims = Claim::query();
        $claims = $this->applyChartFilters($claims, $request, 'Workers Comp');
        $claim_ids = $claims->pluck('id')->toArray();

        if($claim_ids) {
            $injury_body_part_other_count = 0;

            $claimWorkersComp = ClaimWorkersComp::whereIn('claim_id', $claim_ids)
            ->where('claim_workers_comp_event_type', 'Injury')
            ->pluck('claim_workers_comp_body_parts')
            ->toArray();

            if($claimWorkersComp) {
                $myArray = collect(array_count_values(explode(",", implode(",", $claimWorkersComp))));
                $labels = $myArray->keys()->toArray();
                $data = $myArray->values()->toArray();
            }

            $injury_body_part_other_count = ClaimWorkersComp::whereIn('claim_id', $claim_ids)
            ->where('claim_workers_comp_event_type', 'Injury')
            ->where('claim_workers_comp_body_parts_other', '!=', null)
            ->count();

            if($injury_body_part_other_count) {
                array_push($labels, 'Other');
                array_push($data, $injury_body_part_other_count);
            }
        }

        $response['labels'] = $labels;
        $response['data'] = $data;
        $response['message'] = 'Process Completed';

        return response()->json($response, 200);
    }

    public function getWorkerEmpDeptChartData(Request $request)
    {
        $labels = $data = '';

        $claims = Claim::query();
        $claims = $this->applyChartFilters($claims, $request, 'Workers Comp');
        $claim_ids = $claims->pluck('id')->toArray();

        if($claim_ids) {
            $claimWorkersComp = ClaimWorkersComp::whereIn('claim_id', $claim_ids)
            ->groupBy('claim_workers_comp_employee_department')
            ->selectRaw('count(*) as total, claim_workers_comp_employee_department');

            $labels = $claimWorkersComp->pluck('claim_workers_comp_employee_department')->toArray();
            $data = $claimWorkersComp->pluck('total')->toArray();
        }

        $response['labels'] = $labels;
        $response['data'] = $data;
        $response['message'] = 'Process Completed';

        return response()->json($response, 200);
    }

    public function getWorkerEventDeptChartData(Request $request)
    {
        $labels = $data = '';

        $claims = Claim::query();
        $claims = $this->applyChartFilters($claims, $request, 'Workers Comp');
        $claim_ids = $claims->pluck('id')->toArray();

        if($claim_ids) {
            $claimWorkersComp = ClaimWorkersComp::whereIn('claim_id', $claim_ids)
            ->groupBy('claim_workers_comp_event_department')
            ->selectRaw('count(*) as total, claim_workers_comp_event_department');

            $labels = $claimWorkersComp->pluck('claim_workers_comp_event_department')->toArray();
            $data = $claimWorkersComp->pluck('total')->toArray();
        }

        $response['labels'] = $labels;
        $response['data'] = $data;
        $response['message'] = 'Process Completed';

        return response()->json($response, 200);
    }

    public function getGeneralLocationCountChartData(Request $request)
    {
        $labels = $data = '';

        $locations_ids = null;
        $claims = Claim::groupBy('location_id')->selectRaw('count(*) as total, location_id');
        $claims = $this->applyChartFilters($claims, $request, 'General Liability / Property of Others');
        $locations_ids = $claims->pluck('location_id')->toArray();

        if($locations_ids) {
            $labels = Location::whereIn('id', $locations_ids)->pluck('name')->toArray();
            $data = $claims->pluck('total')->toArray();
        }

        $response['labels'] = $labels;
        $response['data'] = $data;
        $response['message'] = 'Process Completed';

        return response()->json($response, 200);
    }

    public function getGeneralAreaLossChartData(Request $request)
    {
        $labels = $data = '';

        $claim_ids = null;
        $claims = Claim::query();
        $claims = $this->applyChartFilters($claims, $request, 'General Liability / Property of Others');
        $claim_ids = $claims->pluck('id')->toArray();

        if($claim_ids) {
            $claim_general_liab_loss_area_other_count = 0;

            $claimGeneralLiability = ClaimGeneralLiab::whereIn('claim_id', $claim_ids)
            ->pluck('claim_general_liab_loss_area')
            ->toArray();

            $myArray = collect(array_count_values(explode(",", implode(",", $claimGeneralLiability))));
            $labels = $myArray->keys()->toArray();
            $data = $myArray->values()->toArray();

            $claim_general_liab_loss_area_other_count = ClaimGeneralLiab::whereIn('claim_id', $claim_ids)
            ->where('claim_general_liab_loss_area_other', '!=', null)
            ->count();

            if($claim_general_liab_loss_area_other_count) {
                array_push($labels, 'Other');
                array_push($data, $claim_general_liab_loss_area_other_count);
            }
        }

        $response['labels'] = $labels;
        $response['data'] = $data;
        $response['message'] = 'Process Completed';

        return response()->json($response, 200);
    }

    public function getGeneralLossChartData(Request $request)
    {
        $labels = $data = '';

        $claims = Claim::query();
        $claims = $this->applyChartFilters($claims, $request, 'General Liability / Property of Others');
        $claim_ids = $claims->pluck('id')->toArray();

        if($claim_ids) {
            $claim_general_liab_loss_type_other_count = 0;

            $claimGeneralLiability = ClaimGeneralLiab::whereIn('claim_id', $claim_ids)
            ->groupBy('claim_general_liab_loss_type')
            ->selectRaw('count(*) as total, claim_general_liab_loss_type');

            $labels = $claimGeneralLiability->pluck('claim_general_liab_loss_type')->toArray();
            $data = $claimGeneralLiability->pluck('total')->toArray();

            $claim_general_liab_loss_type_other_count = ClaimGeneralLiab::whereIn('claim_id', $claim_ids)
            ->where('claim_general_liab_loss_type_other', '!=', null)
            ->count();

            if($claim_general_liab_loss_type_other_count) {
                array_push($labels, 'Other');
                array_push($data, $claim_general_liab_loss_type_other_count);
            }
        }

        $response['labels'] = $labels;
        $response['data'] = $data;
        $response['message'] = 'Process Completed';

        return response()->json($response, 200);
    }

    public function getAutoLocationCountChartData(Request $request)
    {
        $labels = $data = '';

        $locations_ids = null;
        $claims = Claim::groupBy('location_id')->selectRaw('count(*) as total, location_id');
        $claims = $this->applyChartFilters($claims, $request, 'Auto');
        $locations_ids = $claims->pluck('location_id')->toArray();

        if($locations_ids) {
            $labels = Location::whereIn('id', $locations_ids)->pluck('name')->toArray();
            $data = $claims->pluck('total')->toArray();
        }

        $response['labels'] = $labels;
        $response['data'] = $data;
        $response['message'] = 'Process Completed';

        return response()->json($response, 200);
    }

    public function getAutoNatureCountChartData(Request $request)
    {
        $labels = $data = '';

        $claims = Claim::query();
        $claims = $this->applyChartFilters($claims, $request, 'Auto');
        $claim_ids = $claims->pluck('id')->toArray();

        if($claim_ids) {
            $claim_auto_other_count = 0;

            $claimAuto = ClaimAuto::whereIn('claim_id', $claim_ids)
            ->groupBy('claim_auto_nature')
            ->selectRaw('count(*) as total, claim_auto_nature');

            $labels = $claimAuto->pluck('claim_auto_nature')->toArray();
            $data = $claimAuto->pluck('total')->toArray();
        
            $claim_auto_other_count = ClaimAuto::whereIn('claim_id', $claim_ids)
            ->where('claim_auto_nature_other', '!=', null)
            ->count();

            if($claim_auto_other_count) {
                array_push($labels, 'Other');
                array_push($data, $claim_auto_other_count);
            }
        }

        $response['labels'] = $labels;
        $response['data'] = $data;
        $response['message'] = 'Process Completed';

        return response()->json($response, 200);
    }

    public function getPropertyLocationCountChartData(Request $request)
    {
        $labels = $data = '';

        $locations_ids = null;
        $claims = Claim::groupBy('location_id')->selectRaw('count(*) as total, location_id');
        $claims = $this->applyChartFilters($claims, $request, 'Your Property');
        $locations_ids = $claims->pluck('location_id')->toArray();

        if($locations_ids) {
            $labels = Location::whereIn('id', $locations_ids)->pluck('name')->toArray();
            $data = $claims->pluck('total')->toArray();
        }

        $response['labels'] = $labels;
        $response['data'] = $data;
        $response['message'] = 'Process Completed';

        return response()->json($response, 200);
    }

    public function getPropertyLossTypeChartData(Request $request)
    {
        $labels = $data = '';

        $claim_ids = null;
        $claims = Claim::query();
        $claims = $this->applyChartFilters($claims, $request, 'Your Property');
        $claim_ids = $claims->pluck('id')->toArray();

        if($claim_ids) {
            $claim_property_loss_other_count = 0;

            $claimPropertyLoss = ClaimProperty::whereIn('claim_id', $claim_ids)
            ->pluck('claim_property_loss_type')
            ->toArray();

            if($claimPropertyLoss) {
                $myArray = collect(array_count_values(explode(",", implode(",", $claimPropertyLoss))));
                $labels = $myArray->keys()->toArray();
                $data = $myArray->values()->toArray();
            }

            $claim_property_loss_other_count = ClaimProperty::whereIn('claim_id', $claim_ids)
            ->where('claim_property_loss_type_other', '!=', null)
            ->count();

            if($claim_property_loss_other_count) {
                array_push($labels, 'Other');
                array_push($data, $claim_property_loss_other_count);
            }
        }

        $response['labels'] = $labels;
        $response['data'] = $data;
        $response['message'] = 'Process Completed';

        return response()->json($response, 200);
    }

    public function getLaborLocationCountChartData(Request $request)
    {
        $labels = $data = '';

        $claims = Claim::with('location')->groupBy('location_id')->selectRaw('count(*) as total, location_id');
        $claims = $this->applyChartFilters($claims, $request, 'Labor Law');
        $locations_ids = $claims->pluck('location_id')->toArray();

        if($locations_ids) {
            $labels = Location::whereIn('id', $locations_ids)->pluck('name')->toArray();
        }
        
        $data = $claims->pluck('total')->toArray();

        $response['labels'] = $labels;
        $response['data'] = $data;
        $response['message'] = 'Process Completed';

        return response()->json($response, 200);
    }

    public function getOtherLocationCountChartData(Request $request)
    {
        $labels = $data = '';

        $claims = Claim::with('location')->groupBy('location_id')->selectRaw('count(*) as total, location_id');
        $claims = $this->applyChartFilters($claims, $request, 'Other');
        $locations_ids = $claims->pluck('location_id')->toArray();

        if($locations_ids) {
            $labels = Location::whereIn('id', $locations_ids)->pluck('name')->toArray();
        }

        $data = $claims->pluck('total')->toArray();

        $response['labels'] = $labels;
        $response['data'] = $data;
        $response['message'] = 'Process Completed';

        return response()->json($response, 200);
    }

    public function getOtherOtherLossChartData(Request $request)
    {
        $labels = $data = '';

        $claim_ids = null;
        $claims = Claim::query();
        $claims = $this->applyChartFilters($claims, $request, 'Other');
        $claim_ids = $claims->pluck('id')->toArray();

        if($claim_ids) {
            $claim_other_area_loss_other_count = 0;

            $claimPropertyLoss = ClaimOther::whereIn('claim_id', $claim_ids)
            ->pluck('claim_other_loss_area')
            ->toArray();

            if($claimPropertyLoss) {
                $myArray = collect(array_count_values(explode(",", implode(",", $claimPropertyLoss))));
                $labels = $myArray->keys()->toArray();
                $data = $myArray->values()->toArray();
            }

            $claim_other_area_loss_other_count = ClaimOther::whereIn('claim_id', $claim_ids)
            ->where('claim_other_loss_area_other', '!=', null)
            ->count();

            if($claim_other_area_loss_other_count) {
                array_push($labels, 'Other');
                array_push($data, $claim_other_area_loss_other_count);
            }
        }

        $response['labels'] = $labels;
        $response['data'] = $data;
        $response['message'] = 'Process Completed';

        return response()->json($response, 200);
    }

    protected function applyChartFilters($claims, $request, $claim_type)
    {
        $selected_location = $request->selected_location;
        $from_date = $request->from_date;
        $to_date = $request->to_date;
        $from_date = Carbon::parse($from_date)->toDateTimeString();
        $to_date = Carbon::parse($to_date)->toDateTimeString();
        $hidden_check = $request->hidden_check;
        $status = $request->status;

        if($claim_type)
            $claims = $claims->where('claims_type', $claim_type);
        
        if($from_date != '' && $to_date != '') {
            $claims->whereDate('claims_datetime', '>=', $from_date);
            $claims->whereDate('claims_datetime', '<=', $to_date);
        }

        if($selected_location != 0 && $selected_location != '')
            $claims->where('location_id', $selected_location);

        if($status != 'All')
            $claims->where('claims_status', $status);

        $claims->where('claims_hidden', 0);

        if($hidden_check)
            $claims->orWhere('claims_hidden', $hidden_check);

        return $claims;
    }

    public function getWorkerClaimdobChartData(Request $request)
    {
        $claim_ids = null;
        $lable = $data = [];

        $claims = Claim::query();
        $claims = $this->applyChartFilters($claims, $request, 'Workers Comp');
        $claim_ids = $claims->pluck('id')->toArray();
       

        if($claim_ids) {
            
            $from_date = Carbon::now()->subYears(18)->toDateString();
            $to_date = Carbon::now()->toDateString();
            $data[] = ClaimWorkersComp::whereIn('claim_id', $claim_ids)
            ->wherebetween('claim_workers_comp_date_of_birth', [$from_date, $to_date])->count();

            $from_date = Carbon::now()->subYears(30)->toDateString();
            $to_date = Carbon::now()->subYears(19)->toDateString();
            $data[] = ClaimWorkersComp::whereIn('claim_id', $claim_ids)
            ->wherebetween('claim_workers_comp_date_of_birth', [$from_date, $to_date])
            ->count();

            $from_date = Carbon::now()->subYears(40)->toDateString();
            $to_date = Carbon::now()->subYears(31)->toDateString();
            $data[] = ClaimWorkersComp::whereIn('claim_id', $claim_ids)
            ->wherebetween('claim_workers_comp_date_of_birth', [$from_date, $to_date])
            ->count();

            $from_date = Carbon::now()->subYears(50)->toDateString();
            $to_date = Carbon::now()->subYears(41)->toDateString();
            $data[] = ClaimWorkersComp::whereIn('claim_id', $claim_ids)
            ->wherebetween('claim_workers_comp_date_of_birth', [$from_date, $to_date])
            ->count();

            $from_date = Carbon::now()->subYears(60)->toDateString();
            $to_date = Carbon::now()->subYears(51)->toDateString();
            $data[] = ClaimWorkersComp::whereIn('claim_id', $claim_ids)
            ->wherebetween('claim_workers_comp_date_of_birth', [$from_date, $to_date])
            ->count();

            $from_date = Carbon::now()->subYears(61)->toDateString();
            $data[] = ClaimWorkersComp::whereIn('claim_id', $claim_ids)
            ->where('claim_workers_comp_date_of_birth', '<', $from_date)
            ->count();
            

            $labels = ['18', '18-30', '30-40', '40-50', '50-60', '60+'];
        }

        $response['labels'] = $labels;
        $response['data'] = $data;
        $response['message'] = 'Process Completed';

        return response()->json($response, 200);
    }
}

<?php

namespace App\Http\Controllers;

use Exception;
use DataTables;
use PDOException;
use App\ClaimAreaOfLoss;
use App\Constants\Permissions;
use App\Http\Requests\ClaimAreaOfLoss as AreaOfLoss;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ClaimAreaOfLossController extends Controller
{
    public function __construct()
    {
        $this->middleware(['permission:'.Permissions::Claims]);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return view('claims_area_of_loss/list');
    }

    public function allClaimAreaOfLoss(Request $request)
    {
        $areas = ClaimAreaOfLoss::all();

        return DataTables::of($areas)
            ->addColumn('action', function ($area) use ($request) {
                $data = array();
                if ($request->user()->hasAccountPermission(Permissions::Claims)) {
                    $data['id'] = $area->id;
                }
                return view('claims_area_of_loss/actions', $data)->render();
            })
            ->make(true);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $area = new ClaimAreaOfLoss;
        
        return view('claims_area_of_loss/create_edit', compact('area'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(AreaOfLoss $request)
    {
        $account_id = $request->user()->account_id;
        $area = new ClaimAreaOfLoss([ 'account_id' => $account_id, 'name' => $request->name ]);
        $area->save();

        return redirect()->route('claim_areaofloss.index');
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        try {
            $area = ClaimAreaOfLoss::findOrFail($id);
        } catch (ModelNotFoundException $exception) {
            return back()->withError($exception->getMessage())->withInput();
        }

        return view('claims_area_of_loss/create_edit', compact('area'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(AreaOfLoss $request, $id)
    {
        try {
            $area = ClaimAreaOfLoss::findOrFail($id);
            $area->update([ 'name' => $request->name ]);
        } catch (ModelNotFoundException $exception) {
            return back()->withError($exception->getMessage())->withInput();
        } catch (Exception $exception) {
            return response()->json(['data'=> $exception->getMessage()], 500);
        }

        return redirect()->route('claim_areaofloss.index');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        try {
            $area = ClaimAreaOfLoss::findOrFail($id);
            $area->delete();
        } catch (ModelNotFoundException $exception) {
            return back()->withError($exception->getMessage())->withInput();
        } catch (Exception $exception) {
            return back()->withError($exception->getMessage());
        }
        
        return redirect()->route('claim_areaofloss.index');
    }
}

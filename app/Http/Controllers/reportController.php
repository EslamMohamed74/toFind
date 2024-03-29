<?php

namespace App\Http\Controllers;

use App\Area;
use App\City;
use App\Http\Requests\StorePostRequest;
use App\Jobs\SearchByImageForReport;
use App\Notifications\NotifyReport;
use App\Report;
use App\User;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;


class reportController extends Controller
{
    private $renderType;
    private $localArray;
    private $localQuery;
    private $globalImage;


    public function __construct()
    {
        $this->globalImage = " ";
        //        $this->middleware(['role:Admin'])->only('index');
        $this->renderType = " ";
        $this->localArray = array();
        $this->localQuery = " ";
    }
    public function index()
    {
        $cities = City::all();
        return view('people.find', ['cities' => $cities]);
    }
    public function fetchAll(){
        $reports = Report::withoutTrashed()->where('type','=','lost')->where('is_found','=','0')->paginate(6);
        return response()->json($reports);
    }
    public function myReports()
    {
        $reports = auth()->user()->reports;
        return view('reports/index', [
            'reports' => $reports,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return Response
     */
    public function create($type)
    {
        if (app()->getLocale() == 'ar') {
            $cities = DB::table("cities")->pluck("city_name_ar", "id");
            // return view('people.form', compact('cities'));
            return view('people.form', ['type' => $type, 'cities' => $cities]);
        } else {
            $cities = DB::table("cities")->pluck("city_name", "id");
            // return view('people.form', compact('cities'));
            return view('people.form', ['type' => $type, 'cities' => $cities]);
        }
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param Request $request
     * @return Factory|View
     */
    public function store(Request $request, $type)
    {
        if ($type == "lookfor") {
            $this->renderType = "lost";
        }
        if ($type == "found") {
            $this->renderType = "found";
        }
        if ($type == 'lookfor') {
            $request->validate([
                'name' => 'required|min:3|max:20',
                'age' => 'required|min:1|max:90',
                'gender' => 'required',
                'image' => 'required|mimes:jpeg,jpg,png|max:2024',
                'special_mark' => 'required',
                'eye_color' => 'required',
                'hair_color' => 'required',
                'location' => 'required',
                'last_seen_on' => 'required',
                'last_seen_at' => 'required',
                'lost_since' => 'required|date',
                'height' => 'required|max:250',
                'weight' => 'required|max:100',
            ]);
        } else if ($type == "found") {
            $request->validate([
                'name' => 'required|min:3',
                'age' => 'required|min:1|max:90',
                'gender' => 'required',
                'image' => 'required|mimes:jpeg,jpg,png|max:2024',
                'special_mark' => 'required',
                'eye_color' => 'required',
                'hair_color' => 'required',
                'location' => 'required',
                'found_since' => 'required|date',
                'height' => 'required|max:250',
                'weight' => 'required|max:100',
            ]);
        }
        $data = [
            'name' => $request->name,
            'age' => $request->age,
            'gender' => $request->gender,
            'type' => $this->renderType,
            'special_mark' => $request->special_mark,
            'eye_color' => $request->eye_color,
            'hair_color' => $request->hair_color,
            'city_id' => $request->city,
            'area_id' => $request->area_id,
            'location' => $request->location,
            'last_seen_on' => $request->last_seen_on,
            'last_seen_at' => $request->last_seen_at,
            'lost_since' => $request->lost_since,
            'found_since' => $request->found_since,
            'height' => $request->height,
            'weight' => $request->weight,
        ];

        $validateFace = $this->detectFace($request->file('image'));
        if ($validateFace == false) {
            return $this->errorResponse('No face Found Of More than one Face', 404);
        } else {
            $tempUrl = $this->uploadImageToS3("temp/", $request->file('image'));
            SearchByImageForReport::dispatch(\auth()->user(), $tempUrl, $type, $data)->onQueue('high');
            return view("popup");
            return redirect('/');
        }
    }
        //accept other report from auth user
        public function acceptOtherReport(Report $report)
        {
            if(\request()->session()->exists('report')){
                \request()->session()->forget('report');
            }
            $otherUser = $report->user;
	         Mail::to($otherUser->email)->send(new \App\Mail\SendSmsMailToReporter($report));
            return redirect('/')->with("message","Send Your information to reporter  successfully");
        }
    public function RejectOtherReport(){
        if (\request()->session()->exists('report') && \request()->session()->get('report') != "") {
            $data = (array)\request()->session()->get('report');
            Report::create($data);
            \request()->session()->forget('report');
            Session::save();
            return redirect('/')->with("message", "Thank you for using our App and  Report created successfully");
        } else {
            return Redirect::to("/people/search/lookfor");
        }

    }
    public function closeReport(Report $report)
    {
        $report->is_found = '1';
        $report->save();
    
        return redirect('/')->with("message", "Thank you for using our App and closed report successfully");
    }
    public function stillReport(Report $report)
    {
        $report->is_found = '0';
        $report->save();
        return redirect('/')->with("message", "Thank you for using our App and report still active");
    }

    public function show(Report $report)
    {
        if (auth()->user()->id == $report->user()->id) {
            return response()->json($report);
        }
    }
    public function showReportDetails(Report $report)
    {
        return view('people.personDetails', ['report' => $report]);
    }

    /**
     * Show the form for editing the specified resource.o
     *
     * @param int $id
     * @return Response
     */
    public function edit(Report $report)
    {
        if (auth()->user()->id == $report->user->id)
            return view('user.editReport', ['report' => $report]);
        else return $this->errorResponse("Unauthorize", 403);
    }


    /**
     * Update the specified resource in storage.
     *
     * @param Request $request
     * @param int $id
     * @return Response
     */
    public function update(Request $request, Report $report)
    {
        if (auth()->user()->id == $report->user->id) {
            if ($request->has('name')) {
                $report->name = $request->name;
            }
            if ($request->has('age')) {
                $report->age = $request->age;
            }
            if ($request->has('gender')) {
                $report->gender = $request->gender;
            }
            if ($request->hasFile('image')) {
                $this->deleteImageFromS3($report->image);
                $report->image = $this->uploadImageToS3("people/", $request->file('image'));
            }
            if ($request->has('type')) {
                $report->type = $request->type;
            }
            if ($request->has('special_mark')) {
                $report->special_mark = $request->special_mark;
            }
            if ($request->has('eye_color')) {
                $report->eye_color = $request->eye_color;
            }
            if ($request->has('hair_color')) {
                $report->hair_color = $request->hair_color;
            }
            if ($request->has('city')) {
                $report->city = $request->city;
            }
            if ($request->has('region')) {
                $report->region = $request->region;
            }
            if ($request->has('location')) {
                $report->location = $request->location;
            }
            if ($request->has('last_seen_on')) {
                $report->last_seen_on = $request->last_seen_on;
            }
            if ($request->has('last_seen_at')) {
                $report->last_seen_at = $request->last_seen_at;
            }
            if ($request->has('lost_since')) {
                $report->lost_since = $request->lost_since;
            }
            if ($request->has('found_since')) {
                $report->found_since = $request->found_since;
            }
            if ($request->has('height')) {
                $report->height = $request->height;
            }
            if ($request->has('weight')) {
                $report->weight = $request->weight;
            }
            if ($report->isClean()) {
                return response()->json('You need to specify a different value to update', 422);
            }
            $report->save();
            return redirect(route('profile.index'));
        } else  return $this->errorResponse("Unauthorize", 403);
    }

    public function destroy(Report $report)
    {
        if (auth()->user()->hasRole('Admin')) {
            $this->deleteImageFromS3($report->image);
            $report->delete();
            return redirect('/admin/panel/report');
        } else if (auth()->user()->id == $report->user->id) {
            $this->deleteImageFromS3($report->image);
            $report->delete();
            return redirect(route('profile.index'));
        }
    }
    public function action($query)
    {
        return Report::withoutTrashed()->where('type','=','lost')->where('is_found','=','0')
                    ->where('name' , 'like' , '%'.$query.'%')
                    ->paginate(6);
    }
    public function doSearchingQuery($request)
    {
        $constraints = json_decode($request, true);
        $searchArray = array();
        foreach ($constraints as $key => $constraint) {
            if ((is_array($constraint) && count($constraint) > 0) || $constraint != "") {
                $searchArray[$key] = $constraint;
            }
        }
        $query = Report::query()->where('type', '=', 'lost');
        $fields = array_keys($constraints);
        $index = 0;
        foreach ($searchArray as $key => $search) {
            if (is_array($search) && count($search) > 0 && $key != "age") {
                $query = $query->whereIn($fields[$index], $search);
            }
            if ($search != "" && !is_array($search)) {
                $query = $query->where($fields[$index], '=', $search);
            }
            if ($key == 'age' && is_array($search) && count($search) > 0) {
                $ageArray = array();
                if (in_array('below_10_years', $search)) {
                    array_push($ageArray, range(1, 9));
                }
                if (in_array('below_20_years', $search)) {
                    array_push($ageArray, range(10, 20));
                }
                if (in_array('below_30_years', $search)) {
                    array_push($ageArray, range(21, 29));
                }
                if (in_array('other_above_30', $search)) {
                    array_push($ageArray, range(30, 90));
                }
                $mergedArray = [];
                foreach ($ageArray as $age) {
                    foreach ($age as $one) {
                        array_push($mergedArray, $one);
                    }
                }
                $query = $query->WhereIn('age', $mergedArray);
            }
            $index++;
            }
        return $query->paginate(6);
    }


    public function getAreaList(Request $request)
    {
        if (app()->getLocale() == 'ar') {
            $states = DB::table("areas")
                ->where("city_id", $request->city_id)
                ->pluck("area_name_ar", "id");
            return response()->json($states);
        } else {
            $states = DB::table("areas")
                ->where("city_id", $request->city_id)
                ->pluck("area_name", "id");
            return response()->json($states);
        }
    }
    public function create2Admin()
    {
        $cities = DB::table("cities")->pluck("city_name", "id");
        return view('layouts.AdminPanel.reportsAdmin.create', ['cities' => $cities]);
    }
    public function show2Admin($id)
    {
        $report = Report::find($id);
        return view('layouts.AdminPanel.reportsAdmin.show', ['report' => $report]);
    }
    public function edit2Admin($id)
    {

        $report = Report::find($id);
        $cities = City::all();
        $area = Area::all()->where('city_id', '=', $report->city_id); //all areas
        $user = User::all()->where('id', '=', $report->user_id);
        return view('layouts.AdminPanel.reportsAdmin.edit', compact(
            'report',
            'cities',
            'area',
            'user'
        ));
    }
    public  function indexAdmin()
    {
        return view('layouts.AdminPanel.reportsAdmin.table', ['reports' => Report::paginate(6)]);
    }
}

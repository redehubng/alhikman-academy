<?php

namespace App\Http\Controllers;

use App\Classroom;
use App\ClassroomSubject;
use App\Country;
use App\Result;
use App\Role;
use App\Session;
use App\Student;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\User;
use App\Teacher;
use App\Http\Requests;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class TeacherController extends Controller
{
    public function index(){

        $teachers = Teacher::all();
        return view('teacher.index')->with(['teachers' => $teachers]);

    }

    public function show($id){

        $teacher = Teacher::with('classrooms', 'classroom_subjects', 'levels')->find($id);

        return view('admin.teacher')->with('teacher', $teacher);
    }

    public function create(){

        $countries = Country::all();

        $staff_id = generate_staff_id();
        return view('teacher.create')->with(['countries' => $countries, 'staff_id' => $staff_id]);
    }

    public function store(Requests\StoreNewTeacher $request){

        //dd($request);

        $user = new User;

        $user->username = $request->staff_id;

        $user->password = bcrypt($request->surname);

        $user->save();

        event(new Registered($user));

        $user->addRole(Role::where('name', '=', 'teacher')->first()->id);


        $teacher = Teacher::create([
            'title'         => $request->title,
            'name'          => $request->surname . ' ' . $request->first_name . ' ' . $request->middle_name,
            'user_id'       => $user->id,
            'email'         => $request->email,
            'phone'         => $request->phone,
            'dob'           => Carbon::createFromFormat('m/d/Y', $request->dob),
            'date_employed' => Carbon::createFromFormat('m/d/Y', $request->date_employed),
            'staff_id'      => $request->staff_id,
            'address'       => $request->address,
            'marital_status'=> $request->marital_status,
            'salary'        => $request->salary,
            'description'   => $request->description,
            'sex'           => $request->sex,
            'country_id'    => $request->country_id,
            'state_id'      => $request->state_id,
            'lga_id'        => $request->lga_id
        ]);

       // $teacher->user_id = $user->id;
       // $teacher->save();

        if ($request->hasFile('image')) {

            if ($request->file('image')->isValid()) {
                $path = $request->image->store('public/images/teacher/profile');
                $teacher->image = str_replace('public/', null, $path);
                $teacher->save();

                return back()->with('Message', 'Teacher record created successfully');
            }
        }

        return back()->with('Message', 'Teacher record created successfully, upload image again');

    }

    public function dashboard(){

        $teacher = Teacher::with('classrooms', 'classroom_subjects')->where('staff_id', '=', Auth::user()->username)->first();

        return view('teacher.dashboard', compact('teacher'));
    }

    public function showClassroom($classroom_id){
        $classroom = Classroom::with(['students', 'teacher', 'level'])->findOrFail($classroom_id);
        $classrooms = Classroom::all();

        $students = $classroom->active_students();
        $promoted_students = $classroom->promoted_students();

        //dd($students);

        return view('teacher.classroom')
            ->with('classroom', $classroom)
            ->with('teacher', $classroom->teacher)
            ->with('students', $students)
            ->with('promoted_students', $promoted_students)
            ->with('classrooms', $classrooms);
    }


    public function showStudent($classroom_id, $student_id){

        $classroom = Classroom::with(['students', 'teacher'])->findOrFail($classroom_id);
        $classrooms = Classroom::all();
        $student = Student::with('results')->findOrFail($student_id);

        return view('teacher.student')
            ->with('classroom', $classroom)
            ->with('classrooms', $classrooms)
            ->with('teacher', $classroom->teacher)
            ->with('student', $student);
    }

    public function showSubject($classroom_id, $subject_id){

        $classroom = Classroom::with(['results', 'students'])->findOrFail($classroom_id);

        $students = $classroom->students()->where('status', '=', 'active')->get();

        $subject = ClassroomSubject::where('subject_id', '=', $subject_id)->where('classroom_id', '=', $classroom_id)->first();


        $session = Session::where('status', 'active')->first();

        if(isset($session) && !is_null($session) && $session->status == 'active'){

            $subject_results = Result::where('subject_id', '=', $subject_id)
                ->where('classroom_id', '=', $classroom_id)
                ->where('session_id', '=', $session->id)->get();
        }else{
            $subject_results = null;
        }

        $teacher =  Auth::user()->teacher;

        return view('teacher.subject')
            ->with('classroom', $classroom)
            ->with('teacher', $teacher)
            ->with('subject', $subject)
            ->with('subject_results', $subject_results)
            ->with('students', $students);
    }

    public function showTeacherClassroom($classroom_id){
        $classroom = Classroom::with('students', 'subjects')->firstOrFail($classroom_id);
        $classrooms = Classroom::all();
        $students = $classroom->students()->where('status', '=', 'active');


        return view('teacher.classroom')->with('classroom', $classroom)
            ->with('classrooms', $classrooms)
            ->with('students', $students);
    }


    public function storeStudentSubjectScore(Requests\StoreStudentSubjectScore $request, $classroom_id, $subject_id, $student_id){

        $result = Result::where('classroom_id', '=', $request->classroom_id)
                        ->where('subject_id', '=', $request->subject_id)
                        ->where('student_id', '=', $request->student_id)
                        ->where('term', '=', $request->term)
                        ->where('session_id', '=', $request->session_id)
                        ->where('teacher_id', '=', $request->teacher_id)->get()->first();


        if(!isset($result) || is_null($result)){
            $result = Result::create([
                'session_id' => $request->session_id,
                'classroom_id' => $request->classroom_id,
                'subject_id' => $request->subject_id,
                'student_id' => $request->student_id,
                'teacher_id' => $request->teacher_id,
                'term' => $request->term,
                'first_ca' => (int)$request->first_ca,
                'second_ca' => (int)$request->second_ca,
                'exam' => (int)$request->exam,
            ]);
        }else{
            $result->first_ca = (int)$request->first_ca;
            $result->second_ca = (int)$request->second_ca;
            $result->exam = (int)$request->exam;
            $result->save();
        }

        $score = [];
        $score['first_ca'] = $result->first_ca;
        $score['second_ca'] = $result->second_ca;
        $score['exam'] = $result->exam;
        $score['total'] = $result->total();
        $score['grade'] = $result->grade();
        $score['position'] = $result->position();

        return $score;
    }


    public function update(Request $request, $id){

        $teacher = Teacher::find($id);

        $teacher->salary = $request->salary;
        $teacher->marital_status = $request->marital_status;
        $teacher->address = $request->address;

        $teacher->save();

        return back()->with('message', 'teacher record updated successfully');

    }


    public function promoteStudent(Requests\PromoteStudent $request, $classroom_id, $student_id){

        $student = Student::find($student_id);

        if($student->status == 'active'){
            $student->previous_classroom_id = $classroom_id;
            $student->classroom_id = $request->promoted_to_classroom_id;
            $student->status = 'promoting';

            $student->save();
        }else{
            return back()->with('message', 'Students cant be promoted');
        }


        return back()->with('message', 'Students promoted successfully');

    }

    public function promoteAllStudent(Requests\PromoteAllStudent $request, $classroom_id){

        Student::where('classroom_id', '=', $classroom_id)->where('status', '=', 'active')->update([
            'previous_classroom_id' => $classroom_id,
            'classroom_id' => $request->promoted_to_classroom_id,
            'status' => 'promoting'
        ]);

        return back()->with('message', 'Students promoted successfully');
    }

    public function repeatStudent(Requests\RepeatStudent $request, $classroom_id, $student_id){

        $student = Student::find($student_id);

        if($student->status == 'active'){
            $student->previous_classroom_id = $classroom_id;
            $student->classroom_id = $request->repeated_to_classroom_id;
            $student->status = 'repeating';

            $student->save();
        }else{
            return back()->with('message', 'Students cant be repeated');
        }


        return back()->with('message', 'Students repeated successfully');

    }

    public function repeatAllStudent(Requests\RepeatAllStudent $request, $classroom_id){

        Student::where('classroom_id', '=', $classroom_id)->where('status', '=', 'active')->update([
            'previous_classroom_id' => $classroom_id,
            'classroom_id' => $request->repeated_to_classroom_id,
            'status' => 'repeating'
        ]);

        return back()->with('message', 'Students repeated successfully');
    }
}

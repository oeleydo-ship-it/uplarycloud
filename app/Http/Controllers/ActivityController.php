<?php
namespace App\Http\Controllers;
use App\Models\ActivityLog;use App\Support\TenantContext;use Illuminate\Http\Request;use Illuminate\View\View;
class ActivityController extends Controller{public function __invoke(Request $request,TenantContext $context):View{$query=ActivityLog::where('tenant_id',$context->id())->with('user')->when($request->filled('search'),fn($q)=>$q->where('description','like','%'.$request->search.'%'))->when($request->filled('status'),fn($q)=>$q->where('status',$request->status));return view('operations.activity',['activities'=>$query->latest('created_at')->paginate(30)->withQueryString()]);}}

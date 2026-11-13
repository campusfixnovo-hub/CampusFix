<?php
namespace App\Http\Controllers;

use App\Enums\{TicketPriority, TicketStatus};
use App\Models\{Building, Campus, Category, Location, SlaPolicy, Ticket, TicketHistory, Attachment};
use App\Services\{SlaClock, TicketAccessScope, TicketHistoryPresenter, TicketWorkflow};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

class TicketController extends Controller
{
    public function index(Request $request, TicketAccessScope $scope, SlaClock $clock)
    {
        $this->authorize('viewAny', Ticket::class); $query = $scope->query($request->user())->with(['category','requester','assignedTechnician','team','slaPolicy.calendar'])->withCount(['slaPauses as active_sla_pause_count'=>fn($q)=>$q->whereNull('ended_at')]);
        if ($q = $request->string('q')->trim()->toString()) $query->where(fn($b)=>$b->where('code','like',"%$q%")->orWhere('title','like',"%$q%"));
        foreach (['status','priority','category_id'] as $field) if ($request->filled($field)) $query->where($field,$request->input($field));
        if ($request->input('sla') === 'overdue') {
            $query->where('due_at','<',now())->whereNotIn('status',['resolved','closed','cancelled','awaiting_requester']);
        } elseif ($request->input('sla') === 'soon') {
            $candidates = (clone $query)->with('slaPolicy.calendar')->whereBetween('due_at',[now(),now()->addDays(14)])->get();
            $ids = $candidates->filter(function (Ticket $ticket) use ($clock): bool {
                if ($ticket->active_sla_pause_count > 0) return false;
                $calendar = $ticket->slaPolicy?->calendar;
                $minutes = $calendar
                    ? $clock->businessMinutesBetween(\Carbon\CarbonImmutable::now(), \Carbon\CarbonImmutable::parse($ticket->due_at), $calendar)
                    : now()->diffInMinutes($ticket->due_at);
                return $minutes >= 0 && $minutes <= ($ticket->slaPolicy?->warn_before_minutes ?? 240);
            })->modelKeys();
            $query->whereKey($ids);
        }
        $tickets=$query->latest()->paginate(15)->withQueryString();
        return view('tickets.index', ['tickets'=>$tickets,'statuses'=>TicketStatus::cases(),'priorities'=>TicketPriority::cases(),'categories'=>Category::where('is_active',true)->orderBy('name')->get()]);
    }
    public function create()
    {
        $this->authorize('create', Ticket::class);
        return view('tickets.create',['categories'=>Category::where('is_active',true)->orderBy('name')->get(),'campuses'=>Campus::where('is_active',true)->orderBy('name')->get(),'buildings'=>Building::where('is_active',true)->orderBy('name')->get(),'locations'=>Location::where('is_active',true)->orderBy('name')->get(),'priorities'=>TicketPriority::cases()]);
    }
    public function store(Request $request, TicketWorkflow $workflow)
    {
        $this->authorize('create', Ticket::class);
        $data=$request->validate(['title'=>['required','string','max:180'],'description'=>['required','string','max:20000'],'category_id'=>['required',Rule::exists('categories','id')->where('is_active',true)],'priority'=>['required',Rule::enum(TicketPriority::class)],'campus_id'=>['required',Rule::exists('campuses','id')->where('is_active',true)],'building_id'=>['nullable',Rule::exists('buildings','id')->where('is_active',true)],'location_id'=>['nullable',Rule::exists('locations','id')->where('is_active',true)],'location_details'=>['nullable','string','max:2000'],'attachments'=>['nullable','array','max:5'],'attachments.*'=>['file','mimes:jpg,jpeg,png,webp,pdf','max:10240']]);
        if (!empty($data['building_id']) && !Building::where('id',$data['building_id'])->where('campus_id',$data['campus_id'])->exists()) return back()->withErrors(['building_id'=>'O prédio não pertence ao campus selecionado.'])->withInput();
        if (!empty($data['location_id']) && (!empty($data['building_id']) ? !Location::where('id',$data['location_id'])->where('building_id',$data['building_id'])->exists() : true)) return back()->withErrors(['location_id'=>'O local deve pertencer ao prédio selecionado.'])->withInput();
        $files=$data['attachments']??[]; unset($data['attachments']); $storedPaths=[];
        try {
            $ticket=DB::transaction(function()use($workflow,$request,$data,$files,&$storedPaths){
                $ticket=$workflow->create($request->user(),$data);
                foreach($files as $file){
                    $path=$file->store("tickets/{$ticket->id}",'local');
                    if(! $path) throw new \RuntimeException('Falha ao armazenar o anexo.');
                    $storedPaths[]=$path;
                    $attachment=$ticket->attachments()->create(['uploaded_by'=>$request->user()->id,'disk'=>'local','path'=>$path,'original_name'=>$file->getClientOriginalName(),'mime_type'=>$file->getMimeType(),'size_bytes'=>$file->getSize(),'sha256'=>hash_file('sha256',$file->getRealPath())]);
                    TicketHistory::create(['ticket_id'=>$ticket->id,'actor_id'=>$request->user()->id,'event_type'=>\App\Enums\TicketAuditEvent::AttachmentAdded,'source'=>'web','metadata'=>['attachment_id'=>$attachment->id,'original_name'=>$attachment->original_name],'created_at'=>now()]);
                }
                return $ticket;
            });
        } catch (Throwable $exception) {
            foreach($storedPaths as $path) Storage::disk('local')->delete($path);
            throw $exception;
        }
        return redirect()->route('tickets.show',$ticket)->with('status','Solicitação aberta com sucesso.');
    }
    public function show(Ticket $ticket)
    {
        $this->authorize('view',$ticket);
        $ticket->load(['requester','assignedTechnician','team','category','campus','building','location','histories.actor','comments.user','attachments','rating','slaPolicy.calendar','slaPauses']);
        $comments=$ticket->comments->filter(fn($c)=>$this->userCanSeeComment(auth()->user(),$c));
        $historyEvents = $ticket->histories->filter(fn ($event) => auth()->user()->can('viewInternal', $ticket) || ! ($event->event_type === \App\Enums\TicketAuditEvent::CommentAdded && ($event->new_values['internal'] ?? false)));
        $history = app(TicketHistoryPresenter::class)->present($historyEvents, $ticket);
        $slaPresentation = app(\App\Services\TicketSlaPresenter::class)->present($ticket);
        return view('tickets.show',compact('ticket','comments','history','slaPresentation'));
    }
    private function userCanSeeComment($user,$comment): bool { return !$comment->is_internal || $user->can('view',$comment); }
}

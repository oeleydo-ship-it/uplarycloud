<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportTicketReply extends Model
{
    protected $fillable = ['support_ticket_id', 'user_id', 'message', 'staff_reply'];
    protected function casts(): array { return ['staff_reply' => 'boolean']; }
    public function ticket(): BelongsTo { return $this->belongsTo(SupportTicket::class, 'support_ticket_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Attachment extends Model
{
    use HasFactory;

    protected $fillable = ['ticket_id', 'comment_id', 'uploaded_by', 'disk', 'path', 'original_name', 'mime_type', 'size_bytes', 'sha256'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    public function comment()
    {
        return $this->belongsTo(Comment::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}

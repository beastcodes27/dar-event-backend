<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['sender_id', 'recipient_id', 'status'])]
class MessageRequest extends Model {}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use App\Models\CustomerNote;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Gate;

/** Deleting a note on one customer (CHW-44, B5): one of theirs at this business, or a 404. */
class DeleteNoteRequest extends CustomerRequest
{
    private ?CustomerNote $note = null;

    public function authorize(): bool
    {
        return Gate::allows('delete', $this->note());
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [];
    }

    public function note(): CustomerNote
    {
        $customer = $this->customer();

        return $this->note ??= CustomerNote::query()
            ->where('business_id', $customer->business_id)
            ->where('user_id', $customer->user_id)
            ->whereKey((int) $this->route('note'))
            ->first() ?? abort(404);
    }
}

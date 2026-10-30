<?php

declare(strict_types=1);

namespace App\Http\Requests\Verification;

use App\Models\Business;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;
use LogicException;

/**
 * A verification document (CHW-145): whoever runs the business worked in
 * (BusinessPolicy::update) sends a photo or a PDF, 10 MB at most. The type
 * is read from the file's content, never its name; nothing that can carry
 * a script (SVG, HTML) gets through. It names the business its page shows
 * (CHW-295): one switched since in another tab (a business's email link)
 * is refused, never filed under the business now worked in.
 */
final class DocumentRequest extends FormRequest
{
    /** 10 MB: a phone photo of an ID or a scanned bill, never more. */
    public const int MAX_KILOBYTES = 10240;

    public function authorize(): bool
    {
        $business = $this->business();

        return $business instanceof Business && ($this->user()?->can('update', $business) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'mimetypes:image/jpeg,image/png,application/pdf', 'max:'.self::MAX_KILOBYTES],
        ];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->integer('business') !== $this->business()?->id) {
                $validator->errors()->add('file', __('These documents changed since you opened this page. Reload it and look again.'));
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        // PHP dropped it (upload_max_filesize, post_max_size): too large for this server, whatever the rule says.
        return ['file.uploaded' => __('The file is larger than this server takes. Try a smaller one.')];
    }

    public function business(): ?Business
    {
        return app(TenantContext::class)->business();
    }

    /** The validated file. */
    public function document(): UploadedFile
    {
        $file = $this->file('file');

        return $file instanceof UploadedFile ? $file : throw new LogicException('A validated document request carries one file.');
    }
}

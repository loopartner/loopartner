<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Support\Media\ImageProcessor;
use App\Support\Media\MediaPurpose;
use App\Support\Media\Rules\ImageFitsPurpose;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * POST /media : envoi d'une image pour un usage donné.
 *
 * Le type du fichier est vérifié sur son contenu (finfo, puis en-tête de l'image), jamais
 * sur son extension ni sur le type annoncé par le client. Les limites dépendent de l'usage.
 */
class StoreMediaRequest extends FormRequest
{
    /**
     * Le rôle requis par l'usage est vérifié ici (403). Un usage inconnu est
     * signalé par la validation (422).
     */
    public function authorize(): Response
    {
        $limits = $this->requestedPurpose()?->limits();

        /** @var User $user */
        $user = $this->user();

        return $limits === null || $limits->allows($user)
            ? Response::allow()
            : Response::deny('Votre type de compte ne permet pas d\'envoyer une image pour cet usage.');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $limits = $this->requestedPurpose()?->limits();

        return [
            'purpose' => [
                'required',
                'string',
                Rule::enum(MediaPurpose::class),
            ],
            'file' => [
                'required',
                'file',
                'mimetypes:'.implode(',', ImageProcessor::ACCEPTED_MIME_TYPES),
                ...($limits === null ? [] : ['max:'.$limits->maxKilobytes, new ImageFitsPurpose($limits)]),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.mimetypes' => 'Le fichier doit être une image JPEG, PNG ou WebP.',
            'file.max' => 'Le fichier ne doit pas dépasser :max kilo-octets.',
        ];
    }

    public function purpose(): MediaPurpose
    {
        return MediaPurpose::from($this->string('purpose')->toString());
    }

    public function uploadedImage(): UploadedFile
    {
        /** @var UploadedFile */
        return $this->file('file');
    }

    private function requestedPurpose(): ?MediaPurpose
    {
        $purpose = $this->input('purpose');

        return is_string($purpose) ? MediaPurpose::tryFrom($purpose) : null;
    }
}

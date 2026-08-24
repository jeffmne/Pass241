<?php

namespace App\Livewire\Organizations;

use App\Actions\Organizations\SubmitKyc;
use App\Enums\AuditAction;
use App\Enums\DocumentType;
use App\Enums\KycStatus;
use App\Enums\PermissionName;
use App\Models\OrganizationDocument;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\OrganizationContext;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;

#[Layout('components.layouts.app')]
class Settings extends Component
{
    use WithFileUploads;

    #[Validate('required|string|min:2|max:120')]
    public string $name = '';

    #[Validate('nullable|email|max:255')]
    public string $contactEmail = '';

    #[Validate('nullable|string|max:20')]
    public string $contactPhone = '';

    /** @var array<string, mixed> */
    public array $documents = [];

    public function mount(OrganizationContext $context): void
    {
        $organization = $context->current();

        $this->name = $organization->name;
        $this->contactEmail = $organization->contact_email ?? '';
        $this->contactPhone = $organization->contact_phone ?? '';
    }

    /**
     * Taille maximale d'une piece justificative, en kilo-octets.
     *
     * La meme valeur borne le televersement temporaire de Livewire
     * (config/livewire.php) : un fichier trop lourd est ainsi refuse des le
     * depart, sans avoir ete transfere en entier. Sur une connexion mobile
     * gabonaise, la difference est concrete.
     */
    public const MAX_DOCUMENT_KILOBYTES = 2048;

    protected function rules(): array
    {
        return [
            'documents.*' => [
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,pdf',
                'max:'.self::MAX_DOCUMENT_KILOBYTES,
            ],
        ];
    }

    protected function messages(): array
    {
        return [
            'documents.*.max' => 'Chaque piece doit peser 2 Mo au maximum.',
            'documents.*.mimes' => 'Formats acceptes : JPG, PNG ou PDF.',
            'documents.*.file' => 'Le fichier envoye est invalide.',
        ];
    }

    public function updateProfile(AuditLogger $audit, OrganizationContext $context): void
    {
        $this->authorizeManage();
        $this->validateOnly('name');
        $this->validateOnly('contactEmail');
        $this->validateOnly('contactPhone');

        $organization = $context->current();

        $organization->update([
            'name' => $this->name,
            'contact_email' => $this->contactEmail !== '' ? $this->contactEmail : null,
            'contact_phone' => $this->contactPhone !== '' ? $this->contactPhone : null,
        ]);

        $audit->log(AuditAction::OrganizationUpdated, $organization);

        $this->dispatch('notify', message: 'Organisation mise a jour.');
    }

    public function submitKyc(SubmitKyc $submitKyc, OrganizationContext $context): void
    {
        $this->authorizeManage();
        $this->validate();

        $organization = $context->current();

        $files = array_filter(
            $this->documents,
            fn ($file) => $file instanceof TemporaryUploadedFile
        );

        try {
            $submitKyc->handle($organization, auth()->user(), $files);
        } catch (RuntimeException $e) {
            $this->addError('documents', $e->getMessage());

            return;
        }

        $this->documents = [];
        $this->dispatch('notify', message: 'Pieces transmises. Verification en cours.');
    }

    private function authorizeManage(): void
    {
        abort_unless(
            auth()->user()->can(PermissionName::OrganizationManage->value),
            403,
            'Vous n\'avez pas le droit de modifier cette organisation.'
        );
    }

    public function render()
    {
        $organization = app(OrganizationContext::class)->current();

        return view('livewire.organizations.settings', [
            'organization' => $organization,
            'requiredDocuments' => DocumentType::requiredFor($organization->type),
            'uploadedDocuments' => OrganizationDocument::query()
                ->where('organization_id', $organization->getKey())
                ->latest()
                ->get()
                ->keyBy(fn (OrganizationDocument $doc) => $doc->type->value),
            'canSubmitKyc' => $organization->kyc_status !== KycStatus::Verified,
            'canManage' => auth()->user()->can(PermissionName::OrganizationManage->value),
        ]);
    }
}

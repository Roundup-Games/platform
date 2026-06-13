<?php

namespace App\Livewire\Media;

use App\Models\Media;
use App\Models\Team;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Spatie\MediaLibrary\HasMedia;

class ImageUpload extends Component
{
    use WithFileUploads;

    #[Locked]
    public string $model_type;

    #[Locked]
    public string $model_id;

    /** The media collection name (e.g. 'logo', 'banner'). */
    public string $collection;

    /** The label shown in the UI (e.g. 'Team Logo'). */
    public string $label;

    /** Accept attribute for the file input. */
    public string $accept = 'image/jpeg,image/png,image/gif,image/webp';

    /** Maximum file size in KB. */
    public int $maxSize = 2048;

    /** Recommended dimensions hint. */
    public string $dimensionHint = '';

    /** The uploaded file (Livewire temporary). */
    public ?TemporaryUploadedFile $image = null;

    /** Whether we're currently processing. */
    public bool $uploading = false;

    /** Flash message. */
    public ?string $message = null;

    public ?string $messageType = null;

    public function mount(Model $model, string $collection = 'logo', string $label = 'Image'): void
    {
        $this->model_type = get_class($model);
        $key = $model->getKey();
        $this->model_id = to_string_id($key);
        $this->collection = $collection;
        $this->label = $label;
    }

    /**
     * Resolve the Eloquent model from stored type and ID.
     */
    private function resolveModel(): HasMedia
    {
        $model = app($this->model_type)::findOrFail((string) $this->model_id);

        return $model;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'image' => [
                'required',
                'image',
                'max:'.$this->maxSize,
                'mimes:jpeg,png,gif,webp',
            ],
        ];
    }

    public function updatedImage(): void
    {
        $this->validateOnly('image');
    }

    public function upload(): void
    {
        $this->validate();

        $this->uploading = true;

        $model = $this->resolveModel();

        try {
            // Clear existing media in this collection (singleFile behavior)
            $model->clearMediaCollection($this->collection);

            if ($this->image === null) {
                return;
            }

            $model->addMedia($this->image->getRealPath())
                ->usingName($this->image->getClientOriginalName())
                ->toMediaCollection($this->collection);

            Log::info('Media uploaded', [
                'model_type' => $this->model_type,
                'model_id' => $this->model_id,
                'collection' => $this->collection,
                'uploaded_by' => Auth::id(),
            ]);

            $this->message = "{$this->label} uploaded successfully.";
            $this->messageType = 'success';
            $this->image = null;
        } catch (\Throwable $e) {
            Log::error('Media upload failed', [
                'model_type' => $this->model_type,
                'model_id' => $this->model_id,
                'collection' => $this->collection,
                'uploaded_by' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            $this->message = 'Upload failed. Please try again.';
            $this->messageType = 'error';
        } finally {
            $this->uploading = false;
        }
    }

    public function remove(): void
    {
        $model = $this->resolveModel();

        try {
            $model->clearMediaCollection($this->collection);

            Log::info('Media removed', [
                'model_type' => $this->model_type,
                'model_id' => $this->model_id,
                'collection' => $this->collection,
                'removed_by' => Auth::id(),
            ]);

            $this->message = "{$this->label} removed.";
            $this->messageType = 'success';
        } catch (\Throwable $e) {
            Log::error('Media removal failed', [
                'model_type' => $this->model_type,
                'model_id' => $this->model_id,
                'collection' => $this->collection,
                'error' => $e->getMessage(),
            ]);

            $this->message = 'Failed to remove image.';
            $this->messageType = 'error';
        }
    }

    public function getHasMediaProperty(): bool
    {
        return $this->resolveModel()->hasMedia($this->collection);
    }

    public function getCurrentMediaProperty(): ?\Spatie\MediaLibrary\MediaCollections\Models\Media
    {
        $media = $this->resolveModel()->getMedia($this->collection);

        $first = $media->isNotEmpty() ? $media->first() : null;

        return $first instanceof \Spatie\MediaLibrary\MediaCollections\Models\Media ? $first : null;
    }

    public function render(): View
    {
        return view('livewire.media.image-upload');
    }
}

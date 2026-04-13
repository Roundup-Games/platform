<?php

namespace App\Livewire\Media;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Livewire\WithFileUploads;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ImageUpload extends Component
{
    use WithFileUploads;

    public Model $model;

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
    public $image = null;

    /** Whether we're currently processing. */
    public bool $uploading = false;

    /** Flash message. */
    public ?string $message = null;
    public ?string $messageType = null;

    public function mount(Model $model, string $collection = 'logo', string $label = 'Image'): void
    {
        $this->model = $model;
        $this->collection = $collection;
        $this->label = $label;
    }

    public function rules(): array
    {
        return [
            'image' => [
                'required',
                'image',
                'max:' . $this->maxSize,
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

        try {
            // Clear existing media in this collection (singleFile behavior)
            $this->model->clearMediaCollection($this->collection);

            $this->model->addMedia($this->image->getRealPath())
                ->usingName($this->image->getClientOriginalName())
                ->toMediaCollection($this->collection);

            Log::info('Media uploaded', [
                'model_type' => get_class($this->model),
                'model_id' => $this->model->getKey(),
                'collection' => $this->collection,
                'uploaded_by' => Auth::id(),
            ]);

            $this->message = "{$this->label} uploaded successfully.";
            $this->messageType = 'success';
            $this->image = null;
        } catch (\Throwable $e) {
            Log::error('Media upload failed', [
                'model_type' => get_class($this->model),
                'model_id' => $this->model->getKey(),
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
        try {
            $this->model->clearMediaCollection($this->collection);

            Log::info('Media removed', [
                'model_type' => get_class($this->model),
                'model_id' => $this->model->getKey(),
                'collection' => $this->collection,
                'removed_by' => Auth::id(),
            ]);

            $this->message = "{$this->label} removed.";
            $this->messageType = 'success';
        } catch (\Throwable $e) {
            Log::error('Media removal failed', [
                'model_type' => get_class($this->model),
                'model_id' => $this->model->getKey(),
                'collection' => $this->collection,
                'error' => $e->getMessage(),
            ]);

            $this->message = 'Failed to remove image.';
            $this->messageType = 'error';
        }
    }

    public function getHasMediaProperty(): bool
    {
        return $this->model->fresh()->hasMedia($this->collection);
    }

    public function getCurrentMediaProperty(): ?Media
    {
        return $this->model->fresh()->getFirstMedia($this->collection);
    }

    public function render()
    {
        return view('livewire.media.image-upload');
    }
}

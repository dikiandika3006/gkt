<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Attachment;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Attachment::DISK);
    }

    public function test_upload_download_and_delete(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Bendahara]));
        $tx = Transaction::factory()->create();

        $id = $this->postJson('/api/attachments', [
            'attachable_type' => 'transaction', 'attachable_id' => $tx->id,
            'file' => UploadedFile::fake()->image('nota.jpg'),
        ])->assertCreated()->json('data.id');

        $path = Attachment::findOrFail($id)->path;
        Storage::disk(Attachment::DISK)->assertExists($path);

        $this->get("/api/attachments/{$id}")->assertOk();

        $this->deleteJson("/api/attachments/{$id}")->assertOk();
        Storage::disk(Attachment::DISK)->assertMissing($path);
    }

    public function test_rejects_wrong_type_and_oversized_file(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Bendahara]));
        $tx = Transaction::factory()->create();

        $this->postJson('/api/attachments', [
            'attachable_type' => 'transaction', 'attachable_id' => $tx->id,
            'file' => UploadedFile::fake()->create('script.php', 10, 'application/x-php'),
        ])->assertJsonValidationErrors('file');

        $this->postJson('/api/attachments', [
            'attachable_type' => 'transaction', 'attachable_id' => $tx->id,
            'file' => UploadedFile::fake()->create('besar.pdf', 6000, 'application/pdf'),
        ])->assertJsonValidationErrors('file');
    }

    public function test_sekretariat_cannot_attach_to_regular_transaction_and_guest_cannot_download(): void
    {
        $tx = Transaction::factory()->create();
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Sekretariat]));

        $this->postJson('/api/attachments', [
            'attachable_type' => 'transaction', 'attachable_id' => $tx->id,
            'file' => UploadedFile::fake()->image('nota.jpg'),
        ])->assertForbidden();

        $attachment = $tx->attachments()->create(['path' => 'x.jpg', 'original_name' => 'x.jpg', 'mime_type' => 'image/jpeg', 'size' => 1]);
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/attachments/{$attachment->id}")->assertUnauthorized();
    }
}

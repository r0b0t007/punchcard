<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Business verification documents (CHW-145): an ID of the owner and a proof
 * of the business's address, sent after onboarding. Site data, like kit
 * orders: the composite foreign key keeps a document in its business's
 * organization. The file lives on the private `documents` disk, encrypted
 * with DOCUMENTS_KEY (key_id), under a random name (path); never its
 * uploaded name. deletes_at is set when the platform decides (accepted or
 * rejected): a nightly job deletes the file and the row then.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id');
            $table->string('kind', 32);
            $table->string('path', 255);
            // Which DOCUMENTS_KEY encrypted it (DocumentCipher::keyId), so a rotation finds the files to encrypt again.
            $table->string('key_id', 16);
            $table->string('mime', 64);
            $table->unsignedInteger('size');
            $table->string('status', 16)->default('submitted');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('deletes_at')->nullable()->index();
            $table->timestamps();

            $table->foreign(['business_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('businesses')
                ->cascadeOnDelete();
            $table->index(['business_id', 'organization_id']);
            $table->index(['business_id', 'kind']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                alter table business_documents
                    add constraint business_documents_kind_check check (kind in ('id_document', 'proof_of_address')),
                    add constraint business_documents_status_check check (status in ('submitted', 'accepted', 'rejected')),
                    add constraint business_documents_decided_check check ((status = 'submitted') = (decided_at is null))
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('business_documents');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('enrollment_documents', 'snapshot')) {
            Schema::table('enrollment_documents', function (Blueprint $table): void {
                $table->json('snapshot')->nullable()->after('content_hash');
            });
        }

        // chunkById, not each()/chunk(): each row's update flips it out of the
        // `document_type = 'com'` filter this same query re-runs on every
        // chunk, so offset-based paging drifts and silently skips rows once
        // the table exceeds one chunk. chunkById re-queries by `id > last id`
        // instead of an offset, so already-updated rows never shift
        // not-yet-processed ones out of range.
        DB::table('enrollment_documents')
            ->where('document_type', 'com')
            ->orderBy('id')
            ->chunkById(500, function (Collection $documents): void {
                foreach ($documents as $document) {
                    DB::table('enrollment_documents')
                        ->where('id', $document->id)
                        ->update([
                            'document_type' => 'cor',
                            'document_number' => preg_replace('/^COM/', 'COR', $document->document_number),
                        ]);
                }
            });
    }

    public function down(): void
    {
        DB::table('enrollment_documents')
            ->where('document_type', 'cor')
            ->orderBy('id')
            ->chunkById(500, function (Collection $documents): void {
                foreach ($documents as $document) {
                    DB::table('enrollment_documents')
                        ->where('id', $document->id)
                        ->update([
                            'document_type' => 'com',
                            'document_number' => preg_replace('/^COR/', 'COM', $document->document_number),
                        ]);
                }
            });

        Schema::table('enrollment_documents', function (Blueprint $table): void {
            $table->dropColumn('snapshot');
        });
    }
};

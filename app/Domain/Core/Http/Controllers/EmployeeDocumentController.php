<?php

declare(strict_types=1);

namespace App\Domain\Core\Http\Controllers;

use App\Domain\Core\Actions\UploadEmployeeDocument;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\EmployeeDocument;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Dokumen (B1.10): private disk only; downloads stream through this controller.
 */
final class EmployeeDocumentController extends Controller
{
    public function store(Request $request, Employee $employee, UploadEmployeeDocument $upload): RedirectResponse
    {
        $this->authorize('manageDocuments', $employee);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.config('myhrmis.documents.max_kb'), 'mimes:'.implode(',', config('myhrmis.documents.mimes'))],
            'document_type_id' => ['required', 'integer'],
            'title' => ['nullable', 'string', 'max:255'],
            'issued_on' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date'],
            'remind_before_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $upload->handle($employee, $request->file('file'), $data, $request->user());

        return $this->done('core.documents.uploaded');
    }

    public function download(Request $request, EmployeeDocument $document): StreamedResponse
    {
        $this->authorize('download', $document);

        activity('core')->performedOn($document)->causedBy($request->user())->event('download')->log('download');

        return Storage::disk((string) config('myhrmis.documents.disk'))->download($document->file_path, $document->original_name);
    }

    public function destroy(Request $request, EmployeeDocument $document): RedirectResponse
    {
        $this->authorize('delete', $document);
        Storage::disk((string) config('myhrmis.documents.disk'))->delete($document->file_path);
        $document->delete();

        return $this->done('common.deleted');
    }
}

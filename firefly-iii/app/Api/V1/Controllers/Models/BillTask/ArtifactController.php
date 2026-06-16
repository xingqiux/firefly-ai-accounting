<?php

declare(strict_types=1);

namespace FireflyIII\Api\V1\Controllers\Models\BillTask;

use FireflyIII\Api\V1\Controllers\Controller;
use FireflyIII\Models\BillArtifact;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ArtifactController extends Controller
{
    public function download(BillArtifact $billArtifact): StreamedResponse
    {
        if (null === $billArtifact->path || '' === $billArtifact->path || !Storage::disk('local')->exists($billArtifact->path)) {
            throw new NotFoundHttpException();
        }

        return Storage::disk('local')->download($billArtifact->path, $billArtifact->filename ?? basename($billArtifact->path));
    }
}

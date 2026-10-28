<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ContentImages;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class MediaController extends Controller
{
    /**
     * A picture of a car or of a part. The name is the checksum of the bytes, so the picture behind a name never changes: a copy is good for a year, and a client
     * that has it does not ask again. Nothing in the file is run or shown as anything but the picture it was taken for.
     */
    public function show(Request $request, ContentImages $images, string $name): Response
    {
        $image = $images->find($name);

        abort_if($image === null, 404);

        $response = response($image['bytes'], 200, [
            'Content-Type' => $image['mime'],
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);

        $response->setEtag($image['sha256']);
        $response->setPublic();
        $response->setMaxAge(31_536_000);
        $response->headers->addCacheControlDirective('immutable');
        $response->isNotModified($request);

        return $response;
    }
}

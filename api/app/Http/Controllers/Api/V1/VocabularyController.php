<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Content\Vocabulary\Vocabulary;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class VocabularyController extends Controller
{
    /**
     * The words that the pack in play says in its own way, for the web app to put over its texts: every language, key by key, with the English text of the pack
     * where a language has none. It is the same for everyone and changes only when another pack is made live, so a copy is good for a minute and the number of the
     * pack tells whether it is still the one.
     */
    public function show(Request $request, Vocabulary $vocabulary): JsonResponse
    {
        $response = response()->json([
            'number' => $vocabulary->number(),
            'messages' => $vocabulary->isEmpty() ? (object) [] : array_map(static fn (array $texts): array|object => $texts === [] ? (object) [] : $texts, $vocabulary->byLocale()),
        ]);

        $response->setEtag(md5($vocabulary->number().'|'.(string) $response->getContent()));
        $response->setPublic();
        $response->setMaxAge(60);
        $response->isNotModified($request);

        return $response;
    }
}

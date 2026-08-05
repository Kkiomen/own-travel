<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\OpenApi\DealApiSpecification;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

final class OpenApiController extends Controller
{
    public function __construct(private readonly DealApiSpecification $specification) {}

    /**
     * The machine-readable contract - what Swagger UI and any client generator
     * reads.
     */
    public function specification(): JsonResponse
    {
        return response()->json($this->specification->toArray(), options: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Swagger UI over that document.
     */
    public function docs(): View
    {
        return view('api-docs', ['specificationUrl' => route('api.openapi')]);
    }
}
